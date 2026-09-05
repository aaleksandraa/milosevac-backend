<?php

namespace App\Support\Atlas\Network;

use App\Models\DataSource;
use App\Models\GeoHydroFeature;
use App\Models\GeoSettlement;
use App\Models\GeoTransportSegment;
use App\Models\SettlementHydroProfile;
use App\Models\SettlementProximity;
use App\Models\SettlementTransportProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class NetworkImportService
{
    public function import(array $data): array
    {
        $header = array_diff_key($data, ['objects' => true, 'links' => true]);
        $header['object_count'] = is_array($data['objects'] ?? null) ? count($data['objects']) : null;
        $header['links'] = is_array($data['links'] ?? null) ? [] : null;
        Validator::make($header, [
            'algorithm_version' => 'required|in:m11.1', 'dependency_hash' => 'required|string|size:64',
            'source.raw_sha256' => 'required|string|size:64', 'source.osm_timestamp' => 'required|date',
            'source.retrieved_at' => 'required|date', 'source.endpoint' => 'required|url',
            'source.query_sha256' => 'required|string|size:64', 'object_count' => 'required|integer|min:1',
            'records' => 'required|array', 'links' => 'present|array',
            'records.*.settlement_id' => 'required|integer|distinct',
            'records.*.proximity' => 'present|array',
            'records.*.proximity.*.metric_code' => 'required|string',
            'records.*.proximity.*.distance_straight_m' => 'nullable|numeric|min:0',
        ])->validate();
        // Validate objects independently: expanding nested wildcard rules over an
        // entire regional geometry artifact produces quadratic validation work.
        foreach ($data['objects'] as $object) {
            Validator::make($object, [
                'osm_type' => 'required|in:way,relation', 'osm_id' => 'required|integer|min:1',
                'kind' => 'required|in:road,hydro', 'feature_type' => 'required|string',
                'geometry.type' => 'required|in:LineString,MultiLineString,Polygon,MultiPolygon',
                'geometry.coordinates' => 'required|array|min:1',
                'source_properties' => 'present|array',
            ])->validate();
        }
        if (! hash_equals((new NetworkSnapshot)->payload()['dependency_hash'], $data['dependency_hash'])) {
            throw new InvalidArgumentException('Stale M4/M6 dependencies: export and process jobs again');
        }

        return DB::transaction(function () use ($data) {
            $version = $data['source']['raw_sha256'];
            $source = DataSource::firstOrCreate(['source_type' => 'osm_network_m11'], [
                'name' => 'OpenStreetMap network', 'provider' => 'OpenStreetMap contributors', 'source_class' => 'C', 'authority_rank' => 20,
                'url' => 'https://www.openstreetmap.org/copyright', 'license' => 'ODbL-1.0', 'attribution' => '© OpenStreetMap contributors',
            ]);
            $source->fill(['dataset_version' => $version, 'metadata' => $data['source']]);
            if ($source->isDirty()) {
                $source->save();
            }
            $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
            $save = function ($class, $key, $values) use (&$counts) {
                $model = $class::firstOrNew($key);
                $exists = $model->exists;
                $model->fill($values);
                if (! $model->isDirty()) {
                    $counts['unchanged']++;

                    return;
                }
                $model->save();
                $counts[$exists ? 'updated' : 'inserted']++;
            };
            $seen = [];
            foreach ($data['objects'] as $o) {
                $identity = $o['kind'].'/'.$o['osm_type'].'/'.$o['osm_id'];
                if (isset($seen[$identity])) {
                    throw new InvalidArgumentException('Duplicate network identity');
                }
                $seen[$identity] = true;
                $save($o['kind'] === 'road' ? GeoTransportSegment::class : GeoHydroFeature::class,
                    ['source_id' => $source->id, 'osm_type' => $o['osm_type'], 'osm_id' => $o['osm_id']],
                    ['feature_type' => $o['feature_type'], 'name' => $o['name'], 'geometry' => $o['geometry'], 'source_properties' => $o['source_properties'],
                        'osm_version' => $o['osm_version'], 'osm_timestamp' => $o['osm_timestamp'], 'dataset_version' => $version]);
            }
            $base = ['source_id' => $source->id, 'dataset_version' => $version, 'algorithm_version' => $data['algorithm_version'], 'dependency_hash' => $data['dependency_hash'], 'calculated_at' => $data['source']['retrieved_at']];
            foreach ($data['records'] as $row) {
                $s = GeoSettlement::with('municipality')->findOrFail($row['settlement_id']);
                if (! in_array($s->municipality->slug, ['modrica', 'samac'], true) || $s->slug !== $row['settlement_slug'] || $s->municipality->slug !== $row['municipality_slug']) {
                    throw new InvalidArgumentException('Outside pilot or mismatched settlement');
                }
                foreach (['transport' => SettlementTransportProfile::class, 'hydrography' => SettlementHydroProfile::class] as $block => $class) {
                    if ($row[$block] === null) {
                        $class::where('settlement_id', $s->id)->delete();

                        continue;
                    }
                    $boundary = $s->boundaries()->where('geometry_valid', true)->find($row['boundary_id']);
                    if (! $boundary) {
                        throw new InvalidArgumentException('Invalid profile boundary');
                    }
                    foreach ($row[$block] as $value) {
                        if (is_numeric($value) && (! is_finite((float) $value) || $value < 0)) {
                            throw new InvalidArgumentException('Invalid metric');
                        }
                    }
                    $save($class, ['settlement_id' => $s->id], $base + ['boundary_id' => $boundary->id, 'summary' => $row[$block], 'quality_flag' => 'incomplete_osm', 'metadata' => ['boundary_fingerprint' => $boundary->geometry_fingerprint]]);
                }
                $codes = [];
                foreach ($row['proximity'] as $p) {
                    if (! isset(NetworkRegistry::PROXIMITIES[$p['metric_code']]) || in_array($p['metric_code'], $codes, true)) {
                        throw new InvalidArgumentException('Invalid proximity code');
                    }
                    $codes[] = $p['metric_code'];
                    if (! in_array($p['method'], ['WGS84_geodesic', 'EPSG3035_point_to_geometry'], true)) {
                        throw new InvalidArgumentException('Invalid distance method');
                    }
                    $save(SettlementProximity::class, ['settlement_id' => $s->id, 'metric_code' => $p['metric_code']], $base + [
                        'origin_type' => $row['origin_type'], 'origin_lat' => $row['lat'], 'origin_lng' => $row['lng'],
                        'target_type' => $p['target_type'], 'target_id' => $p['target_id'], 'target_name' => $p['target_name'],
                        'distance_straight_m' => $p['distance_straight_m'] === null ? null : round((float) $p['distance_straight_m'], 3), 'distance_route_m' => null, 'travel_time_s' => null,
                        'method' => $p['method'], 'quality_flag' => $p['quality_flag'], 'metadata' => ['target_source_id' => $p['target_source_id'] ?? null, 'target_osm_type' => $p['target_osm_type'] ?? null],
                    ]);
                }
                SettlementProximity::where('settlement_id', $s->id)->whereNotIn('metric_code', $codes)->delete();
            }
            $ids = array_column($data['records'], 'settlement_id');
            $existingLinks = DB::table('settlement_network_relations')->whereIn('settlement_id', $ids)->get()->keyBy(fn ($r) => $r->settlement_id.'/'.$r->kind.'/'.$r->osm_type.'/'.$r->osm_id);
            $linkIds = [];
            foreach ($data['links'] as $link) {
                if (! in_array($link['settlement_id'], $ids, true)) {
                    throw new InvalidArgumentException('Relationship outside artifact settlements');
                }
                $key = $link['settlement_id'].'/'.$link['kind'].'/'.$link['osm_type'].'/'.$link['osm_id'];
                $old = $existingLinks->get($key);
                if ($old) {
                    $linkIds[] = $old->id;
                    if ($old->relation !== $link['relation'] || abs($old->length_km - $link['length_km']) > 1e-10 || abs($old->area_km2 - $link['area_km2']) > 1e-10) {
                        DB::table('settlement_network_relations')->where('id', $old->id)->update($link);
                    }
                } else {
                    $linkIds[] = DB::table('settlement_network_relations')->insertGetId($link);
                }
            }
            DB::table('settlement_network_relations')->whereIn('settlement_id', $ids)->whereNotIn('id', $linkIds)->delete();
            foreach ([GeoTransportSegment::class, GeoHydroFeature::class] as $class) {
                $class::where('source_id', $source->id)->where('dataset_version', '!=', $version)->delete();
            }

            return $counts;
        });
    }
}
