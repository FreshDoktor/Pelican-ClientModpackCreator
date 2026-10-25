<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

use App\Models\Server;
use App\Models\User;
use FreshDoktor\MinecraftClientModpack\Exceptions\ClientModpackException;
use FreshDoktor\MinecraftClientModpack\Jobs\BuildClientModpackJob;
use FreshDoktor\MinecraftClientModpack\Models\ClientModpack;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The client modpack of a server: its settings, the built file on the panel's local disk (where the
 * public download route serves it from) and the state of a running build.
 *
 * The download link stays the same across builds, so it can be posted once (e.g. on Discord) and
 * always hands out the latest build. Resetting the link makes the old one stop working.
 *
 * @phpstan-import-type ExtraMod from ClientModpack
 *
 * @phpstan-type BuildStatus array{status: string, operation_id: string, step: ?string, current: ?int, total: ?int, message: ?string, updated_at: int}
 */
class ClientModpackService
{
    public const DIRECTORY = 'minecraft-client-modpack';

    public const MODS_FOLDER = 'mods';

    public const LOADERS = ['fabric', 'quilt', 'forge', 'neoforge'];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FAILED = 'failed';

    public const DEFAULT_MAX_MOD_MB = 64;

    public const DEFAULT_MAX_EMBEDDED_MB = 200;

    // A build that hasn't reported progress for this long is treated as dead, e.g. because no queue worker is running.
    protected const STALE_MINUTES = 20;

    // How long the error of a failed build stays on the page.
    protected const FAILED_HOURS = 24;

    public function find(Server $server): ?ClientModpack
    {
        return ClientModpack::query()->with('builder')->where('server_id', $server->id)->first()?->setRelation('server', $server);
    }

    /**
     * The modpack of the server, created with default settings if it has none yet.
     */
    public function findOrCreate(Server $server): ClientModpack
    {
        $pack = ClientModpack::query()->firstOrCreate(
            ['server_id' => $server->id],
            ['link_token' => Str::random(40)],
        );

        return $pack->setRelation('server', $server);
    }

    /**
     * Change settings that take effect with the next build.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateSettings(ClientModpack $pack, array $attributes): void
    {
        $pack->fill($attributes);

        if ($pack->isDirty()) {
            $pack->settings_changed_at = now();
            $pack->save();
        }
    }

    /**
     * Key a rule for a mod of the server is stored under. The Modrinth project survives updates of
     * the mod (the file name doesn't), so it is preferred.
     */
    public static function ruleKey(?string $projectId, string $filename): string
    {
        return $projectId !== null && $projectId !== '' ? "project:$projectId" : 'file:'.strtolower($filename);
    }

    public function setRule(ClientModpack $pack, string $key, ?string $rule): void
    {
        $rules = $pack->mod_rules ?? [];

        if ($rule === null) {
            unset($rules[$key]);
        } elseif (in_array($rule, ModClassifier::RULES, true)) {
            $rules[$key] = $rule;
        }

        ksort($rules);

        $this->updateSettings($pack, ['mod_rules' => $rules === [] ? null : $rules]);
    }

    /**
     * @param  ExtraMod  $mod
     */
    public function addExtraMod(ClientModpack $pack, array $mod): void
    {
        $mods = array_values(array_filter($pack->extra_mods ?? [], fn (array $other) => $other['project_id'] !== $mod['project_id']));
        $mods[] = $mod;

        usort($mods, fn (array $a, array $b) => strcasecmp($a['title'], $b['title']));

        $this->updateSettings($pack, ['extra_mods' => $mods]);
    }

    public function removeExtraMod(ClientModpack $pack, string $projectId): void
    {
        $mods = array_values(array_filter($pack->extra_mods ?? [], fn (array $other) => $other['project_id'] !== $projectId));

        $this->updateSettings($pack, ['extra_mods' => $mods === [] ? null : $mods]);
    }

    /**
     * A new download link, the old one stops working right away.
     */
    public function resetLink(ClientModpack $pack): void
    {
        $pack->link_token = Str::random(40);
        $pack->save();
    }

    /**
     * Delete the modpack with its settings and file.
     */
    public function delete(ClientModpack $pack): void
    {
        $path = $pack->file_token !== null ? $this->filePath($pack->server, $pack->file_token) : null;

        $pack->delete();

        if ($path !== null) {
            $this->disk()->delete($path);
        }
    }

