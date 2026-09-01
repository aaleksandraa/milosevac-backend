<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\GeoMunicipalitySummaryResource;
use App\Support\Atlas\AtlasQueryService;
use Illuminate\Http\JsonResponse;

class GeoMunicipalityController extends Controller
{
    public function index(AtlasQueryService $atlas): JsonResponse
    {
        $municipalities = $atlas->municipalitiesForIndex();

        return response()->json([
            'municipalities' => $municipalities
                ->map(fn ($municipality) => (new GeoMunicipalitySummaryResource($municipality, true))->resolve())
                ->values(),
        ]);
    }

    public function show(string $municipality, AtlasQueryService $atlas): JsonResponse
    {
        $row = $atlas->municipalityBySlug($municipality);
        abort_if($row === null, 404);

        return response()->json([
            'municipality' => (new GeoMunicipalitySummaryResource($row, true))->resolve(),
        ]);
    }
}
