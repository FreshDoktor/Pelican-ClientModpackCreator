<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

use ZipArchive;

/**
 * Reads what a mod jar says about itself: id, name, version, loader, the Minecraft version it is made
 * for and, most important here, whether it is needed on the client at all. Knows the metadata files
 * of Fabric, Quilt, Forge, NeoForge and legacy Forge, and falls back to guessing from the file name.
 *
 * environment is "client" or "server" when the mod only runs on that side. display_test is the
 * displayTest of mods.toml, "IGNORE_SERVER_VERSION" marks a mod clients don't need.
 *
 * @phpstan-type JarInfo array{mod_id: ?string, name: ?string, version: ?string, description: ?string, authors: array<int, string>, loaders: array<int, string>, minecraft: ?string, environment: ?string, display_test: ?string}
 */
class ModJarParser
{
    public const KNOWN_LOADERS = ['neoforge', 'fabric', 'forge', 'quilt', 'liteloader'];

    // Metadata files are tiny, anything bigger is not what we are looking for.
    protected const MAX_METADATA_BYTES = 1024 * 1024;

    /**
     * @return JarInfo
     */
    public function parseFile(string $path, string $filename): array
    {
        $info = $this->parseArchive($path);
        $fallback = $this->fromFilename($filename);

        return [
            'mod_id' => $info['mod_id'] ?? null,
            'name' => $info['name'] ?? $fallback['name'],
            'version' => $info['version'] ?? $fallback['version'],
            'description' => $info['description'] ?? null,
            'authors' => $info['authors'] ?? [],
            'loaders' => !empty($info['loaders']) ? $info['loaders'] : $fallback['loaders'],
            'minecraft' => $info['minecraft'] ?? $fallback['minecraft'],
            'environment' => $info['environment'] ?? null,
            'display_test' => $info['display_test'] ?? null,
        ];
    }

    /**
     * Best guess from the file name alone, e.g. "sodium-fabric-0.5.8+mc1.20.4.jar".
     *
     * @return JarInfo
     */
    public function fromFilename(string $filename): array
    {
        $loader = $this->extractLoaderFromFilename($filename);

        return [
            'mod_id' => null,
            'name' => $this->cleanFilename($filename),
            'version' => $this->extractVersionFromFilename($filename),
            'description' => null,
            'authors' => [],
            'loaders' => $loader ? [$loader] : [],
            'minecraft' => $this->extractMcVersion($filename),
            'environment' => null,
            'display_test' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseArchive(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return [];
        }

        try {
            return $this->parseEntries(fn (string $name) => $this->readEntry($zip, $name));
        } finally {
            $zip->close();
        }
    }

    protected function readEntry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false || ($stat['size'] ?? 0) > self::MAX_METADATA_BYTES) {
            return null;
        }

