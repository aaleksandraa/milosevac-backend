<?php

namespace App\Support\Atlas\Network;

use App\Models\GeoPlace;
use App\Models\GeoSettlement;

final class NetworkSnapshot
{
    public function payload(): array
    {
        $settlements = GeoSettlement::with(['municipality', 'boundaries'])->whereHas('municipality', fn ($q) => $q->whereIn('slug', ['modrica', 'samac']))->orderBy('id')->get();
        $jobs = $settlements->map(function ($s) {
            $b = $s->boundaries->where('geometry_valid', true)->sortByDesc('id')->first();
            $verified = in_array($s->label_point_verification_status?->value, ['manually_verified', 'official_source_verified'], true);
            $label = $verified && $s->label_point_lat !== null && $s->label_point_lng !== null;

            return ['settlement_id' => $s->id, 'settlement_slug' => $s->slug, 'settlement_name' => $s->name, 'municipality_slug' => $s->municipality->slug,
                'boundary_id' => $b?->id, 'geometry' => $b?->geometry(), 'area_km2' => $b?->area_km2,
                'origin_type' => $label ? 'verified_label_point' : 'geographic_centroid',
                'lat' => $label ? $s->label_point_lat : $s->geographic_centroid_lat,
                'lng' => $label ? $s->label_point_lng : $s->geographic_centroid_lng];
        })->all();
        $pois = GeoPlace::with('placeType')->where('is_active', true)->whereNotNull('lat')->whereNotNull('lng')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'lat' => $p->lat, 'lng' => $p->lng, 'code' => $p->placeType?->code, 'source_id' => $p->source_id,
            'updated_at' => $p->updated_at?->toIso8601String(), 'osm_timestamp' => $p->osm_timestamp?->toIso8601String(),
        ])->all();
        $payload = ['jobs' => $jobs, 'pois' => $pois];
        $payload['dependency_hash'] = hash('sha256', json_encode($payload));

        return $payload;
    }
}
