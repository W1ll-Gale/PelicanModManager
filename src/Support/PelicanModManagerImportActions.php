<?php

namespace MrBytesized\PelicanModManager\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use Exception;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use MrBytesized\PelicanModManager\Facades\PelicanModManager;
use MrBytesized\PelicanModManager\Services\ModManagerFileService;
use MrBytesized\PelicanModManager\Services\ModpackService;
use ZipArchive;

class PelicanModManagerImportActions
{
    public static function processImportTick(): Closure
    {
        return function (): void {
        if (!$this->isImporting || !$this->importFilePath) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            $type = ModrinthProjectType::fromServer($server);
            if (!$type) {
                throw new Exception('Server does not support Modrinth mods or plugins');
            }

            $fileRepository = app(DaemonFileRepository::class);
            $fileRepository->setServer($server);

            // Phase 1: Parse & Extract Overrides
            if ($this->importFilesToDownload === null) {
                $this->importStatus = 'Reading pack index and extracting overrides...';
                $this->importProgress = 10;

                $zip = new ZipArchive();
                if ($zip->open($this->importFilePath) !== true) {
                    throw new Exception('Failed to open zip archive.');
                }

                $indexJsonPath = null;
                $basePath = '';
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if ($entryName === 'modrinth.index.json' || $entryName === 'index.json') {
                        $indexJsonPath = $entryName;
                        $basePath = '';
                        break;
                    } elseif (str_ends_with($entryName, '/modrinth.index.json')) {
                        $indexJsonPath = $entryName;
                        $basePath = substr($entryName, 0, -strlen('modrinth.index.json'));
                        break;
                    } elseif (str_ends_with($entryName, '/index.json')) {
                        $indexJsonPath = $entryName;
                        $basePath = substr($entryName, 0, -strlen('index.json'));
                        break;
                    }
                }

                if ($indexJsonPath === null) {
                    $zip->close();
                    throw new Exception('Missing modrinth.index.json in .mrpack.');
                }

                $indexJsonContent = $zip->getFromName($indexJsonPath);
                if ($indexJsonContent === false) {
                    $zip->close();
                    throw new Exception('Failed to read index content.');
                }

                $indexData = json_decode($indexJsonContent, true);
                if (!is_array($indexData) || !isset($indexData['files']) || !is_array($indexData['files'])) {
                    $zip->close();
                    throw new Exception('Invalid index format.');
                }

                // Extract overrides
                $overrides = [];
                $serverOverrides = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $entryName = $stat['name'];

                    if (str_ends_with($entryName, '/')) {
                        continue;
                    }

                    if (str_contains($entryName, '..') || str_contains($entryName, "\0")) {
                        continue;
                    }

                    if ($basePath !== '') {
                        if (!str_starts_with($entryName, $basePath)) {
                            continue;
                        }
                        $relativeEntryName = substr($entryName, strlen($basePath));
                    } else {
                        $relativeEntryName = $entryName;
                    }

                    if (str_starts_with($relativeEntryName, 'overrides/')) {
                        $target = substr($relativeEntryName, strlen('overrides/'));
                        $overrides[$target] = $entryName;
                    } elseif (str_starts_with($relativeEntryName, 'server-overrides/')) {
                        $target = substr($relativeEntryName, strlen('server-overrides/'));
                        $serverOverrides[$target] = $entryName;
                    }
                }

                $allOverrides = array_merge($overrides, $serverOverrides);
                foreach ($allOverrides as $targetPath => $zipEntryName) {
                    $content = $zip->getFromName($zipEntryName);
                    if ($content !== false) {
                        $fileRepository->putContent($targetPath, $content)->throw();
                    }
                }

                $zip->close();

                // Prepare files to download
                $filesToDownload = [];
                $installedSha1s = $this->getInstalledModrinthSha1s($server);
                $skippedInstalled = 0;
                foreach ($indexData['files'] as $fileEntry) {
                    if (!isset($fileEntry['path']) || !isset($fileEntry['downloads']) || !is_array($fileEntry['downloads'])) {
                        continue;
                    }

                    if (isset($fileEntry['env']['server']) && $fileEntry['env']['server'] === 'unsupported') {
                        continue;
                    }

                    $targetPath = $fileEntry['path'];
                    if (str_contains($targetPath, '..') || str_contains($targetPath, "\0") || str_starts_with($targetPath, '/') || str_starts_with($targetPath, '\\')) {
                        continue;
                    }

                    $sha1 = isset($fileEntry['hashes']['sha1']) && is_string($fileEntry['hashes']['sha1'])
                        ? strtolower($fileEntry['hashes']['sha1'])
                        : null;
                    if ($sha1 && isset($installedSha1s[$sha1])) {
                        $skippedInstalled++;
                        continue;
                    }

                    $filesToDownload[] = [
                        'url' => $fileEntry['downloads'][0],
                        'path' => $targetPath,
                        'sha1' => $sha1,
                    ];
                }

                $this->importFilesToDownload = $filesToDownload;
                $this->importDownloadedMods = [];
                $this->importSkippedInstalledMods = $skippedInstalled;
                $this->importProgress = 15;
                $this->importStatus = $skippedInstalled > 0
                    ? "Skipped {$skippedInstalled} already installed mod" . ($skippedInstalled === 1 ? '. Downloading changes...' : 's. Downloading changes...')
                    : 'Overrides extracted. Downloading mods...';
                return;
            }

