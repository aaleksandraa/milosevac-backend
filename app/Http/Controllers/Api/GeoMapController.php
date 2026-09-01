<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SettlementBoundary;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\AtlasQueryService;
use App\Support\Atlas\Map\SettlementMapPresenter;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GeoMapController extends Controller
{
    private const CACHE_TTL_SECONDS = 600;

    public function settlements(Request $request, AtlasQueryService $atlas): JsonResponse
    {
        $municipality = $request->query('municipality');
        $municipality = is_string($municipality) && $municipality !== '' ? $municipality : null;

        if ($municipality !== null) {
            abort_if(! $atlas->municipalitySlugExists($municipality), 404);
        }

        $version = $this->datasetVersion();
        $cacheKey = 'geo.map.settlements.'.($municipality ?? 'all').'.'.$version;
        $etag = sha1($cacheKey);
        $lastModified = $this->lastModifiedAt();

        $payload = cache()->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => (new SettlementMapPresenter)->collection($atlas->settlementsForMap($municipality)),
        );

        $response = response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
            ->setEtag($etag);

        if ($lastModified instanceof DateTimeInterface) {
            $response->setLastModified($lastModified);
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }

    private function datasetVersion(): string
    {
        $boundaryStamp = SettlementBoundary::query()->max('updated_at');
        $terrainStamp = SettlementTerrainStat::query()->max('updated_at');
        $boundaryCount = SettlementBoundary::query()->where('geometry_valid', true)->count();

        return sha1(implode('|', [
            (string) $boundaryStamp,
            (string) $terrainStamp,
            (string) $boundaryCount,
        ]));
    }

    private function lastModifiedAt(): ?Carbon
    {
        $stamps = array_filter([
            SettlementBoundary::query()->max('updated_at'),
            SettlementTerrainStat::query()->max('updated_at'),
        ]);

        if ($stamps === []) {
            return null;
        }

        return Carbon::parse(max($stamps));
    }
}
