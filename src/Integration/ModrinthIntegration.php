<?php

namespace FreshDoktor\MinecraftClientModpack\Integration;

use App\Models\Plugin;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use FreshDoktor\MinecraftClientModpack\Filament\Server\Pages\ClientModpackPage;
use FreshDoktor\MinecraftClientModpack\Livewire\ClientModpackButton;
use Illuminate\Support\Facades\Blade;
use Throwable;

/**
 * With the minecraft-modrinth plugin (by Boy132) enabled, the client modpack is built from the header
 * of its mods page instead of having a menu item of its own. Nothing of that plugin is used besides
 * the name of its page, so this plugin works just the same without it.
 *
 * Some plugins combine the mods page with others into one "Modrinth" page (see their ModrinthHub),
 * the page hosting it is in the config under modrinth_hub.pages, the button goes there too.
 */
class ModrinthIntegration
{
    public const MODRINTH_PLUGIN_ID = 'minecraft-modrinth';

    public const MODRINTH_PLUGIN = 'Boy132\MinecraftModrinth\MinecraftModrinthPlugin';

    public const MODRINTH_MOD_PAGE = 'Boy132\MinecraftModrinth\Filament\Server\Pages\MinecraftModrinthModPage';

    protected static bool $hooked = false;

    /** @var array<string, bool> */
    protected static array $enabled = [];

    /**
     * Whether the minecraft-modrinth plugin is enabled on the given panel.
     *
     * The panel autoloads the classes of every plugin in the plugins folder, even of the ones that
     * aren't installed or are disabled, so their classes existing doesn't tell whether they are in use.
     */
    public static function isModrinthPluginEnabled(string $panelId = 'server'): bool
    {
        if (!class_exists(self::MODRINTH_PLUGIN) || !class_exists(self::MODRINTH_MOD_PAGE)) {
            return false;
        }

        // Asked for every navigation build, the answer doesn't change within a request.
        return static::$enabled[$panelId] ??= (function () use ($panelId) {
            try {
                return Plugin::query()->find(self::MODRINTH_PLUGIN_ID)?->shouldLoad($panelId) ?? false;
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        })();
    }

    /**
     * Put the button into the header of the mods page. Runs once all plugins registered their pages,
     * so the page combining the mods page with others is known by now.
     */
    public static function registerButton(): void
    {
        if (static::$hooked) {
            return;
        }

        static::$hooked = true;

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
            fn (): string => static::renderButton(),
            static::getButtonScopes(),
        );
    }

    /**
     * Pages whose header shows the button.
     *
     * @return array<int, class-string>
     */
    public static function getButtonScopes(): array
    {
        $scopes = [self::MODRINTH_MOD_PAGE];

        $hub = config('modrinth_hub.pages.server');

        if (is_string($hub) && class_exists($hub) && config('modrinth_hub.sections.mods') !== null) {
            $scopes[] = $hub;
        }

        return $scopes;
    }

    protected static function renderButton(): string
    {
        try {
            $server = Filament::getTenant();

            if (!$server instanceof Server || !ClientModpackPage::canAccessServer($server)) {
                return '';
            }

            return Blade::render(
                '@livewire($component, ["serverId" => $serverId], key("minecraft-client-modpack-button"))',
                ['component' => ClientModpackButton::NAME, 'serverId' => $server->id],
            );
        } catch (Throwable $exception) {
            // The page of the other plugin must never break because of this button.
            report($exception);

            return '';
        }
    }
}
