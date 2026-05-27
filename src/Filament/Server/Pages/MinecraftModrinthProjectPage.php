<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class MinecraftModrinthProjectPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    /** @var array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedModsMetadata = null;

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    public bool $isImporting = false;
    public int $importProgress = 0;
    public string $importStatus = '';
    public ?string $importFilePath = null;
    public ?array $importFilesToDownload = null;
    public ?array $importDownloadedMods = [];

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && ModrinthProjectType::fromServer($server);
    }

    public static function getNavigationLabel(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = ModrinthProjectType::fromServer($server);

        return $type?->getLabel() ?? 'Modrinth';
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(trans('minecraft-modrinth::strings.page.view_all')),
            'installed' => Tab::make(trans('minecraft-modrinth::strings.page.view_installed')),
        ];
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();

            $this->installedModsMetadata = MinecraftModrinth::getInstalledModsMetadata($server);
        }

        return $this->installedModsMetadata;
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata();

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->versionsCache[$projectId] = MinecraftModrinth::getProjectVersions($projectId, $server);
        }

        return $this->versionsCache[$projectId];
    }

    /**
     * @param  array<int, array{primary: bool, filename: string, url: string}>  $files
     * @return array{primary: bool, filename: string, url: string}|null
     */
    protected function getPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     *
     * @throws Exception
     */
    private function performInstallOrUpdate(
        Server $server,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null
    ): void {
        $fileRepository = app(DaemonFileRepository::class);

        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            throw new Exception('Server does not support Modrinth mods or plugins');
        }

        $folder = $type->getFolder();

        $fileRepository
            ->setServer($server)
            ->pull($primaryFile['url'], $folder)
            ->throw();

        $saved = MinecraftModrinth::saveModMetadata(
            $server,
            $record['project_id'],
            $record['slug'],
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? null
        );

        if (!$saved) {
            if (!$oldFilename || $oldFilename !== $safeNewFilename) {
                try {
                    Http::daemon($server->node)
                        ->post("/api/servers/{$server->uuid}/files/delete", [
                            'root' => '/',
                            'files' => [$folder . '/' . $safeNewFilename],
                        ])
                        ->throw();
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$folder . '/' . $oldFilename],
                    ])
                    ->throw();
            } catch (Exception $deleteException) {
                try {
                    Http::daemon($server->node)
                        ->post("/api/servers/{$server->uuid}/files/delete", [
                            'root' => '/',
                            'files' => [$folder . '/' . $safeNewFilename],
                        ])
                        ->throw();
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }

                if ($installedMod && !MinecraftModrinth::saveModMetadata(
                    $server,
                    $record['project_id'],
                    $installedMod['project_slug'],
                    $installedMod['project_title'],
                    $installedMod['version_id'],
                    $installedMod['version_number'],
                    $oldFilename,
                    $installedMod['author'] ?? null
                )) {
                    report(new Exception('Failed to restore old mod metadata during rollback'));
                }

                throw $deleteException;
            }
        }
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                /** @var Server $server */
                $server = Filament::getTenant();

                if ($this->activeTab === 'installed') {
                    $type = ModrinthProjectType::fromServer($server);
                    if (!$type) {
                        return new LengthAwarePaginator([], 0, 20, $page);
                    }

                    $fileRepository = app(DaemonFileRepository::class);
                    $installedModsMetadata = $this->getInstalledModsMetadata();

                    // 1. Get actual files in the mods/plugins folder
                    try {
                        $files = $fileRepository->setServer($server)->getDirectory($type->getFolder());
                        if (isset($files['error'])) {
                            throw new Exception($files['error']);
                        }
                    } catch (Exception $e) {
                        report($e);
                        $files = [];
                    }

                    // Filter for .jar files
                    $jarFiles = collect($files)
                        ->filter(fn ($file) => $file['mime'] === 'application/jar' || str($file['name'])->lower()->endsWith('.jar'))
                        ->toArray();

                    $combinedItems = [];

                    // 2. Map disk files to Modrinth metadata or synthetic local entries
                    foreach ($jarFiles as $file) {
                        $filename = $file['name'];
                        $matchedMetadata = collect($installedModsMetadata)
                            ->first(fn ($mod) => strcasecmp($mod['filename'], $filename) === 0);

                        if ($matchedMetadata) {
                            $combinedItems[] = [
                                'project_id' => $matchedMetadata['project_id'],
                                'slug' => $matchedMetadata['project_slug'],
                                'title' => $matchedMetadata['project_title'],
                                'filename' => $filename,
                                'installed_at' => $matchedMetadata['installed_at'],
                                'author' => $matchedMetadata['author'] ?? 'Unknown',
                                'is_local' => false,
                                'metadata' => $matchedMetadata,
                            ];
                        } else {
                            $combinedItems[] = [
                                'project_id' => 'local_' . md5($filename),
                                'slug' => '',
                                'title' => basename($filename, '.jar'),
                                'description' => 'Local mod file (' . $filename . ')',
                                'icon_url' => null,
                                'author' => 'Unknown',
                                'downloads' => 0,
                                'date_modified' => $file['modified'] ?? '',
                                'project_type' => $type->value,
                                'unavailable' => true,
                                'filename' => $filename,
                                'is_local' => true,
                            ];
                        }
                    }

                    // 3. Apply search query if present
                    if ($search) {
                        $searchLower = strtolower($search);
                        $combinedItems = array_values(array_filter($combinedItems, function (array $item) use ($searchLower) {
                            return str_contains(strtolower($item['title']), $searchLower)
                                || str_contains(strtolower($item['slug'] ?? ''), $searchLower)
                                || str_contains(strtolower($item['filename']), $searchLower);
                        }));
                    }

                    $totalItems = count($combinedItems);

                    // 4. Slice items for current page
                    $perPage = 20;
                    $pageItems = array_slice($combinedItems, ($page - 1) * $perPage, $perPage);

                    // 5. Query Modrinth API in bulk for Modrinth-managed items on this page
                    $pageRegisteredMods = collect($pageItems)->filter(fn ($item) => empty($item['is_local']))->toArray();
                    $pageLocalMods = collect($pageItems)->filter(fn ($item) => !empty($item['is_local']))->toArray();

                    $resolvedRegistered = [];
                    if (!empty($pageRegisteredMods)) {
                        $ids = collect($pageRegisteredMods)->pluck('project_id')->unique()->values()->toArray();
                        try {
                            $response = Http::asJson()
                                ->timeout(10)
                                ->connectTimeout(5)
                                ->throw()
                                ->get('https://api.modrinth.com/v2/projects', [
                                    'ids' => json_encode($ids),
                                ])
                                ->json();

                            $modrinthMap = [];
                            if (is_array($response)) {
                                foreach ($response as $proj) {
                                    if (isset($proj['id'])) {
                                        $modrinthMap[$proj['id']] = $proj;
                                    }
                                }
                            }

                            foreach ($pageRegisteredMods as $item) {
                                $projectId = $item['project_id'];
                                $mod = $item['metadata'];
                                if (isset($modrinthMap[$projectId])) {
                                    $project = $modrinthMap[$projectId];
                                    $project['project_id'] = $project['id'];
                                    if (isset($project['updated']) && !isset($project['date_modified'])) {
                                        $project['date_modified'] = $project['updated'];
                                    }
                                    if (isset($mod['author']) && !isset($project['author'])) {
                                        $project['author'] = $mod['author'];
                                    }
                                    $project['filename'] = $item['filename'];
                                    $project['is_local'] = false;
                                    $resolvedRegistered[] = $project;
                                } else {
                                    // Fallback if mod is no longer available on Modrinth
                                    $resolvedRegistered[] = [
                                        'project_id' => $mod['project_id'],
                                        'slug' => $mod['project_slug'],
                                        'title' => $mod['project_title'],
                                        'description' => trans('minecraft-modrinth::strings.page.mod_unavailable'),
                                        'icon_url' => null,
                                        'author' => $mod['author'] ?? '',
                                        'downloads' => 0,
                                        'date_modified' => $mod['installed_at'],
                                        'project_type' => '',
                                        'unavailable' => true,
                                        'filename' => $item['filename'],
                                        'is_local' => false,
                                    ];
                                }
                            }
                        } catch (Exception $e) {
                            report($e);
                            // Fallback to metadata details for display
                            foreach ($pageRegisteredMods as $item) {
                                $mod = $item['metadata'];
                                $resolvedRegistered[] = [
                                    'project_id' => $mod['project_id'],
                                    'slug' => $mod['project_slug'],
                                    'title' => $mod['project_title'],
                                    'description' => 'Modrinth mod (' . $item['filename'] . ')',
                                    'icon_url' => null,
                                    'author' => $mod['author'] ?? 'Unknown',
                                    'downloads' => 0,
                                    'date_modified' => $mod['installed_at'],
                                    'project_type' => '',
                                    'unavailable' => true,
                                    'filename' => $item['filename'],
                                    'is_local' => false,
                                ];
                            }
                        }
                    }

                    // 6. Merge resolved Modrinth projects and local mods back together in the original page order
                    $finalRecords = [];
                    foreach ($pageItems as $item) {
                        if ($item['is_local']) {
                            $finalRecords[] = $item;
                        } else {
                            $matchedResolved = collect($resolvedRegistered)
                                ->first(fn ($res) => $res['project_id'] === $item['project_id'] && strcasecmp($res['filename'], $item['filename']) === 0);
                            if ($matchedResolved) {
                                $finalRecords[] = $matchedResolved;
                            } else {
                                // Ultimate fallback
                                $mod = $item['metadata'];
                                $finalRecords[] = [
                                    'project_id' => $mod['project_id'],
                                    'slug' => $mod['project_slug'],
                                    'title' => $mod['project_title'],
                                    'description' => 'Modrinth mod (' . $item['filename'] . ')',
                                    'icon_url' => null,
                                    'author' => $mod['author'] ?? 'Unknown',
                                    'downloads' => 0,
                                    'date_modified' => $mod['installed_at'],
                                    'project_type' => '',
                                    'unavailable' => true,
                                    'filename' => $item['filename'],
                                    'is_local' => false,
                                ];
                            }
                        }
                    }

                    return new LengthAwarePaginator($finalRecords, $totalItems, $perPage, $page);
                } else {
                    $response = MinecraftModrinth::getProjects($server, $page, $search);

                    return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
                }
            })
            ->paginated([20])
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn (array $record) => (strlen($record['description']) > 120) ? substr($record['description'], 0, 120).'...' : $record['description']),
                TextColumn::make('author')
                    ->url(fn ($state) => "https://modrinth.com/user/$state", true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable'])) {
                    return null;
                }

                return "https://modrinth.com/{$record['project_type']}/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('minecraft-modrinth::strings.actions.versions'))
                    ->visible(fn (array $record) => empty($record['unavailable']))
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $installedVersionId = $installedMod['version_id'] ?? null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? []);

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('minecraft-modrinth::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('minecraft-modrinth::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('minecraft-modrinth::strings.version.published'))
                                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('minecraft-modrinth::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('minecraft-modrinth::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                                $sectionIconColor = 'success';
                            } else {
                                $headerAction = Action::make('install_version_' . $versionIndex)
                                    ->label(trans('minecraft-modrinth::strings.actions.install'))
                                    ->icon('tabler-download')
                                    ->visible($primaryFile !== null)
                                    ->action(function () use ($record, $versionData, $primaryFile) {
                                        try {
                                            /** @var Server $server */
                                            $server = Filament::getTenant();

                                            if (!$primaryFile) {
                                                throw new Exception('No downloadable file found');
                                            }

                                            $installedMod = $this->getInstalledMod($record['project_id']);

                                            $this->performInstallOrUpdate($server, $record, $versionData, $primaryFile, $installedMod);

                                            $this->installedModsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('minecraft-modrinth::strings.notifications.install_success'))
                                                ->body(trans('minecraft-modrinth::strings.notifications.install_success_body', [
                                                    'name' => $record['title'],
                                                    'version' => $versionData['version_number'],
                                                ]))
                                                ->success()
                                                ->send();
                                        } catch (Exception $exception) {
                                            report($exception);

                                            $this->installedModsMetadata = null;
                                            $this->versionsCache = [];
                                            $this->js('$wire.$refresh()');

                                            Notification::make()
                                                ->title(trans('minecraft-modrinth::strings.notifications.install_failed'))
                                                ->body(trans('minecraft-modrinth::strings.notifications.install_failed_body'))
                                                ->danger()
                                                ->send();
                                        }
                                    });
                                $sectionIcon = null;
                                $sectionIconColor = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor($sectionIconColor);
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                Action::make('install_latest')
                    ->iconButton()
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('minecraft-modrinth::strings.actions.install_latest'))
                    ->visible(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        return is_null($installedMod);
                    })
                    ->action(function (array $record) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $versions = MinecraftModrinth::getProjectVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $record, $latestVersion, $primaryFile);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('minecraft-modrinth::strings.notifications.install_success'))
                                ->body(trans('minecraft-modrinth::strings.notifications.install_success_body', [
                                    'name' => $record['title'],
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('minecraft-modrinth::strings.notifications.install_failed'))
                                ->body(trans('minecraft-modrinth::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('minecraft-modrinth::strings.actions.update'))
                    ->visible(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            return false;
                        }

                        return $installedMod['version_id'] !== $versions[0]['id'];
                    })
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modrinth::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $versions = $this->getCachedVersions($record['project_id']);

                        return trans('minecraft-modrinth::strings.modals.update_description', [
                            'old_version' => $installedMod['version_number'] ?? 'unknown',
                            'new_version' => $versions[0]['version_number'] ?? 'unknown',
                        ]);
                    })
                    ->action(function (array $record) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $installedMod = $this->getInstalledMod($record['project_id']);

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $versions = MinecraftModrinth::getProjectVersions($record['project_id'], $server);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('minecraft-modrinth::strings.notifications.update_success'))
                                ->body(trans('minecraft-modrinth::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('minecraft-modrinth::strings.notifications.update_failed'))
                                ->body(trans('minecraft-modrinth::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('installed')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('minecraft-modrinth::strings.actions.installed'))
                    ->disabled()
                    ->visible(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            return true;
                        }

                        return $installedMod['version_id'] === $versions[0]['id'];
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('minecraft-modrinth::strings.actions.uninstall'))
                    ->visible(function (array $record) {
                        if (!empty($record['is_local'])) {
                            return true;
                        }
                        return !is_null($this->getInstalledMod($record['project_id']));
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('minecraft-modrinth::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('minecraft-modrinth::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            if (!empty($record['is_local'])) {
                                $filename = $record['filename'];
                            } else {
                                $installedMod = $this->getInstalledMod($record['project_id']);
                                if (!$installedMod) {
                                    throw new Exception('Mod not found in metadata');
                                }
                                $filename = $installedMod['filename'];
                            }

                            $safeFilename = $this->validateFilename($filename);

                            $type = ModrinthProjectType::fromServer($server);
                            if (!$type) {
                                throw new Exception('Server does not support Modrinth mods or plugins');
                            }

                            $folder = $type->getFolder();

                            Http::daemon($server->node)
                                ->post("/api/servers/{$server->uuid}/files/delete", [
                                    'root' => '/',
                                    'files' => [$folder . '/' . $safeFilename],
                                ])
                                ->throw();

                            if (empty($record['is_local'])) {
                                $metadataRemoved = MinecraftModrinth::removeModMetadata($server, $record['project_id']);

                                if (!$metadataRemoved) {
                                    Log::warning('Failed to remove mod metadata after successful file deletion', [
                                        'project_id' => $record['project_id'],
                                        'server_id' => $server->id,
                                    ]);

                                    if (is_array($this->installedModsMetadata)) {
                                        $this->installedModsMetadata = array_values(
                                            array_filter($this->installedModsMetadata, fn ($mod) => $mod['project_id'] !== $record['project_id'])
                                        );
                                    }

                                    unset($this->versionsCache[$record['project_id']]);
                                } else {
                                    $this->installedModsMetadata = null;
                                    $this->versionsCache = [];
                                }
                            } else {
                                $this->installedModsMetadata = null;
                                $this->versionsCache = [];
                            }

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('minecraft-modrinth::strings.notifications.uninstall_success'))
                                ->body(trans('minecraft-modrinth::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            if ($this->activeTab === 'installed') {
                                $this->js('$wire.$refresh()');
                            }

                            Notification::make()
                                ->title(trans('minecraft-modrinth::strings.notifications.uninstall_failed'))
                                ->body(trans('minecraft-modrinth::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            return [];
        }

        $folder = $type->getFolder();

        return [
            Action::make('open_folder')
                ->tooltip(fn () => trans('minecraft-modrinth::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
            Action::make('upload_mod')
                ->label(trans('minecraft-modrinth::strings.actions.upload_mod'))
                ->tooltip(trans('minecraft-modrinth::strings.actions.upload_mod_tooltip'))
                ->icon('tabler-upload')
                ->color('primary')
                ->schema([
                    FileUpload::make('file')
                        ->label(trans('minecraft-modrinth::strings.page.mod_file'))
                        ->required(),
                ])
                ->action(function (array $data) use ($server) {
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
                            $possiblePaths = [
                                storage_path('app/' . $filePath),
                                storage_path('app/public/' . $filePath),
                                storage_path($filePath),
                            ];
                            foreach ($possiblePaths as $p) {
                                if (file_exists($p)) {
                                    $absolutePath = $p;
                                    break;
                                }
                            }
                        }

                        if (!$absolutePath || !file_exists($absolutePath)) {
                            throw new Exception('Uploaded file not found.');
                        }

                        $type = ModrinthProjectType::fromServer($server);
                        if (!$type) {
                            throw new Exception('Server does not support Modrinth mods or plugins');
                        }

                        $folder = $type->getFolder();

                        if (Str::endsWith(strtolower($filePath), ['.jar'])) {
                            $filename = basename($absolutePath);
                            $safeFilename = $this->validateFilename($filename);
                            $sha1 = sha1_file($absolutePath);

                            $jarContent = file_get_contents($absolutePath);
                            if ($jarContent === false) {
                                throw new Exception('Failed to read uploaded jar file.');
                            }

                            $fileRepository = app(DaemonFileRepository::class);
                            $fileRepository
                                ->setServer($server)
                                ->putContent($folder . '/' . $safeFilename, $jarContent)
                                ->throw();

                            if ($disk) {
                                try {
                                    $disk->delete($filePath);
                                } catch (Exception $e) {}
                            }

                            $resolved = false;
                            $projectName = basename($safeFilename, '.jar');
                            $projectSlug = '';
                            $projectId = '';
                            $versionId = '';
                            $versionNumber = '';
                            $author = null;

                            if ($sha1) {
                                try {
                                    $versionResponse = Http::asJson()
                                        ->timeout(10)
                                        ->connectTimeout(5)
                                        ->get("https://api.modrinth.com/v2/version_file/{$sha1}?algorithm=sha1");

                                    if ($versionResponse->successful()) {
                                        $versionData = $versionResponse->json();
                                        $pId = $versionData['project_id'] ?? null;
                                        $vId = $versionData['id'] ?? null;
                                        $vNum = $versionData['version_number'] ?? null;

                                        if ($pId && $vId) {
                                            $projectResponse = Http::asJson()
                                                ->timeout(10)
                                                ->connectTimeout(5)
                                                ->get("https://api.modrinth.com/v2/project/{$pId}");

                                            if ($projectResponse->successful()) {
                                                $projectData = $projectResponse->json();
                                                $projectId = $pId;
                                                $projectSlug = $projectData['slug'] ?? '';
                                                $projectName = $projectData['title'] ?? $projectName;
                                                $versionId = $vId;
                                                $versionNumber = $vNum ?? '';

                                                MinecraftModrinth::saveModMetadata(
                                                    $server,
                                                    $projectId,
                                                    $projectSlug,
                                                    $projectName,
                                                    $versionId,
                                                    $versionNumber,
                                                    $safeFilename,
                                                    $author
                                                );
                                                $resolved = true;
                                            }
                                        }
                                    }
                                } catch (Exception $apiException) {
                                    Log::warning('Modrinth API upload hash lookup failed: ' . $apiException->getMessage());
                                }
                            }

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];
                            $this->js('$wire.$refresh()');

                            if ($resolved) {
                                Notification::make()
                                    ->title(trans('minecraft-modrinth::strings.notifications.install_success'))
                                    ->body("Successfully uploaded, verified against Modrinth, and registered as a managed mod: {$projectName}")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title(trans('minecraft-modrinth::strings.notifications.install_success'))
                                    ->body("Successfully uploaded as local mod: {$safeFilename}")
                                    ->success()
                                    ->send();
                            }
                        } else {
                            $tempDest = storage_path('app/modpack_import_' . $server->id . '.zip');
                            if (file_exists($tempDest)) {
                                unlink($tempDest);
                            }

                            if (!copy($absolutePath, $tempDest)) {
                                throw new Exception('Failed to prepare pack file.');
                            }

                            if ($disk) {
                                try {
                                    $disk->delete($filePath);
                                } catch (Exception $e) {
                                    // Ignore
                                }
                            }

                            $this->isImporting = true;
                            $this->importProgress = 5;
                            $this->importStatus = 'Initializing modpack installation...';
                            $this->importFilePath = $tempDest;
                            $this->importFilesToDownload = null;
                            $this->importDownloadedMods = [];

                            Notification::make()
                                ->title(trans('minecraft-modrinth::strings.actions.upload_mod'))
                                ->body('Modpack installation started. Please keep this page open to track progress.')
                                ->info()
                                ->send();
                        }

                    } catch (Exception $exception) {
                        report($exception);

                        Notification::make()
                            ->title(trans('minecraft-modrinth::strings.notifications.mrpack_upload_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function processImportTick(): void
    {
        if (!$this->isImporting || !$this->importFilePath) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        try {
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

                    $filesToDownload[] = [
                        'url' => $fileEntry['downloads'][0],
                        'path' => $targetPath,
                        'sha1' => $fileEntry['hashes']['sha1'] ?? null,
                    ];
                }

                $this->importFilesToDownload = $filesToDownload;
                $this->importDownloadedMods = [];
                $this->importProgress = 15;
                $this->importStatus = 'Overrides extracted. Downloading mods...';
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
                if (!empty($this->importDownloadedMods)) {
                    $chunks = array_chunk($this->importDownloadedMods, 50);
                    $versionDataMap = [];

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
                    $projectDetailsMap = [];
                    if (!empty($projectIds)) {
                        $projectChunks = array_chunk($projectIds, 50);
                        foreach ($projectChunks as $pChunk) {
                            $idsParam = json_encode($pChunk);
                            try {
                                $projResponse = Http::asJson()
                                    ->timeout(10)
                                    ->connectTimeout(5)
                                    ->throw()
                                    ->get('https://api.modrinth.com/v2/projects', [
                                        'ids' => $idsParam,
                                    ])
                                    ->json();

                                if (is_array($projResponse)) {
                                    foreach ($projResponse as $proj) {
                                        if (isset($proj['id'])) {
                                            $projectDetailsMap[$proj['id']] = $proj;
                                        }
                                    }
                                }
                            } catch (Exception $apiException) {
                                Log::warning('Modrinth API bulk projects lookup failed: ' . $apiException->getMessage());
                            }
                        }
                    }

                    foreach ($modResolutions as $sha1 => $res) {
                        $pId = $res['project_id'];
                        if (isset($projectDetailsMap[$pId])) {
                            $proj = $projectDetailsMap[$pId];
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
                    MinecraftModrinth::saveModsMetadata($server, $resolvedMods);
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

            $this->installedModsMetadata = null;
            $this->versionsCache = [];
            $this->js('$wire.$refresh()');

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.notifications.mrpack_upload_success'))
                ->body(trans('minecraft-modrinth::strings.notifications.mrpack_upload_success_body'))
                ->success()
                ->send();

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

            $this->installedModsMetadata = null;
            $this->versionsCache = [];
            $this->js('$wire.$refresh()');

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.notifications.mrpack_upload_failed'))
                ->body(trans('minecraft-modrinth::strings.notifications.mrpack_upload_failed_body', [
                    'error' => $exception->getMessage(),
                ]))
                ->danger()
                ->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = ModrinthProjectType::fromServer($server);

        return $schema
            ->components([
                TextEntry::make('import_progress')
                    ->hidden(fn () => !$this->isImporting)
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->state(fn () => new HtmlString(<<<HTML
                         <div wire:poll.1s="processImportTick" class="modpack-import-card">
                             <style>
                                 .modpack-import-card {
                                     background: linear-gradient(135deg, rgba(20, 20, 25, 0.95) 0%, rgba(30, 30, 40, 0.95) 100%);
                                     border: 1px solid rgba(255, 255, 255, 0.08);
                                     border-radius: 16px;
                                     padding: 24px;
                                     box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.3);
                                     margin-bottom: 24px;
                                     font-family: inherit;
                                     display: flex;
                                     flex-direction: column;
                                     gap: 16px;
                                     position: relative;
                                     overflow: hidden;
                                 }
                                 
                                 .modpack-import-card::before {
                                     content: '';
                                     position: absolute;
                                     top: 0;
                                     left: 0;
                                     right: 0;
                                     height: 3px;
                                     background: linear-gradient(90deg, #10b981, #3b82f6);
                                     opacity: 0.8;
                                 }

                                 .modpack-import-header {
                                     display: flex;
                                     justify-content: space-between;
                                     align-items: center;
                                 }

                                 .modpack-import-title-group {
                                     display: flex;
                                     align-items: center;
                                     gap: 12px;
                                 }

                                 .modpack-import-spinner {
                                     width: 10px;
                                     height: 10px;
                                     background-color: #10b981;
                                     border-radius: 50%;
                                     position: relative;
                                     box-shadow: 0 0 10px #10b981;
                                 }

                                 .modpack-import-spinner::after {
                                     content: '';
                                     position: absolute;
                                     width: 10px;
                                     height: 10px;
                                     background-color: #10b981;
                                     border-radius: 50%;
                                     top: 0;
                                     left: 0;
                                     animation: modpack-pulse 1.8s infinite ease-in-out;
                                 }

                                 @keyframes modpack-pulse {
                                     0% {
                                         transform: scale(1);
                                         opacity: 1;
                                     }
                                     100% {
                                         transform: scale(2.8);
                                         opacity: 0;
                                     }
                                 }

                                 .modpack-import-title {
                                     font-size: 15px;
                                     font-weight: 600;
                                     color: #f3f4f6;
                                     letter-spacing: -0.01em;
                                 }

                                 .modpack-import-percentage {
                                     font-size: 14px;
                                     font-weight: 700;
                                     color: #10b981;
                                     font-family: monospace;
                                     background: rgba(16, 185, 129, 0.1);
                                     padding: 2px 8px;
                                     border-radius: 6px;
                                     border: 1px solid rgba(16, 185, 129, 0.15);
                                 }

                                 .modpack-import-progress-container {
                                     width: 100%;
                                     height: 10px;
                                     background: rgba(255, 255, 255, 0.05);
                                     border-radius: 9999px;
                                     overflow: hidden;
                                     border: 1px solid rgba(255, 255, 255, 0.03);
                                     box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
                                 }

                                 .modpack-import-progress-bar {
                                     height: 100%;
                                     background: linear-gradient(90deg, #10b981 0%, #3b82f6 100%);
                                     border-radius: 9999px;
                                     box-shadow: 0 0 12px rgba(16, 185, 129, 0.4);
                                     transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                                 }

                                 .modpack-import-status {
                                     font-size: 13px;
                                     color: #9ca3af;
                                     display: flex;
                                     align-items: center;
                                     gap: 6px;
                                 }

                                 .modpack-import-status-label {
                                     font-weight: 500;
                                     color: #d1d5db;
                                 }
                             </style>

                             <div class="modpack-import-header">
                                 <div class="modpack-import-title-group">
                                     <div class="modpack-import-spinner"></div>
                                     <span class="modpack-import-title">Installing Modpack</span>
                                 </div>
                                 <span class="modpack-import-percentage">{$this->importProgress}%</span>
                             </div>
                             
                             <div class="modpack-import-progress-container">
                                 <div class="modpack-import-progress-bar" style="width: {$this->importProgress}%"></div>
                             </div>
                             
                             <div class="modpack-import-status">
                                 Status: <span class="modpack-import-status-label">{$this->importStatus}</span>
                             </div>
                         </div>
                    HTML)),
                Grid::make(3)
                    ->schema([
                        TextEntry::make('Minecraft Version')
                            ->state(fn () => MinecraftModrinth::getMinecraftVersion($server) ?? trans('minecraft-modrinth::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('Loader')
                            ->state(fn () => MinecraftModrinth::getLoaderFromServer($server)['display_name'] ?? trans('minecraft-modrinth::strings.page.unknown'))
                            ->icon(fn () => new HtmlString(MinecraftModrinth::getLoaderFromServer($server)['icon'] ?? ''))
                            ->badge(),
                        TextEntry::make('installed')
                            ->label(fn () => trans('minecraft-modrinth::strings.page.installed', ['type' => $type?->getLabel() ?? 'Modrinth']))
                            ->state(function (DaemonFileRepository $fileRepository) use ($server, $type) {
                                try {
                                    if (!$type) {
                                        return trans('minecraft-modrinth::strings.page.unknown');
                                    }

                                    $files = $fileRepository->setServer($server)->getDirectory($type->getFolder());

                                    if (isset($files['error'])) {
                                        throw new Exception($files['error']);
                                    }

                                    return collect($files)
                                        ->filter(fn ($file) => $file['mime'] === 'application/jar' || str($file['name'])->lower()->endsWith('.jar'))
                                        ->count();
                                } catch (Exception $exception) {
                                    report($exception);

                                    return trans('minecraft-modrinth::strings.page.unknown');
                                }
                            })
                            ->badge(),
                    ]),
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }
}