    /**
     * Everything stored for a server, called once it is deleted.
     */
    public function deleteServerFiles(Server $server): void
    {
        $this->disk()->deleteDirectory($this->directory($server));
        Cache::forget($this->statusKey($server->id));
    }

    public function absolutePath(ClientModpack $pack): ?string
    {
        return $pack->file_token !== null ? $this->disk()->path($this->filePath($pack->server, $pack->file_token)) : null;
    }

    /**
     * Path of a built file relative to the local disk.
     */
    public function filePath(Server $server, string $fileToken): string
    {
        return $this->directory($server)."/$fileToken.mrpack";
    }

    public function directory(Server $server): string
    {
        return self::DIRECTORY.'/'.$server->uuid;
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    public function downloadUrl(ClientModpack $pack): string
    {
        return $this->publicBaseUrl().route('minecraft-client-modpack.download', ['token' => $pack->link_token], false);
    }

    public function publicBaseUrl(): string
    {
        $url = config('minecraft-client-modpack.public_url');

        return rtrim(filled($url) ? (string) $url : (string) config('app.url'), '/');
    }

    /**
     * Why players might not be able to download from the public URL, null if it looks fine.
     */
    public function publicUrlProblem(): ?string
    {
        $parts = parse_url($this->publicBaseUrl());
        $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || $host === '' || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return trans('minecraft-client-modpack::strings.page.url_invalid', ['url' => $this->publicBaseUrl()]);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1' || $host === '[::1]' || str_starts_with($host, '127.')) {
            return trans('minecraft-client-modpack::strings.page.url_local', ['url' => $this->publicBaseUrl()]);
        }

        return null;
    }

    /**
     * Name of the pack, the server's name unless one was set.
     */
    public function packName(ClientModpack $pack): string
    {
        return filled($pack->name) ? (string) $pack->name : $pack->server->name;
    }

    /**
     * File name players get the modpack as, e.g. "My-Server-1.0.0.mrpack".
     */
    public function downloadFileName(string $name, string $version): string
    {
        $base = preg_replace('/[^A-Za-z0-9._+-]+/', '-', Str::ascii("$name-$version")) ?? '';
        $base = trim(preg_replace('/-{2,}/', '-', $base) ?? $base, '-.');

        return mb_substr($base !== '' ? $base : 'modpack', 0, 150).'.mrpack';
    }

