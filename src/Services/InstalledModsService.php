<?php

namespace MrBytesized\PelicanModManager\Services;

use App\Models\Server;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;

class InstalledModsService
{
    public function __construct(
        protected ModManagerFileService $files,
        protected ModrinthClient $modrinth,
    ) {}

    /** @return array<int, mixed> */
    public function metadataOnlyList(array $metadata): array
    {
        return collect($metadata)->map(fn ($mod) => [
            'project_id' => $mod['project_id'],
            'slug' => $mod['project_slug'],
            'title' => $mod['project_title'],
            'filename' => $mod['filename'],
            'installed_at' => $mod['installed_at'],
            'author' => $mod['author'] ?? '',
            'author_avatar' => null,
            'icon_url' => null,
            'project_type' => 'mod',
            'is_local' => false,
            'is_disabled' => str_ends_with(strtolower($mod['filename']), '.disabled'),
            'metadata' => $mod,
        ])->toArray();
    }

    /** @return array<int, mixed> */
    public function basicList(Server $server, ModrinthProjectType $type, array $metadata): array
    {
        $cacheKey = "pmm_basic_installed_{$server->uuid}";

        return cache()->remember($cacheKey, now()->addMinutes($this->cacheMinutes('installed_lists', 5)), function () use ($server, $type, $metadata) {
            return $this->buildDiskBackedList($server, $type, $metadata, false);
        });
    }

    /** @return array<int, mixed> */
    public function resolvedList(Server $server, ModrinthProjectType $type, array $metadata): array
    {
        $cacheKey = "modrinth_installed_resolved_list_" . $server->uuid;

        return cache()->remember($cacheKey, now()->addMinutes($this->cacheMinutes('installed_lists', 5)), function () use ($server, $type, $metadata) {
            $combinedItems = $this->buildDiskBackedList($server, $type, $metadata, true);
            $registeredMods = collect($combinedItems)->filter(fn ($item) => empty($item['is_local']))->toArray();
            $resolvedRegistered = [];

            if (!empty($registeredMods)) {
                $projectIds = collect($registeredMods)->pluck('project_id')->unique()->values()->toArray();
                $modrinthMap = $this->modrinth->getProjectsMap($projectIds);
                $teamAuthorMap = $this->modrinth->getTeamAuthorsMap(collect($modrinthMap)->pluck('team')->filter()->unique()->values()->toArray());

                foreach ($registeredMods as $item) {
                    $projectId = $item['project_id'];
                    $mod = $item['metadata'];

                    if (isset($modrinthMap[$projectId])) {
                        $project = $modrinthMap[$projectId];
                        $project['project_id'] = $project['id'];
                        if (isset($project['updated']) && !isset($project['date_modified'])) {
                            $project['date_modified'] = $project['updated'];
                        }

                        $teamId = $project['team'] ?? null;
                        if ($teamId && isset($teamAuthorMap[$teamId])) {
                            $project['author'] = $teamAuthorMap[$teamId]['username'] ?? ($mod['author'] ?? '');
                            $project['author_avatar'] = $teamAuthorMap[$teamId]['avatar_url'] ?? null;
                        } elseif (isset($mod['author']) && !isset($project['author'])) {
                            $project['author'] = $mod['author'];
                        }

                        $project['filename'] = $item['filename'];
                        $project['is_local'] = false;
                        $project['is_disabled'] = $item['is_disabled'] ?? false;
                        $project['metadata'] = $mod;
                        $resolvedRegistered[] = $project;
                    } else {
                        $resolvedRegistered[] = $this->unavailableModRecord($mod, $item['filename'], $item['is_disabled'] ?? false);
                    }
                }
            }

            $finalRecords = [];
            foreach ($combinedItems as $item) {
                if ($item['is_local']) {
                    $finalRecords[] = $item;
                    continue;
                }

                $matchedResolved = collect($resolvedRegistered)
                    ->first(fn ($res) => $res['project_id'] === $item['project_id'] && strcasecmp($res['filename'], $item['filename']) === 0);
                $finalRecords[] = $matchedResolved ?: $this->unavailableModRecord($item['metadata'], $item['filename'], $item['is_disabled'] ?? false);
            }

            return $finalRecords;
        });
    }

