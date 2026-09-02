<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoPlace;
use App\Models\SettlementBoundary;
use App\Models\SettlementClimateProfile;
use App\Models\SettlementPopulation;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\AtlasQueryService;
use App\Support\Atlas\Map\PlaceMapPresenter;
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

    public function places(Request $request, AtlasQueryService $atlas): JsonResponse
    {
        $municipality = $request->query('municipality');
        $municipality = is_string($municipality) && $municipality !== '' ? $municipality : null;
        $settlement = $request->query('settlement');
        $settlement = is_string($settlement) && $settlement !== '' ? $settlement : null;
        $type = $request->query('type');
        $type = is_string($type) && $type !== '' ? $type : null;
        $families = $atlas->parseFamilies(is_string($request->query('family')) ? $request->query('family') : null);

        if ($municipality !== null) {
            abort_if(! $atlas->municipalitySlugExists($municipality), 404);
        }
        if ($settlement !== null) {
            abort_if($municipality === null, 422);
            abort_if(! $atlas->settlementSlugInMunicipalityExists($municipality, $settlement), 404);
        }
        if ($type !== null) {
            abort_if(! $atlas->placeTypeCodeExists($type), 422);
        }

        $version = $this->placeDatasetVersion();
        $cacheKey = 'geo.map.places.'.($municipality ?? 'all').'.'.($settlement ?? 'all').'.'.implode('-', $families).'.'.($type ?? 'all').'.'.$version;
        $etag = sha1($cacheKey);
        $lastModified = $this->placesLastModifiedAt();

        $payload = cache()->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => (new PlaceMapPresenter)->collection(
                $atlas->placesForPublic($municipality, $settlement, $families, $type),
            ),
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
        $climateStamp = SettlementClimateProfile::query()->max('updated_at');
        $populationStamp = SettlementPopulation::query()->max('updated_at');
        $boundaryCount = SettlementBoundary::query()->where('geometry_valid', true)->count();

        return sha1(implode('|', [
            (string) $boundaryStamp,
            (string) $terrainStamp,
            (string) $climateStamp,
            (string) $populationStamp,
            (string) $boundaryCount,
        ]));
    }

    private function lastModifiedAt(): ?Carbon
    {
        $stamps = array_filter([
            SettlementBoundary::query()->max('updated_at'),
            SettlementTerrainStat::query()->max('updated_at'),
            SettlementClimateProfile::query()->max('updated_at'),
            SettlementPopulation::query()->max('updated_at'),
        ]);

        if ($stamps === []) {
            return null;
        }

        return Carbon::parse(max($stamps));
    }

    private function placeDatasetVersion(): string
    {
        return sha1(implode('|', [
            (string) GeoPlace::query()->max('updated_at'),
            (string) GeoPlace::query()->where('is_active', true)->count(),
        ]));
    }

    private function placesLastModifiedAt(): ?Carbon
    {
        $stamp = GeoPlace::query()->max('updated_at');

        return $stamp ? Carbon::parse($stamp) : null;
    }
}
