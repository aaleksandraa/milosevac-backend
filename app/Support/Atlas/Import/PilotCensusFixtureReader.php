<?php

namespace App\Support\Atlas\Import;

use Illuminate\Support\Str;
use RuntimeException;

final class PilotCensusFixtureReader
{
    /**
     * @return array{
     *     source: array<string, mixed>,
     *     records: list<NormalizedSettlementRecord>
     * }
     */
    public function read(string $path, ?array $municipalitySlugs = null): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Atlas fixture nije čitljiv: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['source'], $decoded['municipalities']) || ! is_array($decoded['municipalities'])) {
            throw new RuntimeException("Atlas fixture nije validan JSON: {$path}");
        }

        $wanted = $municipalitySlugs === null ? null : array_fill_keys($municipalitySlugs, true);
        $records = [];
        $seenCodes = [];
        $seenSlugs = [];

        foreach ($decoded['municipalities'] as $municipality) {
            if (! is_array($municipality) || ! isset($municipality['slug'], $municipality['settlements']) || ! is_array($municipality['settlements'])) {
                throw new RuntimeException('Atlas fixture sadrži neispravnu opštinu.');
            }

            $municipalitySlug = (string) $municipality['slug'];
            if ($wanted !== null && ! isset($wanted[$municipalitySlug])) {
                continue;
            }

            foreach ($municipality['settlements'] as $row) {
                if (! is_array($row) || ! isset($row['statistical_code'], $row['name'])) {
                    throw new RuntimeException("Atlas fixture sadrži neispravan red za {$municipalitySlug}.");
                }

                $code = trim((string) $row['statistical_code']);
                $name = trim((string) $row['name']);
                if ($code === '' || $name === '') {
                    throw new RuntimeException("Atlas fixture ima prazan naziv ili šifru u {$municipalitySlug}.");
                }

                $slug = Str::slug($name);
                if ($slug === '') {
                    throw new RuntimeException("Nije moguće napraviti slug za naselje: {$name}");
                }

                $slugKey = $municipalitySlug.'|'.$slug;
                if (isset($seenSlugs[$slugKey])) {
                    throw new RuntimeException("Sukob slugova u fixtureu: {$municipalitySlug}/{$slug}");
                }
                if (isset($seenCodes[$code])) {
                    throw new RuntimeException("Duplikat statističke šifre u fixtureu: {$code}");
                }

                $seenSlugs[$slugKey] = true;
                $seenCodes[$code] = true;

                $records[] = new NormalizedSettlementRecord(
                    municipalitySlug: $municipalitySlug,
                    name: $name,
                    slug: $slug,
                    statisticalCode: $code,
                    municipalityStatisticalCode: isset($municipality['statistical_code']) ? (string) $municipality['statistical_code'] : null,
                    population: array_key_exists('population', $row) ? (int) $row['population'] : null,
                    households: array_key_exists('households', $row) ? (int) $row['households'] : null,
                    sourceMetadata: [
                        'municipality_name' => $municipality['name'] ?? null,
                    ],
                );
            }
        }

        return [
            'source' => $decoded['source'],
            'records' => $records,
        ];
    }
}
