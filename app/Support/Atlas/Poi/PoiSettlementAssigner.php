<?php

namespace App\Support\Atlas\Poi;

use App\Enums\GeoPlaceAssignmentStatus;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Support\Atlas\Geometry\GeoJsonPolygon;
use InvalidArgumentException;

final class PoiSettlementAssigner
{
    /**
     * @param  list<string>  $municipalitySlugs
     * @return array{
     *     municipality: GeoMunicipality|null,
     *     settlement: GeoSettlement|null,
     *     status: GeoPlaceAssignmentStatus,
     *     note: string,
     *     persist: bool
     * }
     */
    public function assign(float $lat, float $lng, array $municipalitySlugs): array
    {
        $envelope = config('atlas_poi.geography');
        if (
            $lat < (float) $envelope['min_lat'] || $lat > (float) $envelope['max_lat']
            || $lng < (float) $envelope['min_lng'] || $lng > (float) $envelope['max_lng']
        ) {
            return $this->skip('outside_bih_envelope', 'Koordinata je izvan geografskog okvira BiH (zaštita od prekograničnih OSM objekata).');
        }

        $municipalities = $this->loadMunicipalities($municipalitySlugs);
        $bboxHits = [];
        foreach ($municipalities as $municipality) {
            $bbox = $municipality['bbox'];
            if ($bbox === null) {
                continue;
            }
            if ($lat >= $bbox['min_lat'] && $lat <= $bbox['max_lat'] && $lng >= $bbox['min_lng'] && $lng <= $bbox['max_lng']) {
                $bboxHits[] = $municipality;
            }
        }

        if ($bboxHits === []) {
            return $this->skip('outside_municipality_bbox', 'Koordinata nije u geografskom opsegu pilot opština.');
        }

        $matches = [];
        foreach ($bboxHits as $municipality) {
            foreach ($municipality['polygons'] as $row) {
                if ($row['polygon']->contains($lng, $lat)) {
                    $matches[] = [
                        'municipality' => $municipality['model'],
                        'settlement' => $row['settlement'],
                    ];
                }
            }
        }

        if (count($matches) > 1) {
            $uniqueSettlements = collect($matches)->unique(fn ($row) => $row['settlement']->id);
            if ($uniqueSettlements->count() > 1) {
                $municipality = $bboxHits[0]['model'];
                if (count($bboxHits) === 1) {
                    return [
                        'municipality' => $municipality,
                        'settlement' => null,
                        'status' => GeoPlaceAssignmentStatus::Ambiguous,
                        'note' => 'Tačka pada u više od jednog atlas poligona naselja. settlement_id ostaje prazan.',
                        'persist' => true,
                    ];
                }

                return $this->skip('ambiguous_cross_municipality', 'Tačka pada u poligone naselja u više od jedne opštine.');
            }
            $matches = [$uniqueSettlements->first()];
        }

        if (count($matches) === 1) {
            return [
                'municipality' => $matches[0]['municipality'],
                'settlement' => $matches[0]['settlement'],
                'status' => GeoPlaceAssignmentStatus::BoundaryMatch,
                'note' => 'Dodijeljeno prostorno prema trenutno dostupnom atlas poligonu naselja (OSM admin_level=9). To nije službena statistička pripadnost.',
                'persist' => true,
            ];
        }

        if (count($bboxHits) > 1) {
            return $this->skip('ambiguous_municipality_bbox', 'Tačka je u preklapajućim bbox-ovima opština bez jednoznačnog poligona.');
        }

        return [
            'municipality' => $bboxHits[0]['model'],
            'settlement' => null,
            'status' => GeoPlaceAssignmentStatus::MunicipalityOnly,
            'note' => 'Opština je geografski poznata, ali tačka nije unutar uvezenog poligona naselja (nema granice ili je izvan nje).',
            'persist' => true,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return list<array{model: GeoMunicipality, bbox: array{min_lat: float, min_lng: float, max_lat: float, max_lng: float}|null, polygons: list<array{settlement: GeoSettlement, polygon: GeoJsonPolygon}>}>
     */
    private function loadMunicipalities(array $slugs): array
    {
        $padding = (float) config('atlas_poi.overpass.bbox_padding_deg', 0.02);
        $rows = GeoMunicipality::query()
            ->whereIn('slug', $slugs)
            ->with(['settlements.boundaries' => fn ($query) => $query->where('geometry_valid', true)])
            ->get();

        $result = [];
        foreach ($rows as $municipality) {
            $polygons = [];
            $minLat = $minLng = $maxLat = $maxLng = null;
            foreach ($municipality->settlements as $settlement) {
                foreach ($settlement->boundaries as $boundary) {
                    $geometry = $boundary->geometry();
                    if (! is_array($geometry)) {
                        continue;
                    }
                    try {
                        $polygon = GeoJsonPolygon::from($geometry);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    $polygons[] = ['settlement' => $settlement, 'polygon' => $polygon];
                    $bbox = $polygon->bbox();
                    $minLat = min($minLat ?? $bbox['min_lat'], $bbox['min_lat']);
                    $minLng = min($minLng ?? $bbox['min_lng'], $bbox['min_lng']);
                    $maxLat = max($maxLat ?? $bbox['max_lat'], $bbox['max_lat']);
                    $maxLng = max($maxLng ?? $bbox['max_lng'], $bbox['max_lng']);
                }
            }

            $result[] = [
                'model' => $municipality,
                'bbox' => $minLat === null ? null : [
                    'min_lat' => $minLat - $padding,
                    'min_lng' => $minLng - $padding,
                    'max_lat' => $maxLat + $padding,
                    'max_lng' => $maxLng + $padding,
                ],
                'polygons' => $polygons,
            ];
        }

        return $result;
    }

    /**
     * @return array{municipality: null, settlement: null, status: GeoPlaceAssignmentStatus, note: string, persist: bool}
     */
    private function skip(string $reason, string $note): array
    {
        return [
            'municipality' => null,
            'settlement' => null,
            'status' => GeoPlaceAssignmentStatus::Unassigned,
            'note' => $reason.': '.$note,
            'persist' => false,
        ];
    }
}
