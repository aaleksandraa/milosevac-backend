<?php

namespace App\Support\Atlas;

use App\Models\DataSource;

final class PublicSourcePresenter
{
    /**
     * Public provenance only. Authority rank is an internal conflict-resolution field.
     *
     * @return array<string, mixed>
     */
    public function present(DataSource $source): array
    {
        $metadata = is_array($source->metadata) ? $source->metadata : [];
        $retrievalDate = $metadata['retrieval_date'] ?? null;

        $payload = [
            'name' => $source->name,
            'provider' => $source->provider,
            'source_class' => $source->source_class->value,
            'source_class_label' => $source->source_class->publicLabel(),
            'dataset_version' => $source->dataset_version,
            'attribution' => $source->attribution,
            'url' => $source->url,
            'retrieval_date' => $retrievalDate,
        ];

        if ($source->source_type === 'census_settlements') {
            $payload['methodology_note'] = $this->censusMethodologyNote(is_string($retrievalDate) ? $retrievalDate : null);
        } elseif ($source->source_type === 'osm_settlement_boundaries') {
            $payload['methodology_note'] = 'Granica naselja je OSM poligon (admin_level=9), usklađen sa popisnim identitetom naselja. Nije katastarski ili statistički službeni poligon. Licenca ODbL.';
        } elseif ($source->source_type === 'copernicus_dem_glo30') {
            $payload['methodology_note'] = 'Nadmorske visine i nagib su raster-izvedeni iz Copernicus DEM GLO-30 unutar izabrane granice naselja. To nije službena izmjerena visina naselja.';
        } elseif (is_string($metadata['notes'] ?? null) && $metadata['notes'] !== '') {
            $payload['methodology_note'] = $metadata['notes'];
        }

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    private function censusMethodologyNote(?string $retrievalDate): string
    {
        $retrieved = $retrievalDate ? ' Preuzeto '.$retrievalDate.'.' : '';

        return 'Normalizovani izvod tabela naseljenih mjesta Popisa stanovništva, domaćinstava i stanova u BiH 2013. (Agencija za statistiku BiH / Republički zavod za statistiku RS).'.$retrieved
            .' Izvor je prezentacija statistika.ba, a ne direktan BHAS datotečni izvod.';
    }
}
