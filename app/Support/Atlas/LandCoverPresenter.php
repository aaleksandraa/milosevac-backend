<?php

namespace App\Support\Atlas;

use App\Models\SettlementLandCoverClass;
use App\Models\SettlementSurfaceProfile;
use App\Support\Atlas\Import\LandcoverImportService;
use App\Support\Atlas\LandCover\ClcNomenclature;
use Illuminate\Support\Collection;

final class LandCoverPresenter
{
    public const METHODOLOGY = 'Pokrov zemljišta izračunat je unutar trenutno dostupne atlas granice naselja (OSM admin_level=9, ne katastar). CORINE Land Cover 2018 je generalizovani evropski inventar (MMU 25 ha), ne karta parcela. HRL Tree Cover Density mjeri gustoću krošnji, HRL Forest Type šumsku masku, a HRL Imperviousness Density gustoću nepropusnosti — to nisu istoznačni pojmovi. Podaci se odnose na referentnu 2018. godinu, ne na trenutno stanje. Pokrov ne dokazuje plodnost, drvnu zalihu, vlasništvo, zaštićena područja niti nivo urbanizacije.';

    /**
     * @param  Collection<int, SettlementSurfaceProfile>  $profiles
     * @return array<string, mixed>|null
     */
    public function present(Collection $profiles): ?array
    {
        $usable = $profiles
            ->filter(fn (SettlementSurfaceProfile $row) => $row->isPubliclyUsable())
            ->sortByDesc(fn (SettlementSurfaceProfile $row) => $row->calculated_at?->timestamp ?? 0)
            ->values();
        if ($usable->isEmpty()) {
            return null;
        }

        $clc = $usable->firstWhere('product_code', ClcNomenclature::PRODUCT_CLC);
        $tcd = $usable->firstWhere('product_code', ClcNomenclature::PRODUCT_TCD);
        $imd = $usable->firstWhere('product_code', ClcNomenclature::PRODUCT_IMD);
        $waw = $usable->firstWhere('product_code', ClcNomenclature::PRODUCT_WAW);
        $fty = $usable->firstWhere('product_code', ClcNomenclature::PRODUCT_FTY);

        $summary = $clc?->summary ?? [];
        $dominantGroup = is_array($summary['dominant_group'] ?? null) ? $summary['dominant_group'] : null;
        $dominantClass = is_array($summary['dominant_class'] ?? null) ? $summary['dominant_class'] : null;

        $block = array_filter([
            'reference_year' => 2018,
            'summary' => array_filter([
                'dominant_group' => $dominantGroup,
                'dominant_class' => $dominantClass,
                'artificial_pct' => $this->pct($summary['landcover_artificial_pct'] ?? null),
                'agricultural_pct' => $this->pct($summary['landcover_agricultural_pct'] ?? null),
                'forest_pct' => $this->pct($summary['landcover_forest_pct'] ?? null),
                'seminatural_pct' => $this->pct($summary['landcover_seminatural_pct'] ?? null),
                'open_or_sparse_pct' => $this->pct($summary['landcover_open_or_sparse_pct'] ?? null),
                'wetland_pct' => $this->pct($summary['landcover_wetland_pct'] ?? null),
                'water_pct' => $this->pct($summary['landcover_water_pct'] ?? null),
                'groups' => $this->groups($summary),
            ], fn ($value) => $value !== null && $value !== []),
            'classes' => $this->classes($clc),
            'forest' => $this->forest($tcd, $fty),
            'imperviousness' => $this->imperviousness($imd),
            'water_wetness' => $this->waterWetness($waw),
            'methodology' => [
                'text' => self::METHODOLOGY,
                'algorithm_version' => LandcoverImportService::ALGORITHM_VERSION,
                'processing_crs' => 'EPSG:3035',
                'clc_method' => $clc?->processing_method,
                'hrl_method' => $tcd?->processing_method ?? $imd?->processing_method,
                'quality_flag' => $clc?->quality_flag ?? $usable->first()?->quality_flag,
                'boundary_note' => 'u okviru trenutno dostupne atlas granice naselja',
                'temporal_note' => 'prema podacima za referentnu 2018. godinu',
            ],
            'source' => $clc && $clc->relationLoaded('source') && $clc->source
                ? (new PublicSourcePresenter)->present($clc->source)
                : null,
        ], fn ($value) => $value !== null && $value !== []);

        return $block === [] ? null : $block;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    private function groups(array $summary): array
    {
        $map = [
            'artificial_surfaces' => $summary['landcover_artificial_pct'] ?? ($summary['groups']['artificial_surfaces'] ?? null),
            'agricultural' => $summary['landcover_agricultural_pct'] ?? ($summary['groups']['agricultural'] ?? null),
            'forest' => $summary['landcover_forest_pct'] ?? ($summary['groups']['forest'] ?? null),
            'shrub_herbaceous_or_seminatural' => $summary['landcover_seminatural_pct'] ?? ($summary['groups']['shrub_herbaceous_or_seminatural'] ?? null),
            'open_or_sparse' => $summary['landcover_open_or_sparse_pct'] ?? ($summary['groups']['open_or_sparse'] ?? null),
            'wetlands' => $summary['landcover_wetland_pct'] ?? ($summary['groups']['wetlands'] ?? null),
            'water' => $summary['landcover_water_pct'] ?? ($summary['groups']['water'] ?? null),
        ];
        $rows = [];
        foreach ($map as $code => $value) {
            if ($value === null) {
                continue;
            }
            $rows[] = [
                'code' => $code,
                'label' => ClcNomenclature::groupLabel($code),
                'share_pct' => $this->pct($value),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function classes(?SettlementSurfaceProfile $clc): array
    {
        if ($clc === null || ! $clc->relationLoaded('classes')) {
            return [];
        }

        return $clc->classes
            ->filter(fn (SettlementLandCoverClass $row) => (float) $row->share_pct > 0)
            ->sortByDesc(fn (SettlementLandCoverClass $row) => (float) $row->share_pct)
            ->map(fn (SettlementLandCoverClass $row) => [
                'code' => $row->class_code,
                'label' => $row->source_class_label,
                'atlas_group' => $row->atlas_group,
                'atlas_group_label' => $row->atlas_group ? ClcNomenclature::groupLabel($row->atlas_group) : null,
                'area_km2' => round((float) $row->area_km2, 4),
                'share_pct' => $this->pct($row->share_pct),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function forest(?SettlementSurfaceProfile $tcd, ?SettlementSurfaceProfile $fty): ?array
    {
        $block = array_filter([
            'tree_cover_mean_pct' => $tcd?->summaryValue('tree_cover_mean_pct') !== null
                ? $this->pct($tcd->summaryValue('tree_cover_mean_pct'))
                : null,
            'forest_area_pct' => $fty?->summaryValue('forest_area_pct') !== null
                ? $this->pct($fty->summaryValue('forest_area_pct'))
                : null,
            'tree_cover_wording' => $tcd ? 'Pokrivenost krošnjama / gustoća drvenastog pokrova prema Copernicus HRL Tree Cover Density 2018.' : null,
            'forest_area_wording' => $fty ? 'Udio šumske maske prema Copernicus HRL Forest Type 2018, ne CLC razred „šuma“ i ne gustoća krošnji.' : null,
            'reference_year' => 2018,
        ], fn ($value) => $value !== null);

        return $block === [] ? null : $block;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imperviousness(?SettlementSurfaceProfile $imd): ?array
    {
        if ($imd?->summaryValue('imperviousness_mean_pct') === null) {
            return null;
        }

        return [
            'imperviousness_mean_pct' => $this->pct($imd->summaryValue('imperviousness_mean_pct')),
            'wording' => 'Prosječna gustoća nepropusnosti prema Copernicus HRL Imperviousness Density 2018. To nije udio zgrada niti nivo urbanizacije.',
            'reference_year' => 2018,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function waterWetness(?SettlementSurfaceProfile $waw): ?array
    {
        if ($waw === null) {
            return null;
        }

        $block = array_filter([
            'permanent_water_pct' => $this->pct($waw->summaryValue('permanent_water_pct')),
            'temporary_water_pct' => $this->pct($waw->summaryValue('temporary_water_pct')),
            'wet_area_pct' => $this->pct($waw->summaryValue('wet_area_pct')),
            'water_wetness_pct' => $this->pct($waw->summaryValue('water_wetness_pct')),
            'wording' => 'HRL Water and Wetness 2018 klasifikuje suho, stalnu/privremenu vodu i vlažnost. To nije riječna mreža, poplavna zona niti sliv.',
            'reference_year' => 2018,
        ], fn ($value) => $value !== null);

        return $block === [] ? null : $block;
    }

    private function pct(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 1);
    }
}
