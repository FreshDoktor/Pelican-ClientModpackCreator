<?php

namespace FreshDoktor\MinecraftClientModpack\Livewire;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use FreshDoktor\MinecraftClientModpack\Filament\Concerns\BuildsClientModpack;
use FreshDoktor\MinecraftClientModpack\Filament\Server\Pages\ClientModpackPage;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Build button and link to the client modpack page, shown in the header of the mods page of
 * minecraft-modrinth, see ModrinthIntegration.
 */
class ClientModpackButton extends Component implements HasActions, HasSchemas
{
    use BuildsClientModpack;
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Registered under this name, so Livewire finds the component again on updates. */
    public const NAME = 'minecraft-client-modpack.button';

    #[Locked]
    public int $serverId;

    protected ?Server $server = null;

    public function mount(int $serverId): void
    {
        $this->serverId = $serverId;

        $this->authorizeAccess();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();

        $this->reportWatchedBuild(true);
    }

    /**
     * Updates of this component don't pass through the page that rendered it, so check again that
     * the user may still access the server.
     */
    protected function authorizeAccess(): void
    {
        $server = $this->getServer();

        abort_unless(user()?->canAccessTenant($server) && ClientModpackPage::canAccessServer($server), 403);
    }

    protected function getServer(): Server
    {
        return $this->server ??= Server::findOrFail($this->serverId);
    }

    public function buildAction(): Action
    {
        return $this->makeBuildAction(true);
    }

    public function openAction(): Action
    {
        return Action::make('open')
            ->label(trans('minecraft-client-modpack::strings.page.title'))
            ->iconButton()
            ->tooltip(trans('minecraft-client-modpack::strings.actions.open_hint'))
            ->icon('tabler-package')
            ->color('gray')
            ->url(fn () => ClientModpackPage::getUrl(tenant: $this->getServer()));
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div class="flex items-center gap-3" @if ($this->isBuilding()) wire:poll.3s @endif>
                {{ $this->buildAction }}

                {{ $this->openAction }}

                <x-filament-actions::modals />
            </div>
        BLADE;
    }
}
