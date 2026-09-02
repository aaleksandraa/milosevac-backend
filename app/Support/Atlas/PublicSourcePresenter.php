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
        } elseif ($source->source_type === 'census_settlements_historical') {
            $payload['methodology_note'] = $this->historicalCensusMethodologyNote(is_string($retrievalDate) ? $retrievalDate : null);
        } elseif ($source->source_type === 'osm_settlement_boundaries') {
            $payload['methodology_note'] = 'Granica naselja je OSM poligon (admin_level=9), usklađen sa popisnim identitetom naselja. Nije katastarski ili statistički službeni poligon. Licenca ODbL.';
        } elseif ($source->source_type === 'copernicus_dem_glo30') {
            $payload['methodology_note'] = 'Nadmorske visine i nagib su raster-izvedeni iz Copernicus DEM GLO-30 unutar izabrane granice naselja. To nije službena izmjerena visina naselja.';
        } elseif ($source->source_type === 'copernicus_era5_land') {
            $payload['methodology_note'] = 'Klimatološki podaci su ERA5-Land reanaliza (Copernicus C3S / ECMWF) za područje naselja, period 1991–2020. Padavine su ERA5 jer Open-Meteo ERA5-Land ne isporučuje padavine. To nisu mjerenja meteorološke stanice niti prognoza.';
        } elseif ($source->source_type === 'osm_pois') {
            $payload['methodology_note'] = 'Objekti su uvezeni iz OpenStreetMap (ODbL) kao trenutno mapirani/uvezeni zapisi. OSM pokrivenost je zajednička i može biti nepotpuna ili zastarjela. Dodjela naselju koristi dostupni atlas poligon, a ne službenu statističku pripadnost. To nije službeni inventar niti Google katalog.';
        } elseif ($source->source_type === 'copernicus_clc_2018') {
            $payload['methodology_note'] = 'CORINE Land Cover 2018 (v2020_20u1) unutar trenutno dostupne atlas granice naselja. Generalizovani inventar (MMU 25 ha), referentna 2018. godina, ne katastar i ne trenutno stanje.';
        } elseif ($source->source_type === 'copernicus_hrl_tcd_2018') {
            $payload['methodology_note'] = 'Copernicus HRL Tree Cover Density 2018: gustoća krošnji 0–100 unutar atlas granice. Nije CLC udio šume.';
        } elseif ($source->source_type === 'copernicus_hrl_imd_2018') {
            $payload['methodology_note'] = 'Copernicus HRL Imperviousness Density 2018: gustoća nepropusnosti 0–100. Nije udio zgrada niti nivo urbanizacije.';
        } elseif ($source->source_type === 'copernicus_hrl_waw_2018') {
            $payload['methodology_note'] = 'Copernicus HRL Water and Wetness 2018. Nije riječna mreža, poplavna zona niti sliv.';
        } elseif ($source->source_type === 'copernicus_hrl_fty_2018') {
            $payload['methodology_note'] = 'Copernicus HRL Forest Type 2018: šumska maska, nije gustoća krošnji.';
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

    private function historicalCensusMethodologyNote(?string $retrievalDate): string
    {
        $retrieved = $retrievalDate ? ' Preuzeto '.$retrievalDate.'.' : '';

        return 'Uporedni podaci Popisa 1971, 1981. i 1991. za naseljena mjesta, usklađeni sa Statističkim biltenom 265 Federalnog zavoda za statistiku (Sarajevo, april 1998.) i 1991. tabele mjesnih zajednica.'.$retrieved
            .' Historijska opština je izvorni popisni kontekst i nije isto što i današnja opština. Etnička/nacionalna struktura nije prikazana. Domaćinstva za 1971–1991. nisu uvezena.';
    }
}
