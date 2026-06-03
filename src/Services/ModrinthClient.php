<?php

namespace MrBytesized\PelicanModManager\Services;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class ModrinthClient
{
    protected function timeout(): int
    {
        return (int) config('pelican-mod-manager.modrinth.timeout', 5);
    }

    protected function connectTimeout(): int
    {
        return (int) config('pelican-mod-manager.modrinth.connect_timeout', 5);
    }

    protected function cacheMinutes(string $key, int $default): int
    {
        return (int) config("pelican-mod-manager.cache.{$key}_minutes", $default);
    }

    public function getLatestMinecraftVersion(): ?string
    {
        return cache()->remember('modrinth:latest_minecraft_version', now()->addMinutes($this->cacheMinutes('tags', 60)), function () {
            try {
                /** @var array<int, mixed> $versions */
                $versions = Http::asJson()
                    ->timeout($this->timeout())
                    ->connectTimeout($this->connectTimeout())
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/game_version')
                    ->json();

                return collect($versions)->filter(fn ($version) => $version['version_type'] === 'release')->first()['version'] ?? null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /** @return array<int, array{icon: string, name: string, supported_project_types: string[]}> */
    public function getLoaders(): array
    {
        return cache()->remember('modrinth:loaders', now()->addMinutes($this->cacheMinutes('tags', 60)), function () {
            try {
                return Http::asJson()
                    ->timeout($this->timeout())
                    ->connectTimeout($this->connectTimeout())
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/loader')
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function searchProjects(
        string $projectType,
        string $minecraftVersion,
        string $minecraftLoader,
        int $page = 1,
        ?string $search = null,
        ?string $sortColumn = null,
        ?string $sortDirection = null,
        array $filters = [],
        int $limit = 20
    ): array {
        $limit = max(1, min((int) config('pelican-mod-manager.browse.max_page_size', 100), $limit));
        $facets = [
            ["categories:$minecraftLoader"],
            ["versions:$minecraftVersion"],
            ["project_type:{$projectType}"],
        ];

        $categories = array_values(array_filter($filters['categories'] ?? []));
        if (!empty($categories)) {
            $facets[] = array_map(fn ($category) => "categories:{$category}", $categories);
        }

        foreach (array_values(array_filter($filters['excluded_categories'] ?? [])) as $category) {
            $facets[] = ["categories!={$category}"];
        }

        $environments = array_values(array_filter($filters['environments'] ?? []));
        if (in_array('client', $environments, true)) {
            $facets[] = ['client_side:required', 'client_side:optional'];
        }
        if (in_array('server', $environments, true)) {
            $facets[] = ['server_side:required', 'server_side:optional'];
        }

        $excludedEnvironments = array_values(array_filter($filters['excluded_environments'] ?? []));
        if (in_array('client', $excludedEnvironments, true)) {
            $facets[] = ['client_side:unsupported'];
        }
        if (in_array('server', $excludedEnvironments, true)) {
            $facets[] = ['server_side:unsupported'];
        }

        if (!empty($filters['open_source'])) {
            $facets[] = ['open_source:true'];
        }
        if (!empty($filters['exclude_open_source'])) {
            $facets[] = ['open_source:false'];
        }

        $excludedProjectIds = array_flip(array_values(array_filter($filters['exclude_project_ids'] ?? [])));
        $requestLimit = !empty($excludedProjectIds) ? min(100, max($limit * 3, $limit + 10)) : $limit;

        $data = [
            'offset' => ($page - 1) * $limit,
            'limit' => $requestLimit,
            'facets' => json_encode($facets),
        ];

        if ($sortColumn === 'downloads') {
            $data['index'] = 'downloads';
        } elseif ($sortColumn === 'date_modified') {
            $data['index'] = 'updated';
        }

        $filterKey = md5(json_encode($filters));
        $key = "modrinth_projects:{$projectType}:$minecraftVersion:$minecraftLoader:$page:$limit:$filterKey";

        if ($search) {
            $data['query'] = $search;
            $key .= ":$search";
        }

        if ($sortColumn) {
            $key .= ":{$sortColumn}:{$sortDirection}";
        }

        $response = cache()->remember($key, now()->addMinutes($this->cacheMinutes('search', 30)), function () use ($data) {
            try {
                return Http::asJson()
                    ->timeout($this->timeout())
                    ->connectTimeout($this->connectTimeout())
                    ->throw()
                    ->get('https://api.modrinth.com/v2/search', $data)
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [
                    'hits' => [],
                    'total_hits' => 0,
                ];
            }
        });

        if (!empty($excludedProjectIds) && isset($response['hits']) && is_array($response['hits'])) {
            $response['hits'] = collect($response['hits'])
                ->reject(fn ($hit) => isset($excludedProjectIds[$hit['project_id'] ?? '']))
                ->take($limit)
                ->values()
                ->toArray();
            $response['total_hits'] = max(0, (int)($response['total_hits'] ?? 0) - count($excludedProjectIds));
            $response['limit'] = count($response['hits']);
        }

        if ($sortColumn === 'title' || $sortColumn === 'author') {
            $descending = $sortDirection === 'desc';
            if (isset($response['hits']) && is_array($response['hits'])) {
                $response['hits'] = collect($response['hits'])
                    ->sortBy(fn ($item) => strtolower($item[$sortColumn] ?? ''), SORT_REGULAR, $descending)
                    ->values()
                    ->toArray();
            }
        }

        return $response;
    }

    /** @return array<string, array<string, mixed>> */
    public function getProjectsMap(array $projectIds): array
    {
        $projectIds = collect($projectIds)->filter()->unique()->values()->toArray();
        if (empty($projectIds)) {
            return [];
        }

        $idsParam = json_encode($projectIds);
        $projects = cache()->remember('modrinth_bulk:' . md5($idsParam), now()->addMinutes($this->cacheMinutes('projects', 30)), function () use ($idsParam) {
            try {
                return Http::asJson()
                    ->timeout(10)
                    ->connectTimeout($this->connectTimeout())
                    ->throw()
                    ->get('https://api.modrinth.com/v2/projects', [
                        'ids' => $idsParam,
                    ])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });

        $map = [];
        if (is_array($projects)) {
            foreach ($projects as $project) {
                if (isset($project['id'])) {
                    $map[$project['id']] = $project;
                }
            }
        }

        return $map;
    }

    /** @return array<string, array{username: ?string, avatar_url: ?string}> */
    public function getTeamAuthorsMap(array $teamIds): array
    {
        $teamIds = collect($teamIds)->filter()->unique()->values()->toArray();
        if (empty($teamIds)) {
            return [];
        }

        $idsParam = json_encode($teamIds);
        $teams = cache()->remember('modrinth_teams:' . md5($idsParam), now()->addMinutes($this->cacheMinutes('projects', 30)), function () use ($idsParam) {
            try {
                return Http::asJson()
                    ->timeout(10)
                    ->connectTimeout($this->connectTimeout())
                    ->get('https://api.modrinth.com/v2/teams', [
                        'ids' => $idsParam,
                    ])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });

        $map = [];
        if (is_array($teams)) {
            foreach ($teams as $members) {
                if (empty($members) || !is_array($members)) {
                    continue;
                }

                $teamId = $members[0]['team_id'] ?? null;
                if (!$teamId) {
                    continue;
                }

                $owner = collect($members)->firstWhere('role', 'Owner') ?? $members[0];
                $map[$teamId] = [
                    'username' => $owner['user']['username'] ?? null,
                    'avatar_url' => $owner['user']['avatar_url'] ?? null,
                ];
            }
        }

        return $map;
    }

    /** @return array<int, array<string, mixed>> */
    public function getProjectVersions(string $projectId, ?string $minecraftVersion, ?string $minecraftLoader, ?Server $server = null): array
    {
        $data = [];
        if ($minecraftVersion) {
            $data['game_versions'] = json_encode([$minecraftVersion]);
        }
        if ($minecraftLoader) {
            $data['loaders'] = json_encode([$minecraftLoader]);
        }

        $cacheKey = $server
            ? "pmm_versions_{$projectId}_{$server->uuid}"
            : "modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader";

        return cache()->remember($cacheKey, now()->addMinutes($this->cacheMinutes('versions', 10)), function () use ($projectId, $data) {
            try {
                $versions = Http::asJson()
                    ->timeout($this->timeout())
                    ->connectTimeout($this->connectTimeout())
                    ->throw()
                    ->get("https://api.modrinth.com/v2/project/$projectId/version", $data)
                    ->json();

                if (is_array($versions) && !empty($versions)) {
                    usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));
                }

                return is_array($versions) ? $versions : [];
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function warmProjectVersions(Server $server, array $projectIds, ?string $minecraftVersion, ?string $minecraftLoader, array $known = []): array
    {
        $projectIds = collect($projectIds)->filter()->unique()->values()->toArray();
        if (empty($projectIds)) {
            return $known;
        }

        $needed = [];
        foreach ($projectIds as $id) {
            if (isset($known[$id])) {
                continue;
            }

            $cacheKey = "pmm_versions_{$id}_{$server->uuid}";
            $cached = cache()->get($cacheKey);
            if ($cached !== null) {
                $known[$id] = $cached;
            } else {
                $needed[] = $id;
            }
        }

        if (empty($needed)) {
            return $known;
        }

        $params = [];
        if ($minecraftLoader) {
            $params['loaders'] = json_encode([$minecraftLoader]);
        }
        if ($minecraftVersion) {
            $params['game_versions'] = json_encode([$minecraftVersion]);
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($needed, $params) {
                foreach ($needed as $id) {
                    $pool->as($id)
                        ->timeout(10)
                        ->connectTimeout($this->connectTimeout())
                        ->get("https://api.modrinth.com/v2/project/{$id}/version", $params);
                }
            });

            foreach ($responses as $id => $response) {
                $versions = [];
                if (!($response instanceof Exception) && $response->successful()) {
                    $versions = $response->json() ?: [];
                    if (is_array($versions) && !empty($versions)) {
                        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));
                    }
                }

                cache()->put("pmm_versions_{$id}_{$server->uuid}", $versions, now()->addMinutes($this->cacheMinutes('versions', 10)));
                $known[$id] = $versions;
            }
        } catch (Exception $exception) {
            report($exception);
        }

        return $known;
    }

    /** @return array<string, array<string, mixed>> */
    public function getVersionsByHashes(array $hashes, string $algorithm = 'sha1'): array
    {
        $hashes = collect($hashes)->filter()->unique()->values()->toArray();
        if (empty($hashes)) {
            return [];
        }

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->connectTimeout($this->connectTimeout())
                ->throw()
                ->post('https://api.modrinth.com/v2/version_files', [
                    'hashes' => $hashes,
                    'algorithm' => $algorithm,
                ])
                ->json();

            return is_array($response) ? $response : [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    public function getPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return null;
    }
}
