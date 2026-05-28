# Pelican Minecraft Mod Manager

A Minecraft mod and plugin manager for Pelican server panels, using Modrinth.

This is a standalone fork of the original [Minecraft Modrinth](https://hub.pelican.dev/plugins/minecraft-modrinth) plugin by Boy132 & H1ghSyst3m, with major performance improvements and card layout styling changes.

## Features

- **Modrinth Search**: Search and filter mods, plugins, and modpacks inside your server panel.
- **Smart Installs**: Automatically downloads the latest compatible version based on your server loader and Minecraft version.
- **Modpack Importing**: Import entire modpacks (from Modrinth packages or local zip/jar files) directly from the tab.
- **Unified Management**: Manage both Modrinth-resolved mods and standard local jar files seamlessly in a single list.
- **Status & Updates**: View installed mods, available updates, and manage them.
- **Toggle switch**: Enable/disable mods directly by renaming `.jar` files to `.jar.disabled`.
- **Local files**: Manage and delete local files not tracked by Modrinth.

## Setup

### Installation

1. Download the latest `pelican-mod-manager.zip` from the releases tab.
2. Go to your Pelican admin panel's **Plugins** tab.
3. Upload the ZIP archive directly.

### Egg Setup

Configure your egg with the following:

- **Features**: Add `modrinth_mods` and/or `modrinth_plugins` to the `features` array.
- **Minecraft Tag**: The egg must have the `minecraft` tag.
- **Loader Tag**: Add the loader tag (e.g. `paper`, `purpur`, `fabric`, `neoforge`, `forge`, `quilt`).

## Credits & Acknowledgements

Based on the original **Minecraft Modrinth** plugin by:
- **Boy132** (Lead Developer)
- **H1ghSyst3m** (Co-developer)

## License
MIT