            // Phase 2: Download files (3 at a time)
            if (!empty($this->importFilesToDownload)) {
                $chunk = array_splice($this->importFilesToDownload, 0, 3);
                foreach ($chunk as $fileToDownload) {
                    $fileRepository->pull($fileToDownload['url'], dirname($fileToDownload['path']))->throw();

                    if ($fileToDownload['sha1']) {
                        $this->importDownloadedMods[] = [
                            'sha1' => $fileToDownload['sha1'],
                            'filename' => basename($fileToDownload['path']),
                        ];
                    }
                }

                // Update progress
                $remaining = count($this->importFilesToDownload);
                $total = count($this->importDownloadedMods) + $remaining;
                $downloadProgress = $total > 0 ? (1 - ($remaining / $total)) : 1;

                $this->importProgress = (int)(15 + $downloadProgress * 65);
                $this->importStatus = "Downloading mods (" . (count($this->importDownloadedMods)) . "/{$total})...";
                return;
            }

            // Phase 3: Resolve metadata & save in bulk
            if ($this->importProgress < 90) {
                $this->importStatus = 'Resolving metadata against Modrinth API...';
                $this->importProgress = 90;

                $resolvedMods = [];
                $filesToDelete = [];
                if (!empty($this->importDownloadedMods)) {
                    $versionDataMap = app(ModpackService::class)->resolveDownloadedFilesBySha1($this->importDownloadedMods);
                    $chunks = [];

                    foreach ($chunks as $c) {
                        $hashes = array_column($c, 'sha1');
                        try {
                            $response = Http::asJson()
                                ->timeout(10)
                                ->connectTimeout(5)
                                ->throw()
                                ->post('https://api.modrinth.com/v2/version_files', [
                                    'hashes' => $hashes,
                                    'algorithm' => 'sha1',
                                ])
                                ->json();

                            if (is_array($response)) {
                                foreach ($response as $hash => $version) {
                                    $versionDataMap[$hash] = $version;
                                }
                            }
                        } catch (Exception $apiException) {
                            Log::warning('Modrinth API bulk hash lookup failed: ' . $apiException->getMessage());
                        }
                    }

                    $projectIds = [];
                    $modResolutions = [];

                    foreach ($this->importDownloadedMods as $mod) {
                        $sha1 = $mod['sha1'];
                        if (isset($versionDataMap[$sha1])) {
                            $v = $versionDataMap[$sha1];
                            $projectId = $v['project_id'] ?? null;
                            if ($projectId) {
                                $projectIds[] = $projectId;
                                $modResolutions[$sha1] = [
                                    'version_id' => $v['id'],
                                    'version_number' => $v['version_number'],
                                    'project_id' => $projectId,
                                    'filename' => $mod['filename'],
                                ];
                            }
                        }
                    }

                    $projectIds = array_values(array_unique($projectIds));
                    $projectDetailsMap = app(ModpackService::class)->getProjectsMap($projectIds);

                    $installedByProjectId = collect($this->getInstalledModsMetadata())->keyBy('project_id');

                    foreach ($modResolutions as $sha1 => $res) {
                        $pId = $res['project_id'];
                        if (isset($projectDetailsMap[$pId])) {
                            $proj = $projectDetailsMap[$pId];
                            $existingMod = $installedByProjectId->get($pId);
                            if (
                                is_array($existingMod)
                                && !empty($existingMod['filename'])
                                && strcasecmp($existingMod['filename'], $res['filename']) !== 0
                            ) {
                                $filesToDelete[] = $type->getFolder() . '/' . $this->validateFilename($existingMod['filename']);
                            }
                            $resolvedMods[] = [
                                'project_id' => $pId,
                                'project_slug' => $proj['slug'] ?? '',
                                'project_title' => $proj['title'] ?? '',
                                'version_id' => $res['version_id'],
                                'version_number' => $res['version_number'],
                                'filename' => $res['filename'],
                            ];
                        }
                    }
                }

                if (!empty($resolvedMods)) {
                    PelicanModManager::saveModsMetadata($server, $resolvedMods);
                }
                if (!empty($filesToDelete)) {
                    try {
                        app(ModManagerFileService::class)->deleteFiles($server, array_values(array_unique($filesToDelete)));
                    } catch (Exception $deleteException) {
                        Log::warning('Failed to remove replaced modpack jars: ' . $deleteException->getMessage());
                    }
                }

                $this->importProgress = 95;
                $this->importStatus = 'Finalizing modpack installation...';
                return;
            }

