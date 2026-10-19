<?php

namespace App\Support\Atlas\Network;

use App\Models\DataSource;
use App\Models\GeoSettlement;

final class NetworkPresenter
{
    public function present(GeoSettlement $s): array
    {
        $result = [];
        foreach (['transport' => 'transportProfiles', 'hydrography' => 'hydroProfiles'] as $block => $relation) {
            if (! $s->relationLoaded($relation)) {
                continue;
            }
            $p = $s->{$relation}->first();
            if (! $p) {
                continue;
            }
            $b = $s->relationLoaded('boundaries') ? $s->boundaries->where('geometry_valid', true)->sortByDesc('id')->first() : null;
            if (! $b || $b->id !== $p->boundary_id || $b->geometry_fingerprint !== ($p->metadata['boundary_fingerprint'] ?? null) || $p->dataset_version !== ($this->source($p->source_id)['dataset_version'] ?? null)) {
                continue;
            }
            $result[$block] = $p->summary + ['dataset_version' => $p->dataset_version, 'algorithm_version' => $p->algorithm_version,
                'quality_flag' => $p->quality_flag, 'methodology' => NetworkRegistry::CAVEAT, 'source' => $this->source($p->source_id)];
        }
        if ($s->relationLoaded('proximities')) {
            foreach ($s->proximities as $p) {
                if ($p->distance_straight_m === null || ! $this->originCurrent($s, $p) || ! $this->dependenciesCurrent($p->dependency_hash) || $p->dataset_version !== ($this->source($p->source_id)['dataset_version'] ?? null)) {
                    continue;
                }
                $result['proximity'][$p->metric_code] = [
                    'label' => NetworkRegistry::PROXIMITIES[$p->metric_code], 'target_name' => $p->target_name,
                    'target_type' => $p->target_type, 'target_id' => $p->target_id, 'target_in_country' => $p->metadata['target_in_country'] ?? null,
                    'distance_straight_m' => $p->distance_straight_m, 'distance_route_m' => null, 'travel_time_s' => null,
                    'method' => $p->method, 'origin_type' => $p->origin_type, 'origin' => ['lat' => (float) $p->origin_lat, 'lng' => (float) $p->origin_lng],
                    'quality_flag' => $p->quality_flag, 'dataset_version' => $p->dataset_version,
                    'source' => $this->source(($p->target_type === 'geo_place' ? ($p->metadata['target_source_id'] ?? null) : null) ?: $p->source_id),
                    'network_source' => $this->source($p->source_id),
                ];
            }
        }

        return $result;
    }

    private function originCurrent(GeoSettlement $s, $p): bool
    {
        $label = in_array($s->label_point_verification_status?->value, ['manually_verified', 'official_source_verified'], true) && $s->label_point_lat !== null && $s->label_point_lng !== null;
        $type = $label ? 'verified_label_point' : 'geographic_centroid';
        $lat = $label ? $s->label_point_lat : $s->geographic_centroid_lat;
        $lng = $label ? $s->label_point_lng : $s->geographic_centroid_lng;

        return $lat !== null && $lng !== null && $type === $p->origin_type && abs((float) $lat - (float) $p->origin_lat) < 0.00000005 && abs((float) $lng - (float) $p->origin_lng) < 0.00000005;
    }

    private function source(int $id): array
    {
        $key = 'm11.source.'.$id;
        $s = request()->attributes->get($key);
        if (! $s) {
            $s = DataSource::find($id);
            request()->attributes->set($key, $s);
        }

        return ['name' => $s?->name, 'attribution' => $s?->attribution, 'license' => $s?->license, 'url' => $s?->url, 'dataset_version' => $s?->dataset_version, 'osm_timestamp' => $s?->metadata['osm_timestamp'] ?? null];
    }

    private function dependenciesCurrent(string $hash): bool
    {
        $key = 'm11.dependency_hash';
        $current = request()->attributes->get($key);
        if ($current === null) {
            $current = (new NetworkSnapshot)->payload()['dependency_hash'];
            request()->attributes->set($key, $current);
        }

        return hash_equals($hash, $current);
    }
}
