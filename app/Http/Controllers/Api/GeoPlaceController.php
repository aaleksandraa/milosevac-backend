<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\GeoPlaceResource;
use App\Support\Atlas\AtlasQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoPlaceController extends Controller
{
    public function index(Request $request, AtlasQueryService $atlas): JsonResponse
    {
        [$municipality, $settlement, $families, $type] = $this->validatedFilters($request, $atlas);

        $places = $atlas->placesForPublic($municipality, $settlement, $families, $type);

        return response()->json([
            'places' => GeoPlaceResource::collection($places)->resolve(),
            'meta' => [
                'count' => $places->count(),
                'coverage_note' => 'Broj predstavlja uvezeni OSM katalog, ne iscrpan inventar na terenu.',
            ],
        ]);
    }

    public function forSettlement(string $municipality, string $settlement, AtlasQueryService $atlas): JsonResponse
    {
        abort_if(! $atlas->settlementSlugInMunicipalityExists($municipality, $settlement), 404);

        $places = $atlas->placesForPublic($municipality, $settlement);
        $grouped = [];
        foreach (GeoPlaceResource::collection($places)->resolve() as $place) {
            $family = $place['family'] ?? 'other';
            $grouped[$family]['family'] = $family;
            $grouped[$family]['family_label'] = $place['family_label'] ?? $family;
            $grouped[$family]['places'][] = $place;
        }

        return response()->json([
            'municipality' => $municipality,
            'settlement' => $settlement,
            'groups' => array_values($grouped),
            'meta' => [
                'count' => $places->count(),
                'coverage_note' => 'U trenutno dostupnim podacima evidentirani su uvezeni OSM objekti, ne službeni popis.',
            ],
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: list<string>, 3: string|null}
     */
    private function validatedFilters(Request $request, AtlasQueryService $atlas): array
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

        return [$municipality, $settlement, $families, $type];
    }
}