            // Phase 4: Finalize
            if (file_exists($this->importFilePath)) {
                try {
                    unlink($this->importFilePath);
                } catch (Exception $e) {
                    // Ignore
                }
            }

            $this->isImporting = false;
            $this->importProgress = 100;
            $this->importFilePath = null;
            $this->importFilesToDownload = null;
            $this->importDownloadedMods = [];

            $this->invalidateMetadataCache();
            $this->versionsCache = [];
            $this->rebuildInstalledCachesNow($server);

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.mrpack_upload_success'))
                ->body($this->importSkippedInstalledMods > 0
                    ? trans('pelican-mod-manager::strings.notifications.mrpack_upload_success_body') . " Skipped {$this->importSkippedInstalledMods} already installed mod" . ($this->importSkippedInstalledMods === 1 ? '.' : 's.')
                    : trans('pelican-mod-manager::strings.notifications.mrpack_upload_success_body'))
                ->success()
                ->send();
            $this->importSkippedInstalledMods = 0;

        } catch (Exception $exception) {
            report($exception);

            if ($this->importFilePath && file_exists($this->importFilePath)) {
                try {
                    unlink($this->importFilePath);
                } catch (Exception $e) {
                    // Ignore
                }
            }

            $this->isImporting = false;
            $this->importProgress = 0;
            $this->importFilePath = null;
            $this->importFilesToDownload = null;
            $this->importDownloadedMods = [];
            $this->importSkippedInstalledMods = 0;

            $this->invalidateMetadataCache();
            $this->versionsCache = [];
            $this->rebuildInstalledCachesNow($server);

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.mrpack_upload_failed'))
                ->body(trans('pelican-mod-manager::strings.notifications.mrpack_upload_failed_body', [
                    'error' => $exception->getMessage(),
                ]))
                ->danger()
                ->send();
        }
        };
    }
}
