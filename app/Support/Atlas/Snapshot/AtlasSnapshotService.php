<?php

namespace App\Support\Atlas\Snapshot;

use App\Support\Atlas\Network\NetworkSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Copies only atlas-owned tables between databases, preserving primary keys,
 * because processed atlas artifacts and freshness hashes reference local IDs.
 */
final class AtlasSnapshotService
{
    public const FORMAT = 'milosevac-atlas-snapshot/1';

    /** Foreign-key order: every table only references tables listed before it (or `places`). */
    public const TABLES = [
        'data_sources', 'geo_countries', 'geo_entities', 'geo_cantons', 'geo_municipalities', 'geo_settlements',
        'data_imports', 'settlement_population', 'settlement_boundaries', 'settlement_terrain_stats',
        'geo_place_types', 'geo_places', 'settlement_climate_profiles', 'settlement_climate_monthly',
        'settlement_climate_extremes', 'settlement_census_mappings', 'settlement_surface_profiles',
        'settlement_land_cover_classes', 'geo_transport_segments', 'geo_hydro_features',
        'settlement_transport_profiles', 'settlement_hydro_profiles', 'settlement_proximities',
        'settlement_network_relations',
    ];

    private const CHUNK_ROWS = 200;

    private const CHUNK_BYTES = 2_000_000;

    public function export(string $path): array
    {
        $this->assertTables();
        $counts = array_combine(self::TABLES, array_map(fn ($t) => DB::table($t)->count(), self::TABLES));
        if ($counts['geo_settlements'] === 0) {
            throw new RuntimeException('Local atlas is empty; nothing to export');
        }
        $places = DB::table('places')->whereIn('id', DB::table('geo_settlements')->whereNotNull('outage_place_id')->pluck('outage_place_id'))->pluck('slug', 'id');

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $out = gzopen($path, 'wb6');
        if (! $out) {
            throw new RuntimeException("Cannot write {$path}");
        }
        $header = ['format' => self::FORMAT, 'created_at' => now()->toIso8601String(), 'source_driver' => DB::getDriverName(),
            'counts' => $counts, 'outage_place_slugs' => $places->all(), 'network_dependency_hash' => $this->networkHash()];
        gzwrite($out, json_encode(['header' => $header], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        foreach (self::TABLES as $table) {
            foreach (DB::table($table)->orderBy('id')->lazyById(500) as $row) {
                gzwrite($out, json_encode(['t' => $table, 'r' => (array) $row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
            }
        }
        gzclose($out);

        return $header + ['bytes' => filesize($path)];
    }

    /**
     * @return array{counts: array<string,int>, replaced: bool, dry_run: bool, network_hash_matches: bool|null, unmatched_outage_places: list<string>}
     */
    public function import(string $path, bool $dryRun = false, bool $replace = false): array
    {
        $this->assertTables();
        $in = gzopen($path, 'rb');
        if (! $in) {
            throw new RuntimeException("Cannot read {$path}");
        }
        try {
            $header = json_decode((string) gzgets($in, 1 << 20), true, 512, JSON_THROW_ON_ERROR)['header'] ?? null;
            if (($header['format'] ?? null) !== self::FORMAT) {
                throw new RuntimeException('Not an atlas snapshot file');
            }
            $existing = array_filter(array_combine(self::TABLES, array_map(fn ($t) => DB::table($t)->count(), self::TABLES)));
            if ($existing && ! $replace) {
                throw new RuntimeException('Atlas tables already contain data ('.implode(', ', array_map(fn ($t, $n) => "{$t}={$n}", array_keys($existing), $existing)).'). Use --replace to replace only atlas tables.');
            }
            $placeIds = [];
            $unmatched = [];
            foreach ($header['outage_place_slugs'] as $oldId => $slug) {
                $id = DB::table('places')->where('slug', $slug)->value('id');
                $id === null ? $unmatched[] = $slug : $placeIds[(int) $oldId] = $id;
            }
            if ($dryRun) {
                return ['counts' => $header['counts'], 'replaced' => (bool) $existing, 'dry_run' => true, 'network_hash_matches' => null, 'unmatched_outage_places' => $unmatched];
            }

            DB::transaction(function () use ($in, $header, $existing, $placeIds) {
                if ($existing) {
                    foreach (array_reverse(self::TABLES) as $table) {
                        DB::table($table)->delete();
                    }
                }
                $this->insertRows($in, $placeIds);
                foreach ($header['counts'] as $table => $count) {
                    if (DB::table($table)->count() !== $count) {
                        throw new RuntimeException("Row count mismatch in {$table}; nothing was imported");
                    }
                }
                if ($header['network_dependency_hash'] !== null && ! hash_equals($header['network_dependency_hash'], (string) $this->networkHash())) {
                    throw new RuntimeException('Network dependency hash differs after import; nothing was imported');
                }
            });

            return ['counts' => $header['counts'], 'replaced' => (bool) $existing, 'dry_run' => false,
                'network_hash_matches' => $header['network_dependency_hash'] === null ? null : true, 'unmatched_outage_places' => $unmatched];
        } finally {
            gzclose($in);
        }
    }

    private function insertRows($in, array $placeIds): void
    {
        $table = null;
        $batch = [];
        $bytes = 0;
        $flush = function () use (&$table, &$batch, &$bytes) {
            if ($batch) {
                DB::table($table)->insert($batch);
            }
            $batch = [];
            $bytes = 0;
        };
        while (($line = gzgets($in, 64 << 20)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! in_array($item['t'] ?? null, self::TABLES, true)) {
                throw new RuntimeException('Snapshot contains a non-atlas table: '.($item['t'] ?? '?'));
            }
            if ($item['t'] !== $table) {
                $flush();
                $table = $item['t'];
            }
            $row = $item['r'];
            if ($table === 'geo_settlements' && $row['outage_place_id'] !== null) {
                $row['outage_place_id'] = $placeIds[(int) $row['outage_place_id']] ?? null;
            }
            $batch[] = $row;
            $bytes += strlen($line);
            if (count($batch) >= self::CHUNK_ROWS || $bytes >= self::CHUNK_BYTES) {
                $flush();
            }
        }
        $flush();
    }

    private function networkHash(): ?string
    {
        return DB::table('settlement_proximities')->exists() ? (new NetworkSnapshot)->payload()['dependency_hash'] : null;
    }

    private function assertTables(): void
    {
        foreach ([...self::TABLES, 'places'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing table {$table}; run php artisan migrate --force first");
            }
        }
    }
}
