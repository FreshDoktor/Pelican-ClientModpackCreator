# Minecraft Client Modpack

A plugin for the [Pelican Panel](https://pelican.dev) that builds a **client modpack** (`.mrpack`) from the mods on a modded Minecraft server. Players download it from the panel and open it with their launcher, which answers the most common question on modded servers: "Which mods do I need to join?"

## Features

- **Built from the server's mods**: Every jar in the `mods` folder is read and recognised on Modrinth by its hash, including mods uploaded by hand
- **Server-only mods are left out**: Decided from what the jar says about itself (`fabric.mod.json`, `quilt.mod.json`, `mods.toml`) and from Modrinth's client and server side of the project (see [Which mods go in](#which-mods-go-in)). Every decision can be changed by hand
- **Extra client mods**: Add mods from Modrinth that players get on top, e.g. a minimap, shaders or performance mods. Their required dependencies are added too, the version is picked anew for every build
- **Mods that aren't on Modrinth** (e.g. from CurseForge) are put into the modpack file as they are, or left out if you prefer
- **Folders and files**: Optionally put folders like `config` into the modpack
- **Hosted by the panel**: One download link per server that always hands out the latest build, so it can be posted once (e.g. on Discord)
- **Outdated hint**: The page tells when the mods on the server changed since the last build
- **Works with or without minecraft-modrinth**: With [minecraft-modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) by Boy132 enabled, its mods page gets a _Build client modpack_ button. Otherwise the page has its own menu item. No other plugin is required
- **Activity log**: Building, cancelling, changing settings and mods, a new download link and deleting are written to the server's activity log
- **Multilingual**: English and German

## Requirements

- Pelican Panel with plugin support
- A running queue worker (`php artisan queue:work`, which every Pelican installation needs anyway), the modpack is built in the background
- A modded Minecraft server: its egg has the `modrinth_mods` feature, or the `minecraft` tag together with the `mods` feature or a loader tag (`fabric`, `quilt`, `forge`, `neoforge`)
- The panel has to be reachable by the players, they download the modpack from it

## Installation

Copy the plugin into the `plugins` folder of your panel, the folder has to be named like the plugin id (`minecraft-client-modpack`), or import `minecraft-client-modpack.zip` in the admin area under _Plugins_. Then install and enable it there. Installing creates the table `minecraft_client_modpacks`.

## Usage

1. Open _Client Modpack_ in the server menu, or click _Build client modpack_ on the mods page of minecraft-modrinth.
2. **Build** the modpack. The first build reads every mod once and takes a moment, later builds only read new or changed mods.
3. Check the mod list: change single mods to _Required_, _Optional_ or _Leave out_, and **add client mods** if you like.
4. **Rebuild** after changes, then share the **download link** with your players.

Players open the `.mrpack` with the [Modrinth App](https://modrinth.com/app), [Prism Launcher](https://prismlauncher.org), ATLauncher or any other launcher that supports Modrinth modpacks. Mods from Modrinth are downloaded by the launcher, the file itself only holds the mods that aren't on Modrinth and the picked folders.

### Which mods go in

For every mod of the server, the first rule that applies decides:

| Rule | Result |
| --- | --- |
| Set by hand on the page | Required, optional or left out |
| The jar says it only runs on the server (`"environment": "server"`) | Left out |
| The jar says it only runs on the client (`"environment": "client"`, `clientSideOnly`) | Required |
| Modrinth lists the client side as _unsupported_ | Left out |
| Modrinth lists the client side as _required_ | Required |
| Modrinth lists the client side as _optional_ | Optional |
| `displayTest` of `mods.toml` is `IGNORE_SERVER_VERSION`, `IGNORE_ALL_VERSION` or `NONE` (Forge and NeoForge only) | Optional |
| Otherwise | Required |

_Optional_ mods are in the modpack, but launchers may let players leave them out. A rule set by hand sticks with the Modrinth project, so it survives updates of the mod.

### Minecraft version and loader

The modpack names the Minecraft version, the loader and its version, launchers install exactly those. They are found out from the server: the loader from the egg's tags, the versions from what is installed in the `libraries` folder, then from the egg's variables (`MINECRAFT_VERSION`, `MC_VERSION`, `LOADER_VERSION`, `FORGE_VERSION`, `NEOFORGE_VERSION`, …) and the mods themselves. If that goes wrong, set them in the settings of the page.

### Permissions

| Action | Subuser permission |
| --- | --- |
| See the page, download | `file.read` |
| Build, cancel a build | `file.read-content` |
| Settings, change mods, add client mods, new download link | `file.update` |
| Put folders and files into the modpack | `file.update` and `file.read-content` |
| Delete | `file.delete` |

## Configuration

In the admin area under _Plugins_ → _Minecraft Client Modpack_ → _Settings_:

| Setting | Env | Default | Description |
| --- | --- | --- | --- |
| Public panel address | `MINECRAFT_CLIENT_MODPACK_PUBLIC_URL` | `APP_URL` | Address the players download from, e.g. if the panel is reachable under another address from outside |
| Largest mod file | `MINECRAFT_CLIENT_MODPACK_MAX_MOD_SIZE_MB` | `64` | Mods are read off the server to decide whether players need them, bigger files are left out |
| Most a modpack may hold itself | `MINECRAFT_CLIENT_MODPACK_MAX_EMBEDDED_SIZE_MB` | `200` | Size of the mods that aren't on Modrinth and the picked folders together |

`config/minecraft-client-modpack.php` also sets the time a build may take (`job_timeout`, 30 minutes), the most files a picked folder may have (`max_override_files`, 2000) and the hosts launchers may download mods from.

## How it works

- Builds run on the queue. The jars are read through the daemon one by one, their hashes and metadata are cached, so a rebuild only reads what changed
- Mods are recognised with Modrinth's `version_files` API. Mods on Modrinth go into `modrinth.index.json` with their download URL and hashes, all others into the `overrides` folder of the modpack
- Modpacks are stored on the panel's local disk under `storage/app/private/minecraft-client-modpack/<server uuid>/`. The panel serves them without login at `/client-modpacks/<token>.mrpack`; the token is random and stays the same across builds until a new download link is created
- Downloads are limited to 30 per minute and IP and are not written to the activity log
- When a server is deleted, its modpack is deleted too. Uninstalling the plugin drops the table, the files in `storage/app/private/minecraft-client-modpack` have to be deleted by hand

## License

Licensed under the [GNU General Public License v3.0](LICENSE).
