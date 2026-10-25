<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Writes a Modrinth modpack (.mrpack): a zip with modrinth.index.json, which lists the files launchers
 * download themselves, and an overrides folder with everything that is copied into the instance as it
 * is (mods that aren't on Modrinth, config files).
 *
 * @see https://support.modrinth.com/en/articles/8802351-modrinth-modpack-format-mrpack
 *
 * @phpstan-type IndexFile array{path: string, hashes: array{sha1: string, sha512: string}, env: array{client: string, server: string}, downloads: array<int, string>, fileSize: int}
 */
class MrpackWriter
{
    public const INDEX_FILE = 'modrinth.index.json';

    public const OVERRIDES_FOLDER = 'overrides';

    // Keys of "dependencies" for each loader.
    public const LOADER_DEPENDENCIES = [
        'fabric' => 'fabric-loader',
        'quilt' => 'quilt-loader',
        'forge' => 'forge',
        'neoforge' => 'neoforge',
    ];

    /**
     * @param  array<int, IndexFile>  $files
     * @return array<string, mixed>
     */
    public function index(string $name, string $versionId, ?string $summary, array $files, string $minecraftVersion, string $loader, string $loaderVersion): array
    {
        $dependency = self::LOADER_DEPENDENCIES[$loader] ?? throw new InvalidArgumentException("Unknown loader $loader");

        usort($files, fn (array $a, array $b) => strcasecmp($a['path'], $b['path']));

        $index = [
            'formatVersion' => 1,
            'game' => 'minecraft',
            'versionId' => $versionId,
            'name' => $name,
        ];

        if ($summary !== null && trim($summary) !== '') {
            $index['summary'] = trim($summary);
        }

        $index['files'] = array_values($files);
        $index['dependencies'] = [
            'minecraft' => $minecraftVersion,
            $dependency => $loaderVersion,
        ];

        return $index;
    }

    /**
     * Write the modpack to $destination.
     *
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $overrides  local file of every override, by its path in the instance
     *
     * @throws RuntimeException
     */
    public function write(string $destination, array $index, array $overrides): void
    {
        $zip = new ZipArchive();

        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create $destination");
        }

        try {
            $json = json_encode($index, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!$zip->addFromString(self::INDEX_FILE, $json)) {
                throw new RuntimeException('Could not add '.self::INDEX_FILE);
            }

            ksort($overrides, SORT_STRING | SORT_FLAG_CASE);

            foreach ($overrides as $path => $file) {
                $entry = self::OVERRIDES_FOLDER.'/'.static::normalisePath($path);

                if (!$zip->addFile($file, $entry)) {
                    throw new RuntimeException("Could not add $entry");
                }

                // Jars are zips themselves, compressing them again only costs time.
                if (preg_match('/\.(jar|zip|png|jpe?g|ogg)$/i', $entry)) {
                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                }
            }
        } catch (\Throwable $exception) {
            // Without any entry libzip doesn't write the archive at all, so nothing is left behind.
            $zip->unchangeAll();
            $zip->close();

            throw $exception;
        }

        // libzip writes to a temporary file and only renames it on success.
        if (!$zip->close()) {
            throw new RuntimeException("Could not write $destination");
        }
    }

    /**
     * A path inside the instance with forward slashes, no empty or "." segments and nothing that leads out of it.
     *
     * @throws InvalidArgumentException
     */
    public static function normalisePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-z]:/i', $path)) {
            throw new InvalidArgumentException("Invalid path $path");
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new InvalidArgumentException("Invalid path $path");
            }

            $segments[] = $segment;
        }

        if (empty($segments)) {
            throw new InvalidArgumentException("Invalid path $path");
        }

        return implode('/', $segments);
    }
}
