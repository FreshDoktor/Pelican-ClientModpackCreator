<?php

namespace FreshDoktor\MinecraftClientModpack\Providers;

use FreshDoktor\MinecraftClientModpack\Http\Controllers\DownloadClientModpackController;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * The download link of a client modpack. Players download it without an account on the panel, so the
 * route runs without the web middleware (no session, no cookies); the random token is what protects it.
 */
class DownloadRouteServiceProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            Route::get('client-modpacks/{token}.mrpack', DownloadClientModpackController::class)
                ->where('token', '[A-Za-z0-9]{40}')
                ->middleware('throttle:30,1')
                ->name('minecraft-client-modpack.download');
        });
    }
}
