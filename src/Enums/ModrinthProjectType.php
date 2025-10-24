<?php

namespace Boy132\MinecraftModrinth\Enums;

use Filament\Support\Contracts\HasLabel;

enum ModrinthProjectType: string implements HasLabel
{
    case Mod = 'mod';
    case Plugin = 'plugin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mod => 'Minecraft Mods',
            self::Plugin => 'Minecraft Plugins',
        };
    }

    public function getFolder(): string
    {
        return match ($this) {
            self::Mod => 'mods',
            self::Plugin => 'plugins',
        };
    }
}
