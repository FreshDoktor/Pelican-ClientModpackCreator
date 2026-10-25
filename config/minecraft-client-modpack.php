<?php

return [
    // Address players download the modpacks from, e.g. https://panel.example.com. Empty uses APP_URL.
    'public_url' => env('MINECRAFT_CLIENT_MODPACK_PUBLIC_URL'),

    // Largest mod jar (in MB) that is read off the server. Bigger ones can't be recognised on Modrinth
    // or put into the modpack and are left out.
    'max_mod_size_mb' => (int) env('MINECRAFT_CLIENT_MODPACK_MAX_MOD_SIZE_MB', 64),

    // Most the files that are not on Modrinth (uploaded mods, config files) may add to a modpack, in MB.
    'max_embedded_size_mb' => (int) env('MINECRAFT_CLIENT_MODPACK_MAX_EMBEDDED_SIZE_MB', 200),

    // Most files a folder put into the modpack (e.g. config) may have.
    'max_override_files' => 2000,

    // Seconds a build may take, reading every mod off the daemon takes a while on big modpacks.
    'job_timeout' => 1800,

    // Hosts the modpack format allows launchers to download mods from.
    'allowed_download_hosts' => [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'gitlab.com',
    ],
];
