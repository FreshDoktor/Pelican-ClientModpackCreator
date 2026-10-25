<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The parts of the Modrinth API a client modpack needs: recognising jars by their hash, the client
 * and server side of projects, and finding versions of extra client mods.
 */
class ModrinthApi
{
    public const BASE_URL = 'https://api.modrinth.com/v2';

    protected const PROJECTS_CACHE_MINUTES = 30;

    protected const SEARCH_CACHE_MINUTES = 10;

    /**
     * Versions of the files with these sha1 hashes, by lower case hash. Files Modrinth doesn't know are missing.
     *
     * @param  array<int, string>  $hashes
     * @return array<string, array<string, mixed>>
     *
     * @throws Exception
     */
    public function lookupHashes(array $hashes): array
    {
        $versions = [];

        foreach (array_chunk(array_values(array_unique(array_map('strtolower', $hashes))), 500) as $chunk) {
            $response = $this->request(15)
                ->post(self::BASE_URL.'/version_files', [
                    'hashes' => $chunk,
                    'algorithm' => 'sha1',
                ])
                ->json();

            foreach (is_array($response) ? $response : [] as $hash => $version) {
                if (is_array($version) && isset($version['id'], $version['project_id'])) {
                    $versions[strtolower((string) $hash)] = $version;
                }
            }
        }

        return $versions;
    }

