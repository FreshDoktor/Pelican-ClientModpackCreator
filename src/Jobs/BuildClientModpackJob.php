<?php

namespace FreshDoktor\MinecraftClientModpack\Jobs;

use App\Models\Server;
use App\Models\User;
use FreshDoktor\MinecraftClientModpack\Exceptions\BuildCancelledException;
use FreshDoktor\MinecraftClientModpack\Exceptions\ClientModpackException;
use FreshDoktor\MinecraftClientModpack\Models\ClientModpack;
use FreshDoktor\MinecraftClientModpack\Services\ClientModpackService;
use FreshDoktor\MinecraftClientModpack\Services\ModClassifier;
use FreshDoktor\MinecraftClientModpack\Services\ModJarParser;
use FreshDoktor\MinecraftClientModpack\Services\ModrinthApi;
use FreshDoktor\MinecraftClientModpack\Services\MrpackWriter;
use FreshDoktor\MinecraftClientModpack\Services\ServerEnvironmentService;
use FreshDoktor\MinecraftClientModpack\Services\ServerFilesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Builds the client modpack of a server:
 *
 *  1. read every jar in the mods folder (what it says about itself and its hash), skipping the ones
 *     read before and unchanged since
 *  2. look the jars up on Modrinth by their hash
 *  3. find out the Minecraft version and the loader
 *  4. decide for every mod whether players need it (ModClassifier), mods on Modrinth go into the index,
 *     the others are put into the pack as they are
 *  5. add the extra client mods and what they depend on
 *  6. add the folders that were picked to go into the pack (e.g. config)
 *  7. write the .mrpack and swap it in for the previous build
 *
 * Reading every jar off the daemon takes far longer than a web request may run, so this always runs
 * on the queue.
 *
 * @phpstan-import-type JarInfo from ModJarParser
 * @phpstan-import-type IndexFile from MrpackWriter
 * @phpstan-import-type ContentRow from ClientModpack
 *
 * @phpstan-type ScanEntry array{size: int, modified: string, sha1: ?string, jar: ?JarInfo, error: ?string}
 */
class BuildClientModpackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const SOURCE_SERVER = 'server';

    public const SOURCE_EXTRA = 'extra';

    public const SOURCE_DEPENDENCY = 'dependency';

    public const DELIVERY_MODRINTH = 'modrinth';

    public const DELIVERY_EMBEDDED = 'embedded';

    protected const SCAN_CACHE_DAYS = 60;

    // Most extra mods and dependencies a pack resolves, a guard against dependency loops.
    protected const MAX_EXTRA_MODS = 150;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    protected ?string $tempDirectory = null;

    /** @var array<int, array{key: string, params: array<string, string|int>}> */
    protected array $warnings = [];

    public function __construct(
        public int $serverId,
        public string $operationId,
        public ?int $userId = null,
    ) {
        $this->timeout = (int) config('minecraft-client-modpack.job_timeout', 1800);
    }

    public function handle(
        ClientModpackService $service,
        ServerFilesService $files,
        ModrinthApi $modrinth,
        ServerEnvironmentService $environment,
        ModJarParser $parser,
        ModClassifier $classifier,
        MrpackWriter $writer,
    ): void {
        $server = Server::find($this->serverId);

        // Cancelled while queued, or replaced by a newer build.
        if (!$server || !$service->isCurrentBuild($this->serverId, $this->operationId)) {
            return;
        }

        $lock = Cache::lock("minecraft_client_modpack:lock:{$server->id}", $this->timeout + 60);

        if (!$lock->get()) {
            $this->markFailed($service, trans('minecraft-client-modpack::strings.errors.already_running'));

            return;
        }

        try {
            $this->tempDirectory = $this->tempDirectoryPath();
            $service->disk()->makeDirectory($this->tempDirectory);

            $this->build($server, $service, $files, $modrinth, $environment, $parser, $classifier, $writer);

            $service->finishBuild($this->serverId, $this->operationId);
        } catch (BuildCancelledException) {
            // Cancelled on the page, or a newer build took over.
        } catch (Throwable $exception) {
            if (!$exception instanceof ClientModpackException) {
                report($exception);
            }

            $this->markFailed($service, $service->describeError($exception));
        } finally {
            $this->deleteTempDirectory($service);

            $lock->release();
        }
    }

    /**
     * Called when the worker gave up on the job, e.g. because it ran into its timeout.
     */
    public function failed(?Throwable $exception): void
    {
        // A second worker picking the job up after retry_after doesn't mean the first one stopped working on it.
        if ($exception instanceof MaxAttemptsExceededException) {
            return;
        }

        $service = app(ClientModpackService::class);

        $this->deleteTempDirectory($service);
        $this->markFailed($service, trans('minecraft-client-modpack::strings.errors.build_timeout'));
    }

    /**
     * @throws Throwable
     */
    protected function build(
        Server $server,
        ClientModpackService $service,
        ServerFilesService $files,
        ModrinthApi $modrinth,
        ServerEnvironmentService $environment,
        ModJarParser $parser,
        ModClassifier $classifier,
        MrpackWriter $writer,
    ): void {
        // Settings changed from here on belong to the next build.
        $startedAt = now();
        $pack = $service->findOrCreate($server);

        // 1. Read the jars.
        $this->progress($service, 'reading_mods');

        try {
            $jars = $files->listModJars($server);
        } catch (Throwable $exception) {
            report($exception);

            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.list_failed', ['reason' => $service->describeError($exception)]));
        }

        $scan = $this->readJars($server, $service, $files, $parser, $jars);

        // 2. Look them up on Modrinth.
        $this->progress($service, 'looking_up');

        $hashes = array_values(array_filter(array_map(fn (array $entry) => $entry['sha1'], $scan)));

        try {
            $versions = $hashes === [] ? [] : $modrinth->lookupHashes($hashes);
            $projects = $modrinth->getProjects(array_values(array_unique(array_column($versions, 'project_id'))));
        } catch (Throwable $exception) {
            report($exception);

            // Without Modrinth every mod would have to be put into the pack as it is.
            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.modrinth_unreachable'));
        }

        // 3. Minecraft version and loader.
        $this->progress($service, 'detecting');

        $jarLoaders = array_merge([], ...array_values(array_map(fn (array $entry) => $entry['jar']['loaders'] ?? [], $scan)));
        $loader = filled($pack->loader) ? (string) $pack->loader : $environment->detectLoader($server, $jarLoaders);

        if (!in_array($loader, ClientModpackService::LOADERS, true)) {
            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.no_loader'));
        }

        $minecraftVersion = filled($pack->minecraft_version) ? (string) $pack->minecraft_version : $environment->detectMinecraftVersion($server, $loader, array_values($versions));

        if ($minecraftVersion === null) {
            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.no_minecraft_version'));
        }

        $loaderVersion = filled($pack->loader_version) ? (string) $pack->loader_version : $environment->detectLoaderVersion($server, $loader, $minecraftVersion);

        if ($loaderVersion === null && ($loaderVersion = $environment->latestLoaderVersion($loader)) !== null) {
            $this->warn('latest_loader', ['loader' => Str::title($loader), 'version' => $loaderVersion]);
        }

        if ($loaderVersion === null) {
            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.no_loader_version', ['loader' => Str::title($loader)]));
        }

        // 4. The mods of the server.
        $this->progress($service, 'sorting_mods');

        $rules = $pack->mod_rules ?? [];
        $indexFiles = [];
        $overrides = [];
        $rows = [];
        $embeddedBytes = 0;
        $knownProjects = [];

        $total = count($jars);
        $current = 0;

        foreach ($jars as $name => $jar) {
            $this->progress($service, 'sorting_mods', ++$current, $total);

            $entry = $scan[$name];
            $version = $entry['sha1'] !== null ? ($versions[strtolower($entry['sha1'])] ?? null) : null;
            $project = $version !== null ? ($projects[$version['project_id']] ?? null) : null;

            // Only mods belong into the mods folder, a resource pack that happens to match is no match.
            if ($project !== null && !in_array($project['project_type'] ?? 'mod', ['mod', 'plugin'], true)) {
                [$version, $project] = [null, null];
            }

            $file = $version !== null ? $modrinth->fileByHash($version, $entry['sha1']) : null;

            if ($file !== null && !$service->isAllowedDownloadUrl($file['url'])) {
                $file = null;
            }

            $ruleKey = ClientModpackService::ruleKey($project['id'] ?? null, $name);
            $rule = $rules[$ruleKey] ?? $rules[ClientModpackService::ruleKey(null, $name)] ?? null;

            $classification = $classifier->classify($entry['error'] === null ? $entry['jar'] : null, $project, is_string($rule) ? $rule : null, $loader);
            $decision = $classification['decision'];
            $reason = $classification['reason'];
            $delivery = null;

            if ($decision !== ModClassifier::EXCLUDED) {
                if ($entry['error'] !== null) {
                    [$decision, $reason] = [ModClassifier::EXCLUDED, $entry['error']];
                    $this->warn($entry['error'] === ModClassifier::REASON_TOO_LARGE ? 'mod_too_large' : 'mod_unreadable', [
                        'file' => $name,
                        'max' => $service->formatBytes($service->maxModBytes()),
                    ]);
                } elseif ($file !== null) {
                    $delivery = self::DELIVERY_MODRINTH;
                    $indexFiles[] = $this->indexFile(ClientModpackService::MODS_FOLDER.'/'.$name, $file, $classifier->environment($decision, $project, true));
                } elseif (!$pack->embed_unknown) {
                    [$decision, $reason] = [ModClassifier::EXCLUDED, ModClassifier::REASON_NOT_EMBEDDED];
                    $this->warn('mod_not_embedded', ['file' => $name]);
                } elseif ($embeddedBytes + $jar['size'] > $service->maxEmbeddedBytes()) {
                    [$decision, $reason] = [ModClassifier::EXCLUDED, ModClassifier::REASON_EMBED_LIMIT];
                    $this->warn('embed_limit', ['file' => $name, 'max' => $service->formatBytes($service->maxEmbeddedBytes())]);
                } else {
                    try {
                        $overrides[ClientModpackService::MODS_FOLDER.'/'.$name] = $service->disk()->path($this->localJar($server, $service, $files, $name));
                        $embeddedBytes += $jar['size'];
                        $delivery = self::DELIVERY_EMBEDDED;
                    } catch (Throwable $exception) {
                        report($exception);

                        [$decision, $reason] = [ModClassifier::EXCLUDED, ModClassifier::REASON_UNREADABLE];
                        $this->warn('mod_unreadable', ['file' => $name, 'max' => $service->formatBytes($service->maxModBytes())]);
                    }
                }
            }

            if ($project !== null && $decision !== ModClassifier::EXCLUDED) {
                $knownProjects[$project['id']] = true;
            }

            $rows[] = [
                'key' => 'server-'.md5(strtolower($name)),
                'source' => self::SOURCE_SERVER,
                'filename' => $name,
                'title' => (string) ($project['title'] ?? $entry['jar']['name'] ?? $parser->cleanFilename($name)),
                'icon_url' => is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
                'project_id' => $project['id'] ?? null,
                'project_slug' => $project['slug'] ?? null,
                'version_number' => is_string($version['version_number'] ?? null) ? $version['version_number'] : ($entry['jar']['version'] ?? null),
                'rule_key' => $ruleKey,
                'decision' => $decision,
                'reason' => $reason,
                'delivery' => $delivery,
                'size' => $jar['size'],
                'required_by' => null,
            ];
        }

        // 5. Extra client mods.
        $rows = [...$rows, ...$this->resolveExtraMods($pack, $service, $modrinth, $classifier, $loader, $minecraftVersion, $knownProjects, $indexFiles)];

        // 6. Folders and files that go into the pack as they are.
        $this->collectIncludedFiles($server, $pack, $service, $files, $indexFiles, $overrides, $embeddedBytes);

        if ($indexFiles === [] && $overrides === []) {
            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.empty'));
        }

        // 7. Write the pack.
        $this->progress($service, 'writing');

        $name = $service->packName($pack);
        $versionId = filled($pack->version) ? (string) $pack->version : $startedAt->format('Y.m.d-Hi');

        $index = $writer->index($name, $versionId, $pack->summary, $indexFiles, $minecraftVersion, $loader, $loaderVersion);

        $tempFile = $this->tempDirectory.'/pack.mrpack';
        $writer->write($service->disk()->path($tempFile), $index, $overrides);

        $sha1 = $service->disk()->checksum($tempFile, ['checksum_algo' => 'sha1']);
        $size = $service->disk()->size($tempFile);

        if (!is_string($sha1) || $sha1 === '' || $size <= 0) {
            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.store_failed'));
        }

        $this->progress($service, 'writing');

        $this->store($server, $service, $tempFile, [
            'file_name' => $service->downloadFileName($name, $versionId),
            'size' => $size,
            'sha1' => $sha1,
            'built_version' => $versionId,
            'built_minecraft_version' => $minecraftVersion,
            'built_loader' => $loader,
            'built_loader_version' => $loaderVersion,
            'contents' => $this->sortRows($rows),
            'warnings' => $this->warnings === [] ? null : $this->warnings,
            'mods_fingerprint' => ClientModpackService::modsFingerprint($jars),
            'built_at' => $startedAt,
            'built_by' => $this->userId !== null && User::query()->whereKey($this->userId)->exists() ? $this->userId : null,
        ]);
    }

    /**
     * Hash and metadata of every jar, read off the daemon unless an unchanged copy was read before.
     *
     * @param  array<string, array{name: string, size: int, modified: string}>  $jars
     * @return array<string, ScanEntry>
     *
     * @throws BuildCancelledException
     */
    protected function readJars(Server $server, ClientModpackService $service, ServerFilesService $files, ModJarParser $parser, array $jars): array
    {
        $cacheKey = "minecraft_client_modpack:scan:{$server->id}";
        $cached = Cache::get($cacheKey);
        $cached = is_array($cached) ? $cached : [];

        $scan = [];
        $total = count($jars);
        $current = 0;

        foreach ($jars as $name => $jar) {
            $this->progress($service, 'reading_mods', ++$current, $total);

            $previous = $cached[$name] ?? null;

            // Unreadable jars get another try, the daemon may just have been busy.
            if (is_array($previous) && $previous['size'] === $jar['size'] && $previous['modified'] === $jar['modified'] && $previous['error'] !== ModClassifier::REASON_UNREADABLE) {
                $scan[$name] = $previous;

                continue;
            }

            $entry = ['size' => $jar['size'], 'modified' => $jar['modified'], 'sha1' => null, 'jar' => $parser->fromFilename($name), 'error' => null];

            if ($jar['size'] > $service->maxModBytes()) {
                $entry['error'] = ModClassifier::REASON_TOO_LARGE;
            } else {
                try {
                    $path = $this->localJar($server, $service, $files, $name);
                    $sha1 = $service->disk()->checksum($path, ['checksum_algo' => 'sha1']);

                    if (!is_string($sha1) || $sha1 === '') {
                        throw new ClientModpackException("Could not hash $path");
                    }

                    $entry['sha1'] = strtolower($sha1);
                    $entry['jar'] = $parser->parseFile($service->disk()->path($path), $name);
                } catch (Throwable $exception) {
                    report($exception);

                    $entry['error'] = ModClassifier::REASON_UNREADABLE;
                }
            }

            $scan[$name] = $entry;
        }

        Cache::put($cacheKey, $scan, now()->addDays(self::SCAN_CACHE_DAYS));

        return $scan;
    }

    /**
     * Copy of a jar of the mods folder on the local disk, downloaded once per build.
     *
     * @return string path on the local disk
     *
     * @throws Throwable
     */
    protected function localJar(Server $server, ClientModpackService $service, ServerFilesService $files, string $name): string
    {
        $path = $this->tempDirectory.'/mods/'.$name;

        if (!$service->disk()->exists($path)) {
            $files->download($server, ClientModpackService::MODS_FOLDER.'/'.$name, $path, $service->maxModBytes());
        }

        return $path;
    }

    /**
     * The extra client mods and every mod they need that isn't in the pack yet.
     *
     * @param  array<string, true>  $knownProjects  projects already in the pack
     * @param  array<int, IndexFile>  $indexFiles
     * @return array<int, ContentRow>
     *
     * @throws Throwable
     */
    protected function resolveExtraMods(ClientModpack $pack, ClientModpackService $service, ModrinthApi $modrinth, ModClassifier $classifier, string $loader, string $minecraftVersion, array &$knownProjects, array &$indexFiles): array
    {
        $extras = $pack->extra_mods ?? [];

        if ($extras === []) {
            return [];
        }

        // Quilt runs Fabric mods.
        $loaders = $loader === 'quilt' ? ['quilt', 'fabric'] : [$loader];

        $queue = array_map(fn (array $mod) => [
            'project_id' => (string) $mod['project_id'],
            'version_id' => $mod['version_id'] ?? null,
            'source' => self::SOURCE_EXTRA,
            'title' => (string) ($mod['title'] ?? $mod['project_id']),
            'required_by' => null,
        ], $extras);

        $rows = [];
        $paths = array_flip(array_map(fn (array $file) => strtolower($file['path']), $indexFiles));
        $resolved = 0;

        while ($queue !== [] && $resolved < self::MAX_EXTRA_MODS) {
            $item = array_shift($queue);
            $resolved++;

            $this->progress($service, 'resolving_mods', $resolved, $resolved + count($queue));

            if (isset($knownProjects[$item['project_id']])) {
                if ($item['source'] === self::SOURCE_EXTRA) {
                    $rows[] = $this->extraRow($item, null, null, ModClassifier::EXCLUDED, 'already_included');
                }

                continue;
            }

            $knownProjects[$item['project_id']] = true;

            try {
                // Modrinth answers by id, also when asked by slug.
                $project = collect($modrinth->getProjects([$item['project_id']]))
                    ->first(fn (array $project) => $project['id'] === $item['project_id'] || $project['slug'] === $item['project_id']);
                $version = null;

                // Asked by slug for a project that is in already.
                if ($project !== null && $project['id'] !== $item['project_id'] && isset($knownProjects[$project['id']])) {
                    if ($item['source'] === self::SOURCE_EXTRA) {
                        $rows[] = $this->extraRow($item, $project, null, ModClassifier::EXCLUDED, 'already_included');
                    }

                    continue;
                }

                if ($project !== null) {
                    $knownProjects[$project['id']] = true;


                    $version = $item['version_id'] !== null
                        ? $modrinth->getVersion($item['version_id'])
                        : $modrinth->pickVersion($modrinth->getCompatibleVersions($project['id'], $loaders, $minecraftVersion));
                }
            } catch (Throwable $exception) {
                report($exception);

                throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.modrinth_unreachable'));
            }

            if ($project === null) {
                $rows[] = $this->extraRow($item, null, null, ModClassifier::EXCLUDED, 'project_missing');
                $this->warn('extra_missing', ['name' => $item['title']]);

                continue;
            }

            $file = $version !== null && ($version['project_id'] ?? null) === $project['id'] ? $modrinth->primaryFile($version) : null;

            if ($file === null || !$service->isAllowedDownloadUrl($file['url'])) {
                $rows[] = $this->extraRow($item, $project, null, ModClassifier::EXCLUDED, 'no_version');
                $this->warn('extra_no_version', ['name' => $project['title'], 'minecraft' => $minecraftVersion, 'loader' => Str::title($loader)]);

                continue;
            }

            try {
                $path = MrpackWriter::normalisePath(ClientModpackService::MODS_FOLDER.'/'.$file['filename']);
            } catch (InvalidArgumentException) {
                $rows[] = $this->extraRow($item, $project, $version, ModClassifier::EXCLUDED, 'no_version');

                continue;
            }

            // A mod of the server with the same file name is in already.
            if (!isset($paths[strtolower($path)])) {
                $paths[strtolower($path)] = true;
                $indexFiles[] = $this->indexFile($path, $file, $classifier->environment(ModClassifier::REQUIRED, $project, false));
            }

            $rows[] = $this->extraRow($item, $project, $version, ModClassifier::REQUIRED, $item['source'] === self::SOURCE_EXTRA ? ModClassifier::REASON_RULE : 'dependency', (int) ($file['size'] ?? 0));

            foreach (is_array($version['dependencies'] ?? null) ? $version['dependencies'] : [] as $dependency) {
                if (!is_array($dependency) || ($dependency['dependency_type'] ?? null) !== 'required' || !is_string($dependency['project_id'] ?? null)) {
                    continue;
                }

                if (isset($knownProjects[$dependency['project_id']]) || collect($queue)->contains('project_id', $dependency['project_id'])) {
                    continue;
                }

                $queue[] = [
                    'project_id' => $dependency['project_id'],
                    'version_id' => is_string($dependency['version_id'] ?? null) ? $dependency['version_id'] : null,
                    'source' => self::SOURCE_DEPENDENCY,
                    'title' => $dependency['project_id'],
                    'required_by' => (string) $project['title'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array{project_id: string, source: string, title: string, required_by: ?string}  $item
     * @param  array<string, mixed>|null  $project
     * @param  array<string, mixed>|null  $version
     * @return ContentRow
     */
    protected function extraRow(array $item, ?array $project, ?array $version, string $decision, string $reason, ?int $size = null): array
    {
        $file = $version !== null ? app(ModrinthApi::class)->primaryFile($version) : null;

        return [
            'key' => $item['source'].'-'.$item['project_id'],
            'source' => $item['source'],
            'filename' => (string) ($file['filename'] ?? ''),
            'title' => (string) ($project['title'] ?? $item['title']),
            'icon_url' => is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
            'project_id' => $item['project_id'],
            'project_slug' => $project['slug'] ?? null,
            'version_number' => is_string($version['version_number'] ?? null) ? $version['version_number'] : null,
            'rule_key' => null,
            'decision' => $decision,
            'reason' => $reason,
            'delivery' => $decision === ModClassifier::EXCLUDED ? null : self::DELIVERY_MODRINTH,
            'size' => $size,
            'required_by' => $item['required_by'],
        ];
    }

    /**
     * Put the folders and files picked in the settings (e.g. config) into the pack.
     *
     * @param  array<int, IndexFile>  $indexFiles
     * @param  array<string, string>  $overrides
     *
     * @throws Throwable
     */
    protected function collectIncludedFiles(Server $server, ClientModpack $pack, ClientModpackService $service, ServerFilesService $files, array $indexFiles, array &$overrides, int &$embeddedBytes): void
    {
        $paths = $pack->include_paths ?? [];

        if ($paths === []) {
            return;
        }

        $this->progress($service, 'collecting_files');

        $maxFiles = max(1, (int) config('minecraft-client-modpack.max_override_files', 2000));
        $taken = array_flip(array_map(fn (array $file) => strtolower($file['path']), $indexFiles));
        foreach (array_keys($overrides) as $path) {
            $taken[strtolower($path)] = true;
        }

        $found = [];

        foreach ($paths as $path) {
            try {
                $path = MrpackWriter::normalisePath((string) $path);
            } catch (InvalidArgumentException) {
                continue;
            }

            try {
                $listed = $files->listFilesRecursive($server, $path, $maxFiles - count($found), $truncated);
            } catch (Throwable $exception) {
                report($exception);

                $this->warn('path_unreadable', ['path' => $path]);

                continue;
            }

            if ($listed === [] && !$truncated) {
                $this->warn('path_missing', ['path' => $path]);
            }

            $found += $listed;

            if ($truncated || count($found) >= $maxFiles) {
                $this->warn('too_many_files', ['max' => $maxFiles]);

                break;
            }
        }

        $total = count($found);
        $current = 0;

        foreach ($found as $path => $file) {
            $this->progress($service, 'collecting_files', ++$current, $total);

            // The mods themselves are in the pack already.
            if (isset($taken[strtolower($path)])) {
                continue;
            }

            if ($embeddedBytes + $file['size'] > $service->maxEmbeddedBytes()) {
                $this->warn('files_limit', ['path' => $path, 'max' => $service->formatBytes($service->maxEmbeddedBytes())]);

                break;
            }

            $local = $this->tempDirectory.'/files/'.$path;

            try {
                $files->download($server, $path, $local, $service->maxEmbeddedBytes() - $embeddedBytes);
            } catch (Throwable $exception) {
                report($exception);

                $this->warn('file_unreadable', ['path' => $path]);

                continue;
            }

            $overrides[$path] = $service->disk()->path($local);
            $taken[strtolower($path)] = true;
            $embeddedBytes += $file['size'];
        }
    }

    /**
     * Move the built file in place and save the build, then delete the previous one.
     *
     * @param  string  $tempFile  path on the local disk
     * @param  array<string, mixed>  $attributes
     *
     * @throws Throwable
     */
    protected function store(Server $server, ClientModpackService $service, string $tempFile, array $attributes): void
    {
        $disk = $service->disk();
        $fileToken = Str::random(40);
        $path = $service->filePath($server, $fileToken);

        if (!$disk->move($tempFile, $path)) {
            throw new ClientModpackException(trans('minecraft-client-modpack::strings.errors.store_failed'));
        }

        try {
            $oldToken = Cache::lock("minecraft_client_modpack:pack:{$server->id}", 30)->block(10, fn () => DB::transaction(function () use ($server, $service, $fileToken, $attributes) {
                // The last moment to notice a cancel, after this the build is saved.
                $this->progress($service, 'writing');

                $pack = ClientModpack::query()->where('server_id', $server->id)->lockForUpdate()->first()
                    // Deleted on the page while it was being built.
                    ?? throw new BuildCancelledException();

                $old = $pack->file_token;

                $pack->fill([...$attributes, 'file_token' => $fileToken]);
                $pack->save();

                return $old;
            }));
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($oldToken !== null && $oldToken !== $fileToken) {
            $disk->delete($service->filePath($server, $oldToken));
        }
    }

    /**
     * @param  array<string, mixed>  $file  file of a Modrinth version
     * @param  array{client: string, server: string}  $env
     * @return IndexFile
     */
    protected function indexFile(string $path, array $file, array $env): array
    {
        return [
            'path' => $path,
            'hashes' => [
                'sha1' => strtolower((string) $file['hashes']['sha1']),
                'sha512' => strtolower((string) $file['hashes']['sha512']),
            ],
            'env' => $env,
            'downloads' => [(string) $file['url']],
            'fileSize' => (int) ($file['size'] ?? 0),
        ];
    }

    /**
     * Mods of the server first, then the extra mods with their dependencies, each by name.
     *
     * @param  array<int, ContentRow>  $rows
     * @return array<int, ContentRow>
     */
    protected function sortRows(array $rows): array
    {
        $order = [self::SOURCE_SERVER => 0, self::SOURCE_EXTRA => 1, self::SOURCE_DEPENDENCY => 2];

        usort($rows, fn (array $a, array $b) => ($order[$a['source']] ?? 9) <=> ($order[$b['source']] ?? 9)
            ?: strcasecmp($a['title'], $b['title'])
            ?: strcasecmp($a['filename'], $b['filename']));

        return $rows;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    protected function warn(string $key, array $params = []): void
    {
        $this->warnings[] = ['key' => $key, 'params' => $params];
    }

    /**
     * Report progress, stopping the build as soon as it got cancelled or replaced.
     *
     * @throws BuildCancelledException
     */
    protected function progress(ClientModpackService $service, string $step, ?int $current = null, ?int $total = null): void
    {
        if (!$service->isCurrentBuild($this->serverId, $this->operationId)) {
            throw new BuildCancelledException();
        }

        $service->updateBuildStatus($this->serverId, $this->operationId, [
            'status' => ClientModpackService::STATUS_RUNNING,
            'step' => $step,
            'current' => $current,
            'total' => $total,
        ]);
    }

    protected function markFailed(ClientModpackService $service, string $message): void
    {
        $service->updateBuildStatus($this->serverId, $this->operationId, [
            'status' => ClientModpackService::STATUS_FAILED,
            'message' => $message,
        ]);
    }

    /**
     * Folder on the local disk the build works in. Named after the operation, so failed() finds it
     * too: it runs on another instance of the job.
     */
    protected function tempDirectoryPath(): string
    {
        return ClientModpackService::DIRECTORY.'/tmp/'.$this->operationId;
    }

    protected function deleteTempDirectory(ClientModpackService $service): void
    {
        rescue(fn () => $service->disk()->deleteDirectory($this->tempDirectoryPath()), report: false);

        $this->tempDirectory = null;
    }
}
