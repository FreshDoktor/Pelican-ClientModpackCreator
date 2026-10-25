<?php

namespace FreshDoktor\MinecraftClientModpack;

use App\Contracts\Plugins\HasPluginSettings;
use App\Facades\Activity;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use FreshDoktor\MinecraftClientModpack\Filament\Server\Pages\ClientModpackPage;
use FreshDoktor\MinecraftClientModpack\Integration\ModrinthIntegration;
use FreshDoktor\MinecraftClientModpack\Livewire\ClientModpackButton;
use FreshDoktor\MinecraftClientModpack\Services\ClientModpackService;
use Livewire\Livewire;

/**
 * Builds a modpack (.mrpack) for the players of a modded Minecraft server from the mods on it,
 * without the server-only ones and with extra client mods, and hosts it on the panel.
 *
 * The page is in the menu of the server. With minecraft-modrinth enabled it is built from the
 * header of that plugin's mods page instead, see ModrinthIntegration.
 */
class MinecraftClientModpackPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'minecraft-client-modpack';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() !== 'server') {
            return;
        }

        $panel->pages([ClientModpackPage::class]);

        // Registered by name so Livewire finds the component again on updates, wherever it got embedded.
        Livewire::component(ClientModpackButton::NAME, ClientModpackButton::class);
    }

    public function boot(Panel $panel): void
    {
        if ($panel->getId() === 'server' && ModrinthIntegration::isModrinthPluginEnabled($panel->getId())) {
            ModrinthIntegration::registerButton();
        }
    }

    public function getSettingsFormData(): array
    {
        return [
            'public_url' => config('minecraft-client-modpack.public_url'),
            'max_mod_size_mb' => (int) config('minecraft-client-modpack.max_mod_size_mb', ClientModpackService::DEFAULT_MAX_MOD_MB),
            'max_embedded_size_mb' => (int) config('minecraft-client-modpack.max_embedded_size_mb', ClientModpackService::DEFAULT_MAX_EMBEDDED_MB),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('public_url')
                ->label(trans('minecraft-client-modpack::strings.plugin_settings.public_url'))
                ->helperText(trans('minecraft-client-modpack::strings.plugin_settings.public_url_helper'))
                ->placeholder(fn () => (string) config('app.url'))
                ->url()
                ->maxLength(255)
                ->default(fn () => config('minecraft-client-modpack.public_url')),
            TextInput::make('max_mod_size_mb')
                ->label(trans('minecraft-client-modpack::strings.plugin_settings.max_mod_size'))
                ->helperText(trans('minecraft-client-modpack::strings.plugin_settings.max_mod_size_helper'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(1024)
                ->suffix('MB')
                ->required()
                ->default(fn () => (int) config('minecraft-client-modpack.max_mod_size_mb', ClientModpackService::DEFAULT_MAX_MOD_MB)),
            TextInput::make('max_embedded_size_mb')
                ->label(trans('minecraft-client-modpack::strings.plugin_settings.max_embedded_size'))
                ->helperText(trans('minecraft-client-modpack::strings.plugin_settings.max_embedded_size_helper'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(8192)
                ->suffix('MB')
                ->required()
                ->default(fn () => (int) config('minecraft-client-modpack.max_embedded_size_mb', ClientModpackService::DEFAULT_MAX_EMBEDDED_MB)),
        ];
    }

    public function saveSettings(array $data): void
    {
        $new = [
            'public_url' => rtrim(trim((string) ($data['public_url'] ?? '')), '/'),
            'max_mod_size_mb' => max(1, (int) ($data['max_mod_size_mb'] ?? ClientModpackService::DEFAULT_MAX_MOD_MB)),
            'max_embedded_size_mb' => max(1, (int) ($data['max_embedded_size_mb'] ?? ClientModpackService::DEFAULT_MAX_EMBEDDED_MB)),
        ];

        $this->writeToEnvironment([
            'MINECRAFT_CLIENT_MODPACK_PUBLIC_URL' => $new['public_url'],
            'MINECRAFT_CLIENT_MODPACK_MAX_MOD_SIZE_MB' => $new['max_mod_size_mb'],
            'MINECRAFT_CLIENT_MODPACK_MAX_EMBEDDED_SIZE_MB' => $new['max_embedded_size_mb'],
        ]);

        if ($user = user()) {
            Activity::event('user:client-modpack.settings')
                ->subject($user)
                ->property([
                    'url' => $new['public_url'] !== '' ? $new['public_url'] : (string) config('app.url'),
                    'mod_limit' => $new['max_mod_size_mb'],
                    'embedded_limit' => $new['max_embedded_size_mb'],
                ])
                ->log();
        }

        Notification::make()
            ->title(trans('minecraft-client-modpack::strings.plugin_settings.saved'))
            ->success()
            ->send();
    }
}