    /**
     * Projects by id, cached for a while.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<string, mixed>>
     *
     * @throws Exception
     */
    public function getProjects(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds, fn ($id) => is_string($id) && $id !== '')));

        $projects = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get("minecraft_client_modpack:project:$projectId");

            if (is_array($cached)) {
                $projects[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        foreach (array_chunk($missing, 100) as $chunk) {
            $response = $this->request()
                ->get(self::BASE_URL.'/projects', ['ids' => json_encode($chunk, JSON_THROW_ON_ERROR)])
                ->json();

            foreach (is_array($response) ? $response : [] as $project) {
                if (!is_array($project) || !isset($project['id'], $project['slug'], $project['title'])) {
                    continue;
                }

                $projects[$project['id']] = $project;

                cache()->put("minecraft_client_modpack:project:{$project['id']}", $project, now()->addMinutes(self::PROJECTS_CACHE_MINUTES));
            }
        }

        return $projects;
    }

    /**
     * A single project by id or slug, null if Modrinth doesn't know it or can't be reached.
     *
     * @return array<string, mixed>|null
     */
    public function getProject(string $idOrSlug): ?array
    {
        $idOrSlug = trim($idOrSlug);

        if ($idOrSlug === '' || !preg_match('/^[\w.!@$()`*-]+$/', $idOrSlug)) {
            return null;
        }

        $key = "minecraft_client_modpack:project:$idOrSlug";
        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        // Most searches aren't a slug, don't ask Modrinth about them on every keystroke.
        if ($cached === false) {
            return null;
        }

        try {
            $response = $this->request(10, throw: false)->get(self::BASE_URL.'/project/'.rawurlencode($idOrSlug));
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        $project = $response->json();

        if (!is_array($project) || !isset($project['id'], $project['slug'], $project['title'])) {
            if ($response->status() === 404) {
                cache()->put($key, false, now()->addMinutes(self::SEARCH_CACHE_MINUTES));
            }

            return null;
        }

        cache()->put($key, $project, now()->addMinutes(self::PROJECTS_CACHE_MINUTES));
        cache()->put("minecraft_client_modpack:project:{$project['id']}", $project, now()->addMinutes(self::PROJECTS_CACHE_MINUTES));

        return $project;
    }

    /**
     * Search mods players can install. A Modrinth link, slug or project id is resolved directly and listed first.
     *
     * @param  array<int, string>  $loaders
     * @return array<string, array<string, mixed>> projects by id
     */
    public function searchMods(string $search, array $loaders, ?string $minecraftVersion): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $results = [];

        $direct = preg_match('#modrinth\.com/[a-z]+/([^/?\#\s]+)#i', $search, $matches) ? $matches[1] : (str_contains($search, ' ') ? null : $search);

        if ($direct !== null && ($project = $this->getProject($direct)) && ($project['project_type'] ?? 'mod') === 'mod') {
            $project['project_id'] = $project['id'];
            $results[$project['id']] = $project;
        }

        $facets = [['project_type:mod'], ['client_side:required', 'client_side:optional']];

        if (!empty($loaders)) {
            $facets[] = array_map(fn (string $loader) => "categories:$loader", $loaders);
        }

        if ($minecraftVersion !== null && $minecraftVersion !== '') {
            $facets[] = ["versions:$minecraftVersion"];
        }

        $facets = json_encode($facets, JSON_THROW_ON_ERROR);
        $key = 'minecraft_client_modpack:search:'.md5(strtolower($search).$facets);
        $hits = cache()->get($key);

        if (!is_array($hits)) {
            try {
                $hits = $this->request(5)
                    ->get(self::BASE_URL.'/search', [
                        'query' => $search,
                        'limit' => 20,
                        'facets' => $facets,
                    ])
                    ->json('hits');

                $hits = is_array($hits) ? $hits : [];

                cache()->put($key, $hits, now()->addMinutes(self::SEARCH_CACHE_MINUTES));
            } catch (Exception $exception) {
                report($exception);

                $hits = [];
            }
        }

        foreach ($hits as $hit) {
            if (is_array($hit) && isset($hit['project_id'], $hit['title'], $hit['slug'])) {
                $results[$hit['project_id']] ??= $hit;
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    public function getVersion(string $versionId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $versionId)) {
            return null;
        }

        $response = $this->request(10, throw: false)->get(self::BASE_URL.'/version/'.rawurlencode($versionId));

        if ($response->status() === 404) {
            return null;
        }

        $version = $response->throw()->json();

        return is_array($version) && isset($version['id'], $version['project_id']) ? $version : null;
    }

    /**
     * Versions of a project for these loaders and Minecraft version, newest first.
     *
     * @param  array<int, string>  $loaders
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function getCompatibleVersions(string $projectId, array $loaders, ?string $minecraftVersion): array
    {
        $query = [];

        if (!empty($loaders)) {
            $query['loaders'] = json_encode(array_values($loaders), JSON_THROW_ON_ERROR);
        }

        if ($minecraftVersion !== null && $minecraftVersion !== '') {
            $query['game_versions'] = json_encode([$minecraftVersion], JSON_THROW_ON_ERROR);
        }

        $key = 'minecraft_client_modpack:versions:'.$projectId.':'.md5(json_encode($query, JSON_THROW_ON_ERROR));
        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request(10, throw: false)->get(self::BASE_URL.'/project/'.rawurlencode($projectId).'/version', $query);

        if ($response->status() === 404) {
            return [];
        }

        $versions = $response->throw()->json();
        $versions = array_values(array_filter(is_array($versions) ? $versions : [], fn ($version) => is_array($version) && isset($version['id'])));

        usort($versions, fn (array $a, array $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        cache()->put($key, $versions, now()->addMinutes(self::SEARCH_CACHE_MINUTES));

        return $versions;
    }

    /**
     * The newest release, or the newest version at all if there is no release.
     *
     * @param  array<int, array<string, mixed>>  $versions  newest first
     * @return array<string, mixed>|null
     */
    public function pickVersion(array $versions): ?array
    {
        foreach ($versions as $version) {
            if (($version['version_type'] ?? 'release') === 'release') {
                return $version;
            }
        }

        return $versions[0] ?? null;
    }

    /**
     * The file of a version a launcher should download.
     *
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>|null
     */
    public function primaryFile(array $version): ?array
    {
        $files = array_values(array_filter(is_array($version['files'] ?? null) ? $version['files'] : [], fn ($file) => is_array($file)
            && is_string($file['url'] ?? null)
            && is_string($file['filename'] ?? null)
            && is_string($file['hashes']['sha1'] ?? null)
            && is_string($file['hashes']['sha512'] ?? null)));

        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return $files[0] ?? null;
    }

    /**
     * The file of a version with this sha1 hash.
     *
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>|null
     */
    public function fileByHash(array $version, string $sha1): ?array
    {
        foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $file) {
            if (is_array($file) && strtolower((string) ($file['hashes']['sha1'] ?? '')) === strtolower($sha1)
                && is_string($file['url'] ?? null) && is_string($file['hashes']['sha512'] ?? null)) {
                return $file;
            }
        }

        return null;
    }

    protected function request(int $timeout = 10, bool $throw = true): PendingRequest
    {
        $request = Http::asJson()
            ->timeout($timeout)
            ->connectTimeout(5)
            // Modrinth asks API users to identify themselves.
            ->withUserAgent('FreshDoktor/minecraft-client-modpack (Pelican Panel plugin)');

        return $throw ? $request->throw() : $request;
    }
}
