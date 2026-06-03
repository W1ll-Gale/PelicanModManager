<?php

namespace MrBytesized\PelicanModManager\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use MrBytesized\PelicanModManager\Facades\PelicanModManager;
use MrBytesized\PelicanModManager\Services\ModManagerFileService;
use MrBytesized\PelicanModManager\Services\ModrinthClient;
use ZipArchive;

class PelicanModManagerHeaderActions
{
    public static function actions(): Closure
    {
        return function (): array {
        /** @var Server $server */
        $server = Filament::getTenant();

        if (!ModrinthProjectType::fromServer($server)) {
            return [];
        }

        return [
            // Uninstall confirmation modal — CSS-hidden, triggered via openConfirmUninstall() Livewire method.
            Action::make('confirm_uninstall')
                ->extraAttributes(['style' => 'display:none !important'])
                ->requiresConfirmation()
                ->modalHeading(fn (array $arguments) => trans('pelican-mod-manager::strings.modals.uninstall_heading'))
                ->modalDescription(fn (array $arguments) => trans('pelican-mod-manager::strings.modals.uninstall_description', ['name' => $arguments['title'] ?? '']))
                ->action(function (array $arguments) {
                    $projectId = $arguments['projectId'] ?? '';
                    $filename  = $arguments['filename'] ?? '';
                    $isLocal   = !empty($arguments['isLocal']);
                    $title     = $arguments['title'] ?? '';

                    try {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        if (!$isLocal) {
                            $installedMod = $this->getInstalledMod($projectId);
                            if ($installedMod) {
                                $filename = $installedMod['filename'];
                            }
                        }

                        $safeFilename = $this->validateFilename($filename);

                        $type = ModrinthProjectType::fromServer($server);
                        if (!$type) {
                            throw new Exception('Server does not support Modrinth mods or plugins');
                        }

                        Http::daemon($server->node)
                            ->post("/api/servers/{$server->uuid}/files/delete", [
                                'root' => '/',
                                'files' => [$type->getFolder() . '/' . $safeFilename],
                            ])
                            ->throw();

                        if (!$isLocal) {
                            PelicanModManager::removeModMetadata($server, $projectId);
                        }

                        $this->invalidateMetadataCache();
                        $this->versionsCache = [];
                        $this->removeInstalledRowsFromCaches(
                            $server,
                            !$isLocal ? [$projectId] : [],
                            [$safeFilename]
                        );

                        Notification::make()
                            ->title(trans('pelican-mod-manager::strings.notifications.uninstall_success'))
                            ->body(trans('pelican-mod-manager::strings.notifications.uninstall_success_body', ['name' => $title]))
                            ->success()
                            ->send();
                    } catch (Exception $exception) {
                        report($exception);
                        $this->invalidateMetadataCache();
                        $this->versionsCache = [];
                        Notification::make()
                            ->title(trans('pelican-mod-manager::strings.notifications.uninstall_failed'))
                            ->body(trans('pelican-mod-manager::strings.notifications.uninstall_failed_body'))
                            ->danger()
                            ->send();
                    }
                }),
            // Page action for the browse tab's Version Selection button.
            // CSS-hidden from the header; triggered via openBrowseVersions() Livewire method.
            Action::make('browse_versions')
                ->label(trans('pelican-mod-manager::strings.actions.versions'))
                ->extraAttributes(['style' => 'display:none !important'])
                ->modalSubmitAction(false)
                ->schema(fn (array $arguments) => $this->buildVersionsSections(
                    $arguments['projectId'] ?? '',
                    ['project_id' => $arguments['projectId'] ?? '', 'title' => $arguments['title'] ?? '']
                )),
            // Upload mod — CSS-hidden page action triggered via openUploadModal() from the
            // installed tab filter bar. Hidden from the header so it doesn't appear on
            // the browse tab; the openUploadModal() wrapper guards against activeTab mismatch.
            Action::make('upload_mod')
                ->label(trans('pelican-mod-manager::strings.actions.upload_mod'))
                ->tooltip(trans('pelican-mod-manager::strings.actions.upload_mod_tooltip'))
                ->icon('tabler-upload')
                ->color('primary')
                ->extraAttributes(['style' => 'display:none !important'])
                ->schema([
                    FileUpload::make('file')
                        ->label(trans('pelican-mod-manager::strings.page.mod_file'))
                        ->preserveFilenames()
                        ->getUploadedFileNameForStorageUsing(fn ($file) => $this->validateFilename($file->getClientOriginalName()))
                        ->required(),
                ])
                ->action(function (array $data) {
                    /** @var Server $server */
                    $server = Filament::getTenant();
                    try {
                        $filePath = $data['file'];
                        if (!Str::endsWith(strtolower($filePath), ['.mrpack', '.zip', '.jar'])) {
                            throw new Exception('Invalid file type. Only .jar, .mrpack, and .zip files are accepted.');
                        }
                        $absolutePath = null;
                        $disk = null;
                        foreach (['public', 'local'] as $diskName) {
                            if (Storage::disk($diskName)->exists($filePath)) {
                                $absolutePath = Storage::disk($diskName)->path($filePath);
                                $disk = Storage::disk($diskName);
                                break;
                            }
                        }
                        if (!$absolutePath) {
                            foreach ([storage_path('app/' . $filePath), storage_path('app/public/' . $filePath), storage_path($filePath)] as $p) {
                                if (file_exists($p)) { $absolutePath = $p; break; }
                            }
                        }
                        if (!$absolutePath || !file_exists($absolutePath)) throw new Exception('Uploaded file not found.');
                        $type = ModrinthProjectType::fromServer($server);
                        if (!$type) throw new Exception('Server does not support Modrinth mods or plugins');
                        $folder = $type->getFolder();
                        if (Str::endsWith(strtolower($filePath), ['.jar'])) {
                            $filename = basename($absolutePath);
                            $safeFilename = $this->validateFilename($filename);
                            $sha1 = sha1_file($absolutePath);
                            $jarContent = file_get_contents($absolutePath);
                            if ($jarContent === false) throw new Exception('Failed to read uploaded jar file.');
                            $fileRepository = app(DaemonFileRepository::class);
                            $fileRepository->setServer($server)->putContent($folder . '/' . $safeFilename, $jarContent)->throw();
                            if ($disk) { try { $disk->delete($filePath); } catch (Exception $e) {} }
                            $resolved = false;
                            $projectName = basename($safeFilename, '.jar');
                            $projectSlug = $projectId = $versionId = $versionNumber = '';
                            $author = null;
                            $projectIconUrl = null;
                            $projectType = $type->value;
                            $versionData = [];
                            if ($sha1) {
                                try {
                                    $versionMap = app(ModrinthClient::class)->getVersionsByHashes([$sha1], 'sha1');
                                    $vd = $versionMap[strtolower($sha1)] ?? null;
                                    if (is_array($vd)) {
                                        $pId = $vd['project_id'] ?? null;
                                        $vId = $vd['id'] ?? null;
                                        if ($pId && $vId) {
                                            $projectMap = app(ModrinthClient::class)->getProjectsMap([$pId]);
                                            $pd = $projectMap[$pId] ?? null;
                                            if (is_array($pd)) {
                                                $projectId = $pId; $projectSlug = $pd['slug'] ?? ''; $projectName = $pd['title'] ?? $projectName;
                                                $versionId = $vId; $versionNumber = $vd['version_number'] ?? '';
                                                $projectIconUrl = $pd['icon_url'] ?? null;
                                                $projectType = $pd['project_type'] ?? $projectType;
                                                $versionData = $vd;
                                                $existingMod = $this->getInstalledMod($projectId);
                                                if ($existingMod && strcasecmp($existingMod['filename'] ?? '', $safeFilename) !== 0) {
                                                    try {
                                                        app(ModManagerFileService::class)->deleteFiles($server, [$folder . '/' . $this->validateFilename($existingMod['filename'] ?? '')]);
                                                    } catch (Exception $deleteException) {
                                                        Log::warning('Failed to remove replaced uploaded jar: ' . $deleteException->getMessage());
                                                    }
                                                }
                                                PelicanModManager::saveModMetadata($server, $projectId, $projectSlug, $projectName, $versionId, $versionNumber, $safeFilename, $author);
                                                $resolved = true;
                                            }
                                        }
                                    }
                                } catch (Exception $ae) { Log::warning('Modrinth upload hash lookup: ' . $ae->getMessage()); }
                            }
                            $this->invalidateMetadataCache(); $this->versionsCache = [];
                            if ($resolved) {
                                $this->patchInstalledCachesAfterInstallOrUpdate(
                                    $server,
                                    [
                                        'project_id' => $projectId,
                                        'slug' => $projectSlug ?: $projectId,
                                        'title' => $projectName,
                                        'author' => $author,
                                        'icon_url' => $projectIconUrl,
                                        'project_type' => $projectType,
                                    ],
                                    $versionData,
                                    $safeFilename
                                );
                            } else {
                                $this->addLocalInstalledRowToCaches($server, $type, $safeFilename);
                            }
                            $this->installedDataReady = true;
                            $this->installedEnriched = true;
                            $this->rebuildInstalledCachesNow($server, $type);
                            Notification::make()->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                                ->body($resolved ? "Uploaded, verified and registered: {$projectName}" : "Uploaded as local mod: {$safeFilename}")->success()->send();
                        } else {
                            $tempDest = storage_path('app/modpack_import_' . $server->id . '.zip');
                            if (file_exists($tempDest)) unlink($tempDest);
                            if (!copy($absolutePath, $tempDest)) throw new Exception('Failed to prepare pack file.');
                            if ($disk) { try { $disk->delete($filePath); } catch (Exception $e) {} }
                            $this->isImporting = true; $this->importProgress = 5;
                            $this->importStatus = 'Initializing modpack installation...';
                            $this->importFilePath = $tempDest; $this->importFilesToDownload = null; $this->importDownloadedMods = []; $this->importSkippedInstalledMods = 0;
                            Notification::make()->title(trans('pelican-mod-manager::strings.actions.upload_mod'))
                                ->body('Modpack installation started. Please keep this page open.')->info()->send();
                        }
                    } catch (Exception $exception) {
                        report($exception);
                        Notification::make()->title(trans('pelican-mod-manager::strings.notifications.mrpack_upload_failed'))
                            ->body($exception->getMessage())->danger()->send();
                    }
                }),
            // Export modpack — triggered from the installed filter bar via mountAction('export_modpack').
            // Hidden from the page header; always mountable.
            Action::make('export_modpack')
                ->label('Export modpack')
                ->extraAttributes(['style' => 'display:none !important'])
                ->action(function () {
                    /** @var Server $server */
                    $server = Filament::getTenant();
                    $type = ModrinthProjectType::fromServer($server);
                    if (!$type) return;

                    $folder = $type->getFolder();
                    $selectedProjectIds = array_values(array_filter(
                        $this->exportModpackProjectIds ?: $this->getInstalledBulkSelectionIds()
                    ));
                    $selectedRecords = empty($selectedProjectIds)
                        ? collect()
                        : $this->getInstalledRecordsByIds($selectedProjectIds);
                    $metadata = $this->getInstalledModsMetadata();
                    if (!empty($selectedProjectIds)) {
                        $metadata = array_values(array_filter(
                            $metadata,
                            fn ($mod) => in_array($mod['project_id'] ?? '', $selectedProjectIds, true)
                        ));
                    }

                    // Build modrinth.index.json files array
                    $indexFiles = [];
                    foreach ($metadata as $mod) {
                        $versions = $this->getCachedVersions($mod['project_id']);
                        $ver = collect($versions)->firstWhere('id', $mod['version_id']);
                        if (!$ver) continue;
                        $pf = $this->getPrimaryFile($ver['files'] ?? []);
                        if (!$pf) continue;
                        $indexFiles[] = [
                            'path'      => $folder . '/' . ($pf['filename'] ?? basename($pf['url'])),
                            'hashes'    => $pf['hashes'] ?? [],
                            'env'       => ['client' => 'optional', 'server' => 'required'],
                            'downloads' => [$pf['url']],
                            'fileSize'  => $pf['size'] ?? 0,
                        ];
                    }

                    $mcVersion = PelicanModManager::getMinecraftVersion($server) ?? '1.0.0';
                    $loaderInfo = PelicanModManager::getLoaderFromServer($server);
                    $loaderKey = strtolower($loaderInfo['name'] ?? 'fabric') . '-loader';
                    $deps = array_filter(['minecraft' => $mcVersion]);

                    $indexJson = json_encode([
                        'formatVersion' => 1,
                        'game'          => 'minecraft',
                        'versionId'     => '1.0.0',
                        'name'          => ($server->name ?? 'Server') . ' Modpack',
                        'summary'       => 'Exported from Pelican Mod Manager',
                        'files'         => $indexFiles,
                        'dependencies'  => $deps,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                    // Build zip in memory
                    $tmpPath = sys_get_temp_dir() . '/pmm_export_' . $server->uuid . '_' . time() . '.mrpack';
                    $zip = new ZipArchive();
                    if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                        Notification::make()->title('Export failed')->body('Could not create archive.')->danger()->send();
                        return;
                    }
                    $zip->addFromString('modrinth.index.json', $indexJson);

                    // Add local (untracked) jar files to overrides/mods/
                    $managedFiles = collect($metadata)->pluck('filename')
                        ->map(fn ($f) => strtolower(str_replace('.disabled', '', $f)))->toArray();
                    $selectedLocalFiles = $selectedRecords
                        ->filter(fn ($record) => !empty($record['is_local']))
                        ->pluck('filename')
                        ->map(fn ($f) => strtolower(str_replace('.disabled', '', $f)))
                        ->values()
                        ->toArray();
                    try {
                        $fileRepository = app(DaemonFileRepository::class);
                        $dirFiles = $fileRepository->setServer($server)->getDirectory($folder);
                        foreach ($dirFiles as $df) {
                            $fn = $df['name'];
                            $clean = strtolower(str_replace('.disabled', '', $fn));
                            if (!empty($selectedProjectIds)) {
                                if (!in_array($clean, $selectedLocalFiles, true)) continue;
                            } elseif (in_array($clean, $managedFiles, true)) {
                                continue;
                            }
                            if (!str_ends_with($clean, '.jar')) continue;
                            try {
                                $content = Http::daemon($server->node)
                                    ->get("/api/servers/{$server->uuid}/files/contents", ['file' => $folder . '/' . $fn])
                                    ->body();
                                if ($content) {
                                    $zip->addFromString('overrides/' . $folder . '/' . $fn, $content);
                                }
                            } catch (Exception $e) { /* skip undownloadable files */ }
                        }
                    } catch (Exception $e) { /* skip if listing fails */ }

                    $zip->close();
                    $this->exportModpackProjectIds = [];

                    return response()->download($tmpPath, 'modpack.mrpack', [
                        'Content-Type' => 'application/zip',
                    ])->deleteFileAfterSend(true);
                }),
        ];
        };
    }
}
