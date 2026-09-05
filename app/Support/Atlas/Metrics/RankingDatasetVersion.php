<?php

namespace App\Support\Atlas\Metrics;

use App\Enums\RankingScope;
use App\Models\GeoMunicipality;
use App\Models\GeoPlace;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementClimateProfile;
use App\Models\SettlementHydroProfile;
use App\Models\SettlementPopulation;
use App\Models\SettlementProximity;
use App\Models\SettlementSurfaceProfile;
use App\Models\SettlementTerrainStat;
use App\Models\SettlementTransportProfile;
use App\Support\Atlas\Network\NetworkSnapshot;
use Illuminate\Support\Collection;

final class RankingDatasetVersion
{
    public const CACHE_TTL_SECONDS = 600;

    public function forFamily(string $sourceFamily): string
    {
        return sha1(implode('|', [
            $sourceFamily,
            ...$this->stampsFor($sourceFamily),
        ]));
    }

    public function combined(): string
    {
        return sha1(implode('|', [
            $this->forFamily('population'),
            $this->forFamily('geography'),
            $this->forFamily('terrain'),
            $this->forFamily('climate'),
            $this->forFamily('poi'),
            $this->forFamily('landcover'),
            $this->forFamily('network'),
        ]));
    }

    /**
     * @return list<string>
     */
    private function stampsFor(string $sourceFamily): array
    {
        return match ($sourceFamily) {
            'population' => [
                (string) SettlementPopulation::query()->max('updated_at'),
                (string) SettlementPopulation::query()->count(),
            ],
            'geography' => [
                (string) SettlementBoundary::query()->max('updated_at'),
                (string) SettlementBoundary::query()->where('geometry_valid', true)->count(),
            ],
            'terrain' => [
                (string) SettlementTerrainStat::query()->max('updated_at'),
                (string) SettlementTerrainStat::query()->count(),
            ],
            'climate' => [
                (string) SettlementClimateProfile::query()->max('updated_at'),
                (string) SettlementClimateProfile::query()->count(),
            ],
            'poi' => [
                (string) GeoPlace::query()->max('updated_at'),
                (string) GeoPlace::query()->where('is_active', true)->count(),
            ],
            'network' => [
                hash('sha256', SettlementTransportProfile::orderBy('id')->get()->toJson()),
                hash('sha256', SettlementHydroProfile::orderBy('id')->get()->toJson()),
                hash('sha256', SettlementProximity::orderBy('id')->get()->toJson()),
                (new NetworkSnapshot)->payload()['dependency_hash'],
            ],
            'landcover' => [
                (string) SettlementSurfaceProfile::query()->max('updated_at'),
                (string) SettlementSurfaceProfile::query()->count(),
            ],
            default => [
                (string) GeoSettlement::query()->max('updated_at'),
            ],
        };
    }

    /**
     * @param  Collection<int, GeoMunicipality>  $municipalities
     */
    public function universeNote(RankingScope $scope, ?GeoMunicipality $municipality, Collection $municipalities): string
    {
        if ($scope === RankingScope::Municipality && $municipality !== null) {
            return 'u opštini '.$municipality->name;
        }

        $names = $municipalities->pluck('name')->unique()->sort()->values();
        if ($names->isEmpty()) {
            return 'među trenutno obrađenim naseljima';
        }

        return 'među trenutno obrađenim naseljima ('.$names->implode(' i ').')';
    }
}
