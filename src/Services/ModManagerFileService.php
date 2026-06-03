<?php

namespace MrBytesized\PelicanModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Support\Facades\Http;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;

class ModManagerFileService
{
    /**
     * @throws Exception
     */
    public function metadataFilePath(Server $server): string
    {
        $type = ModrinthProjectType::fromServer($server);

        if (!$type) {
            throw new Exception("Server {$server->id} does not support Modrinth mods or plugins");
        }

        return join_paths($type->getFolder(), '.modrinth-metadata.json');
    }

    /** @return array<int, array<string, mixed>> */
    public function readInstalledModsMetadata(Server $server): array
    {
        try {
            $content = app(DaemonFileRepository::class)
                ->setServer($server)
                ->getContent($this->metadataFilePath($server));
            $metadata = json_decode($content, true);

            if (!is_array($metadata) || !isset($metadata['installed_mods']) || !is_array($metadata['installed_mods'])) {
                return [];
            }

            $requiredKeys = array_flip([
                'project_id',
                'project_slug',
                'project_title',
                'version_id',
                'version_number',
                'filename',
                'installed_at',
            ]);

            $validInstalledMods = [];
            foreach ($metadata['installed_mods'] as $entry) {
                if (is_array($entry) && empty(array_diff_key($requiredKeys, $entry))) {
                    $validInstalledMods[] = $entry;
                }
            }

            return $validInstalledMods;
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    public function writeInstalledModsMetadata(Server $server, array $installedMods): bool
    {
        $metadata = [
            'installed_mods' => array_values($installedMods),
        ];

        $response = app(DaemonFileRepository::class)
            ->setServer($server)
            ->putContent(
                $this->metadataFilePath($server),
                json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

        return !$response->failed();
    }

    /** @return array<int, array<string, mixed>> */
    public function listJarFiles(Server $server, ModrinthProjectType $type): array
    {
        try {
            $files = app(DaemonFileRepository::class)
                ->setServer($server)
                ->getDirectory($type->getFolder());

            if (isset($files['error'])) {
                throw new Exception($files['error']);
            }

            return collect($files)
                ->filter(function ($file) {
                    $name = strtolower($file['name'] ?? '');

                    return str_ends_with($name, '.jar') || str_ends_with($name, '.jar.disabled');
                })
                ->values()
                ->toArray();
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param array<int, array{from: string, to: string}> $renames
     */
    public function renameFiles(Server $server, array $renames): void
    {
        if (empty($renames)) {
            return;
        }

        Http::daemon($server->node)
            ->put("/api/servers/{$server->uuid}/files/rename", [
                'root' => '/',
                'files' => $renames,
            ])
            ->throw();
    }

    /** @param string[] $files */
    public function deleteFiles(Server $server, array $files): void
    {
        $files = array_values(array_filter($files));
        if (empty($files)) {
            return;
        }

        Http::daemon($server->node)
            ->post("/api/servers/{$server->uuid}/files/delete", [
                'root' => '/',
                'files' => $files,
            ])
            ->throw();
    }

    public function pull(Server $server, string $url, string $folder): void
    {
        app(DaemonFileRepository::class)
            ->setServer($server)
            ->pull($url, $folder)
            ->throw();
    }

    public function getContent(Server $server, string $path): string
    {
        return Http::daemon($server->node)
            ->get("/api/servers/{$server->uuid}/files/contents", ['file' => $path])
            ->body();
    }

    /**
     * @throws Exception
     */
    public function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }
}
