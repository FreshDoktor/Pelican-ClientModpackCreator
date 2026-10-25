<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

use App\Models\Server;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Finds out what a modpack has to name as its dependencies: the Minecraft version, the mod loader
 * and its version. What the server actually installed (its libraries folder) beats the egg's
 * variables, which may still say "latest" or name an installer version.
 */
class ServerEnvironmentService
{
    // Folders of the libraries folder each loader installs itself to, every version is a subfolder.
    protected const LOADER_LIBRARIES = [
        'fabric' => ['libraries/net/fabricmc/fabric-loader'],
        'quilt' => ['libraries/org/quiltmc/quilt-loader'],
        // e.g. "1.20.1-47.2.0"
        'forge' => ['libraries/net/minecraftforge/forge'],
        // e.g. "21.1.77", NeoForge for 1.20.1 still used the old name and versions like "1.20.1-47.1.106"
        'neoforge' => ['libraries/net/neoforged/neoforge', 'libraries/net/neoforged/forge'],
    ];

    // Variables of the eggs that name the loader version. FABRIC_VERSION is the installer, not the loader.
    protected const LOADER_VARIABLES = [
        'fabric' => ['FABRIC_LOADER_VERSION', 'LOADER_VERSION'],
        'quilt' => ['QUILT_LOADER_VERSION', 'LOADER_VERSION'],
        'forge' => ['FORGE_VERSION'],
        'neoforge' => ['NEOFORGE_VERSION'],
    ];

    protected const MINECRAFT_VARIABLES = ['MINECRAFT_VERSION', 'MC_VERSION'];

    protected const VERSION_PATTERN = '/^[0-9][0-9A-Za-z.+_-]*$/';

    public function __construct(protected ServerFilesService $files) {}

