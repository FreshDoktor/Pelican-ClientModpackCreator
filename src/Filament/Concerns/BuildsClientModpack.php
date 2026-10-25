<?php

namespace FreshDoktor\MinecraftClientModpack\Filament\Concerns;

use App\Facades\Activity;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use FreshDoktor\MinecraftClientModpack\Filament\Server\Pages\ClientModpackPage;
use FreshDoktor\MinecraftClientModpack\Services\ClientModpackService;
use Throwable;

/**
 * The build button, shared by the client modpack page and the button in the header of the mods page.
 * Tells the user once a build they watched finished.
 */
trait BuildsClientModpack
{
    /** Operation id of the build this component waits for, to report its end. */
    public ?string $watchedBuild = null;

    abstract protected function getServer(): Server;

    protected function service(): ClientModpackService
    {
        return app(ClientModpackService::class);
    }

    protected function userCan(string $permission): bool
    {
        return user()?->can($permission, $this->getServer()) ?? false;
    }

    /** Public, the button's view asks it. */
    public function isBuilding(): bool
    {
        return $this->service()->isBuilding($this->getServer());
    }

    protected function makeBuildAction(bool $linkToPage): Action
    {
        return Action::make('build')
            ->label(fn () => match (true) {
                $this->isBuilding() => trans('minecraft-client-modpack::strings.actions.building'),
                $linkToPage => trans('minecraft-client-modpack::strings.actions.build_from_mods'),
                $this->service()->find($this->getServer())?->isBuilt() ?? false => trans('minecraft-client-modpack::strings.actions.rebuild'),
                default => trans('minecraft-client-modpack::strings.actions.build'),
            })
            ->icon(fn () => $this->isBuilding() ? 'tabler-loader-2' : 'tabler-package-export')
            ->disabled(fn () => $this->isBuilding())
            ->visible(fn () => $this->userCan('file.read-content'))
            ->action(function () use ($linkToPage) {
                $server = $this->getServer();

                try {
                    $queued = $this->service()->dispatchBuild($server, user());
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title(trans('minecraft-client-modpack::strings.notifications.build_failed'))
                        ->body(trans('minecraft-client-modpack::strings.errors.unexpected'))
                        ->danger()
                        ->send();

                    return;
                }

                if (!$queued) {
                    Notification::make()
                        ->title(trans('minecraft-client-modpack::strings.notifications.already_building'))
                        ->info()
                        ->send();

                    return;
                }

                $this->watchedBuild = $this->service()->getBuildStatus($server)['operation_id'] ?? null;

                Activity::event('server:client-modpack.build')
                    ->subject($server)
                    ->log();

                Notification::make()
                    ->title(trans('minecraft-client-modpack::strings.notifications.build_queued'))
                    ->body(trans('minecraft-client-modpack::strings.notifications.build_queued_body'))
                    ->info()
                    ->actions($linkToPage ? [
                        Action::make('open')
                            ->label(trans('minecraft-client-modpack::strings.actions.open'))
                            ->url(ClientModpackPage::getUrl(tenant: $server)),
                    ] : [])
                    ->send();
            });
    }

    /**
     * Report the end of the watched build: the new file, or why it failed. Called on every request of
     * the component, which polls while a build runs.
     */
    protected function reportWatchedBuild(bool $linkToPage): void
    {
        if ($this->watchedBuild === null) {
            return;
        }

        $status = $this->service()->getBuildStatus($this->getServer());

        if (($status['operation_id'] ?? null) === $this->watchedBuild && $this->service()->isBuilding($this->getServer())) {
            return;
        }

        $this->watchedBuild = null;

        // Replaced by another build, which someone else watches.
        if ($status !== null && $status['status'] !== ClientModpackService::STATUS_FAILED) {
            return;
        }

        if ($status !== null) {
            Notification::make()
                ->title(trans('minecraft-client-modpack::strings.notifications.build_failed'))
                ->body($status['message'] ?? null)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $pack = $this->service()->find($this->getServer());

        if ($pack === null || !$pack->isBuilt()) {
            return;
        }

        Notification::make()
            ->title(trans('minecraft-client-modpack::strings.notifications.built'))
            ->body(trans('minecraft-client-modpack::strings.notifications.built_body', [
                'name' => $pack->file_name,
                'size' => $this->service()->formatBytes((int) $pack->size),
            ]))
            ->success()
            ->actions($linkToPage ? [
                Action::make('open')
                    ->label(trans('minecraft-client-modpack::strings.actions.open'))
                    ->url(ClientModpackPage::getUrl(tenant: $this->getServer())),
            ] : [])
            ->send();
    }
}