    /** @return array<string, true> */
    public function installedModrinthSha1s(Server $server, array $metadata, ?string $minecraftVersion, ?string $minecraftLoader): array
    {
        $hashes = [];
        $projectIds = collect($metadata)->pluck('project_id')->filter()->unique()->values()->toArray();
        $versionsByProject = $this->modrinth->warmProjectVersions($server, $projectIds, $minecraftVersion, $minecraftLoader);

        foreach ($metadata as $mod) {
            $projectId = $mod['project_id'] ?? '';
            $versionId = $mod['version_id'] ?? '';
            if ($projectId === '' || $versionId === '') {
                continue;
            }

            $version = collect($versionsByProject[$projectId] ?? [])->firstWhere('id', $versionId);
            if (!$version || empty($version['files']) || !is_array($version['files'])) {
                continue;
            }

            foreach ($version['files'] as $file) {
                $sha1 = $file['hashes']['sha1'] ?? null;
                if (is_string($sha1) && $sha1 !== '') {
                    $hashes[strtolower($sha1)] = true;
                }
            }
        }

        return $hashes;
    }

    public function forgetInstalledListCaches(Server $server): void
    {
        cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);
        cache()->forget("pmm_basic_installed_{$server->uuid}");
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $versionData
     * @param array<string, mixed>|null $installedMod
     */
    public function patchAfterInstallOrUpdate(
        Server $server,
        array $record,
        array $versionData,
        string $filename,
        ?array $installedMod = null
    ): array {
        $projectId = $record['project_id'] ?? null;
        if (!$projectId) {
            return $this->cacheStats($server);
        }

        $metadata = [
            'project_id' => $projectId,
            'project_slug' => $record['slug'] ?? ($installedMod['project_slug'] ?? ''),
            'project_title' => $record['title'] ?? ($installedMod['project_title'] ?? $projectId),
            'version_id' => $versionData['id'] ?? '',
            'version_number' => $versionData['version_number'] ?? '',
            'filename' => $filename,
            'installed_at' => now()->toIso8601String(),
        ];
        if (($record['author'] ?? ($installedMod['author'] ?? null)) !== null) {
            $metadata['author'] = $record['author'] ?? $installedMod['author'];
        }

        foreach ($this->installedListCachePrefixes() as $prefix) {
            $cacheKey = $prefix . $server->uuid;
            $cachedItems = cache()->get($cacheKey);
            if (!is_array($cachedItems)) {
                continue;
            }

            $found = false;
            $cachedItems = array_map(function (array $item) use (&$found, $projectId, $filename, $metadata, $versionData, $record) {
                if (($item['project_id'] ?? null) !== $projectId) {
                    return $item;
                }

                $found = true;
                $item['filename'] = $filename;
                $item['is_disabled'] = false;
                $item['is_local'] = false;
                $item['metadata'] = $metadata;
                $item['version_number'] = $versionData['version_number'] ?? ($item['version_number'] ?? '');
                $item['slug'] = $metadata['project_slug'];
                $item['title'] = $metadata['project_title'];
                $item['author'] = $record['author'] ?? ($metadata['author'] ?? ($item['author'] ?? ''));

                return $item;
            }, $cachedItems);

            if (!$found) {
                $cachedItems[] = [
                    'project_id' => $projectId,
                    'slug' => $metadata['project_slug'],
                    'title' => $metadata['project_title'],
                    'filename' => $filename,
                    'installed_at' => $metadata['installed_at'],
                    'author' => $metadata['author'] ?? ($record['author'] ?? ''),
                    'author_avatar' => null,
                    'icon_url' => $record['icon_url'] ?? null,
                    'project_type' => $record['project_type'] ?? 'mod',
                    'is_local' => false,
                    'is_disabled' => false,
                    'metadata' => $metadata,
                ];
            }

            cache()->put($cacheKey, array_values($cachedItems), now()->addMinutes($this->cacheMinutes('installed_lists', 5)));
        }

        return $this->cacheStats($server);
    }