    /**
     * @param  array<int, string>  $jarLoaders  loaders the jars in the mods folder are made for
     */
    public function detectLoader(Server $server, array $jarLoaders): ?string
    {
        $server->loadMissing('egg');
        $tags = $server->egg->tags ?? [];

        // "neoforge" before "forge", an egg may carry both.
        foreach (['neoforge', 'quilt', 'fabric', 'forge'] as $loader) {
            if (in_array($loader, $tags, true)) {
                return $loader;
            }
        }

        foreach (['neoforge', 'quilt', 'fabric', 'forge'] as $loader) {
            if ($this->installedLoaderVersions($server, $loader) !== []) {
                return $loader;
            }
        }

        // The loader most jars are made for.
        $counts = array_count_values(array_filter($jarLoaders, fn ($loader) => in_array($loader, ClientModpackService::LOADERS, true)));
        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $modrinthVersions  Modrinth versions of the mods on the server
     */
    public function detectMinecraftVersion(Server $server, string $loader, array $modrinthVersions): ?string
    {
        $variable = $this->variable($server, self::MINECRAFT_VARIABLES);

        if ($variable !== null && preg_match('/^\d+\.\d+(\.\d+)?$/', $variable)) {
            return $variable;
        }

        $installed = $this->installedMinecraftVersion($server, $loader);

        if ($installed !== null) {
            return $installed;
        }

        // The version most mods on the server are made for.
        $counts = [];
        foreach ($modrinthVersions as $version) {
            foreach (is_array($version['game_versions'] ?? null) ? $version['game_versions'] : [] as $gameVersion) {
                if (is_string($gameVersion) && preg_match('/^\d+\.\d+(\.\d+)?$/', $gameVersion)) {
                    $counts[$gameVersion] = ($counts[$gameVersion] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        uksort($counts, fn (string $a, string $b) => $counts[$b] <=> $counts[$a] ?: version_compare($b, $a));

        return array_key_first($counts);
    }

    public function detectLoaderVersion(Server $server, string $loader, ?string $minecraftVersion): ?string
    {
        $installed = $this->installedLoaderVersions($server, $loader);

        if ($installed !== []) {
            return $this->loaderVersionFromLibrary(end($installed), $loader);
        }

        $variable = $this->variable($server, self::LOADER_VARIABLES[$loader] ?? []);

        if ($variable !== null) {
            // Forge eggs often name the full version, e.g. "1.20.1-47.2.0".
            $variable = preg_replace('/^\d+\.\d+(\.\d+)?-/', '', $variable) ?? $variable;

            if (preg_match(self::VERSION_PATTERN, $variable)) {
                return $variable;
            }
        }

        return null;
    }

    /**
     * The newest stable Fabric or Quilt loader, which works with every Minecraft version they support.
     * Only used when the version on the server can't be found out.
     */
    public function latestLoaderVersion(string $loader): ?string
    {
        $url = match ($loader) {
            'fabric' => 'https://meta.fabricmc.net/v2/versions/loader',
            'quilt' => 'https://meta.quiltmc.org/v3/versions/loader',
            default => null,
        };

        if ($url === null) {
            return null;
        }

        try {
            $versions = Http::timeout(10)->connectTimeout(5)->throw()->get($url)->json();
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        foreach (is_array($versions) ? $versions : [] as $version) {
            $number = is_array($version) ? ($version['version'] ?? null) : null;

            if (is_string($number) && ($version['stable'] ?? !str_contains($number, '-')) && preg_match(self::VERSION_PATTERN, $number)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * Versions of the loader in the libraries folder, oldest first.
     *
     * @return array<int, string>
     */
    protected function installedLoaderVersions(Server $server, string $loader): array
    {
        $versions = [];

        foreach (self::LOADER_LIBRARIES[$loader] ?? [] as $folder) {
            try {
                $names = $this->files->listFolderNames($server, $folder);
            } catch (Exception $exception) {
                report($exception);

                continue;
            }

            foreach ($names as $name) {
                if (preg_match(self::VERSION_PATTERN, $name)) {
                    $versions[] = $name;
                }
            }

            if ($versions !== []) {
                break;
            }
        }

        usort($versions, fn (string $a, string $b) => version_compare($this->loaderVersionFromLibrary($a, $loader), $this->loaderVersionFromLibrary($b, $loader)));

        return $versions;
    }

    protected function loaderVersionFromLibrary(string $folder, string $loader): string
    {
        // Forge (and the first NeoForge) put the Minecraft version in front.
        if (in_array($loader, ['forge', 'neoforge'], true) && preg_match('/^\d+\.\d+(?:\.\d+)?-(.+)$/', $folder, $matches)) {
            return $matches[1];
        }

        return $folder;
    }

    protected function installedMinecraftVersion(Server $server, string $loader): ?string
    {
        $candidates = [];

        try {
            if (in_array($loader, ['fabric', 'quilt'], true)) {
                // Fabric and Quilt map Minecraft with intermediary, one folder per Minecraft version.
                $candidates = $this->files->listFolderNames($server, 'libraries/net/fabricmc/intermediary');
            } else {
                // Forge and NeoForge keep the server jar here, e.g. "1.21.1-20240808.144430".
                $candidates = array_map(fn (string $name) => explode('-', $name, 2)[0], $this->files->listFolderNames($server, 'libraries/net/minecraft/server'));

                if ($candidates === [] && $loader === 'forge') {
                    $candidates = array_map(fn (string $name) => explode('-', $name, 2)[0], $this->files->listFolderNames($server, 'libraries/net/minecraftforge/forge'));
                }
            }
        } catch (Exception $exception) {
            report($exception);
        }

        $candidates = array_values(array_filter($candidates, fn (string $version) => (bool) preg_match('/^\d+\.\d+(\.\d+)?$/', $version)));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, 'version_compare');

        return end($candidates);
    }

    /**
     * @param  array<int, string>  $names
     */
    protected function variable(Server $server, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $server->variables()->where('env_variable', $name)->first()?->server_value;

            if (is_string($value)) {
                $value = trim($value);

                if ($value !== '' && !in_array(strtolower($value), ['latest', 'recommended', 'snapshot'], true)) {
                    return $value;
                }
            }
        }

        return null;
    }
}
