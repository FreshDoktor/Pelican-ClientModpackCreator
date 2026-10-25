<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Http\Client\RequestException;

/**
 * Reads the files of a server through the daemon.
 *
 * @phpstan-type ServerFile array{name: string, size: int, modified: string}
 */
class ServerFilesService
{
    /**
     * Every jar right inside the mods folder, by file name.
     *
     * @return array<string, ServerFile>
     *
     * @throws Exception
     */
    public function listModJars(Server $server): array
    {
        $jars = [];

        foreach ($this->listFolder($server, ClientModpackService::MODS_FOLDER) as $entry) {
            if (!$this->isFile($entry) || !str_ends_with(strtolower($entry['name']), '.jar')) {
                continue;
            }

            $jars[$entry['name']] = $this->toFile($entry);
        }

        ksort($jars, SORT_NATURAL | SORT_FLAG_CASE);

        return $jars;
    }

    /**
     * Every file below $path (or $path itself if it is a file), by its path relative to the server root.
     * Stops after $maxFiles files, $truncated then tells it did.
     *
     * @return array<string, ServerFile>
     *
     * @throws Exception
     */
    public function listFilesRecursive(Server $server, string $path, int $maxFiles, ?bool &$truncated = null): array
    {
        $truncated = false;

        $parent = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : '';
        $name = basename($path);

        $entry = collect($this->listFolder($server, $parent))->first(fn ($entry) => is_array($entry) && ($entry['name'] ?? null) === $name);

        if (!is_array($entry) || !empty($entry['symlink'])) {
            return [];
        }

        if ($this->isFile($entry)) {
            return [$path => $this->toFile($entry)];
        }

        $files = [];
        $folders = [$path];

        while ($folders !== []) {
            $folder = array_shift($folders);

            foreach ($this->listFolder($server, $folder) as $child) {
                if (!is_array($child) || !is_string($child['name'] ?? null) || !$this->isValidName($child['name']) || !empty($child['symlink'])) {
                    continue;
                }

                if (!empty($child['directory'])) {
                    $folders[] = "$folder/{$child['name']}";

                    continue;
                }

                if (!$this->isFile($child)) {
                    continue;
                }

                if (count($files) >= $maxFiles) {
                    $truncated = true;

                    return $files;
                }

                $files["$folder/{$child['name']}"] = $this->toFile($child);
            }
        }

        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * Copy a file of the server to a local file.
     *
     * @throws Exception
     */
    public function download(Server $server, string $path, string $destination, int $maxBytes): void
    {
        $contents = (string) app(DaemonFileRepository::class)->setServer($server)->getContent($path, $maxBytes);

        if (strlen($contents) > $maxBytes) {
            throw new Exception("$path is larger than $maxBytes bytes");
        }

        $directory = dirname($destination);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new Exception("Could not create $directory");
        }

        if (file_put_contents($destination, $contents) !== strlen($contents)) {
            throw new Exception("Could not write $destination");
        }
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    public function listFolder(Server $server, string $folder): array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory($folder === '' ? '/' : $folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                // The folder simply doesn't exist.
                return [];
            }

            throw $exception;
        }

        if (isset($files['error'])) {
            throw new Exception("Daemon returned an error while listing $folder: ".(is_string($files['error']) ? $files['error'] : 'unknown error'));
        }

        return $files;
    }

    /**
     * Names of the folders right inside $folder, e.g. the versions of a library.
     *
     * @return array<int, string>
     *
     * @throws Exception
     */
    public function listFolderNames(Server $server, string $folder): array
    {
        $names = [];

        foreach ($this->listFolder($server, $folder) as $entry) {
            if (is_array($entry) && !empty($entry['directory']) && is_string($entry['name'] ?? null) && $this->isValidName($entry['name'])) {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    public function isValidName(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && !str_contains($name, "\0")
            && !str_contains($name, '/')
            && !str_contains($name, '\\');
    }

    /** The daemon calls a file "file" and a folder "directory", symlinks are neither of our business. */
    protected function isFile(mixed $entry): bool
    {
        return is_array($entry)
            && is_string($entry['name'] ?? null)
            && $this->isValidName($entry['name'])
            && !empty($entry['file'])
            && empty($entry['directory'])
            && empty($entry['symlink']);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return ServerFile
     */
    protected function toFile(array $entry): array
    {
        return [
            'name' => (string) $entry['name'],
            'size' => (int) ($entry['size'] ?? 0),
            'modified' => (string) ($entry['modified'] ?? ''),
        ];
    }
}