    /**
     * @param array<int, array{project_id: string, old_filename: string, new_filename: string, is_disabled: bool}> $updates
     */
    public function patchEnabledStateInCaches(Server $server, array $updates): array
    {
        if (empty($updates)) {
            return $this->cacheStats($server);
        }

        foreach ($this->installedListCachePrefixes() as $prefix) {
            $cacheKey = $prefix . $server->uuid;
            $cachedItems = cache()->get($cacheKey);
            if (!is_array($cachedItems)) {
                continue;
            }

            $cachedItems = array_map(function (array $item) use ($updates) {
                foreach ($updates as $update) {
                    $sameProject = ($item['project_id'] ?? null) === $update['project_id'];
                    $sameFile = strcasecmp($item['filename'] ?? '', $update['old_filename']) === 0;
                    if (!$sameProject && !$sameFile) {
                        continue;
                    }

                    $item['filename'] = $update['new_filename'];
                    $item['is_disabled'] = $update['is_disabled'];
                    if (str_starts_with($update['project_id'], 'local_')) {
                        $cleanFilename = str_replace('.disabled', '', $update['new_filename']);
                        $item['project_id'] = 'local_' . md5($update['new_filename']);
                        $item['title'] = basename($cleanFilename, '.jar');
                    }
                    if (isset($item['metadata']) && is_array($item['metadata'])) {
                        $item['metadata']['filename'] = $update['new_filename'];
                    }

                    break;
                }

                return $item;
            }, $cachedItems);

            cache()->put($cacheKey, $cachedItems, now()->addMinutes($this->cacheMinutes('installed_lists', 5)));
        }

        return $this->cacheStats($server);
    }

    /**
     * @param string[] $projectIds
     * @param string[] $filenames
     */
    public function removeRowsFromCaches(Server $server, array $projectIds = [], array $filenames = []): array
    {
        $projectIds = array_values(array_filter($projectIds));
        $filenames = array_map(fn ($filename) => strtolower(str_replace('.disabled', '', $filename)), array_filter($filenames));

        foreach ($this->installedListCachePrefixes() as $prefix) {
            $cacheKey = $prefix . $server->uuid;
            $cachedItems = cache()->get($cacheKey);
            if (!is_array($cachedItems)) {
                continue;
            }

            $cachedItems = array_values(array_filter($cachedItems, function (array $item) use ($projectIds, $filenames) {
                $projectId = $item['project_id'] ?? '';
                $filename = strtolower(str_replace('.disabled', '', $item['filename'] ?? ''));

                return !($projectId && in_array($projectId, $projectIds, true))
                    && !($filename && in_array($filename, $filenames, true));
            }));

            cache()->put($cacheKey, $cachedItems, now()->addMinutes($this->cacheMinutes('installed_lists', 5)));
        }

        return $this->cacheStats($server);
    }

    public function addLocalRowToCaches(Server $server, ModrinthProjectType $type, string $filename): array
    {
        $row = $this->localRecord($filename, $type, now()->toIso8601String());
        $normalizedFilename = strtolower(str_replace('.disabled', '', $filename));

        foreach ($this->installedListCachePrefixes() as $prefix) {
            $cacheKey = $prefix . $server->uuid;
            $cachedItems = cache()->get($cacheKey);
            if (!is_array($cachedItems)) {
                continue;
            }

            $cachedItems = array_values(array_filter($cachedItems, function (array $item) use ($normalizedFilename) {
                return strtolower(str_replace('.disabled', '', $item['filename'] ?? '')) !== $normalizedFilename;
            }));
            $cachedItems[] = $row;

            cache()->put($cacheKey, $cachedItems, now()->addMinutes($this->cacheMinutes('installed_lists', 5)));
        }

        return $this->cacheStats($server);
    }