    /**
     * Same rule as the mods page of minecraft-modrinth, plus eggs tagged with a mod loader.
     */
    public static function isModdedServer(Server $server): bool
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];
        $tags = $server->egg->tags ?? [];

        if (in_array('modrinth_mods', $features, true)) {
            return true;
        }

        return in_array('minecraft', $tags, true) && (in_array('mods', $features, true) || array_intersect(self::LOADERS, $tags) !== []);
    }

    /**
     * A fingerprint of the jars in the mods folder, changes whenever one is added, removed or replaced.
     *
     * @param  array<string, array{name: string, size: int, modified: string}>  $jars
     */
    public static function modsFingerprint(array $jars): string
    {
        $lines = array_map(fn (array $jar) => strtolower($jar['name']).'|'.$jar['size'].'|'.$jar['modified'], $jars);
        sort($lines);

        return sha1(implode("\n", $lines));
    }

    public function maxModBytes(): int
    {
        $megabytes = (int) config('minecraft-client-modpack.max_mod_size_mb', self::DEFAULT_MAX_MOD_MB);

        return ($megabytes > 0 ? $megabytes : self::DEFAULT_MAX_MOD_MB) * 1024 * 1024;
    }

    public function maxEmbeddedBytes(): int
    {
        $megabytes = (int) config('minecraft-client-modpack.max_embedded_size_mb', self::DEFAULT_MAX_EMBEDDED_MB);

        return ($megabytes > 0 ? $megabytes : self::DEFAULT_MAX_EMBEDDED_MB) * 1024 * 1024;
    }

    public function isAllowedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $hosts = config('minecraft-client-modpack.allowed_download_hosts');
        $hosts = is_array($hosts) ? $hosts : ['cdn.modrinth.com'];

        return in_array(strtolower($parts['host']), array_map('strtolower', $hosts), true);
    }

    /**
     * Queue a build, unless one is already queued or running.
     *
     * @return bool whether a build was queued
     */
    public function dispatchBuild(Server $server, ?User $user): bool
    {
        $status = [
            'status' => self::STATUS_QUEUED,
            'operation_id' => (string) Str::uuid(),
            'step' => null,
            'current' => null,
            'total' => null,
            'message' => null,
            'updated_at' => now()->timestamp,
        ];

        // Atomic, so two clicks don't queue two builds.
        if (!Cache::add($this->statusKey($server->id), $status, now()->addHours(self::FAILED_HOURS))) {
            if ($this->isBuilding($server)) {
                return false;
            }

            // A failed or dead build only blocks until it is replaced.
            Cache::put($this->statusKey($server->id), $status, now()->addHours(self::FAILED_HOURS));
        }

        try {
            BuildClientModpackJob::dispatch($server->id, $status['operation_id'], $user?->id);
        } catch (Throwable $exception) {
            Cache::forget($this->statusKey($server->id));

            throw $exception;
        }

        return true;
    }

    /**
     * The state of the current or last failed build, null if there is none. A build that stopped
     * reporting progress shows up as failed.
     *
     * @return BuildStatus|null
     */
    public function getBuildStatus(Server|int $server): ?array
    {
        $status = Cache::get($this->statusKey($server instanceof Server ? $server->id : $server));

        if (!is_array($status) || !isset($status['status'], $status['operation_id'])) {
            return null;
        }

        $active = in_array($status['status'], [self::STATUS_QUEUED, self::STATUS_RUNNING], true);

        if ($active && ($status['updated_at'] ?? 0) < now()->subMinutes(self::STALE_MINUTES)->timestamp) {
            return array_merge($status, [
                'status' => self::STATUS_FAILED,
                'message' => trans('minecraft-client-modpack::strings.errors.build_stalled'),
            ]);
        }

        return $status;
    }

    public function isBuilding(Server|int $server): bool
    {
        return in_array($this->getBuildStatus($server)['status'] ?? null, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    /**
     * Whether $operationId is still the build the server waits for, it may have been replaced by a newer one.
     */
    public function isCurrentBuild(int $serverId, string $operationId): bool
    {
        $status = Cache::get($this->statusKey($serverId));

        return is_array($status) && ($status['operation_id'] ?? null) === $operationId;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateBuildStatus(int $serverId, string $operationId, array $changes): void
    {
        $status = Cache::get($this->statusKey($serverId));

        if (!is_array($status) || ($status['operation_id'] ?? null) !== $operationId) {
            return;
        }

        Cache::put($this->statusKey($serverId), array_merge($status, $changes, ['updated_at' => now()->timestamp]), now()->addHours(self::FAILED_HOURS));
    }

    public function finishBuild(int $serverId, string $operationId): void
    {
        if ($this->isCurrentBuild($serverId, $operationId)) {
            Cache::forget($this->statusKey($serverId));
        }
    }

    /**
     * Stop a queued or running build, it notices at its next step. Also clears the error of a failed one.
     */
    public function cancelBuild(Server $server): void
    {
        Cache::forget($this->statusKey($server->id));
    }

    protected function statusKey(int $serverId): string
    {
        return "minecraft_client_modpack:build:$serverId";
    }

    /**
     * Turn a failed daemon call into something a user can act on, e.g. the daemon's own error message.
     */
    public function describeError(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $error = $exception->response->json('error') ?? $exception->response->json('errors.0.detail');

            return trim("HTTP {$exception->response->status()}: ".(is_string($error) ? $error : $exception->response->reason()));
        }

        if ($exception instanceof ConnectionException) {
            return trans('minecraft-client-modpack::strings.errors.daemon_unreachable');
        }

        if ($exception instanceof ClientModpackException) {
            return $exception->getMessage();
        }

        return trans('minecraft-client-modpack::strings.errors.unexpected');
    }

    public function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 * 1024 => round($bytes / 1024 / 1024 / 1024, 2).' GB',
            $bytes >= 1024 * 1024 => round($bytes / 1024 / 1024, 2).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    }
}
