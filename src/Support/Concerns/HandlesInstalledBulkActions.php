<?php

namespace MrBytesized\PelicanModManager\Support\Concerns;

use App\Models\Server;
use Exception;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use MrBytesized\PelicanModManager\Facades\PelicanModManager;
use MrBytesized\PelicanModManager\Services\InstalledModsService;
use MrBytesized\PelicanModManager\Services\ModManagerFileService;

trait HandlesInstalledBulkActions
{
    public function clearInstalledSelection(): void
    {
        $this->installedBulkSelectionJson = '[]';
        $this->exportModpackProjectIds = [];

        if (method_exists($this, 'deselectAllTableRecords')) {
            $this->deselectAllTableRecords();
            return;
        }

        $this->selectedTableRecords = [];
    }

    public function uninstallSelectedInstalledMods(): void
    {
        try {
            $records = $this->getInstalledRecordsByIds($this->getInstalledBulkSelectionIds());

            if ($records->isEmpty()) {
                return;
            }

            $this->uninstallInstalledRecords($records);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_failed'))
                ->body($e->getMessage())
                ->danger()
            ->send();
        }
    }

    /**
     * @param string[] $ids
     */
    public function uninstallInstalledModsByIds(array $ids): void
    {
        try {
            $records = $this->getInstalledRecordsByIds($ids);

            if ($records->isEmpty()) {
                return;
            }

            $this->uninstallInstalledRecords($records);
        } catch (Exception $e) {
            report($e);
            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param string[] $ids
     */
    public function setSelectedInstalledModsEnabled(array $ids, bool $enabled): void
    {
        try {
            $records = $this->getInstalledRecordsByIds($ids);

            if ($records->isEmpty()) {
                return;
            }

            /** @var Server $server */
            $server = Filament::getTenant();
            $type = ModrinthProjectType::fromServer($server);
            if (!$type) {
                throw new Exception('Server does not support Modrinth mods or plugins');
            }

            $folder = $type->getFolder();
            $renames = [];
            $updates = [];

            foreach ($records as $record) {
                $currentlyDisabled = !empty($record['is_disabled']);
                if ($enabled === !$currentlyDisabled) {
                    continue;
                }

                $oldFilename = $this->validateFilename($record['filename'] ?? '');
                if ($oldFilename === '') {
                    continue;
                }

                $newFilename = $enabled
                    ? preg_replace('/\.disabled$/i', '', $oldFilename)
                    : $oldFilename . '.disabled';

                if (!$newFilename || strcasecmp($oldFilename, $newFilename) === 0) {
                    continue;
                }

                $renames[] = [
                    'from' => $folder . '/' . $oldFilename,
                    'to' => $folder . '/' . $newFilename,
                ];
                $updates[] = [
                    'project_id' => $record['project_id'] ?? '',
                    'old_filename' => $oldFilename,
                    'new_filename' => $newFilename,
                    'is_disabled' => !$enabled,
                ];
            }

            if (empty($renames)) {
                return;
            }

            app(ModManagerFileService::class)->renameFiles($server, $renames);

            $metadataByProjectId = collect($this->getInstalledModsMetadata())->keyBy('project_id');
            $metadataUpdates = [];
            foreach ($updates as $update) {
                $projectId = $update['project_id'];
                if ($projectId === '' || str_starts_with($projectId, 'local_')) {
                    continue;
                }

                $installedMod = $metadataByProjectId->get($projectId);
                if (!$installedMod) {
                    continue;
                }

                $metadataUpdates[] = [
                    'project_id' => $projectId,
                    'project_slug' => $installedMod['project_slug'],
                    'project_title' => $installedMod['project_title'],
                    'version_id' => $installedMod['version_id'],
                    'version_number' => $installedMod['version_number'],
                    'filename' => $update['new_filename'],
                    'author' => $installedMod['author'] ?? null,
                ];
            }
            if (!empty($metadataUpdates)) {
                PelicanModManager::saveModsMetadata($server, $metadataUpdates);
            }

            $this->invalidateMetadataCache();

            $stats = app(InstalledModsService::class)->patchEnabledStateInCaches($server, $updates);
            $this->installedHasDisabled = $stats['has_disabled'] ?? false;
            if (!$this->installedHasDisabled && in_array($this->installedStatusFilter, ['enabled', 'disabled'], true)) {
                $this->installedStatusFilter = 'all';
            }
            $this->clearInstalledSelection();

            Notification::make()
                ->title($enabled ? 'Mods enabled' : 'Mods disabled')
                ->body(($enabled ? 'Enabled ' : 'Disabled ') . count($updates) . ' selected mod' . (count($updates) === 1 ? '.' : 's.'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title($enabled ? 'Failed to enable mods' : 'Failed to disable mods')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function prepareSelectedModpackExport(): void
    {
        try {
            $records = $this->getInstalledRecordsByIds($this->getInstalledBulkSelectionIds());

            $this->exportModpackProjectIds = $records
                ->pluck('project_id')
                ->filter()
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            report($e);
            $this->exportModpackProjectIds = [];
            Notification::make()
                ->title('Failed to prepare export')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param string[] $ids
     */
    public function exportSelectedModpack(array $ids): mixed
    {
        $this->exportModpackProjectIds = collect($ids)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->toArray();

        return $this->mountAction('export_modpack');
    }

    public function setInstalledBulkSelection(string $idsJson): void
    {
        $this->installedBulkSelectionJson = $idsJson;
    }

    /** @return string[] */
    protected function getInstalledBulkSelectionIds(): array
    {
        $decoded = json_decode($this->installedBulkSelectionJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->toArray();
    }

    protected function getSelectedInstalledRecordsForBulk(): \Illuminate\Support\Collection
    {
        try {
            if (method_exists($this, 'getSelectedTableRecords')) {
                $records = $this->getSelectedTableRecords();
                return $records instanceof \Illuminate\Support\Collection ? $records : collect($records);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to read selected table records: ' . $e->getMessage());
        }

        return $this->getInstalledRecordsByIds($this->selectedTableRecords ?? []);
    }

    /**
     * @param string[] $ids
     */
    protected function getInstalledRecordsByIds(array $ids): \Illuminate\Support\Collection
    {
        if (empty($ids)) {
            return collect();
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            return collect();
        }

        $records = collect([
            cache()->get("modrinth_installed_resolved_list_" . $server->uuid),
            cache()->get("pmm_basic_installed_{$server->uuid}"),
            $this->getMetadataOnlyList(),
        ])
            ->filter(fn ($items) => is_array($items) && !empty($items))
            ->flatMap(fn ($items) => $items)
            ->unique(fn ($record) => $record['project_id'] ?? ($record['filename'] ?? uniqid('', true)))
            ->values();

        if ($records->isEmpty()) {
            $records = collect($this->getInstalledModsResolvedList($server, $type));
        }

        return $records
            ->filter(fn ($record) => in_array($record['project_id'] ?? '', $ids, true))
            ->values();
    }

    protected function uninstallInstalledRecords(\Illuminate\Support\Collection $records): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            throw new Exception('Server does not support Modrinth mods or plugins');
        }

        $folder = $type->getFolder();
        $filesToDelete = [];
        $projectIdsToRemove = [];
        $filenamesToRemove = [];

        foreach ($records as $record) {
            if (!empty($record['is_local'])) {
                $filename = $record['filename'];
            } else {
                $installedMod = $this->getInstalledMod($record['project_id']);
                if ($installedMod) {
                    $filename = $installedMod['filename'];
                    $projectIdsToRemove[] = $record['project_id'];
                } else {
                    $filename = $record['filename'] ?? null;
                }
            }

            if ($filename) {
                $filesToDelete[] = $folder . '/' . $this->validateFilename($filename);
                $filenamesToRemove[] = $filename;
            }
        }

        if (!empty($filesToDelete)) {
            Http::daemon($server->node)
                ->post("/api/servers/{$server->uuid}/files/delete", [
                    'root' => '/',
                    'files' => $filesToDelete,
                ])
                ->throw();
        }

        foreach ($projectIdsToRemove as $pId) {
            PelicanModManager::removeModMetadata($server, $pId);
        }

        $this->invalidateMetadataCache();
        $this->versionsCache = [];
        $this->removeInstalledRowsFromCaches($server, $projectIdsToRemove, $filenamesToRemove);
        $this->clearInstalledSelection();

        Notification::make()
            ->title(trans('pelican-mod-manager::strings.notifications.uninstall_success'))
            ->body('Successfully uninstalled selected mods.')
            ->success()
            ->send();
    }
}
