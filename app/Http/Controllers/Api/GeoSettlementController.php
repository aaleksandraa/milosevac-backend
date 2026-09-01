<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\GeoMunicipalitySummaryResource;
use App\Http\Resources\Geo\GeoSettlementResource;
use App\Http\Resources\Geo\GeoSettlementSummaryResource;
use App\Support\Atlas\AtlasQueryService;
use Illuminate\Http\JsonResponse;

class GeoSettlementController extends Controller
{
    public function index(string $municipality, AtlasQueryService $atlas): JsonResponse
    {
        $row = $atlas->municipalityBySlug($municipality);
        abort_if($row === null, 404);

        $row->settlements->each(fn ($settlement) => $settlement->setRelation('municipality', $row));

        return response()->json([
            'municipality' => (new GeoMunicipalitySummaryResource($row))->resolve(),
            'settlements' => GeoSettlementSummaryResource::collection($row->settlements)->resolve(),
        ]);
    }

    public function show(string $municipality, string $settlement, AtlasQueryService $atlas): JsonResponse
    {
        $row = $atlas->settlementInMunicipality($municipality, $settlement);
        abort_if($row === null, 404);

        return response()->json([
            'settlement' => (new GeoSettlementResource($row))->resolve(),
        ]);
    }

    public function showById(int $settlement, AtlasQueryService $atlas): JsonResponse
    {
        $row = $atlas->settlementById($settlement);
        abort_if($row === null, 404);

        return response()->json([
            'settlement' => (new GeoSettlementResource($row))->resolve(),
        ]);
    }
}
