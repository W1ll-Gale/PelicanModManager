<?php

namespace MrBytesized\PelicanModManager\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use MrBytesized\PelicanModManager\Facades\PelicanModManager;
use MrBytesized\PelicanModManager\Services\InstalledModsService;
use MrBytesized\PelicanModManager\Services\ModManagerFileService;
use MrBytesized\PelicanModManager\Services\ModrinthClient;
use MrBytesized\PelicanModManager\Support\Concerns\HandlesInstalledBulkActions;
use MrBytesized\PelicanModManager\Support\PelicanModManagerHeaderActions;
use MrBytesized\PelicanModManager\Support\PelicanModManagerImportActions;
use MrBytesized\PelicanModManager\Support\PelicanModManagerPageRenderer;
use MrBytesized\PelicanModManager\Support\PelicanModManagerTableBuilder;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class PelicanModManagerProjectPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HandlesInstalledBulkActions;
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
        return (PelicanModManagerTableBuilder::table())->call($this, $table);
    }

    protected function getHeaderActions(): array
    {
        return (PelicanModManagerHeaderActions::actions())->call($this);
    }

    public function processImportTick(): void
    {
        (PelicanModManagerImportActions::processImportTick())->call($this);
    }

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
        $this->handleClearInstalledSelection();
    }

    public function uninstallSelectedInstalledMods(): void
    {
        $this->handleUninstallSelectedInstalledMods();
    }

    /**
     * @param string[] $ids
     */
    public function uninstallInstalledModsByIds(array $ids): void
    {
        $this->handleUninstallInstalledModsByIds($ids);
    }

    /**
     * @param string[] $ids
     */
    public function setSelectedInstalledModsEnabled(array $ids, bool $enabled): void
    {
        $this->handleSetSelectedInstalledModsEnabled($ids, $enabled);
    }

    /**
     * @param array<int, array{id?: string, project_id?: string, filename?: string}> $rows
     */
    public function setInstalledModRowsEnabled(array $rows, bool $enabled): void
    {
        $this->handleSetInstalledModRowsEnabled($rows, $enabled);
    }

    public function prepareSelectedModpackExport(): void
    {
        $this->handlePrepareSelectedModpackExport();
    }

    /**
     * @param string[] $ids
     */
    public function exportSelectedModpack(array $ids): mixed
    {
        return $this->handleExportSelectedModpack($ids);
    }

    public function setInstalledBulkSelection(string $idsJson): void
    {
        $this->handleSetInstalledBulkSelection($idsJson);
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
     * Hydrate installed rows with Modrinth project/team data, then start the
     * slower update-version checks in a separate request so icons/authors can
     * repaint without waiting for version API batches.
     */
    public function enrichInstalledMods(): void
    {
        if ($this->activeTab !== 'installed' || $this->installedEnriched) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = ModrinthProjectType::fromServer($server);
        if (!$type) {
            $this->installedEnriched = true;
            $this->installedUpdatesChecked = true;
            return;
        }

        $this->getInstalledModsResolvedList($server, $type);
        $this->installedEnriched = true;

        $cachedUpdateProjectIds = cache()->get("pmm_update_project_ids_{$server->uuid}", []);
        if (is_array($cachedUpdateProjectIds)) {
            $this->installedUpdateProjectIds = array_fill_keys($cachedUpdateProjectIds, true);
            $this->installedHasUpdates = !empty($this->installedUpdateProjectIds);
        }

        $this->installedUpdatesChecked = cache()->get("pmm_update_check_complete_{$server->uuid}", false) === true;
        if (!$this->installedUpdatesChecked) {
            $this->js("setTimeout(() => \$wire.call('checkInstalledUpdates'), 50)");
        }
    }

    /**
     * Check update state in batches after the installed rows have already been
     * enriched. Version APIs stay out of first paint and icon/author hydration.
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

        // Reuse the enriched list if it is already cached. Update checks are
        // chunked so this request does not fan out across every project at once.
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
