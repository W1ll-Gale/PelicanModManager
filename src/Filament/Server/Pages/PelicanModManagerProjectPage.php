<?php

namespace MrBytesized\PelicanModManager\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use MrBytesized\PelicanModManager\Facades\PelicanModManager;
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

class PelicanModManagerProjectPage extends Page implements HasTable
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

    protected static ?string $slug = 'mods';

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

    public function getTableRecordKey(mixed $record): string
    {
        if (is_array($record) && isset($record['project_id'])) {
            return (string) $record['project_id'];
        }
        return parent::getTableRecordKey($record);
    }

    protected function getDynamicStyles(): string
    {
        $sharedCss = <<<CSS
            /* FORCE TABLE ELEMENTS TO BLOCK LAYOUT */
            .fi-ta-table,
            .fi-ta-table tbody,
            table,
            tbody {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* HIDE COLUMN HEADERS */
            .fi-ta-content thead,
            table thead,
            .fi-ta-table thead,
            thead {
                display: none !important;
            }

            /* REMOVE DEFAULT CONTAINER SHADOWS */
            .fi-ta-content {
                background: transparent !important;
                box-shadow: none !important;
                border: none !important;
            }

            /* EACH ROW AS A CARD */
            .fi-ta-row {
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
                background-color: #1a1a1e !important;
                border: 1px solid #2d2f34 !important;
                border-radius: 12px !important;
                padding: 16px 20px !important;
                margin-bottom: 14px !important;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            .fi-ta-row:hover {
                border-color: #4b4f56 !important;
                background-color: #202024 !important;
                transform: translateY(-1px);
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
            }

            /* CELL RESET — block layout so content fills width naturally,
               no dependency on Filament's internal wrapper class names */
            .fi-ta-row > td {
                border: none !important;
                padding: 0 !important;
                background: transparent !important;
                display: block !important;
                height: auto !important;
                min-width: 0 !important;
                box-sizing: border-box !important;
                overflow: visible !important;
            }

            /* Filament's column link/text wrappers — ensure they don't constrain width */
            .fi-ta-row > td > a,
            .fi-ta-row > td > .fi-ta-col,
            .fi-ta-row .fi-ta-text,
            .fi-ta-row .fi-ta-text-item {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* HIDE VISUALLY UNUSED LABELS ON SMALL SCREENS OR HIDDEN TEXT */
            div:has(> .modrinth-custom-styles),
            div:has(> div > .modrinth-custom-styles),
            .fi-in-text:has(.modrinth-custom-styles),
            .fi-in-entry-wrp:has(.modrinth-custom-styles) {
                display: none !important;
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            /* --- SHARED STYLINGS --- */
            .fi-ta-row svg {
                color: #a1a1aa !important;
            }

            .fi-ta-row .fi-btn {
                border-radius: 8px !important;
                padding: 8px 16px !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                text-transform: none !important;
                letter-spacing: normal !important;
                box-shadow: none !important;
                transition: all 0.2s ease !important;
                border: 1px solid transparent !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-success[disabled],
            .fi-ta-row .fi-btn.fi-btn-color-success:disabled {
                background-color: rgba(16, 185, 129, 0.1) !important;
                border: 1px solid rgba(16, 185, 129, 0.2) !important;
                color: #10b981 !important;
                opacity: 0.9 !important;
                cursor: default !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-success:not([disabled]) {
                background-color: #10b981 !important;
                color: #ffffff !important;
                box-shadow: 0 0 12px rgba(16, 185, 129, 0.2) !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-success:not([disabled]):hover {
                background-color: #0d9488 !important;
                box-shadow: 0 0 16px rgba(16, 185, 129, 0.4) !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-warning {
                background-color: #f59e0b !important;
                color: #ffffff !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-warning:hover {
                background-color: #d97706 !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-info,
            .fi-ta-row .fi-btn.fi-btn-color-gray {
                background-color: rgba(255, 255, 255, 0.05) !important;
                border: 1px solid rgba(255, 255, 255, 0.08) !important;
                color: #e4e4e7 !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-info:hover,
            .fi-ta-row .fi-btn.fi-btn-color-gray:hover {
                background-color: rgba(255, 255, 255, 0.1) !important;
                border-color: rgba(255, 255, 255, 0.16) !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-danger {
                background-color: rgba(239, 68, 68, 0.1) !important;
                border: 1px solid rgba(239, 68, 68, 0.2) !important;
                color: #ef4444 !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-danger:hover {
                background-color: rgba(239, 68, 68, 0.2) !important;
                border-color: rgba(239, 68, 68, 0.3) !important;
            }
        CSS;

        if ($this->activeTab === 'installed') {
            // td[1]=checkbox, td[2]=Mod, td[3]=Version, td[4]=⇄+Toggle, td[last]=🗑+⋮
            $tabCss = <<<CSS
                /* --- INSTALLED TAB CELLS --- */

                /* Checkbox */
                .fi-ta-row > td:first-child {
                    display: flex !important;
                    flex-shrink: 0 !important;
                    width: auto !important;
                    margin-right: 12px !important;
                    align-items: center !important;
                    justify-content: center !important;
                }

                /* Mod — takes all remaining space */
                .fi-ta-row > td:nth-child(2) {
                    flex: 1 !important;
                    min-width: 0 !important;
                    align-self: center !important;
                }

                /* Version + filename — fixed width, sits after the Mod column */
                .fi-ta-row > td:nth-child(3) {
                    flex: 0 0 220px !important;
                    width: 220px !important;
                    margin-left: 24px !important;
                    align-self: center !important;
                }

                /* Change-version + toggle — pushed to far right via margin-left:auto, grouped with Delete */
                .fi-ta-row > td:nth-child(4) {
                    display: flex !important;
                    flex-shrink: 0 !important;
                    align-items: center !important;
                    gap: 6px !important;
                    margin-left: auto !important;
                    padding-left: 16px !important;
                }

                /* Delete + three-dot — immediately follows toggle group */
                .fi-ta-row > td:last-child {
                    display: flex !important;
                    flex-shrink: 0 !important;
                    flex-direction: row !important;
                    align-items: center !important;
                    gap: 4px !important;
                    padding-left: 4px !important;
                }

                /* Disabled-row grayscale */
                .pmm-row-disabled {
                    filter: grayscale(1) !important;
                    opacity: 0.45 !important;
                }
            CSS;
        } else {
            // Browse Mods tab ('all')
            // The HtmlString owns the entire card layout including the right panel
            // (Versions button + Install/Installed/Update button + stats).
            // The Filament actions td is hidden — actions are triggered via mountTableAction.
            $tabCss = <<<CSS
                /* --- BROWSE TAB CELLS --- */

                /* Row — stretch so right panel can align stats to the bottom */
                .fi-ta-row {
                    align-items: stretch !important;
                }

                /* Title td — takes all remaining space */
                .fi-ta-row > td:first-child {
                    flex: 1 !important;
                    min-width: 0 !important;
                }

                /* Unused data columns + actions td — all hidden */
                .fi-ta-row > td:nth-child(2),
                .fi-ta-row > td:nth-child(3),
                .fi-ta-row > td:last-child {
                    display: none !important;
                }
            CSS;
        }

        return $sharedCss . $tabCss;
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = ModrinthProjectType::fromServer($server);
        $tabLabel = $type === ModrinthProjectType::Plugin 
            ? trans('pelican-mod-manager::strings.page.browse_plugins') 
            : trans('pelican-mod-manager::strings.page.browse_mods');

        return [
            'all' => Tab::make($tabLabel),
            'installed' => Tab::make(trans('pelican-mod-manager::strings.page.view_installed')),
        ];
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();

            $this->installedModsMetadata = PelicanModManager::getInstalledModsMetadata($server);
        }

        return $this->installedModsMetadata;
    }

    /**
     * @return array<int, mixed>
     */
    protected function getInstalledModsResolvedList(Server $server, ModrinthProjectType $type): array
    {
        $cacheKey = "modrinth_installed_resolved_list_" . $server->uuid;
        
        return cache()->remember($cacheKey, now()->addSeconds(30), function () use ($server, $type) {
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

            // Filter for .jar and .jar.disabled files
            $jarFiles = collect($files)
                ->filter(function ($file) {
                    $name = strtolower($file['name']);
                    return str_ends_with($name, '.jar') || str_ends_with($name, '.jar.disabled');
                })
                ->toArray();

            $combinedItems = [];

            // 2. Map disk files to Modrinth metadata or synthetic local entries
            foreach ($jarFiles as $file) {
                $filename = $file['name'];
                $isDisabled = str_ends_with(strtolower($filename), '.disabled');
                $cleanFilename = str_replace('.disabled', '', $filename);
                
                $matchedMetadata = collect($installedModsMetadata)
                    ->first(fn ($mod) => strcasecmp(str_replace('.disabled', '', $mod['filename']), $cleanFilename) === 0);

                if ($matchedMetadata) {
                    $combinedItems[] = [
                        'project_id' => $matchedMetadata['project_id'],
                        'slug' => $matchedMetadata['project_slug'],
                        'title' => $matchedMetadata['project_title'],
                        'filename' => $filename,
                        'installed_at' => $matchedMetadata['installed_at'],
                        'author' => $matchedMetadata['author'] ?? 'Unknown',
                        'is_local' => false,
                        'is_disabled' => $isDisabled,
                        'metadata' => $matchedMetadata,
                    ];
                } else {
                    $combinedItems[] = [
                        'project_id' => 'local_' . md5($filename),
                        'slug' => '',
                        'title' => basename($cleanFilename, '.jar'),
                        'description' => 'Local mod file (' . $filename . ')',
                        'icon_url' => null,
                        'author' => 'Unknown',
                        'downloads' => 0,
                        'date_modified' => $file['modified'] ?? '',
                        'project_type' => $type->value,
                        'unavailable' => true,
                        'filename' => $filename,
                        'is_local' => true,
                        'is_disabled' => $isDisabled,
                    ];
                }
            }

            // 3. Query Modrinth API in bulk for all managed items
            $registeredMods = collect($combinedItems)->filter(fn ($item) => empty($item['is_local']))->toArray();
            $resolvedRegistered = [];
            
            if (!empty($registeredMods)) {
                $ids = collect($registeredMods)->pluck('project_id')->unique()->values()->toArray();
                try {
                    $response = Http::asJson()
                        ->timeout(10)
                        ->connectTimeout(5)
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

                    // Fetch team members to get real author usernames + avatars
                    // (the /v2/projects bulk endpoint does not return author info)
                    $teamIds = collect($modrinthMap)->pluck('team')->filter()->unique()->values()->toArray();
                    $teamAuthorMap = [];
                    if (!empty($teamIds)) {
                        try {
                            $teamsResp = Http::asJson()
                                ->timeout(10)
                                ->connectTimeout(5)
                                ->get('https://api.modrinth.com/v2/teams', [
                                    'ids' => json_encode($teamIds),
                                ])
                                ->json();
                            if (is_array($teamsResp)) {
                                foreach ($teamsResp as $members) {
                                    if (empty($members) || !is_array($members)) continue;
                                    $teamId = $members[0]['team_id'] ?? null;
                                    if (!$teamId) continue;
                                    // Owner role or first member
                                    $owner = collect($members)->firstWhere('role', 'Owner') ?? $members[0];
                                    $teamAuthorMap[$teamId] = [
                                        'username'   => $owner['user']['username'] ?? null,
                                        'avatar_url' => $owner['user']['avatar_url'] ?? null,
                                    ];
                                }
                            }
                        } catch (Exception $e) {
                            report($e);
                        }
                    }

                    foreach ($registeredMods as $item) {
                        $projectId = $item['project_id'];
                        $mod = $item['metadata'];
                        if (isset($modrinthMap[$projectId])) {
                            $project = $modrinthMap[$projectId];
                            $project['project_id'] = $project['id'];
                            if (isset($project['updated']) && !isset($project['date_modified'])) {
                                $project['date_modified'] = $project['updated'];
                            }
                            // Prefer team-sourced author (most reliable), fall back to metadata
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
                            $resolvedRegistered[] = [
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
                                'filename' => $item['filename'],
                                'is_local' => false,
                            ];
                        }
                    }
                } catch (Exception $e) {
                    report($e);
                    // Fallback to metadata details
                    foreach ($registeredMods as $item) {
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

            // 4. Merge resolved Modrinth projects and local mods back together
            $finalRecords = [];
            foreach ($combinedItems as $item) {
                if ($item['is_local']) {
                    $finalRecords[] = $item;
                } else {
                    $matchedResolved = collect($resolvedRegistered)
                        ->first(fn ($res) => $res['project_id'] === $item['project_id'] && strcasecmp($res['filename'], $item['filename']) === 0);
                    if ($matchedResolved) {
                        $finalRecords[] = $matchedResolved;
                    } else {
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

            return $finalRecords;
        });
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
    protected function buildVersionsSections(string $projectId, array $record = []): array
    {
        if (empty($record['project_id'])) {
            $record['project_id'] = $projectId;
        }

        $versions = $this->getCachedVersions($projectId);
        $installedMod = $this->getInstalledMod($projectId);
        $installedVersionId = $installedMod['version_id'] ?? null;

        $sections = [];
        foreach ($versions as $versionIndex => $versionData) {
            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? []);

            $sectionComponents = [
                TextEntry::make('type_' . $versionIndex)
                    ->label(trans('pelican-mod-manager::strings.version.type'))
                    ->state($versionData['version_type'] ?? '')
                    ->badge()
                    ->color(match ($versionData['version_type'] ?? '') {
                        'release' => 'success',
                        'beta' => 'warning',
                        'alpha' => 'danger',
                        default => 'gray',
                    }),
                TextEntry::make('downloads_' . $versionIndex)
                    ->label(trans('pelican-mod-manager::strings.version.downloads'))
                    ->state($versionData['downloads'] ?? 0)
                    ->icon('tabler-download')
                    ->numeric(),
                TextEntry::make('published_' . $versionIndex)
                    ->label(trans('pelican-mod-manager::strings.version.published'))
                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
            ];

            if (!empty($versionData['changelog'])) {
                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                    ->label(trans('pelican-mod-manager::strings.version.changelog'))
                    ->state($versionData['changelog'])
                    ->markdown();
            }

            if (($versionData['id'] ?? null) === $installedVersionId) {
                $headerAction = Action::make('installed_' . $versionIndex)
                    ->label(trans('pelican-mod-manager::strings.actions.installed'))
                    ->icon('tabler-check')
                    ->color('success')
                    ->disabled();
                $sectionIcon = 'tabler-check';
                $sectionIconColor = 'success';
            } else {
                $headerAction = Action::make('install_version_' . $versionIndex)
                    ->label(trans('pelican-mod-manager::strings.actions.install'))
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
                                ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.install_success_body', [
                                    'name' => $record['title'] ?? $record['project_id'],
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
                                ->title(trans('pelican-mod-manager::strings.notifications.install_failed'))
                                ->body(trans('pelican-mod-manager::strings.notifications.install_failed_body'))
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
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->versionsCache[$projectId] = PelicanModManager::getProjectVersions($projectId, $server);
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
        cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);

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

        $saved = PelicanModManager::saveModMetadata(
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

                if ($installedMod && !PelicanModManager::saveModMetadata(
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

                    $combinedItems = $this->getInstalledModsResolvedList($server, $type);

                    // 1. Apply search query if present
                    if ($search) {
                        $searchLower = strtolower($search);
                        $combinedItems = array_values(array_filter($combinedItems, function (array $item) use ($searchLower) {
                            return str_contains(strtolower($item['title']), $searchLower)
                                || str_contains(strtolower($item['slug'] ?? ''), $searchLower)
                                || str_contains(strtolower($item['filename']), $searchLower);
                        }));
                    }

                    // Apply status filter if present
                    $statusFilter = $this->tableFilters['status']['value'] ?? 'all';
                    if ($statusFilter && $statusFilter !== 'all') {
                        $combinedItems = array_values(array_filter($combinedItems, function (array $item) use ($statusFilter) {
                            $isEnabled = empty($item['is_disabled']);
                            if ($statusFilter === 'enabled') {
                                return $isEnabled;
                            } elseif ($statusFilter === 'disabled') {
                                return !$isEnabled;
                            } elseif ($statusFilter === 'updates') {
                                if (!empty($item['is_local'])) {
                                    return false;
                                }
                                $versions = $this->getCachedVersions($item['project_id']);
                                if (empty($versions)) {
                                    return false;
                                }
                                $installedMod = $item['metadata'];
                                return $installedMod['version_id'] !== $versions[0]['id'];
                            }
                            return true;
                        }));
                    }

                    // Apply sorting if a sort column is active
                    $sortColumn = $this->getTableSortColumn();
                    $sortDirection = $this->getTableSortDirection();
                    if ($sortColumn) {
                        $descending = $sortDirection === 'desc';
                        $combinedItems = collect($combinedItems)
                            ->sortBy(function ($item) use ($sortColumn) {
                                switch ($sortColumn) {
                                    case 'title':
                                        return strtolower($item['title'] ?? '');
                                    case 'author':
                                        return strtolower($item['author'] ?? '');
                                    case 'downloads':
                                        return (int)($item['downloads'] ?? 0);
                                    case 'date_modified':
                                        $dateStr = !empty($item['is_local']) 
                                            ? ($item['date_modified'] ?? '') 
                                            : ($item['metadata']['installed_at'] ?? '');
                                        return $dateStr ? Carbon::parse($dateStr)->timestamp : 0;
                                    default:
                                        return strtolower($item['title'] ?? '');
                                }
                            }, SORT_REGULAR, $descending)
                            ->values()
                            ->toArray();
                    }

                    $totalItems = count($combinedItems);

                    // Return ALL installed items on a single page (pagination disabled for installed tab)
                    return new LengthAwarePaginator($combinedItems, $totalItems, max($totalItems, 1), 1);
                } else {
                    $sortColumn = $this->getTableSortColumn();
                    $sortDirection = $this->getTableSortDirection();
                    $response = PelicanModManager::getProjects($server, $page, $search, $sortColumn, $sortDirection);

                    return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
                }
            })
            ->paginated($this->activeTab === 'installed' ? false : [20])
            ->recordClasses(fn (array $record) => !empty($record['is_disabled']) ? 'pmm-row-disabled' : null)
            ->columns([
                TextColumn::make('title')
                    ->label(fn () => $this->activeTab === 'installed' ? 'Mod' : 'Title')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->formatStateUsing(function ($state, $record) {
                        $title = e($record['title'] ?? $state ?? '');
                        $author = e($record['author'] ?? 'Unknown');
                        $iconUrl = $record['icon_url'] ?? null;
                        
                        // Safe icon element: use a styled div+SVG placeholder when no icon_url
                        // (avoids broken HTML from single-quoted SVG inside src='...' attribute)
                        if (!empty($iconUrl)) {
                            $iconEl = "<img src=\"" . e($iconUrl) . "\" style='width:72px;height:72px;border-radius:12px;object-fit:cover;border:1px solid rgba(255,255,255,0.08);flex-shrink:0;' />";
                        } else {
                            $iconEl = "<div style='width:72px;height:72px;border-radius:12px;background:#27272a;border:1px solid rgba(255,255,255,0.08);flex-shrink:0;display:flex;align-items:center;justify-content:center;'>"
                                . "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"32\" height=\"32\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#6b7280\" stroke-width=\"1.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\">"
                                . "<path d=\"M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z\"/>"
                                . "<polyline points=\"3.27 6.96 12 12.01 20.73 6.96\"/>"
                                . "<line x1=\"12\" y1=\"22.08\" x2=\"12\" y2=\"12\"/>"
                                . "</svg></div>";
                        }

                        if ($this->activeTab === 'installed') {
                            $isLocal = !empty($record['is_local']);
                            $slug = e($record['slug'] ?? '');
                            $projectType = e($record['project_type'] ?? 'mod');

                            // Author row: hidden only for local jars
                            if (!$isLocal) {
                                $authorAvatar = $record['author_avatar'] ?? null;
                                if ($author !== 'Unknown' && $author !== '') {
                                    $authorUrl = "https://modrinth.com/user/" . urlencode($author);
                                    $avatarEl = $authorAvatar
                                        ? "<img src=\"" . e($authorAvatar) . "\" style='width:16px;height:16px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,0.1);flex-shrink:0;' />"
                                        : "<div style='width:16px;height:16px;border-radius:50%;background:#3d4451;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#a1a1aa;'>" . strtoupper(mb_substr($author, 0, 1)) . "</div>";
                                    $authorHtml = "<div style='display:flex;align-items:center;gap:6px;'>"
                                        . $avatarEl
                                        . "<a href=\"{$authorUrl}\" target='_blank' style='font-size:12px;color:#a1a1aa;text-decoration:none;' "
                                        . "onmouseover=\"this.style.textDecoration='underline'\" onmouseout=\"this.style.textDecoration='none'\">"
                                        . "{$author} <svg style='display:inline-block;width:10px;height:10px;margin-left:1px;vertical-align:baseline;' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><path d='M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6'/><polyline points='15 3 21 3 21 9'/><line x1='10' y1='14' x2='21' y2='3'/></svg>"
                                        . "</a></div>";
                                } else {
                                    $authorHtml = "<span style='font-size:12px;color:#6b7280;'>Unknown</span>";
                                }
                            } else {
                                $authorHtml = '';
                            }

                            // Mod name: link to Modrinth for non-local mods with a slug
                            if (!$isLocal && $slug) {
                                $modrinthModUrl = "https://modrinth.com/{$projectType}/{$slug}";
                                $titleEl = "<a href=\"{$modrinthModUrl}\" target='_blank' style='font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;' onmouseover=\"this.style.color='#1bd96a'\" onmouseout=\"this.style.color='#ffffff'\">{$title}</a>";
                            } else {
                                $titleEl = "<span style='font-size:16px;font-weight:700;color:#ffffff;'>{$title}</span>";
                            }

                            return new HtmlString("
                                <div style='display:flex;align-items:center;gap:16px;padding:4px 0;width:100%;'>
                                    {$iconEl}
                                    <div style='display:flex;flex-direction:column;gap:6px;'>
                                        {$titleEl}
                                        {$authorHtml}
                                    </div>
                                </div>
                            ");
                        }
                        
                        // Active tab is 'all'
                        $description = e($record['description'] ?? '');
                        $categories = $record['categories'] ?? [];
                        $tagHtml = '';
                        
                        if (!empty($categories) && is_array($categories)) {
                            $tagHtml .= "<div style='display: flex; flex-wrap: wrap; gap: 6px;'>";
                            
                            $showTags = array_slice($categories, 0, 3);
                            foreach ($showTags as $cat) {
                                $catLabel = ucfirst(e($cat));
                                $tagHtml .= "<span style='display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; background-color: #27272a; color: #a1a1aa; border: 1px solid rgba(255,255,255,0.06);'>{$catLabel}</span>";
                            }
                            
                            if (count($categories) > 3) {
                                $remaining = count($categories) - 3;
                                $tagHtml .= "<span style='display: inline-flex; align-items: center; padding: 3px 6px; border-radius: 6px; font-size: 11px; font-weight: 600; background-color: rgba(45,47,52,0.5); color: #a1a1aa; border: 1px solid rgba(255,255,255,0.03);'>+{$remaining}</span>";
                            }
                            
                            $tagHtml .= "</div>";
                        }

                        $authorUrl = "https://modrinth.com/user/" . urlencode($author);
                        $authorHtml = "";
                        if ($author && $author !== 'Unknown') {
                            $authorHtml = "<span style='font-size: 13.5px; color: #a1a1aa; font-weight: 400; margin-left: 2px;'>by <a href='{$authorUrl}' target='_blank' style='color: #c8c9cb; text-decoration: none; font-weight: 500;' onmouseover=\"this.style.textDecoration='underline'; this.style.color='#ffffff'\" onmouseout=\"this.style.textDecoration='none'; this.style.color='#c8c9cb'\">{$author} <svg style='display: inline-block; width: 11px; height: 11px; margin-left: 2px; vertical-align: middle; color: #a1a1aa;' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6'></path><polyline points='15 3 21 3 21 9'></polyline><line x1='10' y1='14' x2='21' y2='3'></line></svg></a></span>";
                        }
                        
                        $descHtml = "";
                        if ($description) {
                            $descHtml = "<div style='font-size: 13.5px; color: #a1a1aa; line-height: 1.5; margin-top: 6px; max-width: 750px; word-break: break-word; white-space: normal !important;'>{$description}</div>";
                        }
                        
                        // Dynamic statistics formatting for 'all' tab
                        $downloads = (int)($record['downloads'] ?? 0);
                        $downloadsFormatted = '';
                        if ($downloads >= 1000000) {
                            $downloadsFormatted = round($downloads / 1000000, 2) . 'M';
                        } elseif ($downloads >= 1000) {
                            $downloadsFormatted = round($downloads / 1000, 1) . 'K';
                        } else {
                            $downloadsFormatted = $downloads;
                        }
                        $downloadsFormattedFull = number_format($downloads) . ' downloads';

                        $follows = (int)($record['follows'] ?? 0);
                        $followsFormatted = '';
                        if ($follows >= 1000000) {
                            $followsFormatted = round($follows / 1000000, 2) . 'M';
                        } elseif ($follows >= 1000) {
                            $followsFormatted = round($follows / 1000, 1) . 'K';
                        } else {
                            $followsFormatted = $follows;
                        }
                        $followsFormattedFull = number_format($follows) . ' followers';

                        $dateModified = $record['date_modified'] ?? null;
                        $dateFormatted = '';
                        $dateTooltip = '';
                        if ($dateModified) {
                            $carbonDate = Carbon::parse($dateModified, 'UTC');
                            $dateFormatted = $carbonDate->diffForHumans();
                            
                            $timezone = function_exists('user') && user() ? (user()->timezone ?? 'UTC') : 'UTC';
                            $dateTooltip = 'Updated ' . $carbonDate->timezone($timezone)->format('M j, Y, g:i A T');
                        }

                        // Determine install state for button rendering
                        $installedMod = $this->getInstalledMod($record['project_id'] ?? '');
                        $hasUpdate = false;
                        if ($installedMod) {
                            $versions = $this->getCachedVersions($record['project_id'] ?? '');
                            $hasUpdate = !empty($versions) && ($installedMod['version_id'] !== ($versions[0]['id'] ?? null));
                        }

                        $projectId = e($record['project_id'] ?? '');
                        $slug = e($record['slug'] ?? '');
                        $projectType = e($record['project_type'] ?? 'mod');
                        $modrinthUrl = "https://modrinth.com/{$projectType}/{$slug}";
                        $isUnavailable = !empty($record['unavailable']);

                        // Shared button base — height locked via line-height so both buttons are identical height
                        $btnBase = "display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:9px 16px; border-radius:8px; font-size:14px; font-weight:600; line-height:1.25; cursor:pointer; white-space:nowrap; box-sizing:border-box; transition:background 0.15s ease, border-color 0.15s ease;";
                        // Install/Installed share a fixed width so they're always the same size
                        $actionBtnWidth = "min-width:120px;";
                        // Both icon SVGs use the same explicit green stroke so colour is identical
                        $greenIconColor = "#1bd96a";

                        // Versions button — use Alpine $wire so Livewire 3 processes it correctly
                        $versionsIconSvg = "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><line x1='8' y1='6' x2='21' y2='6'></line><line x1='8' y1='12' x2='21' y2='12'></line><line x1='8' y1='18' x2='21' y2='18'></line><line x1='3' y1='6' x2='3.01' y2='6'></line><line x1='3' y1='12' x2='3.01' y2='12'></line><line x1='3' y1='18' x2='3.01' y2='18'></line></svg>";
                        $versionsBtn = $isUnavailable ? "" : "
                            <button type='button'
                                x-on:click.stop=\"\$wire.openBrowseVersions('{$projectId}', '{$title}')\"
                                style=\"{$btnBase} border:1px solid rgba(255,255,255,0.15); background:rgba(255,255,255,0.05); color:#c4c4c8;\"
                                onmouseover=\"this.style.background='rgba(255,255,255,0.1)'\"
                                onmouseout=\"this.style.background='rgba(255,255,255,0.05)'\">
                                {$versionsIconSvg} Version Selection
                            </button>";

                        // Install / Installed / Update — fixed-width, consistent green icon
                        $checkSvg = "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='{$greenIconColor}' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'></polyline></svg>";
                        $plusSvg  = "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='{$greenIconColor}' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><line x1='12' y1='5' x2='12' y2='19'></line><line x1='5' y1='12' x2='19' y2='12'></line></svg>";

                        if ($isUnavailable) {
                            $actionBtn = "";
                        } elseif ($installedMod && !$hasUpdate) {
                            // Muted green border+text, but icon keeps the full green
                            $actionBtn = "
                                <div style=\"{$btnBase} {$actionBtnWidth} border:1px solid rgba(27,217,106,0.4); background:transparent; color:rgba(27,217,106,0.55); cursor:default;\">
                                    {$checkSvg}
                                    Installed
                                </div>";
                        } elseif ($installedMod && $hasUpdate) {
                            $actionBtn = "
                                <button type='button' wire:click.stop=\"updateMod('{$projectId}')\"
                                    style=\"{$btnBase} {$actionBtnWidth} border:1px solid #f59e0b; background:transparent; color:#f59e0b;\"
                                    onmouseover=\"this.style.background='rgba(245,158,11,0.1)'\"
                                    onmouseout=\"this.style.background='transparent'\">
                                    <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='23 4 23 10 17 10'></polyline><path d='M20.49 15a9 9 0 1 1-2.12-9.36L23 10'></path></svg>
                                    Update
                                </button>";
                        } else {
                            $actionBtn = "
                                <button type='button'
                                    x-on:click.stop=\"\$wire.installMod('{$projectId}', '{$slug}', '{$title}', '{$author}')\"
                                    style=\"{$btnBase} {$actionBtnWidth} border:1px solid #1bd96a; background:transparent; color:#1bd96a;\"
                                    onmouseover=\"this.style.background='rgba(27,217,106,0.1)'\"
                                    onmouseout=\"this.style.background='transparent'\">
                                    {$plusSvg}
                                    Install
                                </button>";
                        }

                        // Stats — larger text, stacked two rows, right-aligned to match Install button's right edge
                        $statIcon = "width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'";
                        $statsHtml = "
                            <div style='display:flex; flex-direction:column; align-items:flex-end; gap:5px; color:#a1a1aa; font-size:15px; font-weight:500;'>
                                <div style='display:flex; align-items:center; gap:14px;'>
                                    <div style='display:flex; align-items:center; gap:5px;' title='{$downloadsFormattedFull}'>
                                        <svg xmlns=\"http://www.w3.org/2000/svg\" {$statIcon}><path d=\"M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2\"></path><polyline points=\"7 11 12 16 17 11\"></polyline><line x1=\"12\" y1=\"16\" x2=\"12\" y2=\"4\"></line></svg>
                                        <span>{$downloadsFormatted}</span>
                                    </div>
                                    <div style='display:flex; align-items:center; gap:5px;' title='{$followsFormattedFull}'>
                                        <svg xmlns=\"http://www.w3.org/2000/svg\" {$statIcon}><path d=\"M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z\"></path></svg>
                                        <span>{$followsFormatted}</span>
                                    </div>
                                </div>
                                <div style='display:flex; align-items:center; gap:5px;' title='{$dateTooltip}'>
                                    <svg xmlns=\"http://www.w3.org/2000/svg\" {$statIcon}><circle cx=\"12\" cy=\"12\" r=\"10\"></circle><polyline points=\"12 6 12 12 16 14\"></polyline></svg>
                                    <span>{$dateFormatted}</span>
                                </div>
                            </div>
                        ";

                        // Tags row at bottom of content
                        $tagsRow = $tagHtml ? "<div style='margin-top:8px;'>{$tagHtml}</div>" : "";

                        // Title as a Modrinth link
                        $titleLinkStyle = "font-size:16px; font-weight:700; color:#ffffff; text-decoration:none; transition:color 0.15s ease;";
                        $titleHtml = $isUnavailable
                            ? "<span style='{$titleLinkStyle}'>{$title}</span>"
                            : "<a href='{$modrinthUrl}' target='_blank' style='{$titleLinkStyle}' onmouseover=\"this.style.color='#1bd96a'\" onmouseout=\"this.style.color='#ffffff'\">{$title}</a>";

                        // Right panel — buttons on top, stats pushed to bottom and right-aligned
                        // align-items:flex-end on the column makes the stats right-edge match the Install button right-edge
                        $rightPanel = "
                            <div style='flex-shrink:0; display:flex; flex-direction:column; justify-content:space-between; align-items:flex-end; padding-left:20px;'>
                                <div style='display:flex; gap:8px; align-items:center; flex-wrap:nowrap;'>
                                    {$versionsBtn}
                                    {$actionBtn}
                                </div>
                                <div style='padding-top:14px;'>
                                    {$statsHtml}
                                </div>
                            </div>
                        ";

                        return new HtmlString("
                            <div style='display:flex; align-items:stretch; gap:16px; padding:4px 0; box-sizing:border-box;'>
                                <img src='{$iconUrl}' style='width:72px; height:72px; border-radius:12px; object-fit:cover; border:1px solid rgba(255,255,255,0.08); flex-shrink:0; align-self:flex-start;' />
                                <div style='flex:1; min-width:0; display:flex; flex-direction:column; gap:4px;'>
                                    <div style='display:flex; align-items:baseline; gap:8px; flex-wrap:wrap;'>
                                        {$titleHtml}
                                        {$authorHtml}
                                    </div>
                                    {$descHtml}
                                    {$tagsRow}
                                </div>
                                {$rightPanel}
                            </div>
                        ");
                    })
                    ->description(null),
                TextColumn::make('filename')
                    ->label('Version')
                    ->visible(fn () => $this->activeTab === 'installed')
                    ->wrap()
                    ->formatStateUsing(function ($state, $record) {
                        $isLocal = !empty($record['is_local']);
                        $version = $isLocal ? 'Local' : ($record['version_number'] ?? ($record['metadata']['version_number'] ?? 'Unknown'));
                        $filename = e($record['filename'] ?? '');
                        $projectId = e($record['project_id'] ?? '');
                        $slug = e($record['slug'] ?? '');
                        $projectType = e($record['project_type'] ?? 'mod');

                        $title = e($record['title'] ?? '');

                        // Version number links to Modrinth version page; underline appears on hover
                        if (!$isLocal && $slug && $version !== 'Unknown') {
                            $versionEncoded = rawurlencode($version);
                            $versionUrl = "https://modrinth.com/{$projectType}/{$slug}/version/{$versionEncoded}";
                            $versionHtml = "<a href=\"{$versionUrl}\" target='_blank' style='font-size:14px; font-weight:700; color:#f3f4f6; text-decoration:none;' onmouseover=\"this.style.textDecoration='underline'\" onmouseout=\"this.style.textDecoration='none'\">{$version}</a>";
                        } else {
                            $versionHtml = "<span style='font-size:14px; font-weight:700; color:#f3f4f6;'>{$version}</span>";
                        }

                        return new HtmlString("
                            <div style='display:flex; flex-direction:column; gap:4px; align-items:flex-start; text-align:left;'>
                                {$versionHtml}
                                <span style='font-size:11px; color:#6b7280; font-family:monospace; word-break:break-all; max-width:250px;'>{$filename}</span>
                            </div>
                        ");
                    }),
                // Change-version button + enable/disable toggle — installed tab only
                TextColumn::make('is_disabled')
                    ->label('')
                    ->visible(fn () => $this->activeTab === 'installed')
                    ->formatStateUsing(function ($state, $record) {
                        $projectId = e($record['project_id'] ?? '');
                        $title     = e($record['title'] ?? '');
                        $filename  = e($record['filename'] ?? '');
                        $isLocal   = !empty($record['is_local']);
                        $isEnabled = empty($record['is_disabled']);
                        $isEnabledJs = $isEnabled ? 'true' : 'false';

                        // Change-version button (hidden for local mods with no projectId)
                        $changeVersionBtn = '';
                        if (!$isLocal && $projectId) {
                            $changeVersionBtn = "
                                <button type='button'
                                    x-on:click.stop=\"\$wire.openBrowseVersions('{$projectId}', '{$title}')\"
                                    title='Change version'
                                    style='background:none; border:none; cursor:pointer; padding:4px; display:flex; align-items:center; color:#a1a1aa; border-radius:6px; transition:color 0.15s, background 0.15s;'
                                    onmouseover=\"this.style.color='#ffffff'; this.style.background='rgba(255,255,255,0.08)'\"
                                    onmouseout=\"this.style.color='#a1a1aa'; this.style.background='none'\">
                                    <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                                        <polyline points='17 1 21 5 17 9'></polyline>
                                        <path d='M3 11V9a4 4 0 0 1 4-4h14'></path>
                                        <polyline points='7 23 3 19 7 15'></polyline>
                                        <path d='M21 13v2a4 4 0 0 1-4 4H3'></path>
                                    </svg>
                                </button>";
                        }

                        // Oval toggle switch
                        $bg        = $isEnabled ? '#1BD96A' : '#27272a';
                        $thumbLeft = $isEnabled ? '22px' : '2px';
                        $toggleHtml = "
                            <div x-on:click.stop=\"\$wire.toggleModStatus('{$projectId}', '{$filename}', {$isEnabledJs})\"
                                 title='" . ($isEnabled ? 'Disable' : 'Enable') . "'
                                 style='cursor:pointer; position:relative; flex-shrink:0; width:44px; height:24px; background:{$bg}; border-radius:9999px; transition:background 0.2s ease-in-out;'>
                                <div style='position:absolute; top:2px; left:{$thumbLeft}; width:20px; height:20px; background:#03150A; border-radius:50%; box-shadow:0 2px 4px rgba(0,0,0,0.25); transition:left 0.2s ease-in-out;'></div>
                            </div>";

                        return new HtmlString("
                            <div style='display:flex; align-items:center; gap:10px;'>
                                {$changeVersionBtn}
                                {$toggleHtml}
                            </div>
                        ");
                    }),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->formatStateUsing(function ($state) {
                        if (!$state) return '0';
                        $num = (int)$state;
                        if ($num >= 1000000) {
                            return round($num / 1000000, 2) . 'M';
                        }
                        if ($num >= 1000) {
                            return round($num / 1000, 1) . 'K';
                        }
                        return $num;
                    })
                    ->sortable()
                    ->toggleable()
                    ->visible(fn () => $this->activeTab === 'all'),
                TextColumn::make('date_modified')
                    ->icon('tabler-clock')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->sortable()
                    ->toggleable()
                    ->visible(fn () => $this->activeTab === 'all'),
            ])
            ->recordActions([
                Action::make('install_latest')
                    ->icon('tabler-download')
                    ->color('success')
                    ->label(trans('pelican-mod-manager::strings.actions.install'))
                    ->visible(function (array $record) {
                        if ($this->activeTab !== 'all') return false;
                        if (!empty($record['is_local']) || !empty($record['unavailable'])) {
                            return false;
                        }
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        return is_null($installedMod);
                    })
                    ->action(function (array $record) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $versions = PelicanModManager::getProjectVersions($record['project_id'], $server);

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
                                ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.install_success_body', [
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
                                ->title(trans('pelican-mod-manager::strings.notifications.install_failed'))
                                ->body(trans('pelican-mod-manager::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('update')
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->label(trans('pelican-mod-manager::strings.actions.update'))
                    ->visible(function (array $record) {
                        if ($this->activeTab !== 'all') return false;
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
                    ->modalHeading(trans('pelican-mod-manager::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $versions = $this->getCachedVersions($record['project_id']);

                        return trans('pelican-mod-manager::strings.modals.update_description', [
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

                            $versions = PelicanModManager::getProjectVersions($record['project_id'], $server);

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
                                ->title(trans('pelican-mod-manager::strings.notifications.update_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.update_failed'))
                                ->body(trans('pelican-mod-manager::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('installed')
                    ->icon('tabler-check')
                    ->color('success')
                    ->label(trans('pelican-mod-manager::strings.actions.installed'))
                    ->disabled()
                    ->visible(function (array $record) {
                        if ($this->activeTab !== 'all') return false;
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
                    ->label(trans('pelican-mod-manager::strings.actions.uninstall'))
                    ->visible(function (array $record) {
                        if ($this->activeTab !== 'installed') {
                            return false;
                        }
                        if (!empty($record['is_local'])) {
                            return true;
                        }
                        return !is_null($this->getInstalledMod($record['project_id']));
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('pelican-mod-manager::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('pelican-mod-manager::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);

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
                                $metadataRemoved = PelicanModManager::removeModMetadata($server, $record['project_id']);

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
                                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.uninstall_success_body', [
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
                                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_failed'))
                                ->body(trans('pelican-mod-manager::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                // Three-dot dropdown: Show file + Copy link
                \Filament\Actions\ActionGroup::make([
                    Action::make('show_file')
                        ->icon('tabler-folder-open')
                        ->label('Show file')
                        ->url(function (array $record) {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $type = ModrinthProjectType::fromServer($server);
                            if (!$type) return '#';
                            return ListFiles::getUrl(['path' => $type->getFolder()]);
                        }),
                    Action::make('copy_link')
                        ->icon('tabler-link')
                        ->label('Copy link')
                        ->visible(fn (array $record) => empty($record['is_local']))
                        ->action(function (array $record) {
                            $slug = e($record['slug'] ?? $record['project_slug'] ?? '');
                            $this->js("navigator.clipboard.writeText('https://modrinth.com/mod/{$slug}')");
                            Notification::make()->title('Link copied!')->success()->send();
                        }),
                ])
                ->icon('tabler-dots-vertical')
                ->visible(fn () => $this->activeTab === 'installed'),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->label('Filter Status')
                    ->options([
                        'all' => 'All',
                        'enabled' => 'Enabled',
                        'disabled' => 'Disabled',
                        'updates' => 'Updates',
                    ])
                    ->default('all')
                    ->visible(fn () => $this->activeTab === 'installed'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkAction::make('delete')
                    ->label(trans('pelican-mod-manager::strings.actions.uninstall'))
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn () => $this->activeTab === 'installed')
                    ->action(function (\Illuminate\Support\Collection $records) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $type = ModrinthProjectType::fromServer($server);
                            if (!$type) {
                                throw new Exception('Server does not support Modrinth mods or plugins');
                            }

                            $folder = $type->getFolder();
                            $filesToDelete = [];
                            $projectIdsToRemove = [];

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

                            $this->installedModsMetadata = null;
                            $this->versionsCache = [];
                            $this->js('$wire.$refresh()');

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_success'))
                                ->body('Successfully uninstalled selected mods.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            report($e);
                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
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
            // Page action for the browse tab's Version Selection button.
            // Kept "visible" so Filament allows mounting it, but CSS-hidden from the header.
            // Triggered programmatically via openBrowseVersions() Livewire method.
            Action::make('browse_versions')
                ->label(trans('pelican-mod-manager::strings.actions.versions'))
                ->extraAttributes(['style' => 'display:none !important'])
                ->modalSubmitAction(false)
                ->schema(fn (array $arguments) => $this->buildVersionsSections(
                    $arguments['projectId'] ?? '',
                    ['project_id' => $arguments['projectId'] ?? '', 'title' => $arguments['title'] ?? '']
                )),
            Action::make('open_folder')
                ->tooltip(fn () => trans('pelican-mod-manager::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
            Action::make('upload_mod')
                ->label(trans('pelican-mod-manager::strings.actions.upload_mod'))
                ->tooltip(trans('pelican-mod-manager::strings.actions.upload_mod_tooltip'))
                ->icon('tabler-upload')
                ->color('primary')
                ->schema([
                    FileUpload::make('file')
                        ->label(trans('pelican-mod-manager::strings.page.mod_file'))
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

                                                PelicanModManager::saveModMetadata(
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
                            cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);
                            $this->js('$wire.$refresh()');

                            if ($resolved) {
                                Notification::make()
                                    ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                                    ->body("Successfully uploaded, verified against Modrinth, and registered as a managed mod: {$projectName}")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
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
                                ->title(trans('pelican-mod-manager::strings.actions.upload_mod'))
                                ->body('Modpack installation started. Please keep this page open to track progress.')
                                ->info()
                                ->send();
                        }

                    } catch (Exception $exception) {
                        report($exception);

                        Notification::make()
                            ->title(trans('pelican-mod-manager::strings.notifications.mrpack_upload_failed'))
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
                    PelicanModManager::saveModsMetadata($server, $resolvedMods);
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
            cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);
            $this->js('$wire.$refresh()');

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.mrpack_upload_success'))
                ->body(trans('pelican-mod-manager::strings.notifications.mrpack_upload_success_body'))
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
            cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);
            $this->js('$wire.$refresh()');

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.mrpack_upload_failed'))
                ->body(trans('pelican-mod-manager::strings.notifications.mrpack_upload_failed_body', [
                    'error' => $exception->getMessage(),
                ]))
                ->danger()
                ->send();
        }
    }

    /**
     * Called from the browse tab's Version Selection button via Alpine $wire.
     * Mounts the browse_versions page action server-side so Filament opens the modal.
     */
    public function openBrowseVersions(string $projectId, string $title = ''): void
    {
        $this->mountAction('browse_versions', ['projectId' => $projectId, 'title' => $title]);
    }

    public function installMod(string $projectId, string $slug = '', string $title = '', string $author = ''): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();

            $versions = PelicanModManager::getProjectVersions($projectId, $server);

            if (empty($versions)) {
                throw new Exception('No compatible versions found');
            }

            $latestVersion = $versions[0];
            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

            if (!$primaryFile) {
                throw new Exception('No downloadable file found');
            }

            $record = [
                'project_id' => $projectId,
                'slug'       => $slug ?: $projectId,
                'title'      => $title ?: ($latestVersion['name'] ?? $projectId),
                'author'     => $author ?: null,
            ];
            $this->performInstallOrUpdate($server, $record, $latestVersion, $primaryFile);

            $this->installedModsMetadata = null;
            $this->versionsCache = [];

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                ->body(trans('pelican-mod-manager::strings.notifications.install_success_body', [
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
                ->title(trans('pelican-mod-manager::strings.notifications.install_failed'))
                ->body(trans('pelican-mod-manager::strings.notifications.install_failed_body'))
                ->danger()
                ->send();
        }
    }

    public function updateMod(string $projectId): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();

            $installedMod = $this->getInstalledMod($projectId);
            if (!$installedMod) {
                throw new Exception('Mod not found in metadata');
            }

            $versions = PelicanModManager::getProjectVersions($projectId, $server);

            if (empty($versions)) {
                throw new Exception('No compatible versions found');
            }

            $latestVersion = $versions[0];
            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

            if (!$primaryFile) {
                throw new Exception('No downloadable file found');
            }

            $record = [
                'project_id' => $projectId,
                'slug'       => $installedMod['project_slug'] ?? $projectId,
                'title'      => $installedMod['project_title'] ?? $projectId,
                'author'     => $installedMod['author'] ?? null,
            ];
            $this->performInstallOrUpdate($server, $record, $latestVersion, $primaryFile, $installedMod);

            $this->installedModsMetadata = null;
            $this->versionsCache = [];

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.update_success'))
                ->body(trans('pelican-mod-manager::strings.notifications.update_success_body', [
                    'version' => $latestVersion['version_number'],
                ]))
                ->success()
                ->send();
        } catch (Exception $exception) {
            report($exception);

            $this->installedModsMetadata = null;
            $this->versionsCache = [];

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.update_failed'))
                ->body(trans('pelican-mod-manager::strings.notifications.update_failed_body'))
                ->danger()
                ->send();
        }
    }

    public function toggleModStatus(string $projectId, string $filename, bool $currentlyEnabled): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);

            $type = ModrinthProjectType::fromServer($server);
            if (!$type) {
                throw new Exception('Server does not support Modrinth mods or plugins');
            }

            $folder = $type->getFolder();
            $oldFilename = $this->validateFilename($filename);

            if ($currentlyEnabled) {
                $newFilename = $oldFilename . '.disabled';
            } else {
                $newFilename = str_replace('.disabled', '', $oldFilename);
            }

            Http::daemon($server->node)
                ->put("/api/servers/{$server->uuid}/files/rename", [
                    'root' => '/',
                    'files' => [
                        [
                            'from' => $folder . '/' . $oldFilename,
                            'to' => $folder . '/' . $newFilename,
                        ]
                    ]
                ])
                ->throw();

            $cleanProjectId = str_starts_with($projectId, 'local_') ? null : $projectId;
            if ($cleanProjectId) {
                $installedMod = $this->getInstalledMod($cleanProjectId);
                if ($installedMod) {
                    PelicanModManager::saveModMetadata(
                        $server,
                        $cleanProjectId,
                        $installedMod['project_slug'],
                        $installedMod['project_title'],
                        $installedMod['version_id'],
                        $installedMod['version_number'],
                        $newFilename,
                        $installedMod['author'] ?? null
                    );
                }
            }

            $this->installedModsMetadata = null;
            $this->versionsCache = [];
            $this->js('$wire.$refresh()');

            Notification::make()
                ->title('Mod status updated')
                ->body('Successfully ' . ($currentlyEnabled ? 'disabled' : 'enabled') . ' mod.')
                ->success()
                ->send();
        } catch (Exception $e) {
            report($e);
            Notification::make()
                ->title('Failed to toggle mod status')
                ->body($e->getMessage())
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
                TextEntry::make('custom_styles')
                    ->hiddenLabel()
                    ->state(fn () => new HtmlString("<div class=\"modrinth-custom-styles\"><style>" . $this->getDynamicStyles() . "</style></div>")),
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

                                 /* Strip Filament's default TextEntry card styling from the parents of our card */
                                 div:has(> .modpack-import-card),
                                 div:has(> div > .modpack-import-card),
                                 .fi-in-text:has(.modpack-import-card),
                                 .fi-in-entry-wrp:has(.modpack-import-card) {
                                     border: none !important;
                                     background: transparent !important;
                                     padding: 0 !important;
                                     box-shadow: none !important;
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
                            ->state(fn () => PelicanModManager::getMinecraftVersion($server) ?? trans('pelican-mod-manager::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('Loader')
                            ->state(fn () => PelicanModManager::getLoaderFromServer($server)['display_name'] ?? trans('pelican-mod-manager::strings.page.unknown'))
                            ->icon(fn () => new HtmlString(PelicanModManager::getLoaderFromServer($server)['icon'] ?? ''))
                            ->badge(),
                        TextEntry::make('installed')
                            ->label(fn () => trans('pelican-mod-manager::strings.page.installed', ['type' => $type?->getLabel() ?? 'Modrinth']))
                            ->state(function (DaemonFileRepository $fileRepository) use ($server, $type) {
                                try {
                                    if (!$type) {
                                        return trans('pelican-mod-manager::strings.page.unknown');
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

                                    return trans('pelican-mod-manager::strings.page.unknown');
                                }
                            })
                            ->badge(),
                    ]),
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }
}
