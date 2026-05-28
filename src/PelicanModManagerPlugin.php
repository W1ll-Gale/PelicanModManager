<?php

namespace MrBytesized\PelicanModManager;

use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Panel;

class PelicanModManagerPlugin implements Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'pelican-mod-manager';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "MrBytesized\\PelicanModManager\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
