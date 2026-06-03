<?php

namespace MrBytesized\PelicanModManager\Services;

use App\Models\Server;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use Exception;
use Illuminate\Support\Facades\Cache;

class PelicanModManagerService
{
    public function __construct(
        protected ModrinthClient $modrinth,
        protected ModManagerFileService $files,
    ) {}

    public function getMinecraftVersion(Server $server): ?string
    {
        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return $this->modrinth->getLatestMinecraftVersion();
        }

        return $version;
    }

    public function getLatestMinecraftVersion(): ?string
    {
        return $this->modrinth->getLatestMinecraftVersion();
    }

    /** @return array{icon: string, name: string, supported_project_types: string[], display_name: string}|null */
    public function getLoaderFromServer(Server $server): ?array
    {
        $server->loadMissing('egg');

        $tags = $server->egg->tags ?? [];

        if (!in_array('minecraft', $tags)) {
            return null;
        }

        $projectType = ModrinthProjectType::fromServer($server)?->value;
        if (!$projectType) {
            return null;
        }

        $loaders = $this->getLoaders();
        foreach ($loaders as $loader) {
            if (!in_array($projectType, $loader['supported_project_types'])) {
                continue;
            }

            if (in_array($loader['name'], $tags)) {
                return array_merge($loader, ['display_name' => str($loader['name'])->title()->toString()]);
            }
        }

        return null;
    }

    /** @return array<int, array{icon: string, name: string, supported_project_types: string[]}> */
    public function getLoaders(): array
    {
        return $this->modrinth->getLoaders();
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getProjects(
        Server $server,
        int $page = 1,
        ?string $search = null,
        ?string $sortColumn = null,
        ?string $sortDirection = null,
        array $filters = [],
        int $limit = 20
    ): array {
        $projectType = ModrinthProjectType::fromServer($server)?->value;
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$projectType || !$minecraftLoader) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        if (!$minecraftVersion) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $minecraftLoader = $minecraftLoader['name'];

        return $this->modrinth->searchProjects($projectType, $minecraftVersion, $minecraftLoader, $page, $search, $sortColumn, $sortDirection, $filters, $limit);
    }

    /**
     * @param  array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
    {
        if (empty($installedMods)) {
            return [];
        }

        $projectIds = collect($installedMods)->pluck('project_id')->unique()->values()->all();

        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $pageIds = array_slice($projectIds, $offset, $perPage);

        if (empty($pageIds)) {
            return [];
        }

        $modrinthMap = $this->modrinth->getProjectsMap($pageIds);

        $installedModsById = [];
        foreach ($installedMods as $mod) {
            if (!isset($installedModsById[$mod['project_id']])) {
                $installedModsById[$mod['project_id']] = $mod;
            }
        }

        $results = [];
        foreach ($pageIds as $projectId) {
            $installedMod = $installedModsById[$projectId] ?? null;

            if (!$installedMod) {
                continue;
            }

            if (isset($modrinthMap[$projectId])) {
                $project = $modrinthMap[$projectId];
                $project['project_id'] = $project['id'];
                if (isset($project['updated']) && !isset($project['date_modified'])) {
                    $project['date_modified'] = $project['updated'];
                }
                if (isset($installedMod['author']) && !isset($project['author'])) {
                    $project['author'] = $installedMod['author'];
                }
                $results[] = $project;
            } else {
                $results[] = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'description' => trans('pelican-mod-manager::strings.page.mod_unavailable'),
                    'icon_url' => null,
                    'author' => $installedMod['author'] ?? '',
                    'downloads' => 0,
                    'date_modified' => $installedMod['installed_at'],
                    'project_type' => '',
                    'unavailable' => true,
                ];
            }
        }

        return $results;
    }

    /** @return array<array{name: string, version_number: string, changelog: ?string, dependencies: array<mixed>, game_version: string[], version_type: string, loaders: string[], featured: bool, status: string, requested_status: ?string, id: string, project_id: string, author_id: string, date_published: string, downloads: int, changelog_url: ?string, files: array<mixed>}> */
    public function getProjectVersions(string $projectId, Server $server): array
    {
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        return $this->modrinth->getProjectVersions($projectId, $minecraftVersion, $minecraftLoader, $server);
    }

    /**
     * @throws Exception
     */
    protected function getMetadataFilePath(Server $server): string
    {
        $type = ModrinthProjectType::fromServer($server);

        if (!$type) {
            throw new Exception("Server {$server->id} does not support Modrinth mods or plugins");
        }

        return $this->files->metadataFilePath($server);
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    public function getInstalledModsMetadata(Server $server): array
    {
        return $this->files->readInstalledModsMetadata($server);
    }

    /**
     * @param  array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, author?: string}>  $mods
     */
    public function saveModsMetadata(Server $server, array $mods): bool
    {
        if (empty($mods)) {
            return true;
        }

        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $mods) {
                $installed = $this->getInstalledModsMetadata($server);
                $installedMap = [];
                foreach ($installed as $mod) {
                    $installedMap[$mod['project_id']] = $mod;
                }

                $now = now()->toIso8601String();
                foreach ($mods as $mod) {
                    $projectId = $mod['project_id'];
                    $modEntry = [
                        'project_id' => $projectId,
                        'project_slug' => $mod['project_slug'],
                        'project_title' => $mod['project_title'],
                        'version_id' => $mod['version_id'],
                        'version_number' => $mod['version_number'],
                        'filename' => $mod['filename'],
                        'installed_at' => $now,
                    ];

                    if (isset($mod['author']) && $mod['author'] !== null) {
                        $modEntry['author'] = $mod['author'];
                    }

                    $installedMap[$projectId] = $modEntry;
                }

                return $this->files->writeInstalledModsMetadata($server, array_values($installedMap));
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function saveModMetadata(
        Server $server,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null
    ): bool {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $projectId, $projectSlug, $projectTitle, $versionId, $versionNumber, $filename, $author) {
                $metadata = [
                    'installed_mods' => $this->getInstalledModsMetadata($server),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($mod) => $mod['project_id'] !== $projectId)
                    ->values()
                    ->toArray();

                $modEntry = [
                    'project_id' => $projectId,
                    'project_slug' => $projectSlug,
                    'project_title' => $projectTitle,
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'filename' => $filename,
                    'installed_at' => now()->toIso8601String(),
                ];

                if ($author !== null) {
                    $modEntry['author'] = $author;
                }

                $metadata['installed_mods'][] = $modEntry;

                return $this->files->writeInstalledModsMetadata($server, $metadata['installed_mods']);
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function removeModMetadata(Server $server, string $projectId): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $projectId) {
                $metadata = [
                    'installed_mods' => $this->getInstalledModsMetadata($server),
                ];

                $metadata['installed_mods'] = collect($metadata['installed_mods'])
                    ->filter(fn ($mod) => $mod['project_id'] !== $projectId)
                    ->values()
                    ->toArray();

                return $this->files->writeInstalledModsMetadata($server, $metadata['installed_mods']);
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    public function getInstalledMod(Server $server, string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata($server);

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * @param  array{version_id: string, version_number: string}  $installedMod
     * @param  array<int, array{id: string, version_number: string}>  $availableVersions
     */
    public function isUpdateAvailable(array $installedMod, array $availableVersions): bool
    {
        if (empty($availableVersions)) {
            return false;
        }

        $latestVersion = $availableVersions[0];

        return $installedMod['version_id'] !== $latestVersion['id'];
    }

    /**
     * @return array<string>
     */
    public function getInstalledMods(Server $server): array
    {
        $metadata = $this->getInstalledModsMetadata($server);

        return collect($metadata)
            ->pluck('filename')
            ->map(fn ($name) => strtolower($name))
            ->toArray();
    }
}
