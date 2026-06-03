<?php

namespace MrBytesized\PelicanModManager\Support;

use App\Models\Server;
use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;
use MrBytesized\PelicanModManager\Facades\PelicanModManager;

class PelicanModManagerTableBuilder
{
    public static function table(): Closure
    {
        return function (Table $table): Table {
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
                        $showFileUrl = $modType
                            ? e(url('/server/' . rawurlencode((string) $server->getRouteKey()) . '/files') . '?path=' . rawurlencode($modType->getFolder()))
                            : '#';

                        // Check if this mod has an update available
                        $hasModUpdate = !$isLocal && isset($this->installedUpdateProjectIds[$record['project_id'] ?? '']);

                        // Single primary icon — green download (no border) when update available,
                        // ? change-version otherwise. Both open the version-selection modal.
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
                        // - $dispatch('pmm-close-dropdowns') ? closes every other open dropdown on the page.
                        // - x-on:pmm-close-dropdowns.window ? each instance listens and closes itself.
                        //   Dispatch is synchronous; open=!prev immediately re-opens the target instance.
                        // - x-on:click.away on the teleported panel ? closes on outside clicks that
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

                        // Final order: ?/? primary icon | toggle | ?? delete | ? three-dot
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
        };
    }
}
