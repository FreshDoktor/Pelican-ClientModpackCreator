<?php

namespace FreshDoktor\MinecraftClientModpack\Providers;

use App\Models\Server;
use FreshDoktor\MinecraftClientModpack\Services\ClientModpackService;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The database row of a modpack goes with its server (foreign key), the file on the panel's disk has to be deleted here.
 */
class ServerCleanupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Server::deleted(function (Server $server) {
            try {
                app(ClientModpackService::class)->deleteServerFiles($server);
            } catch (Throwable $exception) {
                // Never keep a server from being deleted.
                report($exception);
            }
        });
    }
}
