<?php

namespace App\Support\Atlas\Poi;

use App\Models\GeoMunicipality;
use App\Models\SettlementBoundary;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class OverpassPoiFetcher
{
    public function __construct(
        private OverpassQueryBuilder $queries = new OverpassQueryBuilder,
    ) {}

    /**
     * @param  list<string>  $municipalitySlugs
     * @return list<array<string, mixed>>
     */
    public function fetch(array $municipalitySlugs): array
    {
        $results = [];
        foreach ($municipalitySlugs as $index => $slug) {
            if ($index > 0) {
                $this->sleep((int) config('atlas_poi.overpass.inter_municipality_sleep_ms', 0));
            }
            $results[] = $this->fetchMunicipality($slug);
        }

        $this->writeIndex($results);

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchMunicipality(string $slug): array
    {
        $bbox = $this->municipalityBbox($slug);
        $timeout = (int) config('atlas_poi.overpass.timeout_seconds', 90);
        $query = $this->queries->bboxQuery($bbox['min_lat'], $bbox['min_lng'], $bbox['max_lat'], $bbox['max_lng'], $timeout);
        $endpoints = config('atlas_poi.overpass.endpoints', []);
        $attempts = max(1, (int) config('atlas_poi.overpass.max_attempts', 3));
        $sleeps = config('atlas_poi.overpass.retry_sleep_ms', [0]);
        $userAgent = (string) config('atlas_poi.overpass.user_agent');

        $lastError = 'Overpass zahtjev nije uspio.';
        $endpointUsed = null;
        $status = null;
        $body = null;

        foreach ($endpoints as $endpoint) {
            if (! is_string($endpoint) || $endpoint === '') {
                continue;
            }

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                if ($attempt > 1) {
                    $this->sleep((int) ($sleeps[min($attempt - 1, count($sleeps) - 1)] ?? 0));
                }

                try {
                    $response = Http::withHeaders([
                        'User-Agent' => $userAgent,
                        'Accept' => 'application/json',
                    ])
                        ->timeout($timeout)
                        ->asForm()
                        ->post($endpoint, ['data' => $query]);
                } catch (Throwable $exception) {
                    $lastError = $exception->getMessage();
                    $status = null;

                    continue;
                }

                $status = $response->status();
                $endpointUsed = $endpoint;

                if ($status === 429 || $status >= 500) {
                    $lastError = "Overpass HTTP {$status}";

                    continue;
                }

                if (! $response->successful()) {
                    throw new RuntimeException("Overpass HTTP {$status} ({$endpoint})");
                }

                $body = $response->body();
                $decoded = json_decode($body, true);
                if (! is_array($decoded) || ! isset($decoded['elements']) || ! is_array($decoded['elements'])) {
                    throw new RuntimeException('Overpass odgovor nije validan JSON s elements.');
                }

                return $this->persist($slug, $query, $endpoint, $bbox, $decoded, $body, $status);
            }
        }

        throw new RuntimeException($lastError.($status ? " (HTTP {$status})" : '').($endpointUsed ? " @ {$endpointUsed}" : ''));
    }

    /**
     * @return array{min_lat: float, min_lng: float, max_lat: float, max_lng: float}
     */
    public function municipalityBbox(string $slug): array
    {
        $municipality = GeoMunicipality::query()->where('slug', $slug)->first();
        if ($municipality === null) {
            throw new RuntimeException("Nepoznata opština: {$slug}");
        }

        $padding = (float) config('atlas_poi.overpass.bbox_padding_deg', 0.02);
        $rows = SettlementBoundary::query()
            ->where('geometry_valid', true)
            ->whereHas('settlement', fn ($query) => $query->where('municipality_id', $municipality->id))
            ->get(['bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng']);

        if ($rows->isEmpty()) {
            throw new RuntimeException("Nema uvezenih granica za bbox opštine {$slug}.");
        }

        return [
            'min_lat' => (float) $rows->min('bbox_min_lat') - $padding,
            'min_lng' => (float) $rows->min('bbox_min_lng') - $padding,
            'max_lat' => (float) $rows->max('bbox_max_lat') + $padding,
            'max_lng' => (float) $rows->max('bbox_max_lng') + $padding,
        ];
    }

    /**
     * @param  array{min_lat: float, min_lng: float, max_lat: float, max_lng: float}  $bbox
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function persist(
        string $slug,
        string $query,
        string $endpoint,
        array $bbox,
        array $decoded,
        string $body,
        int $status,
    ): array {
        $hash = hash('sha256', $body);
        $retrievedAt = now()->toIso8601String();
        $osmTimestamp = is_array($decoded['osm3s'] ?? null)
            ? ($decoded['osm3s']['timestamp_osm_base'] ?? null)
            : null;

        $payload = [
            'meta' => [
                'municipality' => $slug,
                'endpoint' => $endpoint,
                'query' => $query,
                'retrieved_at' => $retrievedAt,
                'response_hash' => $hash,
                'http_status' => $status,
                'overpass_timestamp' => $osmTimestamp,
                'bbox' => $bbox,
                'element_count' => count($decoded['elements']),
            ],
            'elements' => $decoded['elements'],
        ];

        $relative = trim((string) config('atlas_poi.overpass.cache_dir', 'atlas/osm'), '/')."/pilot-{$slug}.json";
        Storage::disk('local')->put($relative, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return [
            'path' => Storage::disk('local')->path($relative),
            'relative' => $relative,
            'meta' => $payload['meta'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function writeIndex(array $results): void
    {
        $relative = trim((string) config('atlas_poi.overpass.cache_dir', 'atlas/osm'), '/').'/latest-index.json';
        Storage::disk('local')->put($relative, json_encode([
            'written_at' => now()->toIso8601String(),
            'files' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
