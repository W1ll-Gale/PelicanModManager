<?php

namespace MrBytesized\PelicanModManager\Services;

use App\Models\Server;

class ModpackService
{
    public function __construct(
        protected ModrinthClient $modrinth,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function resolveDownloadedFilesBySha1(array $downloadedMods): array
    {
        $hashes = collect($downloadedMods)
            ->pluck('sha1')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return $this->modrinth->getVersionsByHashes($hashes, 'sha1');
    }

    /** @return array<string, array<string, mixed>> */
    public function getProjectsMap(array $projectIds): array
    {
        return $this->modrinth->getProjectsMap($projectIds);
    }

    public function suggestedExportName(Server $server): string
    {
        return ($server->name ?? 'Server') . ' Modpack';
    }
}
