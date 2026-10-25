<?php

namespace FreshDoktor\MinecraftClientModpack\Http\Controllers;

use FreshDoktor\MinecraftClientModpack\Models\ClientModpack;
use FreshDoktor\MinecraftClientModpack\Services\ClientModpackService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hands out the latest build of a client modpack. Downloads aren't written to the activity log,
 * every player downloading it would add an entry.
 */
class DownloadClientModpackController
{
    public function __invoke(string $token, ClientModpackService $service): BinaryFileResponse
    {
        $pack = ClientModpack::query()->with('server')->where('link_token', $token)->first();

        abort_if($pack === null || !$pack->isBuilt(), 404);

        abort_unless($service->disk()->exists($service->filePath($pack->server, (string) $pack->file_token)), 404);

        return response()->download((string) $service->absolutePath($pack), $pack->file_name ?? 'modpack.mrpack', [
            'Content-Type' => 'application/x-modrinth-modpack+zip',
            // The link stays the same across builds, a cache must never hand out an old build.
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