        $contents = $zip->getFromName($name);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Combine every metadata file found. A multi-loader jar (e.g. built with Architectury) ships
     * several of them, the first one found provides the name and version.
     *
     * @param  callable(string): ?string  $read
     * @return array<string, mixed>
     */
    public function parseEntries(callable $read): array
    {
        $manifestVersion = $this->parseManifestVersion($read('META-INF/MANIFEST.MF'));

        $found = array_filter([
            $this->parseFabric($read('fabric.mod.json')),
            $this->parseQuilt($read('quilt.mod.json')),
            $this->parseModsToml($read('META-INF/neoforge.mods.toml'), 'neoforge', $manifestVersion),
            $this->parseModsToml($read('META-INF/mods.toml'), 'forge', $manifestVersion),
            $this->parseMcmodInfo($read('mcmod.info')),
        ]);

        if (empty($found)) {
            return [];
        }

        $result = ['loaders' => []];

        foreach ($found as $info) {
            foreach (['mod_id', 'name', 'version', 'description', 'minecraft', 'environment', 'display_test'] as $key) {
                if (!isset($result[$key]) && isset($info[$key]) && $info[$key] !== '') {
                    $result[$key] = $info[$key];
                }
            }

            if (empty($result['authors']) && !empty($info['authors'])) {
                $result['authors'] = $info['authors'];
            }

            $result['loaders'] = array_values(array_unique(array_merge($result['loaders'], $info['loaders'] ?? [])));
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    protected function parseFabric(?string $json): ?array
    {
        $data = $this->decodeJson($json);

        if (!is_array($data) || !isset($data['id'])) {
            return null;
        }

        $authors = [];
        foreach (is_array($data['authors'] ?? null) ? $data['authors'] : [] as $author) {
            $name = is_array($author) ? ($author['name'] ?? null) : $author;

            if (is_string($name) && $name !== '') {
                $authors[] = $name;
            }
        }

        $environment = $data['environment'] ?? null;

        return [
            'mod_id' => $this->string($data['id']),
            'name' => $this->string($data['name'] ?? null),
            'version' => $this->resolvedVersion($data['version'] ?? null),
            'description' => $this->string($data['description'] ?? null),
            'authors' => $authors,
            'loaders' => ['fabric'],
            'minecraft' => $this->formatVersionPredicate($data['depends']['minecraft'] ?? null),
            'environment' => in_array($environment, ['client', 'server'], true) ? $environment : null,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function parseQuilt(?string $json): ?array
    {
        $data = $this->decodeJson($json);
        $loader = is_array($data) ? ($data['quilt_loader'] ?? null) : null;

        if (!is_array($loader) || !isset($loader['id'])) {
            return null;
        }

        $metadata = is_array($loader['metadata'] ?? null) ? $loader['metadata'] : [];

        $minecraft = null;
        foreach (is_array($loader['depends'] ?? null) ? $loader['depends'] : [] as $dependency) {
            if (is_array($dependency) && ($dependency['id'] ?? null) === 'minecraft') {
                $minecraft = $this->formatVersionPredicate($dependency['versions'] ?? null);
            }
        }

        $environment = $data['minecraft']['environment'] ?? null;

        return [
            'mod_id' => $this->string($loader['id']),
            'name' => $this->string($metadata['name'] ?? null),
            'version' => $this->resolvedVersion($loader['version'] ?? null),
            'description' => $this->string($metadata['description'] ?? null),
            'authors' => array_values(array_filter(array_map(fn ($name) => $this->string($name), array_keys(is_array($metadata['contributors'] ?? null) ? $metadata['contributors'] : [])))),
            'loaders' => ['quilt'],
            'minecraft' => $minecraft,
            'environment' => in_array($environment, ['client', 'dedicated_server'], true) ? ($environment === 'client' ? 'client' : 'server') : null,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function parseModsToml(?string $toml, string $loader, ?string $manifestVersion): ?array
    {
        if ($toml === null || trim($toml) === '') {
            return null;
        }

        $tables = $this->parseToml($toml);

        $mod = null;
        $root = [];
        $dependencies = [];
        foreach ($tables as $table) {
            if ($table['name'] === '') {
                $root = $table['values'];
            } elseif ($table['name'] === 'mods' && $mod === null) {
                $mod = $table['values'];
            } elseif (str_starts_with($table['name'], 'dependencies.')) {
                $dependencies[] = $table['values'];
            }
        }

        if (!is_array($mod) || !isset($mod['modId'])) {
            return null;
        }

        $minecraft = null;
        foreach ($dependencies as $dependency) {
            $modId = strtolower((string) ($dependency['modId'] ?? ''));

            if ($modId === 'minecraft') {
                $minecraft ??= $this->formatVersionRange($dependency['versionRange'] ?? null);
            }

            // Early NeoForge releases still used mods.toml, their dependency gives them away.
            if ($modId === 'neoforge') {
                $loader = 'neoforge';
            }
        }

        $version = $this->string($mod['version'] ?? null);
        if ($version !== null && str_contains($version, '${')) {
            $version = $manifestVersion;
        }

        // displayTest belongs to [[mods]], some mods put it at the top of the file anyway.
        $displayTest = strtoupper((string) ($this->string($mod['displayTest'] ?? null) ?? $this->string($root['displayTest'] ?? null) ?? ''));

        return [
            'mod_id' => $this->string($mod['modId']),
            'name' => $this->string($mod['displayName'] ?? null),
            'version' => $version,
            'description' => $this->string($mod['description'] ?? null),
            'authors' => array_values(array_filter(array_map('trim', explode(',', (string) ($mod['authors'] ?? ''))))),
            'loaders' => [$loader],
            'minecraft' => $minecraft,
            // clientSideOnly (Forge 1.20.4 and newer) keeps the mod from loading on a dedicated server.
            'environment' => strtolower((string) ($root['clientSideOnly'] ?? '')) === 'true' ? 'client' : null,
            'display_test' => in_array($displayTest, ['MATCH_VERSION', 'IGNORE_SERVER_VERSION', 'IGNORE_ALL_VERSION', 'NONE'], true) ? $displayTest : null,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function parseMcmodInfo(?string $json): ?array
    {
        $data = $this->decodeJson($json);

        // Either a plain list or, in version 2 of the format, wrapped in "modList".
        $mods = is_array($data['modList'] ?? null) ? $data['modList'] : $data;
        $mod = is_array($mods) ? ($mods[0] ?? null) : null;

        if (!is_array($mod) || !isset($mod['modid'])) {
            return null;
        }

        return [
            'mod_id' => $this->string($mod['modid']),
            'name' => $this->string($mod['name'] ?? null),
            'version' => $this->resolvedVersion($mod['version'] ?? null),
            'description' => $this->string($mod['description'] ?? null),
            'authors' => array_values(array_filter(array_map(fn ($name) => $this->string($name), is_array($mod['authorList'] ?? null) ? $mod['authorList'] : []))),
            'loaders' => ['forge'],
            'minecraft' => $this->resolvedVersion($mod['mcversion'] ?? null),
            'environment' => null,
        ];
    }

    protected function parseManifestVersion(?string $manifest): ?string
    {
        if ($manifest !== null && preg_match('/^Implementation-Version:\s*(\S.*?)\s*$/mi', $manifest, $matches)) {
            return $this->resolvedVersion($matches[1]);
        }

        return null;
    }

    /**
     * Just enough TOML for mods.toml: tables, array tables and string, multi-line string and bare values.
     *
     * @return array<int, array{name: string, values: array<string, string>}>
     */
    public function parseToml(string $toml): array
    {
        $tables = [];
        $current = ['name' => '', 'values' => []];

        $lines = preg_split('/\R/', $toml) ?: [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^\[\[?\s*([^\]]+?)\s*\]\]?/', $line, $matches)) {
                $tables[] = $current;
                $current = ['name' => trim($matches[1], " \"'"), 'values' => []];

                continue;
            }

            if (!preg_match('/^["\']?([A-Za-z0-9_.-]+)["\']?\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }

            [$key, $raw] = [$matches[1], $matches[2]];

            foreach (['"""', "'''"] as $quote) {
                if (!str_starts_with($raw, $quote)) {
                    continue;
                }

                $value = substr($raw, 3);
                while (!str_contains($value, $quote) && $i + 1 < $count) {
                    $value .= "\n".$lines[++$i];
                }

                $end = strpos($value, $quote);
                $current['values'][$key] = trim($end === false ? $value : substr($value, 0, $end));

                continue 2;
            }

            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $matches)) {
                $current['values'][$key] = stripcslashes($matches[1]);
            } elseif (preg_match("/^'([^']*)'/", $raw, $matches)) {
                $current['values'][$key] = $matches[1];
            } else {
                $current['values'][$key] = trim(explode('#', $raw, 2)[0]);
            }
        }

        $tables[] = $current;

        return $tables;
    }

    /**
     * Maven style ranges of mods.toml, e.g. "[1.20.1,1.21)" becomes "≥ 1.20.1, < 1.21".
     */
    public function formatVersionRange(mixed $range): ?string
    {
        $range = $this->string($range);

        if ($range === null || $range === '*') {
            return null;
        }

        if (preg_match('/^\[\s*([^,\]]+)\s*\]$/', $range, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/^([\[(])\s*([^,]*)\s*,\s*([^\])]*)\s*([\])])$/', $range, $matches)) {
            [, $open, $from, $to, $close] = array_map('trim', $matches);

            $parts = array_filter([
                $from !== '' ? ($open === '[' ? '≥ ' : '> ').$from : null,
                $to !== '' ? ($close === ']' ? '≤ ' : '< ').$to : null,
            ]);

            return empty($parts) ? null : implode(', ', $parts);
        }

        return $range;
    }

    /**
     * Version predicates of fabric.mod.json and quilt.mod.json, a single one or a list of them.
     */
    public function formatVersionPredicate(mixed $predicate): ?string
    {
        if (is_array($predicate)) {
            $parts = array_filter(array_map(fn ($part) => $this->formatVersionPredicate($part), $predicate));

            return empty($parts) ? null : implode(', ', array_unique($parts));
        }

        $predicate = $this->string($predicate);

        if ($predicate === null || $predicate === '*') {
            return null;
        }

        // ">=1.20-" uses the trailing dash to also allow pre-releases, which says nothing to a user.
        $predicate = preg_replace('/(\d)-(?=\s|,|$)/', '$1', $predicate) ?? $predicate;
        $predicate = str_replace(['>=', '<='], ['≥ ', '≤ '], $predicate);

        return trim(preg_replace('/\s+/', ' ', $predicate) ?? $predicate);
    }

    /**
     * Extract the mod version from a filename, e.g. "sodium-0.5.6+mc1.21.jar" → "0.5.6".
     * Same rules as the installed list of the resources plugin.
     */
    public function extractVersionFromFilename(string $filename): ?string
    {
        $name = preg_replace('/\.jar$/i', '', $filename) ?? $filename;
        $name = preg_replace('/[-_+](fabric|forge|neoforge|quilt|spigot|paper|bukkit|velocity|sponge|liteloader)$/i', '', $name) ?? $name;
        $name = preg_replace('/[-+_]mc[\d.]+$/i', '', $name) ?? $name;

        if (preg_match('/[-+_](v?\d[\d.\-]*\d|\d+)$/', $name, $matches)) {
            return ltrim($matches[1], 'v');
        }

        return null;
    }

    /**
     * Extract the Minecraft version from a filename, e.g. "sodium-0.5.6+mc1.21.jar" → "1.21".
     */
    public function extractMcVersion(string $filename): ?string
    {
        if (preg_match('/[-+_]mc(1\.\d+(?:\.\d+)?)/i', $filename, $matches)) {
            return $matches[1];
        }

        // Without the "mc" only in the middle of the name, e.g. "jei-1.20.1-forge-15.2.0.jar".
        // At the end it is far more likely to be the version of the mod itself.
        if (preg_match('/[-+_](1\.(?:[7-9]|1\d|2\d)(?:\.\d+)?)[-+_]/', $filename, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Checks 'neoforge' before 'forge' to avoid false matches.
     */
    public function extractLoaderFromFilename(string $filename): ?string
    {
        $lower = strtolower($filename);

        foreach (self::KNOWN_LOADERS as $loader) {
            if (preg_match('/(^|[^a-z])'.$loader.'([^a-z]|$)/', $lower)) {
                return $loader;
            }
        }

        return null;
    }

    /**
     * A readable name from a file name, e.g. "create-fabric-0.5.1.jar" → "create".
     */
    public function cleanFilename(string $filename): string
    {
        $name = preg_replace('/\.jar$/i', '', $filename) ?? $filename;
        $name = preg_replace('/[-_+](?:v|\d)[\d.\-_+a-zA-Z]*$/', '', $name) ?? $name;
        $name = preg_replace('/[-_+]mc[\d.]+$/i', '', $name) ?? $name;
        $name = preg_replace('/[-_+](fabric|forge|neoforge|quilt|spigot|paper|bukkit|velocity|sponge|liteloader)$/i', '', $name) ?? $name;

        $name = trim(str_replace(['-', '_', '+'], ' ', $name));

        return $name !== '' ? $name : $filename;
    }

    protected function decodeJson(?string $json): mixed
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        // Some mods put a byte order mark or raw line breaks into their strings.
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;

        $data = json_decode($json, true, 64, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($data === null) {
            $data = json_decode(str_replace(["\r", "\n", "\t"], ' ', $json), true, 64, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $data;
    }

    /** A version, unless it is a build placeholder that never got replaced. */
    protected function resolvedVersion(mixed $version): ?string
    {
        $version = $this->string($version);

        if ($version === null || str_contains($version, '${') || preg_match('/^@.+@$/', $version)) {
            return null;
        }

        return $version;
    }

    protected function string(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
