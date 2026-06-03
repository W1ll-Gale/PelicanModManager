<?php

namespace MrBytesized\PelicanModManager\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use MrBytesized\PelicanModManager\Facades\PelicanModManager;
use MrBytesized\PelicanModManager\Services\InstalledModsService;
use MrBytesized\PelicanModManager\Services\ModManagerFileService;
use MrBytesized\PelicanModManager\Services\ModpackService;
use MrBytesized\PelicanModManager\Services\ModrinthClient;
use MrBytesized\PelicanModManager\Support\PelicanModManagerPageRenderer;
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

    protected $queryString = [
        'browseSearch' => ['as' => 'q', 'except' => ''],
        'browseSortMode' => ['as' => 'sort', 'except' => 'relevance'],
        'browsePageSize' => ['as' => 'per', 'except' => 20],
        'browseCurrentPage' => ['as' => 'p', 'except' => 1],
        'browseCategoryFilters' => ['as' => 'cat', 'except' => []],
        'browseExcludedCategoryFilters' => ['as' => 'xcat', 'except' => []],
        'browseEnvironmentFilters' => ['as' => 'env', 'except' => []],
        'browseExcludedEnvironmentFilters' => ['as' => 'xenv', 'except' => []],
        'browseOpenSourceOnly' => ['as' => 'open', 'except' => false],
        'browseExcludeOpenSource' => ['as' => 'xopen', 'except' => false],
        'browseHideInstalled' => ['as' => 'hideInstalled', 'except' => false],
        'installedSearch' => ['as' => 'iq', 'except' => ''],
        'installedStatusFilter' => ['as' => 'ifilter', 'except' => 'all'],
        'installedSortMode' => ['as' => 'isort', 'except' => 'alpha_asc'],
    ];

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    public bool $isImporting = false;
    public int $importProgress = 0;
    public string $importStatus = '';
    public ?string $importFilePath = null;
    public ?array $importFilesToDownload = null;
    public ?array $importDownloadedMods = [];
    public int $importSkippedInstalledMods = 0;

    public string $browseSearch = '';
    public string $browseSortMode = 'relevance';
    public int $browsePageSize = 20;
    public int $browseTotalPages = 0;
    public int $browseCurrentPage = 1;
    /** @var string[] */
    public array $browseCategoryFilters = [];
    /** @var string[] */
    public array $browseExcludedCategoryFilters = [];
    /** @var string[] */
    public array $browseEnvironmentFilters = [];
    /** @var string[] */
    public array $browseExcludedEnvironmentFilters = [];
    public bool $browseOpenSourceOnly = false;
    public bool $browseExcludeOpenSource = false;
    public bool $browseHideInstalled = false;
    public string $installedStatusFilter = 'all';
    public string $installedSearch = '';
    public string $installedSortMode = 'alpha_asc';
    public bool $installedHasDisabled = false;
    public bool $installedHasUpdates = false;
    public bool $installedUpdatesChecked = false;
    // Phase 1: local metadata renders immediately; no Wings folder scan or Modrinth call.
    /** @var array<string, bool> Project IDs known to have updates after the lazy background check. */
    public array $installedUpdateProjectIds = [];
    public int $installedUpdateCheckCursor = 0;
    public int $installedUpdateCheckBatchSize = 20;
    public bool $installedDataReady = true;
    // Phase 2: Modrinth-enriched list (icons, author avatars) loaded in background
    public bool $installedEnriched = false;
    /** @var string[] */
    public array $exportModpackProjectIds = [];
    public string $installedBulkSelectionJson = '[]';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'mods/{modTab?}';

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

    public static function getNavigationUrl(): string
    {
        return static::getUrl(['modTab' => 'installed']);
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

    public function mount(?string $modTab = null): void
    {
        $this->installedUpdateCheckBatchSize = (int) config('pelican-mod-manager.installed.update_check_batch_size', $this->installedUpdateCheckBatchSize);

        if (in_array($modTab, ['installed', 'browse'], true)) {
            $this->activeTab = $modTab;
        }

        $this->normalizeUrlBackedState();

        /** @var Server $server */
        $server = Filament::getTenant();
        $cachedUpdateProjectIds = cache()->get("pmm_update_project_ids_{$server->uuid}", []);
        if (is_array($cachedUpdateProjectIds)) {
            $this->installedUpdateProjectIds = array_fill_keys($cachedUpdateProjectIds, true);
            $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
            $this->installedUpdatesChecked = cache()->get("pmm_update_check_complete_{$server->uuid}", false) === true;
        }

        if ($this->activeTab === 'browse' && $this->browseCurrentPage > 1) {
            $this->gotoPage($this->browseCurrentPage);
        }

        if ($modTab === null) {
            $this->syncActiveTabPath();
        }
    }

    protected function normalizeUrlBackedState(): void
    {
        if (!in_array($this->activeTab ?? null, ['installed', 'browse'], true)) {
            $this->activeTab = 'installed';
        }

        $allowedPageSizes = [5, 10, 15, 20, 50, 100];
        if (!in_array($this->browsePageSize, $allowedPageSizes, true)) {
            $this->browsePageSize = 20;
        }
        $this->browseCurrentPage = max(1, (int) $this->browseCurrentPage);

        $categoryOptions = array_keys($this->getBrowseCategoryOptions());
        $this->browseCategoryFilters = $this->sanitizeUrlArray($this->browseCategoryFilters, $categoryOptions);
        $this->browseExcludedCategoryFilters = array_values(array_diff(
            $this->sanitizeUrlArray($this->browseExcludedCategoryFilters, $categoryOptions),
            $this->browseCategoryFilters
        ));

        $environmentOptions = ['client', 'server'];
        $this->browseEnvironmentFilters = $this->sanitizeUrlArray($this->browseEnvironmentFilters, $environmentOptions);
        $this->browseExcludedEnvironmentFilters = array_values(array_diff(
            $this->sanitizeUrlArray($this->browseExcludedEnvironmentFilters, $environmentOptions),
            $this->browseEnvironmentFilters
        ));

        if (!in_array($this->browseSortMode, ['relevance', 'downloads', 'follows', 'newest', 'updated'], true)) {
            $this->browseSortMode = 'relevance';
        }
        if (!in_array($this->installedStatusFilter, ['all', 'enabled', 'disabled', 'updates'], true)) {
            $this->installedStatusFilter = 'all';
        }
        if (!in_array($this->installedSortMode, ['alpha_asc', 'alpha_desc', 'newest', 'oldest'], true)) {
            $this->installedSortMode = 'alpha_asc';
        }

        if ($this->browseOpenSourceOnly && $this->browseExcludeOpenSource) {
            $this->browseExcludeOpenSource = false;
        }
    }

    /**
     * @param string[] $value
     * @param string[] $allowed
     * @return string[]
     */
    protected function sanitizeUrlArray(array $value, array $allowed): array
    {
        return collect($value)
            ->filter(fn ($item) => is_string($item) && in_array($item, $allowed, true))
            ->unique()
            ->values()
            ->toArray();
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
        return (PelicanModManagerPageRenderer::dynamicStyles())->call($this);
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
            'installed' => Tab::make(trans('pelican-mod-manager::strings.page.view_installed')),
            'browse' => Tab::make($tabLabel),
        ];
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();

            // Cache the Wings daemon file read so subsequent Livewire requests
            // (and the very first page load after the first visit) are instant.
            $cacheKey = "pmm_metadata_{$server->uuid}";
            $this->installedModsMetadata = cache()->remember(
                $cacheKey,
                now()->addMinutes(5),
                fn () => PelicanModManager::getInstalledModsMetadata($server)
            );
        }

        return $this->installedModsMetadata;
    }

    protected function invalidateMetadataCache(): void
    {
        $this->installedModsMetadata = null;
        /** @var Server $server */
        $server = Filament::getTenant();
        cache()->forget("pmm_metadata_{$server->uuid}");
    }

    /**
     * Ultra-fast path: reads only the local metadata file — zero network calls.
     * Returns records compatible with getInstalledModsResolvedList() but only for
     * mods that have Modrinth metadata (no local/untracked jars).
     * Used as the initial phase so the installed tab shows instantly.
     * @return array<int, mixed>
     */
    protected function getMetadataOnlyList(): array
    {
        return app(InstalledModsService::class)->metadataOnlyList($this->getInstalledModsMetadata());
    }

    /**
     * Fast path: Wings file listing + local metadata only — zero Modrinth API calls.
     * Returns records compatible with getInstalledModsResolvedList() but with
     * null icon_url / author_avatar (placeholders shown in the UI).
     * @return array<int, mixed>
     */
    protected function getBasicInstalledList(Server $server, ModrinthProjectType $type): array
    {
        return app(InstalledModsService::class)->basicList($server, $type, $this->getInstalledModsMetadata());
    }

    /**
     * @return array<int, mixed>
     */
    protected function getInstalledModsResolvedList(Server $server, ModrinthProjectType $type): array
    {
        return app(InstalledModsService::class)->resolvedList($server, $type, $this->getInstalledModsMetadata());
    }

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

        // Enrich the record with metadata fields that may be missing when called
        // from the "Change version" modal (which only passes projectId + title).
        // saveModMetadata() requires slug; without it the save fails and leaves
        // the newly downloaded jar as an orphaned local file.
        if ($installedMod) {
            $record = array_merge([
                'slug'   => $installedMod['project_slug'] ?? '',
                'author' => $installedMod['author'] ?? null,
            ], $record);
        }

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

                            $this->invalidateMetadataCache();
                            $this->versionsCache = [];

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

                            $this->invalidateMetadataCache();
                            $this->versionsCache = [];

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
            // Use Laravel cache (not just in-memory) so version data survives across
            // Livewire requests — without this every button click re-fetches the
            // Modrinth API for every installed mod, making the UI feel very slow.
            $loader = PelicanModManager::getLoaderFromServer($server);
            $this->versionsCache[$projectId] = app(ModrinthClient::class)->getProjectVersions(
                $projectId,
                PelicanModManager::getMinecraftVersion($server),
                $loader['name'] ?? null,
                $server
            );
        }

        return $this->versionsCache[$projectId];
    }

    /**
     * Fetches version data for multiple projects in parallel using Http::pool().
     * Fires one concurrent request per project instead of N sequential round-trips.
     * Populates both the in-memory versionsCache and Laravel cache so every
     * subsequent getCachedVersions() call is instant (zero network).
     *
     * @param string[] $projectIds
     */
    protected function warmVersionsCacheParallel(Server $server, array $projectIds): void
    {
        $loader = PelicanModManager::getLoaderFromServer($server);
        $this->versionsCache = app(ModrinthClient::class)->warmProjectVersions(
            $server,
            $projectIds,
            PelicanModManager::getMinecraftVersion($server),
            $loader['name'] ?? null,
            $this->versionsCache
        );
    }

    protected function getPrimaryFile(array $files): ?array
    {
        return app(ModrinthClient::class)->getPrimaryFile($files);
    }

    /** @return array<string, true> */
    protected function getInstalledModrinthSha1s(Server $server): array
    {
        $metadata = $this->getInstalledModsMetadata();
        $loader = PelicanModManager::getLoaderFromServer($server);

        return app(InstalledModsService::class)->installedModrinthSha1s(
            $server,
            $metadata,
            PelicanModManager::getMinecraftVersion($server),
            $loader['name'] ?? null
        );
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        return app(ModManagerFileService::class)->validateFilename($filename);
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
        $files = app(ModManagerFileService::class);

        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            throw new Exception('Server does not support Modrinth mods or plugins');
        }

        $folder = $type->getFolder();

        $files->pull($server, $primaryFile['url'], $folder);

        // Slug is required by saveModMetadata. If it's missing from $record
        // (e.g. called from the version-change modal which only passes projectId+title),
        // fall back to the installed mod's stored slug.
        $slug = !empty($record['slug']) ? $record['slug'] : ($installedMod['project_slug'] ?? '');

        $saved = PelicanModManager::saveModMetadata(
            $server,
            $record['project_id'],
            $slug,
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? ($installedMod['author'] ?? null)
        );

        if (!$saved) {
            if (!$oldFilename || $oldFilename !== $safeNewFilename) {
                try {
                    $files->deleteFiles($server, [$folder . '/' . $safeNewFilename]);
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                $files->deleteFiles($server, [$folder . '/' . $oldFilename]);
            } catch (Exception $deleteException) {
                try {
                    $files->deleteFiles($server, [$folder . '/' . $safeNewFilename]);
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

        $this->patchInstalledCachesAfterInstallOrUpdate($server, $record, $versionData, $safeNewFilename, $installedMod);
    }

    /**
     * Keep the installed tab enriched while a single row changes.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $versionData
     * @param array<string, mixed>|null $installedMod
     */
    private function patchInstalledCachesAfterInstallOrUpdate(
        Server $server,
        array $record,
        array $versionData,
        string $filename,
        ?array $installedMod = null
    ): void {
        $projectId = $record['project_id'] ?? null;
        if (!$projectId) return;

        unset($this->installedUpdateProjectIds[$projectId]);
        $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        cache()->put("pmm_update_project_ids_{$server->uuid}", array_keys($this->installedUpdateProjectIds), now()->addMinutes(5));
        cache()->put("pmm_has_updates_{$server->uuid}", $this->installedHasUpdates, now()->addMinutes(5));

        $stats = app(InstalledModsService::class)->patchAfterInstallOrUpdate($server, $record, $versionData, $filename, $installedMod);
        $this->installedHasDisabled = $stats['has_disabled'] ?? false;
        if (!$this->installedHasDisabled && in_array($this->installedStatusFilter, ['enabled', 'disabled'], true)) {
            $this->installedStatusFilter = 'all';
        }
    }

    /**
     * Remove uninstalled rows from installed-list caches without dropping the
     * entire enriched list back to placeholders.
     *
     * @param string[] $projectIds
     * @param string[] $filenames
     */
    private function removeInstalledRowsFromCaches(Server $server, array $projectIds = [], array $filenames = []): void
    {
        $stats = app(InstalledModsService::class)->removeRowsFromCaches($server, $projectIds, $filenames);

        foreach ($projectIds as $projectId) {
            unset($this->installedUpdateProjectIds[$projectId]);
            unset($this->versionsCache[$projectId]);
        }

        $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        $this->installedHasDisabled = $stats['has_disabled'] ?? false;
        if (!$this->installedHasDisabled && in_array($this->installedStatusFilter, ['enabled', 'disabled'], true)) {
            $this->installedStatusFilter = 'all';
        }

        cache()->put("pmm_update_project_ids_{$server->uuid}", array_keys($this->installedUpdateProjectIds), now()->addMinutes(5));
        cache()->put("pmm_has_updates_{$server->uuid}", $this->installedHasUpdates, now()->addMinutes(5));
    }

    private function addLocalInstalledRowToCaches(Server $server, ModrinthProjectType $type, string $filename): void
    {
        $stats = app(InstalledModsService::class)->addLocalRowToCaches($server, $type, $filename);
        $this->installedHasDisabled = $stats['has_disabled'] ?? $this->installedHasDisabled;
    }

    private function rebuildInstalledCachesNow(Server $server, ?ModrinthProjectType $type = null): void
    {
        $type ??= ModrinthProjectType::fromServer($server);
        if (!$type) return;

        app(InstalledModsService::class)->forgetInstalledListCaches($server);

        $this->getBasicInstalledList($server, $type);
        $this->getInstalledModsResolvedList($server, $type);
        $this->installedEnriched = true;
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

                    // Phase 0: not ready yet — loading skeleton visible
                    if (!$this->installedDataReady) {
                        return new LengthAwarePaginator([], 0, 1, 1);
                    }

                    // Phase 1 (instant, ~50ms): metadata-only — shows all tracked mods immediately
                    //   from the local metadata file, no network calls.
                    // Phase 2 (async, ~1-5s): Wings + Modrinth enrichment triggered by checkInstalledUpdates.
                    //   Icons, author avatars, untracked local jars appear once enriched.
                    $combinedItems = $this->installedEnriched
                        ? $this->getInstalledModsResolvedList($server, $type)
                        : $this->getMetadataOnlyList();

                    // Compute cheap stats (no API calls)
                    $this->installedHasDisabled = collect($combinedItems)->contains(fn ($i) => !empty($i['is_disabled']));
                    // installedHasUpdates is computed lazily via checkInstalledUpdates() (triggered
                    // by x-init in the filter bar after first render) so it never blocks tab switching.

                    // 1. Apply search — use our own property (the Filament table search bar
                    //    is hidden for the installed tab; we render a custom input instead)
                    $installedSearchTerm = trim($this->installedSearch);
                    if ($installedSearchTerm) {
                        $searchLower = strtolower($installedSearchTerm);
                        $combinedItems = array_values(array_filter($combinedItems, function (array $item) use ($searchLower) {
                            return str_contains(strtolower($item['title']), $searchLower)
                                || str_contains(strtolower($item['slug'] ?? ''), $searchLower)
                                || str_contains(strtolower($item['filename']), $searchLower);
                        }));
                    }

                    // Apply status filter
                    $statusFilter = $this->installedStatusFilter;
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
                                return isset($this->installedUpdateProjectIds[$item['project_id']]);
                            }
                            return true;
                        }));
                    }

                    // Sort by installedSortMode
                    $dateKey = fn ($i) => !empty($i['is_local'])
                        ? ($i['date_modified'] ?? '')
                        : ($i['metadata']['installed_at'] ?? '');
                    $combinedItems = match ($this->installedSortMode) {
                        'alpha_desc' => collect($combinedItems)->sortByDesc(fn ($i) => strtolower($i['title'] ?? ''))->values()->toArray(),
                        'newest'     => collect($combinedItems)->sortByDesc($dateKey)->values()->toArray(),
                        'oldest'     => collect($combinedItems)->sortBy($dateKey)->values()->toArray(),
                        default      => collect($combinedItems)->sortBy(fn ($i) => strtolower($i['title'] ?? ''))->values()->toArray(),
                    };

                    $totalItems = count($combinedItems);

                    // Return ALL installed items on a single page (pagination disabled for installed tab)
                    return new LengthAwarePaginator($combinedItems, $totalItems, max($totalItems, 1), 1);
                } else {
                    if ($page > 1 && $page !== $this->browseCurrentPage) {
                        $this->browseCurrentPage = $page;
                    } else {
                        $page = max(1, $this->browseCurrentPage);
                    }

                    $browseSortMap = [
                        'downloads' => ['downloads',    'desc'],
                        'follows'   => ['follows',      'desc'],
                        'newest'    => ['date_modified','desc'],
                        'updated'   => ['date_modified','asc'],
                    ];
                    [$bCol, $bDir] = $browseSortMap[$this->browseSortMode] ?? [null, null];
                    $response = PelicanModManager::getProjects($server, $page, $this->browseSearch, $bCol, $bDir, $this->getBrowseFilters(), $this->browsePageSize);
                    $total = (int)($response['total_hits'] ?? 0);
                    $this->browseTotalPages = $this->browsePageSize > 0 ? (int)ceil($total / $this->browsePageSize) : 1;
                    $this->browseCurrentPage = $page;
                    return new LengthAwarePaginator($response['hits'], $total, $this->browsePageSize, $page);
                }
            })
            ->paginated($this->activeTab === 'installed' ? false : [$this->browsePageSize])
            ->recordClasses(fn (array $record) => !empty($record['is_disabled']) ? 'pmm-row-disabled' : null)
            ->columns([
                TextColumn::make('title')
                    ->label(fn () => $this->activeTab === 'installed' ? 'Project' : 'Title')
                    ->wrap()
                    ->formatStateUsing(function ($state, $record) {
                        $title  = e($record['title'] ?? $state ?? '');
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

                        // Determine install state for button rendering without fetching versions.
                        $installedMod = $this->getInstalledMod($record['project_id'] ?? '');
                        $hasUpdate = isset($this->installedUpdateProjectIds[$record['project_id'] ?? '']);

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
                                data-pmm-project-id=\"{$projectId}\"
                                data-pmm-title=\"{$title}\"
                                x-on:click.stop=\"\$wire.openBrowseVersions(\$el.dataset.pmmProjectId, \$el.dataset.pmmTitle)\"
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
                            // Icon-only green download button — no text, same compact style as installed tab
                            $actionBtn = "
                                <button type='button'
                                    data-pmm-project-id=\"{$projectId}\"
                                    x-on:click.stop=\"\$wire.updateMod(\$el.dataset.pmmProjectId)\"
                                    title='Update to latest version'
                                    style=\"{$btnBase} {$actionBtnWidth} border:1px solid rgba(27,217,106,0.5); background:transparent; color:#1bd96a;\"
                                    onmouseover=\"this.style.background='rgba(27,217,106,0.15)'; this.style.borderColor='#1bd96a'\"
                                    onmouseout=\"this.style.background='transparent'; this.style.borderColor='rgba(27,217,106,0.5)'\">
                                    <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='7 10 12 15 17 10'/><line x1='12' y1='15' x2='12' y2='3'/></svg>
                                </button>";
                        } else {
                            $actionBtn = "
                                <button type='button'
                                    data-pmm-project-id=\"{$projectId}\"
                                    data-pmm-slug=\"{$slug}\"
                                    data-pmm-title=\"{$title}\"
                                    data-pmm-author=\"{$author}\"
                                    x-on:click.stop=\"\$wire.installMod(\$el.dataset.pmmProjectId, \$el.dataset.pmmSlug, \$el.dataset.pmmTitle, \$el.dataset.pmmAuthor)\"
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
                                <span style='font-size:11px; color:#6b7280; font-family:monospace; white-space:nowrap;'>{$filename}</span>
                            </div>
                        ");
                    }),
                // Change-version button + enable/disable toggle — installed tab only
                TextColumn::make('project_id')
                    ->label('Actions')
                    ->visible(fn () => $this->activeTab === 'installed')
                    ->formatStateUsing(function ($state, $record) {
                        $projectId   = e($record['project_id'] ?? '');
                        $title    = e($record['title'] ?? '');
                        $filename = e($record['filename'] ?? '');
                        $slug        = e($record['slug'] ?? '');
                        $projectType = e($record['project_type'] ?? 'mod');
                        $isLocal     = !empty($record['is_local']);
                        $isEnabled   = empty($record['is_disabled']);
                        $isEnabledJs = $isEnabled ? 'true' : 'false';

                        // Resolve "Show file" URL for this server's mod folder
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        $modType = ModrinthProjectType::fromServer($server);
                        $showFileUrl = $modType ? e(ListFiles::getUrl(['path' => $modType->getFolder()])) : '#';

                        // Check if this mod has an update available
                        $hasModUpdate = !$isLocal && isset($this->installedUpdateProjectIds[$record['project_id'] ?? '']);

                        // Single primary icon — green download (no border) when update available,
                        // ⇄ change-version otherwise. Both open the version-selection modal.
                        $primaryIconBtn = '';
                        if (!$isLocal && $projectId) {
                            if ($hasModUpdate) {
                                $primaryIconBtn = "
                                    <button type='button'
                                        data-pmm-project-id=\"{$projectId}\"
                                        data-pmm-title=\"{$title}\"
                                        x-on:click.stop=\"\$wire.openBrowseVersions(\$el.dataset.pmmProjectId, \$el.dataset.pmmTitle)\"
                                        title='Update available — click to choose version'
                                        style='background:none; border:none; cursor:pointer; padding:4px; display:flex; align-items:center; justify-content:center; border-radius:6px; flex-shrink:0;'
                                        onmouseover=\"this.style.background='rgba(27,217,106,0.12)'\"
                                        onmouseout=\"this.style.background='none'\">
                                        <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#1bd96a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='7 10 12 15 17 10'/><line x1='12' y1='15' x2='12' y2='3'/></svg>
                                    </button>";
                            } else {
                                $primaryIconBtn = "
                                    <button type='button'
                                        data-pmm-project-id=\"{$projectId}\"
                                        data-pmm-title=\"{$title}\"
                                        x-on:click.stop=\"\$wire.openBrowseVersions(\$el.dataset.pmmProjectId, \$el.dataset.pmmTitle)\"
                                        title='Change version'
                                        style='background:none; border:none; cursor:pointer; padding:4px; display:flex; align-items:center; color:#a1a1aa; border-radius:6px;'
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
                        }

                        // Oval toggle — optimistic Alpine state so it flips instantly on click
                        // without waiting for the Livewire round-trip (Wings API rename ~1-2s).
                        // Inline background is the CSS fallback so the toggle is visible even before
                        // Alpine initialises. Alpine's :style overrides it once it boots.
                        $toggleBg = $isEnabled ? '#1BD96A' : '#2f333d';
                        $toggleKnobBg = $isEnabled ? '#03150A' : '#9aa4b2';
                        $toggleX = $isEnabled ? '22px' : '0px';
                        $toggleHtml = "
                            <button type='button'
                                 class='pmm-toggle-switch'
                                 wire:ignore
                                 x-data=\"{ on: {$isEnabledJs}, busy: false }\"
                                 data-pmm-project-id=\"{$projectId}\"
                                 data-pmm-filename=\"{$filename}\"
                                 title='" . ($isEnabled ? 'Disable' : 'Enable') . "'
                                 aria-label='" . ($isEnabled ? 'Disable mod' : 'Enable mod') . "'
                                 style='--pmm-toggle-bg: {$toggleBg}; --pmm-toggle-knob-bg: {$toggleKnobBg}; --pmm-toggle-x: {$toggleX}; cursor:pointer; outline:none !important; vertical-align:middle; align-items:center;'
                                 x-bind:disabled=\"busy\"
                                 x-bind:aria-busy=\"busy ? 'true' : 'false'\"
                                 x-bind:style=\"{
                                    '--pmm-toggle-bg': on ? '#1BD96A' : '#2f333d',
                                    '--pmm-toggle-knob-bg': on ? '#03150A' : '#9aa4b2',
                                    '--pmm-toggle-x': on ? '22px' : '0px'
                                 }\"
                                 x-on:click.stop=\"if (busy) return; let wasOn=on; let oldFilename=\$el.dataset.pmmFilename; let newFilename=wasOn ? oldFilename + '.disabled' : oldFilename.replace(/\\.disabled$/i, ''); on=!on; busy=true; \$wire.toggleModStatus(\$el.dataset.pmmProjectId, oldFilename, wasOn).then(() => { \$el.dataset.pmmFilename=newFilename; busy=false }).catch(() => { on=wasOn; \$el.dataset.pmmFilename=oldFilename; busy=false })\">
                                <span class='pmm-toggle-switch__knob'></span>
                            </button>";

                        // SVG icons for dropdown items and buttons
                        $trashSvg  = "<svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='3 6 5 6 21 6'/><path d='M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2'/></svg>";
                        $folderSvg = "<svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='flex-shrink:0'><path d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'/></svg>";
                        $linkSvg   = "<svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='flex-shrink:0'><path d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/><path d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/></svg>";
                        $dotsSvg   = "<svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='5' r='1'/><circle cx='12' cy='12' r='1'/><circle cx='12' cy='19' r='1'/></svg>";

                        $iconBtnStyle = "background:none; border:none; cursor:pointer; padding:5px; display:flex; align-items:center; justify-content:center; color:#a1a1aa; border-radius:6px;";

                        // Delete button — triggers the confirm_uninstall page action (CSS-hidden header action)
                        // via openConfirmUninstall() Livewire method. mountTableAction() can't find non-DB records
                        // (Wings API / Modrinth cache), so we use a page action + mountAction() instead.
                        $isLocalJs = $isLocal ? 'true' : 'false';
                        $deleteBtn = "
                            <button type='button'
                                data-pmm-project-id=\"{$projectId}\"
                                data-pmm-filename=\"{$filename}\"
                                data-pmm-is-local=\"{$isLocalJs}\"
                                data-pmm-title=\"{$title}\"
                                x-on:click.stop=\"\$wire.openConfirmUninstall(\$el.dataset.pmmProjectId, \$el.dataset.pmmFilename, \$el.dataset.pmmIsLocal === 'true', \$el.dataset.pmmTitle)\"
                                title='Uninstall'
                                style='{$iconBtnStyle}'
                                onmouseover=\"this.style.color='#ef4444'; this.style.background='rgba(239,68,68,0.1)'\"
                                onmouseout=\"this.style.color='#a1a1aa'; this.style.background='none'\">
                                {$trashSvg}
                            </button>";

                        // "Copy link" dropdown item (hidden for local jars)
                        $copyLinkItem = (!$isLocal && $slug) ? "
                            <button type='button'
                                x-on:click.stop=\"\$wire.copyModLink('{$slug}', '{$projectType}'); open=false\"
                                style='display:flex; align-items:center; gap:10px; width:100%; padding:8px 12px; border-radius:6px; font-size:13px; font-weight:500; color:#e4e4e7; background:transparent; border:none; cursor:pointer; white-space:nowrap;'
                                onmouseover=\"this.style.background='rgba(255,255,255,0.07)'\"
                                onmouseout=\"this.style.background='transparent'\">
                                {$linkSvg} Copy link
                            </button>" : '';

                        // Three-dot dropdown
                        // - x-teleport='body' moves the panel into <body> at the DOM level, escaping
                        //   the .fi-ta-row:hover { transform: translateY(-1px) } containing block.
                        //   Without teleport, position:fixed children are positioned relative to the
                        //   transformed row (not the viewport), causing the panel to flicker in a loop
                        //   as the row hover toggles the transform on/off.
                        // - position:fixed + getBoundingClientRect() gives viewport-relative coordinates.
                        //   Now that the panel is in <body> (no ancestor transform), this works correctly.
                        // - $dispatch('pmm-close-dropdowns') → closes every other open dropdown on the page.
                        // - x-on:pmm-close-dropdowns.window → each instance listens and closes itself.
                        //   Dispatch is synchronous; open=!prev immediately re-opens the target instance.
                        // - x-on:click.away on the teleported panel → closes on outside clicks that
                        //   bubble to document (button clicks are stopped before document, handled by dispatch).
                        $dotsDropdown = "
                            <div x-data=\"{ open:false, py:0, px:0 }\"
                                 x-on:pmm-close-dropdowns.window=\"open=false\"
                                 style='display:inline-flex;'>
                                <button type='button'
                                    x-ref='dotsbtn'
                                    x-on:click.stop=\"
                                        let prev=open;
                                        \$dispatch('pmm-close-dropdowns');
                                        if(!prev){
                                            let r=\$refs.dotsbtn.getBoundingClientRect();
                                            py=r.bottom+6;
                                            px=Math.min(r.right, window.innerWidth-10);
                                        }
                                        open=!prev;
                                    \"
                                    style='{$iconBtnStyle}'
                                    onmouseover=\"this.style.background='rgba(255,255,255,0.08)'; this.style.color='#ffffff'\"
                                    onmouseout=\"this.style.background='none'; this.style.color='#a1a1aa'\">
                                    {$dotsSvg}
                                </button>
                                <template x-teleport='body'>
                                    <div x-show=\"open\" x-cloak
                                         x-on:click.away=\"open=false\"
                                         :style=\"'position:fixed;top:'+py+'px;left:'+Math.max(4,px-162)+'px;background:#18181b;border:1px solid #3f3f46;border-radius:10px;padding:4px;min-width:162px;z-index:9999;box-shadow:0 12px 32px rgba(0,0,0,0.6)'\">
                                        <a href=\"{$showFileUrl}\"
                                           x-on:click.stop=\"open=false\"
                                           style='display:flex; align-items:center; gap:10px; padding:8px 12px; border-radius:6px; font-size:13px; font-weight:500; color:#e4e4e7; text-decoration:none; white-space:nowrap;'
                                           onmouseover=\"this.style.background='rgba(255,255,255,0.07)'\"
                                           onmouseout=\"this.style.background='transparent'\">
                                            {$folderSvg} Show file
                                        </a>
                                        {$copyLinkItem}
                                    </div>
                                </template>
                            </div>";

                        // Final order: ⬇/⇄ primary icon | toggle | 🗑 delete | ⋮ three-dot
                        // width:100% + justify-content:flex-end ensures the group is flush-right inside td[4]
                        return new HtmlString("
                            <div style='display:flex; align-items:center; gap:4px; width:100%; justify-content:flex-end;'>
                                {$primaryIconBtn}
                                {$toggleHtml}
                                {$deleteBtn}
                                {$dotsDropdown}
                            </div>
                        ");
                    }),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->formatStateUsing(function ($state) {
                        if (!$state) return '0';
                        $num = (int)$state;
                        if ($num >= 1000000) return round($num / 1000000, 2) . 'M';
                        if ($num >= 1000) return round($num / 1000, 1) . 'K';
                        return $num;
                    })
                    ->visible(fn () => $this->activeTab === 'browse'),
                TextColumn::make('date_modified')
                    ->icon('tabler-clock')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->visible(fn () => $this->activeTab === 'browse'),
            ])
            ->recordActions([
                Action::make('install_latest')
                    ->icon('tabler-download')
                    ->color('success')
                    ->label(trans('pelican-mod-manager::strings.actions.install'))
                    ->visible(function (array $record) {
                        if ($this->activeTab !== 'browse') return false;
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

                            $this->invalidateMetadataCache();
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

                            $this->invalidateMetadataCache();
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
                        if ($this->activeTab !== 'browse') return false;
                        return isset($this->installedUpdateProjectIds[$record['project_id'] ?? '']);
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

                            $this->invalidateMetadataCache();
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

                            $this->invalidateMetadataCache();
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
                        if ($this->activeTab !== 'browse') return false;
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        return !isset($this->installedUpdateProjectIds[$record['project_id'] ?? '']);
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->label(trans('pelican-mod-manager::strings.actions.uninstall'))
                    ->extraAttributes(['style' => 'display:none !important'])
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
                                    $this->invalidateMetadataCache();
                                    $this->versionsCache = [];
                                }
                            } else {
                                $this->invalidateMetadataCache();
                                $this->versionsCache = [];
                            }

                            $this->removeInstalledRowsFromCaches(
                                $server,
                                empty($record['is_local']) ? [$record['project_id']] : [],
                                [$filename]
                            );

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
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
            ])
            ->filters([])
            ->bulkActions([
                \Filament\Actions\BulkAction::make('delete')
                    ->label(trans('pelican-mod-manager::strings.actions.uninstall'))
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn () => $this->activeTab === 'installed')
                    ->action(function (\Illuminate\Support\Collection $records) {
                        try {
                            $this->uninstallInstalledRecords($records);
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
    }

    /**
     * Called from the browse tab's Version Selection button via Alpine $wire.
     * Mounts the browse_versions page action server-side so Filament opens the modal.
     */
    public function openBrowseVersions(string $projectId, string $title = ''): void
    {
        $this->mountAction('browse_versions', ['projectId' => $projectId, 'title' => $title]);
    }

    /**
     * Called from the installed tab's trash button via Alpine $wire.
     * Mounts the confirm_uninstall page action (CSS-hidden in the header) so Filament
     * opens the confirmation modal. Mirrors the openBrowseVersions pattern — using a
     * page action avoids the mountTableAction record-fetch issue with non-DB records.
     */
    public function openConfirmUninstall(string $projectId, string $filename, bool $isLocal, string $title): void
    {
        $this->mountAction('confirm_uninstall', compact('projectId', 'filename', 'isLocal', 'title'));
    }

    /**
     * Opens the upload_mod modal from the installed tab filter bar.
     * The activeTab guard prevents queued Livewire requests (from spam-clicking)
     * from mounting the modal after the user has already switched to another tab.
     */
    public function openUploadModal(): void
    {
        if ($this->activeTab !== 'installed') return;
        $this->mountAction('upload_mod');
    }

    public function copyModLink(string $slug, string $projectType = 'mod'): void
    {
        if (empty($slug)) return;
        $url = addslashes('https://modrinth.com/' . $projectType . '/' . $slug);
        $this->js("navigator.clipboard.writeText('{$url}')");
        Notification::make()->title('Link copied!')->success()->send();
    }

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

            $this->invalidateMetadataCache();
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

            $this->invalidateMetadataCache();
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

            $this->invalidateMetadataCache();
            $this->versionsCache = [];
            $this->recheckHasUpdates();

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.update_success'))
                ->body(trans('pelican-mod-manager::strings.notifications.update_success_body', [
                    'version' => $latestVersion['version_number'],
                ]))
                ->success()
                ->send();
        } catch (Exception $exception) {
            report($exception);

            $this->invalidateMetadataCache();
            $this->versionsCache = [];

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.update_failed'))
                ->body(trans('pelican-mod-manager::strings.notifications.update_failed_body'))
                ->danger()
                ->send();
        }
    }

    /**
     * Re-checks update availability using only cached version data and fresh metadata.
     * No API calls — uses whatever version data is already in the Laravel cache.
     * Call this after any single-mod update to keep the Updates chip and Update All button accurate.
     */
    protected function recheckHasUpdates(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $updateProjectIds = [];
        foreach ($this->getInstalledModsMetadata() as $mod) {
            if (str_starts_with($mod['project_id'] ?? '', 'local_')) continue;
            $cacheKey = "pmm_versions_{$mod['project_id']}_{$server->uuid}";
            $versions = cache()->get($cacheKey, []);
            if (!empty($versions) && ($mod['version_id'] ?? '') !== ($versions[0]['id'] ?? '')) {
                $updateProjectIds[] = $mod['project_id'];
            }
        }
        $this->installedUpdateProjectIds = array_fill_keys($updateProjectIds, true);
        $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        cache()->put("pmm_update_project_ids_{$server->uuid}", $updateProjectIds, now()->addMinutes(5));
        cache()->put("pmm_update_check_complete_{$server->uuid}", true, now()->addMinutes(5));
        cache()->put("pmm_has_updates_{$server->uuid}", $this->installedHasUpdates, now()->addMinutes(5));
    }

    public function toggleModStatus(string $projectId, string $filename, bool $currentlyEnabled): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();

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

            app(ModManagerFileService::class)->renameFiles($server, [
                [
                    'from' => $folder . '/' . $oldFilename,
                    'to' => $folder . '/' . $newFilename,
                ],
            ]);

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

            // Update metadata and bust cache — no full re-render needed.
            // The Alpine optimistic toggle already flipped the visual state instantly.
            $this->invalidateMetadataCache();
            $this->versionsCache = [];

            $stats = app(InstalledModsService::class)->patchEnabledStateInCaches($server, [[
                'project_id' => $projectId,
                'old_filename' => $oldFilename,
                'new_filename' => $newFilename,
                'is_disabled' => $currentlyEnabled,
            ]]);
            $this->installedHasDisabled = $stats['has_disabled'] ?? false;
            if (!$this->installedHasDisabled && in_array($this->installedStatusFilter, ['enabled', 'disabled'], true)) {
                $this->installedStatusFilter = 'all';
            }

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



    protected function getInstalledSelectionBarScript(): string
    {
        return (PelicanModManagerPageRenderer::installedSelectionBarScript())->call($this);
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
                    ->state(fn () => new HtmlString("<div class=\"modrinth-custom-styles\"><style>" . $this->getDynamicStyles() . "</style><script>" . $this->getInstalledSelectionBarScript() . "</script></div>")),
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
                            ->state(function () use ($server) {
                                $resolved = cache()->get("modrinth_installed_resolved_list_" . $server->uuid);
                                if (is_array($resolved)) {
                                    return count($resolved);
                                }

                                return count($this->getInstalledModsMetadata());
                            })
                            ->badge(),
                    ]),
                $this->getTabsContentComponent(),
                TextEntry::make('browse_filter_bar')
                    ->hiddenLabel()
                    ->hidden(fn () => $this->activeTab === 'installed')
                    ->state(fn () => new HtmlString($this->renderBrowseFilterBar())),
                TextEntry::make('installed_loading')
                    ->hiddenLabel()
                    ->hidden(fn () => true)
                    ->state(fn () => new HtmlString('')),
                TextEntry::make('installed_filter_bar')
                    ->hiddenLabel()
                    ->hidden(fn () => $this->activeTab !== 'installed' || !$this->installedDataReady)
                    ->state(fn () => new HtmlString($this->renderInstalledFilterBar())),
                EmbeddedTable::make(),
                TextEntry::make('installed_selection_bar')
                    ->hiddenLabel()
                    ->hidden(fn () => $this->activeTab !== 'installed')
                    ->state(fn () => new HtmlString($this->renderInstalledSelectionBar())),
            ]);
    }

    protected function renderInstalledSelectionBar(): string
    {
        return (PelicanModManagerPageRenderer::installedSelectionBar())->call($this);
    }

    protected function renderInstalledFilterBar(): string
    {
        return (PelicanModManagerPageRenderer::installedFilterBar())->call($this);
    }

    protected function getBrowseCategoryOptions(): array
    {
        return [
            'adventure' => 'Adventure',
            'cursed' => 'Cursed',
            'decoration' => 'Decoration',
            'economy' => 'Economy',
            'equipment' => 'Equipment',
            'food' => 'Food',
            'game-mechanics' => 'Game Mechanics',
            'library' => 'Library',
            'magic' => 'Magic',
            'management' => 'Management',
            'minigame' => 'Minigame',
            'mobs' => 'Mobs',
            'optimization' => 'Optimization',
            'social' => 'Social',
            'storage' => 'Storage',
            'technology' => 'Technology',
            'transportation' => 'Transportation',
            'utility' => 'Utility',
            'worldgen' => 'World Generation',
        ];
    }

    public function toggleBrowseCategoryFilter(string $category): void
    {
        if (!array_key_exists($category, $this->getBrowseCategoryOptions())) return;

        $this->browseCategoryFilters = in_array($category, $this->browseCategoryFilters, true)
            ? array_values(array_diff($this->browseCategoryFilters, [$category]))
            : array_values(array_unique([...$this->browseCategoryFilters, $category]));
        $this->browseExcludedCategoryFilters = array_values(array_diff($this->browseExcludedCategoryFilters, [$category]));
        $this->setBrowsePage(1);
    }

    public function toggleBrowseExcludedCategoryFilter(string $category): void
    {
        if (!array_key_exists($category, $this->getBrowseCategoryOptions())) return;

        $this->browseExcludedCategoryFilters = in_array($category, $this->browseExcludedCategoryFilters, true)
            ? array_values(array_diff($this->browseExcludedCategoryFilters, [$category]))
            : array_values(array_unique([...$this->browseExcludedCategoryFilters, $category]));
        $this->browseCategoryFilters = array_values(array_diff($this->browseCategoryFilters, [$category]));
        $this->setBrowsePage(1);
    }

    public function toggleBrowseEnvironmentFilter(string $environment): void
    {
        if (!in_array($environment, ['client', 'server'], true)) return;

        $this->browseEnvironmentFilters = in_array($environment, $this->browseEnvironmentFilters, true)
            ? array_values(array_diff($this->browseEnvironmentFilters, [$environment]))
            : array_values(array_unique([...$this->browseEnvironmentFilters, $environment]));
        $this->browseExcludedEnvironmentFilters = array_values(array_diff($this->browseExcludedEnvironmentFilters, [$environment]));
        $this->setBrowsePage(1);
    }

    public function toggleBrowseExcludedEnvironmentFilter(string $environment): void
    {
        if (!in_array($environment, ['client', 'server'], true)) return;

        $this->browseExcludedEnvironmentFilters = in_array($environment, $this->browseExcludedEnvironmentFilters, true)
            ? array_values(array_diff($this->browseExcludedEnvironmentFilters, [$environment]))
            : array_values(array_unique([...$this->browseExcludedEnvironmentFilters, $environment]));
        $this->browseEnvironmentFilters = array_values(array_diff($this->browseEnvironmentFilters, [$environment]));
        $this->setBrowsePage(1);
    }

    public function toggleBrowseOpenSourceFilter(): void
    {
        $this->browseOpenSourceOnly = !$this->browseOpenSourceOnly;
        if ($this->browseOpenSourceOnly) {
            $this->browseExcludeOpenSource = false;
        }
        $this->setBrowsePage(1);
    }

    public function toggleBrowseExcludeOpenSourceFilter(): void
    {
        $this->browseExcludeOpenSource = !$this->browseExcludeOpenSource;
        if ($this->browseExcludeOpenSource) {
            $this->browseOpenSourceOnly = false;
        }
        $this->setBrowsePage(1);
    }

    public function toggleBrowseHideInstalled(): void
    {
        $this->browseHideInstalled = !$this->browseHideInstalled;
        $this->setBrowsePage(1);
    }

    /** @return array<string, mixed> */
    protected function getBrowseFilters(): array
    {
        $filters = [
            'categories' => $this->browseCategoryFilters,
            'excluded_categories' => $this->browseExcludedCategoryFilters,
            'environments' => $this->browseEnvironmentFilters,
            'excluded_environments' => $this->browseExcludedEnvironmentFilters,
            'open_source' => $this->browseOpenSourceOnly,
            'exclude_open_source' => $this->browseExcludeOpenSource,
        ];

        if ($this->browseHideInstalled) {
            $filters['exclude_project_ids'] = collect($this->getInstalledModsMetadata())
                ->pluck('project_id')
                ->filter(fn ($id) => is_string($id) && !str_starts_with($id, 'local_'))
                ->unique()
                ->values()
                ->toArray();
        }

        return $filters;
    }

    protected function renderBrowseFilterBar(): string
    {
        return (PelicanModManagerPageRenderer::browseFilterBar())->call($this);
    }

    /**
     * Livewire lifecycle hook — fires when $activeTab changes.
     */
    public function updatedActiveTab(): void
    {
        $this->resetUrlBackedStateForTabChange();
        $this->syncActiveTabPath();

        if ($this->activeTab === 'installed') {
            /** @var Server $server */
            $server = Filament::getTenant();
            $cachedUpdateProjectIds = cache()->get("pmm_update_project_ids_{$server->uuid}", []);
            if (is_array($cachedUpdateProjectIds)) {
                $this->installedUpdateProjectIds = array_fill_keys($cachedUpdateProjectIds, true);
                $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
            }
            $this->installedUpdatesChecked = cache()->get("pmm_update_check_complete_{$server->uuid}", false) === true;
            // Don't reset installedDataReady/installedEnriched — keeps tab
            // switches fast within the same session once data is loaded.
        }
    }

    protected function resetUrlBackedStateForTabChange(): void
    {
        $this->browseSearch = '';
        $this->browseSortMode = 'relevance';
        $this->browsePageSize = 20;
        $this->browseCurrentPage = 1;
        $this->browseCategoryFilters = [];
        $this->browseExcludedCategoryFilters = [];
        $this->browseEnvironmentFilters = [];
        $this->browseExcludedEnvironmentFilters = [];
        $this->browseOpenSourceOnly = false;
        $this->browseExcludeOpenSource = false;
        $this->browseHideInstalled = false;

        $this->installedSearch = '';
        $this->installedStatusFilter = 'all';
        $this->installedSortMode = 'alpha_asc';

        $this->gotoPage(1);
    }

    protected function syncActiveTabPath(): void
    {
        $tab = in_array($this->activeTab, ['installed', 'browse'], true) ? $this->activeTab : 'installed';
        $this->js(<<<JS
            (() => {
                const url = new URL(window.location.href);
                const replacement = '/mods/{$tab}';
                if (/\\/mods(?:\\/(?:browse|installed))?\\/?$/.test(url.pathname)) {
                    url.pathname = url.pathname.replace(/\\/mods(?:\\/(?:browse|installed))?\\/?$/, replacement);
                } else {
                    url.pathname = url.pathname.replace(/\\/?$/, replacement);
                }
                url.search = '';
                window.history.replaceState({}, '', url);
            })()
        JS);
    }

    public function setInstalledFilter(string $filter): void
    {
        $this->installedStatusFilter = $filter;
    }

    public function setInstalledSort(string $mode): void
    {
        $allowed = ['alpha_asc', 'alpha_desc', 'newest', 'oldest'];
        if (in_array($mode, $allowed, true)) {
            $this->installedSortMode = $mode;
        }
    }

    public function setBrowseSort(string $mode): void
    {
        $allowed = ['relevance', 'downloads', 'follows', 'newest', 'updated'];
        if (in_array($mode, $allowed, true)) {
            $this->browseSortMode = $mode;
            $this->setBrowsePage(1);
        }
    }

    public function setBrowsePageSize(int $size): void
    {
        $allowed = [5, 10, 15, 20, 50, 100];
        if (in_array($size, $allowed, true)) {
            $this->browsePageSize = $size;
            $this->setBrowsePage(1);
        }
    }

    public function setBrowsePage(int $page): void
    {
        $this->browseCurrentPage = max(1, $page);
        $this->gotoPage($this->browseCurrentPage);
    }

    public function updatedBrowseSearch(): void
    {
        $this->setBrowsePage(1);
    }

    /**
     * Triggered by x-init on the loading skeleton after the first (instant) render.
     * Reads only the local metadata file (no network calls) and shows tracked mods
     * immediately. The filter bar then fires checkInstalledUpdates() async which
     * enriches with Wings file listing + Modrinth icons/avatars.
     */
    public function loadInstalledData(): void
    {
        if ($this->activeTab !== 'installed') return;
        // Pre-load metadata into memory (fast, local file read)
        $this->getInstalledModsMetadata();
        $this->installedDataReady = true;
        // records() now returns getMetadataOnlyList() — instant, no API calls
    }

    /**
     * Triggered lazily from the filter bar's x-init after the page renders.
     * Uses a 5-minute Laravel cache so repeated calls (filter clicks, search, etc.) are instant.
     *
     * Key speed trick: we know every Modrinth project_id from the local metadata file
     * (no network needed). So we fire all per-mod version requests in parallel via
     * Http::pool() at the same time as the Wings file-listing + Modrinth bulk-projects
     * calls inside getInstalledModsResolvedList(). The version pool finishes in ~1s
     * regardless of mod count, eliminating the previous N × 0.5-1s sequential wait.
     */
    public function checkInstalledUpdates(): void
    {
        if ($this->activeTab !== 'installed') return;

        /** @var Server $server */
        $server = Filament::getTenant();
        $updatesCacheKey = "pmm_update_project_ids_{$server->uuid}";

        $type = ModrinthProjectType::fromServer($server);
        if (!$type) { $this->installedUpdatesChecked = true; return; }

        $completeCacheKey = "pmm_update_check_complete_{$server->uuid}";

        // Warm icons, author names/avatars, and local jars first. Update checks
        // are chunked so this request does not fan out across every project at once.
        $items = $this->getInstalledModsResolvedList($server, $type);
        $this->installedEnriched = true;

        $cachedUpdateProjectIds = cache()->get($updatesCacheKey, []);
        if (is_array($cachedUpdateProjectIds)) {
            $this->installedUpdateProjectIds = array_fill_keys($cachedUpdateProjectIds, true);
            $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        }

        $metadataIds = collect($this->getInstalledModsMetadata())
            ->pluck('project_id')
            ->filter(fn ($id) => !str_starts_with($id, 'local_'))
            ->unique()
            ->values()
            ->toArray();

        if (empty($metadataIds) || cache()->get($completeCacheKey, false) === true) {
            $this->installedUpdatesChecked = true;
            cache()->put($completeCacheKey, true, now()->addMinutes(5));
            cache()->put("pmm_has_updates_{$server->uuid}", $this->installedHasUpdates, now()->addMinutes(5));
            return;
        }

        $this->installedUpdateCheckCursor = min($this->installedUpdateCheckCursor, count($metadataIds));
        $batchIds = array_slice($metadataIds, $this->installedUpdateCheckCursor, $this->installedUpdateCheckBatchSize);
        $this->warmVersionsCacheParallel($server, $batchIds);

        $itemsByProjectId = collect($items)->keyBy('project_id');
        foreach ($batchIds as $projectId) {
            $item = $itemsByProjectId->get($projectId);
            $meta = $item['metadata'] ?? $this->getInstalledMod($projectId);
            $versions = $this->getCachedVersions($projectId);
            if ($meta && !empty($versions) && ($meta['version_id'] ?? '') !== ($versions[0]['id'] ?? '')) {
                $this->installedUpdateProjectIds[$projectId] = true;
            } else {
                unset($this->installedUpdateProjectIds[$projectId]);
            }
        }

        $this->installedUpdateCheckCursor += count($batchIds);
        $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        $this->installedUpdatesChecked = $this->installedUpdateCheckCursor >= count($metadataIds);
        cache()->put($updatesCacheKey, array_keys($this->installedUpdateProjectIds), now()->addMinutes(5));
        cache()->put($completeCacheKey, $this->installedUpdatesChecked, now()->addMinutes(5));
        cache()->put("pmm_has_updates_{$server->uuid}", $this->installedHasUpdates, now()->addMinutes(5));

        if (!$this->installedUpdatesChecked) {
            $this->js("setTimeout(() => \$wire.call('checkInstalledUpdates'), 75)");
        }

        return;

    }

    public function refreshInstalled(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = ModrinthProjectType::fromServer($server);
        if (!$type) return;

        $previousUpdateProjectIds = array_keys($this->installedUpdateProjectIds);
        if (empty($previousUpdateProjectIds)) {
            $cachedUpdateProjectIds = cache()->get("pmm_update_project_ids_{$server->uuid}", []);
            $previousUpdateProjectIds = is_array($cachedUpdateProjectIds) ? $cachedUpdateProjectIds : [];
        }

        foreach ($this->getInstalledModsMetadata() as $mod) {
            cache()->forget("pmm_versions_{$mod['project_id']}_{$server->uuid}");
        }

        $this->invalidateMetadataCache();
        $this->versionsCache = [];
        cache()->forget("modrinth_installed_resolved_list_" . $server->uuid);
        cache()->forget("pmm_basic_installed_{$server->uuid}");
        cache()->forget("pmm_has_updates_{$server->uuid}");
        cache()->forget("pmm_update_project_ids_{$server->uuid}");
        cache()->forget("pmm_update_check_complete_{$server->uuid}");

        $items = $this->getInstalledModsResolvedList($server, $type);
        $metadataIds = collect($this->getInstalledModsMetadata())
            ->pluck('project_id')
            ->filter(fn ($id) => !str_starts_with($id, 'local_'))
            ->unique()
            ->values()
            ->toArray();

        $updateProjectIds = [];
        $checkedProjectIds = [];
        $metadataByProjectId = collect($this->getInstalledModsMetadata())->keyBy('project_id');
        foreach (array_chunk($metadataIds, $this->installedUpdateCheckBatchSize) as $batchIds) {
            $this->warmVersionsCacheParallel($server, $batchIds);

            foreach ($batchIds as $projectId) {
                $meta = $metadataByProjectId->get($projectId) ?? $this->getInstalledMod($projectId);
                $versions = $this->getCachedVersions($projectId);
                if (!empty($versions)) {
                    $checkedProjectIds[] = $projectId;
                }
                if ($meta && !empty($versions) && ($meta['version_id'] ?? '') !== ($versions[0]['id'] ?? '')) {
                    $updateProjectIds[] = $projectId;
                }
            }
        }

        $missingProjectIds = array_values(array_diff($metadataIds, $checkedProjectIds));
        if (!empty($missingProjectIds)) {
            $updateProjectIds = array_values(array_unique(array_merge(
                $updateProjectIds,
                array_intersect($previousUpdateProjectIds, $missingProjectIds)
            )));
        }
        $updateCheckComplete = empty($missingProjectIds);

        $this->installedEnriched = true;
        $this->installedUpdateProjectIds = array_fill_keys($updateProjectIds, true);
        $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        $this->installedUpdatesChecked = $updateCheckComplete;
        $this->installedUpdateCheckCursor = count($metadataIds);
        cache()->put("pmm_update_project_ids_{$server->uuid}", $updateProjectIds, now()->addMinutes(5));
        cache()->put("pmm_update_check_complete_{$server->uuid}", $updateCheckComplete, now()->addMinutes(5));
        cache()->put("pmm_has_updates_{$server->uuid}", $this->installedHasUpdates, now()->addMinutes(5));
    }

    public function updateAllMods(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = ModrinthProjectType::fromServer($server);
        if (!$type) return;

        $updated = 0;
        $failed  = 0;

        try {
            $items = $this->getInstalledModsResolvedList($server, $type);
        } catch (Exception $e) {
            report($e);
            Notification::make()->title('Failed to load mod list')->danger()->send();
            return;
        }

        foreach ($items as $record) {
            if (!empty($record['is_local'])) continue;
            $versions = $this->getCachedVersions($record['project_id']);
            if (empty($versions)) continue;
            $meta = $record['metadata'] ?? null;
            if (!$meta || ($meta['version_id'] ?? '') === ($versions[0]['id'] ?? '')) continue;

            try {
                $primaryFile = $this->getPrimaryFile($versions[0]['files'] ?? []);
                if (!$primaryFile) continue;
                $this->performInstallOrUpdate($server, $record, $versions[0], $primaryFile, $meta);
                $updated++;
            } catch (Exception $e) {
                report($e);
                $failed++;
            }
        }

        $this->invalidateMetadataCache();
        $this->versionsCache = [];
        $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        cache()->put("pmm_update_project_ids_{$server->uuid}", array_keys($this->installedUpdateProjectIds), now()->addMinutes(5));
        cache()->put("pmm_update_check_complete_{$server->uuid}", true, now()->addMinutes(5));
        cache()->put("pmm_has_updates_{$server->uuid}", $this->installedHasUpdates, now()->addMinutes(5));

        $msg = $failed === 0
            ? "Updated {$updated} mod(s) successfully."
            : "Updated {$updated} mod(s), {$failed} failed.";
        ($failed === 0 ? Notification::make()->success() : Notification::make()->warning())
            ->title($failed === 0 ? 'All mods updated' : 'Update completed with errors')
            ->body($msg)
            ->send();
    }
}
