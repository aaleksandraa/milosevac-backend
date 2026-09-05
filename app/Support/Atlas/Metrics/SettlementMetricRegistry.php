<?php

namespace App\Support\Atlas\Metrics;

use App\Enums\MetricCategory;
use App\Enums\RankingDirection;
use App\Support\Atlas\Network\NetworkRegistry;
use InvalidArgumentException;

final class SettlementMetricRegistry
{
    /** @var list<string> */
    public const SETTLEMENT_FACT_CODES = [
        'population_2013',
        'area_km2',
        'elevation_mean_m',
        'temperature_mean_annual_c',
        'population_change_1991_2013_pct',
    ];

    /** @var list<string> */
    public const COMPARISON_CODES = [
        'population_2013',
        'population_1991',
        'population_change_1991_2013_abs',
        'population_change_1991_2013_pct',
        'area_km2',
        'elevation_mean_m',
        'elevation_min_m',
        'elevation_max_m',
        'elevation_range_m',
        'slope_mean_deg',
        'temperature_mean_annual_c',
        'precipitation_annual_mm',
        'hot_days_30c',
        'frost_days',
        'poi_count_total',
        'poi_count_sport',
        'poi_count_health',
        'poi_count_education',
        'poi_count_religion',
        'poi_count_historic',
        'landcover_agricultural_pct',
        'landcover_forest_pct',
        'landcover_artificial_pct',
        'landcover_water_pct',
        'tree_cover_mean_pct',
        'imperviousness_mean_pct',
        'mapped_road_length_km', 'mapped_road_density_km_per_km2', 'major_road_length_km', 'waterway_length_km', 'distance_to_municipality_centre_km', 'distance_to_nearest_major_road_km',
    ];

    /** @var array<string, MetricDefinition>|null */
    private ?array $definitions = null;

    public function get(string $code): MetricDefinition
    {
        $definition = $this->all()[$code] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Nepoznat metrik: '.$code);
        }