    public function cacheStats(Server $server): array
    {
        $resolvedItems = cache()->get("modrinth_installed_resolved_list_" . $server->uuid);
        if (!is_array($resolvedItems)) {
            $resolvedItems = cache()->get("pmm_basic_installed_{$server->uuid}", []);
        }

        return [
            'has_disabled' => collect(is_array($resolvedItems) ? $resolvedItems : [])
                ->contains(fn ($item) => !empty($item['is_disabled'])),
        ];
    }

    /** @return array<int, mixed> */
    protected function buildDiskBackedList(Server $server, ModrinthProjectType $type, array $metadata, bool $includeDescriptions): array
    {
        $records = [];

        foreach ($this->files->listJarFiles($server, $type) as $file) {
            $filename = $file['name'];
            $isDisabled = str_ends_with(strtolower($filename), '.disabled');
            $cleanFilename = str_replace('.disabled', '', $filename);
            $matchedMetadata = collect($metadata)
                ->first(fn ($mod) => strcasecmp(str_replace('.disabled', '', $mod['filename']), $cleanFilename) === 0);

            if ($matchedMetadata) {
                $records[] = [
                    'project_id' => $matchedMetadata['project_id'],
                    'slug' => $matchedMetadata['project_slug'],
                    'title' => $matchedMetadata['project_title'],
                    'filename' => $filename,
                    'installed_at' => $matchedMetadata['installed_at'],
                    'author' => $matchedMetadata['author'] ?? ($includeDescriptions ? 'Unknown' : ''),
                    'author_avatar' => null,
                    'icon_url' => null,
                    'project_type' => 'mod',
                    'is_local' => false,
                    'is_disabled' => $isDisabled,
                    'metadata' => $matchedMetadata,
                ];
            } else {
                $records[] = $this->localRecord($filename, $type, $file['modified'] ?? '', $includeDescriptions);
            }
        }

        return $records;
    }

    protected function localRecord(string $filename, ModrinthProjectType $type, string $modified = '', bool $includeDescription = true): array
    {
        $isDisabled = str_ends_with(strtolower($filename), '.disabled');
        $cleanFilename = str_replace('.disabled', '', $filename);

        return [
            'project_id' => 'local_' . md5($filename),
            'slug' => '',
            'title' => basename($cleanFilename, '.jar'),
            'description' => $includeDescription ? 'Local mod file (' . $filename . ')' : null,
            'icon_url' => null,
            'author' => $includeDescription ? 'Unknown' : '',
            'author_avatar' => null,
            'downloads' => 0,
            'date_modified' => $modified,
            'project_type' => $type->value,
            'unavailable' => true,
            'filename' => $filename,
            'is_local' => true,
            'is_disabled' => $isDisabled,
        ];
    }

    protected function unavailableModRecord(array $mod, string $filename, bool $isDisabled): array
    {
        return [
            'project_id' => $mod['project_id'],
            'slug' => $mod['project_slug'],
            'title' => $mod['project_title'],
            'description' => trans('pelican-mod-manager::strings.page.mod_unavailable'),
            'icon_url' => null,
            'author' => $mod['author'] ?? '',
            'downloads' => 0,
            'date_modified' => $mod['installed_at'],
            'project_type' => '',
            'unavailable' => true,
            'filename' => $filename,
            'is_local' => false,
            'is_disabled' => $isDisabled,
            'metadata' => $mod,
        ];
    }

    protected function cacheMinutes(string $key, int $default): int
    {
        return (int) config("pelican-mod-manager.cache.{$key}_minutes", $default);
    }

    /** @return string[] */
    protected function installedListCachePrefixes(): array
    {
        return ["modrinth_installed_resolved_list_", "pmm_basic_installed_"];
    }
}
