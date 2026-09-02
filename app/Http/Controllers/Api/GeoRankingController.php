<?php

namespace App\Http\Controllers\Api;

use App\Enums\RankingDirection;
use App\Enums\RankingScope;
use App\Http\Controllers\Controller;
use App\Support\Atlas\Metrics\RankingDatasetVersion;
use App\Support\Atlas\Metrics\RankingPresenter;
use App\Support\Atlas\Metrics\SettlementMetricRegistry;
use App\Support\Atlas\Metrics\SettlementRankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class GeoRankingController extends Controller
{
    public function metrics(SettlementMetricRegistry $registry): JsonResponse
    {
        return response()->json([
            'metrics' => $registry->catalog(),
            'scopes' => [
                ['code' => RankingScope::Pilot->value, 'label' => RankingScope::Pilot->publicLabel()],
                ['code' => RankingScope::Municipality->value, 'label' => RankingScope::Municipality->publicLabel()],
            ],
            'ranking_method' => 'competition',
        ]);
    }

    public function index(
        Request $request,
        SettlementMetricRegistry $registry,
        SettlementRankingService $rankings,
        RankingPresenter $presenter,
        RankingDatasetVersion $versions,
    ): JsonResponse {
        $metricCode = is_string($request->query('metric')) ? $request->query('metric') : 'population_2013';
        $metric = $registry->find($metricCode);
        abort_if($metric === null || ! $metric->rankable, 422, 'Nepoznat ili nerangabilan metrik.');

        $scope = RankingScope::tryFrom(is_string($request->query('scope')) ? $request->query('scope') : RankingScope::Pilot->value);
        abort_if($scope === null || ! $scope->isImplemented(), 422, 'Nepoznat obuhvat rang-liste.');

        $directionRaw = $request->query('direction');
        $direction = is_string($directionRaw) && $directionRaw !== ''
            ? RankingDirection::tryFrom($directionRaw)
            : $metric->defaultDirection;
        abort_if($direction === null || ! $metric->permitsDirection($direction), 422, 'Smjer rangiranja nije dozvoljen.');

        $municipality = is_string($request->query('municipality')) && $request->query('municipality') !== ''
            ? $request->query('municipality')
            : null;
        abort_if($scope === RankingScope::Municipality && $municipality === null, 422, 'Parametar municipality je obavezan.');

        $limitRaw = $request->query('limit');
        $limit = null;
        if ($limitRaw === 'all') {
            $limit = null;
        } elseif ($limitRaw !== null && $limitRaw !== '') {
            abort_if(! is_numeric($limitRaw), 422, 'Neispravan limit.');
            $limit = max(1, min(500, (int) $limitRaw));
        }

        try {
            $table = $rankings->table($metric->code, $scope, $direction, $municipality);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        if ($limit === null && $limitRaw !== 'all') {
            $limit = $presenter->defaultLimit($table->eligible);
        }

        $payload = $presenter->table($table, $limit, $versions);

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