        return $definition;
    }

    public function find(string $code): ?MetricDefinition
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * @return array<string, MetricDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    /**
     * @return list<MetricDefinition>
     */
    public function rankable(): array
    {
        return array_values(array_filter($this->all(), fn (MetricDefinition $metric) => $metric->rankable));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_map(fn (MetricDefinition $metric) => $metric->catalog(), array_values($this->all()));
    }

    /**
     * @return array<string, MetricDefinition>
     */
    private function build(): array
    {
        $metrics = [
            $this->population(
                'population_2013',
                'Stanovništvo prema Popisu 2013.',
                'Popis 2013.',
                '',
                true,
                true,
                0,
                'najviše stanovnika',
                'najmanje stanovnika',
                'Najviše stanovnika',
                'Najmanje stanovnika',
                'Službeni Popis stanovništva, domaćinstava i stanova u BiH 2013. na nivou naseljenog mjesta. Nije procjena za tekuću godinu.',
                'Naselje ima uvezeni zapis Popisa 2013. Nula je važeća službena vrijednost.',
            ),
            $this->population(
                'population_1991',
                'Stanovništvo prema Popisu 1991.',
                'Popis 1991.',
                '',
                false,
                true,
                0,
                'najviše stanovnika 1991.',
                'najmanje stanovnika 1991.',
                'Najviše stanovnika 1991.',
                'Najmanje stanovnika 1991.',
                'Službeni popis 1991. za istorijski identitet naselja. Historijska opština je izvorni kontekst, ne današnja administracija.',
                'Naselje ima uvezeni zapis Popisa 1991. povezan sa kanonskim naseljem.',
            ),
            $this->population(
                'population_change_1991_2013_abs',
                'Promjena broja stanovnika 1991–2013. (apsolutno)',
                'Razlika 1991–2013.',
                '',
                false,
                false,
                0,
                'najveći rast',
                'najveći pad',
                'Najveći rast 1991–2013.',
                'Najveći pad 1991–2013.',
                'Apsolutna razlika Popisa 2013. i Popisa 1991. (kasniji minus raniji). Nije uzrok niti procjena za 2026.',
                'Oba popisa postoje i uporedivost para je dovoljna za prikaz razlike.',
            ),
            $this->population(
                'population_change_1991_2013_pct',
                'Promjena broja stanovnika 1991–2013. (%)',
                'Promjena 1991–2013.',
                '%',
                true,
                false,
                1,
                'najveći rast',
                'najveći pad',
                'Najveći rast 1991–2013.',
                'Najveći pad 1991–2013.',
                'Postotna razlika Popisa 2013. i Popisa 1991. samo za izravno uporedive identitete. Nije uzrok, stopa niti procjena za 2026.',
                'Oba popisa postoje, Popis 1991. nije nula, i uporedivost 1991. je strictly comparable (bez caveat).',
            ),
            $this->geography(
                'area_km2',
                'Površina granice naselja',
                'Površina',
                'km²',
                true,
                false,
                2,
                'najveća površina',
                'najmanja površina',
                'Najveća površina',
                'Najmanja površina',
                'Površina je izvedena iz OSM poligona naselja (admin_level=9), ne iz katastra ili statističke granice.',
                'Naselje ima važeći OSM poligon s izračunatom površinom.',
            ),
            $this->terrain(
                'elevation_mean_m',
                'Prosječna nadmorska visina',
                'Prosječna visina',
                'm',
                true,
                false,
                1,
                'najviša',
                'najnija',
                'Najviša prosječna nadmorska visina',
                'Najniža prosječna nadmorska visina',
                'Prosječna visina iz Copernicus DEM GLO-30 unutar OSM granice. To nisu službene izmjerene visine.',
                'Naselje ima javno upotrebljiv terenski profil s prosječnom visinom.',
            ),
            $this->terrain(
                'elevation_min_m',
                'Najniža nadmorska visina',
                'Minimalna visina',
                'm',
                true,
                true,
                1,
                'najviši minimum',
                'najniji minimum',
                'Najviša minimalna visina',
                'Najniža minimalna visina',
                'Minimalna visina iz Copernicus DEM GLO-30 unutar OSM granice.',
                'Naselje ima javno upotrebljiv terenski profil s minimalnom visinom.',
            ),
            $this->terrain(
                'elevation_max_m',
                'Najviša nadmorska visina',
                'Maksimalna visina',
                'm',
                true,
                false,
                1,
                'najviši maksimum',
                'najniji maksimum',
                'Najviša maksimalna visina',
                'Najniža maksimalna visina',
                'Maksimalna visina iz Copernicus DEM GLO-30 unutar OSM granice.',
                'Naselje ima javno upotrebljiv terenski profil s maksimalnom visinom.',
            ),
            $this->terrain(
                'elevation_range_m',
                'Visinska razlika',
                'Visinski raspon',
                'm',
                true,
                true,
                1,
                'najveća visinska razlika',
                'najmanja visinska razlika',
                'Najveća visinska razlika',
                'Najmanja visinska razlika',
                'Razlika maksimalne i minimalne visine iz Copernicus DEM GLO-30 unutar OSM granice.',
                'Naselje ima javno upotrebljiv terenski profil s visinskim rasponom.',
            ),
            $this->terrain(
                'slope_mean_deg',
                'Prosječan nagib terena',
                'Prosječan nagib',
                '°',
                true,
                true,
                1,
                'najstrmiji',
                'najravniji',
                'Najstrmiji teren',
                'Najravniji teren',
                'Prosječan nagib iz Copernicus DEM GLO-30 unutar OSM granice.',
                'Naselje ima javno upotrebljiv terenski profil s prosječnim nagibom.',
            ),
            $this->climate(
                'temperature_mean_annual_c',
                'Prosječna godišnja temperatura',
                'Prosječna temperatura',
                '°C',
                true,
                true,
                1,
                'najtoplija',
                'najhladnija',
                'Najtoplija naselja',
                'Najhladnija naselja',
                'ERA5-Land reanaliza, klimatološki normalni period 1991–2020. Nisu mjerenja meteorološke stanice u naselju.',
                'Naselje ima javno upotrebljiv klimatski profil s godišnjom temperaturom.',
            ),
            $this->climate(
                'precipitation_annual_mm',
                'Godišnje padavine',
                'Padavine',
                'mm',
                true,
                true,
                1,
                'najviše padavina',
                'najmanje padavina',
                'Najviše padavina',
                'Najmanje padavina',
                'ERA5 reanaliza, klimatološki normalni period 1991–2020. Nisu mjerenja meteorološke stanice.',
                'Naselje ima javno upotrebljiv klimatski profil s godišnjim padavinama.',
            ),
            $this->climate(
                'hot_days_30c',
                'Vrući dani (Tmax ≥ 30 °C)',
                'Vrući dani',
                'dana/god.',
                true,
                true,
                1,
                'najviše vrućih dana',
                'najmanje vrućih dana',
                'Najviše vrućih dana',
                'Najmanje vrućih dana',
                'Prosječan broj dana u godini s dnevnim Tmax ≥ 30 °C prema ERA5-Land reanalizi 1991–2020.',
                'Naselje ima javno upotrebljiv klimatski profil s indeksom hot_days_30c.',
            ),
            $this->climate(
                'frost_days',
                'Mrazni dani (Tmin < 0 °C)',
                'Mrazni dani',
                'dana/god.',
                true,
                true,
                1,
                'najviše mraznih dana',
                'najmanje mraznih dana',
                'Najviše mraznih dana',
                'Najmanje mraznih dana',
                'Prosječan broj dana u godini s dnevnim Tmin < 0 °C prema ERA5-Land reanalizi 1991–2020.',
                'Naselje ima javno upotrebljiv klimatski profil s indeksom frost_days.',
            ),
            $this->poi(
                'poi_count_total',
                'Uvezeni OSM objekti (ukupno)',
                'OSM objekti',
                '',
                true,
                true,
                0,
                'najviše uvezenih objekata',
                'najmanje uvezenih objekata',
                'Najviše uvezenih objekata',
                'Najmanje uvezenih objekata',
                'Broj trenutno uvezenih i naselju dodijeljenih OpenStreetMap objekata. To nije službeni niti iscrpan inventar objekata na terenu.',
                'Sva trenutno obrađena naselja. Nula znači da nema dodijeljenih uvezenih OSM zapisa, ne da objekti ne postoje.',
            ),
            $this->poi(
                'poi_count_sport',
                'Uvezeni sportski objekti',
                'Sport (OSM)',
                '',
                true,
                true,
                0,
                'najviše uvezenih sportskih objekata',
                'najmanje uvezenih sportskih objekata',
                'Najviše uvezenih sportskih objekata',
                'Najmanje uvezenih sportskih objekata',
                'Broj trenutno uvezenih OSM objekata porodice sport dodijeljenih naselju. Nije službeni inventar.',
                'Sva trenutno obrađena naselja. Računaju se samo objekti dodijeljeni naselju, ne municipality_only zapisi.',
            ),
            $this->poi(
                'poi_count_health',
                'Uvezeni zdravstveni objekti',
                'Zdravstvo (OSM)',
                '',
                true,
                true,
                0,
                'najviše uvezenih zdravstvenih objekata',
                'najmanje uvezenih zdravstvenih objekata',
                'Najviše uvezenih zdravstvenih objekata',
                'Najmanje uvezenih zdravstvenih objekata',
                'Broj trenutno uvezenih OSM objekata porodice zdravstvo dodijeljenih naselju. Nije službeni inventar.',
                'Sva trenutno obrađena naselja. Računaju se samo objekti dodijeljeni naselju.',
            ),
            $this->poi(
                'poi_count_education',
                'Uvezeni obrazovni objekti',
                'Obrazovanje (OSM)',
                '',
                true,
                true,
                0,
                'najviše uvezenih obrazovnih objekata',
                'najmanje uvezenih obrazovnih objekata',
                'Najviše uvezenih obrazovnih objekata',
                'Najmanje uvezenih obrazovnih objekata',
                'Broj trenutno uvezenih OSM objekata porodice obrazovanje dodijeljenih naselju. Nije službeni inventar.',
                'Sva trenutno obrađena naselja. Računaju se samo objekti dodijeljeni naselju.',
            ),
            $this->poi(
                'poi_count_religion',
                'Uvezeni vjerski objekti',
                'Vjerski objekti (OSM)',
                '',
                true,
                true,
                0,
                'najviše uvezenih vjerskih objekata',
                'najmanje uvezenih vjerskih objekata',
                'Najviše uvezenih vjerskih objekata',
                'Najmanje uvezenih vjerskih objekata',
                'Broj trenutno uvezenih OSM objekata porodice vjerski objekti dodijeljenih naselju. Nije službeni inventar.',
                'Sva trenutno obrađena naselja. Računaju se samo objekti dodijeljeni naselju.',
            ),
            $this->poi(
                'poi_count_historic',
                'Uvezeni istorijski objekti',
                'Istorija (OSM)',
                '',
                true,
                true,
                0,
                'najviše uvezenih istorijskih objekata',
                'najmanje uvezenih istorijskih objekata',
                'Najviše uvezenih istorijskih objekata',
                'Najmanje uvezenih istorijskih objekata',
                'Broj trenutno uvezenih OSM objekata porodice istorija dodijeljenih naselju. Nije službeni inventar.',
                'Sva trenutno obrađena naselja. Računaju se samo objekti dodijeljeni naselju.',
            ),
            $this->landcover(
                'landcover_agricultural_pct',
                'Udio poljoprivrednih površina',
                'Poljoprivreda (CLC)',
                'Najveći udio poljoprivrednih površina',
                'Najmanji udio poljoprivrednih površina',
                'Udio CLC poljoprivrednih razreda (2xx) unutar trenutno dostupne atlas granice naselja, CORINE Land Cover 2018. Nije obradivo zemljište, prinos niti vlasništvo. Referentna 2018. godina.',
            ),
            $this->landcover(
                'landcover_forest_pct',
                'Udio šumskog pokrova (CLC)',
                'Šuma (CLC)',
                'Najveći udio šumskog pokrova',
                'Najmanji udio šumskog pokrova',
                'Udio CLC šumskih razreda 311–313 unutar atlas granice, CORINE Land Cover 2018. Nije gustoća krošnji, drvna zaliha niti zaštićeno područje. Referentna 2018. godina.',
            ),
            $this->landcover(
                'landcover_artificial_pct',
                'Udio umjetnih površina',
                'Umjetne površine (CLC)',
                'Najveći udio umjetnih površina',
                'Najmanji udio umjetnih površina',
                'Udio CLC umjetnih površina (1xx) unutar atlas granice, CORINE Land Cover 2018. Nisu sve umjetne površine zgrade, niti je ovo nivo urbanizacije. Referentna 2018. godina.',
            ),
            $this->landcover(
                'landcover_water_pct',
                'Udio voda (CLC)',
                'Vode (CLC)',
                'Najveći udio voda',
                'Najmanji udio voda',
                'Udio CLC vodenih razreda (5xx) unutar atlas granice, CORINE Land Cover 2018. Nije riječna mreža, poplavna zona niti sliv. Referentna 2018. godina.',
            ),
            $this->landcover(
                'tree_cover_mean_pct',
                'Prosječna pokrivenost krošnjama',
                'Pokrivenost krošnjama',
                'Najveća prosječna pokrivenost krošnjama',
                'Najmanja prosječna pokrivenost krošnjama',
                'Prosječna gustoća drvenastog pokrova prema Copernicus HRL Tree Cover Density 2018 unutar atlas granice. To nije CLC udio šume. Referentna 2018. godina.',
            ),
            $this->landcover(
                'imperviousness_mean_pct',
                'Prosječna gustoća nepropusnosti',
                'Nepropusnost',
                'Najveća prosječna gustoća nepropusnosti',
                'Najmanja prosječna gustoća nepropusnosti',
                'Prosječna gustoća nepropusnosti prema Copernicus HRL Imperviousness Density 2018 unutar atlas granice. Nije udio zgrada niti nivo razvoja. Referentna 2018. godina.',
            ),
        ];

        foreach (NetworkRegistry::METRICS as $code => [$label, $unit]) {
            $metrics[] = new MetricDefinition($code, $label, $label, $unit, 'network', MetricCategory::Network,
                $code === 'distance_to_nearest_major_road_km' ? RankingDirection::Asc : RankingDirection::Desc,
                true, true, true, 2, 'veća vrijednost', 'manja vrijednost', false,
                NetworkRegistry::CAVEAT, 'Uspješno obrađeni podaci; nedostajući podatak nije nula.',
                match ($code) {
                    'mapped_road_length_km' => 'Najviše mapiranih puteva',
                    'mapped_road_density_km_per_km2' => 'Najveća gustina mapirane putne mreže',
                    'major_road_length_km' => 'Najviše mapiranih glavnih puteva',
                    'waterway_length_km' => 'Najviše mapiranih vodotoka',
                    'distance_to_municipality_centre_km' => 'Najudaljenija od opštinskog centra (pravolinijski)',
                    default => 'Najudaljenija od glavnog puta (pravolinijski)',
                },
                $code === 'distance_to_nearest_major_road_km' ? 'Najbliža glavnom putu (pravolinijski)' : $label.' — manje vrijednosti');
        }
        $indexed = [];
        foreach ($metrics as $metric) {
            $indexed[$metric->code] = $metric;
        }

        return $indexed;
    }

    private function population(
        string $code,
        string $label,
        string $short,
        string $unit,
        bool $rankable,
        bool $percentageDiff,
        int $precision,
        string $highSemantic,
        string $lowSemantic,
        string $highRank,
        string $lowRank,
        string $methodology,
        string $eligibility,
    ): MetricDefinition {
        return new MetricDefinition(
            code: $code,
            publicLabel: $label,
            shortLabel: $short,
            unit: $unit,
            sourceFamily: 'population',
            category: MetricCategory::Population,
            defaultDirection: RankingDirection::Desc,
            allowsBothDirections: true,
            rankable: $rankable,
            zeroIsValid: true,
            precision: $precision,
            highSemanticLabel: $highSemantic,
            lowSemanticLabel: $lowSemantic,
            percentageDifferenceUseful: $percentageDiff,
            methodologyCopy: $methodology,
            eligibilityCopy: $eligibility,
            highRankingLabel: $highRank,
            lowRankingLabel: $lowRank,
        );
    }

    private function geography(
        string $code,
        string $label,
        string $short,
        string $unit,
        bool $rankable,
        bool $percentageDiff,
        int $precision,
        string $highSemantic,
        string $lowSemantic,
        string $highRank,
        string $lowRank,
        string $methodology,
        string $eligibility,
    ): MetricDefinition {
        return new MetricDefinition(
            code: $code,
            publicLabel: $label,
            shortLabel: $short,
            unit: $unit,
            sourceFamily: 'geography',
            category: MetricCategory::Geography,
            defaultDirection: RankingDirection::Desc,
            allowsBothDirections: true,
            rankable: $rankable,
            zeroIsValid: false,
            precision: $precision,
            highSemanticLabel: $highSemantic,
            lowSemanticLabel: $lowSemantic,
            percentageDifferenceUseful: $percentageDiff,
            methodologyCopy: $methodology,
            eligibilityCopy: $eligibility,
            highRankingLabel: $highRank,
            lowRankingLabel: $lowRank,
        );
    }

    private function terrain(
        string $code,
        string $label,
        string $short,
        string $unit,
        bool $rankable,
        bool $zeroValid,
        int $precision,
        string $highSemantic,
        string $lowSemantic,
        string $highRank,
        string $lowRank,
        string $methodology,
        string $eligibility,
    ): MetricDefinition {
        return new MetricDefinition(
            code: $code,
            publicLabel: $label,
            shortLabel: $short,
            unit: $unit,
            sourceFamily: 'terrain',
            category: MetricCategory::Geography,
            defaultDirection: RankingDirection::Desc,
            allowsBothDirections: true,
            rankable: $rankable,
            zeroIsValid: $zeroValid,
            precision: $precision,
            highSemanticLabel: $highSemantic,
            lowSemanticLabel: $lowSemantic,
            percentageDifferenceUseful: false,
            methodologyCopy: $methodology,
            eligibilityCopy: $eligibility,
            highRankingLabel: $highRank,
            lowRankingLabel: $lowRank,
        );
    }

    private function climate(
        string $code,
        string $label,
        string $short,
        string $unit,
        bool $rankable,
        bool $zeroValid,
        int $precision,
        string $highSemantic,
        string $lowSemantic,
        string $highRank,
        string $lowRank,
        string $methodology,
        string $eligibility,
    ): MetricDefinition {
        return new MetricDefinition(
            code: $code,
            publicLabel: $label,
            shortLabel: $short,
            unit: $unit,
            sourceFamily: 'climate',
            category: MetricCategory::Climate,
            defaultDirection: RankingDirection::Desc,
            allowsBothDirections: true,
            rankable: $rankable,
            zeroIsValid: $zeroValid,
            precision: $precision,
            highSemanticLabel: $highSemantic,
            lowSemanticLabel: $lowSemantic,
            percentageDifferenceUseful: false,
            methodologyCopy: $methodology,
            eligibilityCopy: $eligibility,
            highRankingLabel: $highRank,
            lowRankingLabel: $lowRank,
        );
    }

    private function poi(
        string $code,
        string $label,
        string $short,
        string $unit,
        bool $rankable,
        bool $zeroValid,
        int $precision,
        string $highSemantic,
        string $lowSemantic,
        string $highRank,
        string $lowRank,
        string $methodology,
        string $eligibility,
    ): MetricDefinition {
        return new MetricDefinition(
            code: $code,
            publicLabel: $label,
            shortLabel: $short,
            unit: $unit,
            sourceFamily: 'poi',
            category: MetricCategory::Places,
            defaultDirection: RankingDirection::Desc,
            allowsBothDirections: true,
            rankable: $rankable,
            zeroIsValid: $zeroValid,
            precision: $precision,
            highSemanticLabel: $highSemantic,
            lowSemanticLabel: $lowSemantic,
            percentageDifferenceUseful: false,
            methodologyCopy: $methodology,
            eligibilityCopy: $eligibility,
            highRankingLabel: $highRank,
            lowRankingLabel: $lowRank,
        );
    }

    private function landcover(
        string $code,
        string $label,
        string $short,
        string $highRank,
        string $lowRank,
        string $methodology,
    ): MetricDefinition {
        return new MetricDefinition(
            code: $code,
            publicLabel: $label,
            shortLabel: $short,
            unit: '%',
            sourceFamily: 'landcover',
            category: MetricCategory::LandCover,
            defaultDirection: RankingDirection::Desc,
            allowsBothDirections: true,
            rankable: true,
            zeroIsValid: true,
            precision: 1,
            highSemanticLabel: mb_strtolower($highRank),
            lowSemanticLabel: mb_strtolower($lowRank),
            percentageDifferenceUseful: false,
            methodologyCopy: $methodology,
            eligibilityCopy: 'Naselje ima javno upotrebljiv M10 profil za taj izvor unutar važeće atlas granice. Nedostajući profil nije 0%.',
            highRankingLabel: $highRank,
            lowRankingLabel: $lowRank,
            percentagePointDifference: true,
        );
    }
}
