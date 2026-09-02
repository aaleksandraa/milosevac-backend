<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Atlas\AtlasQueryService;
use App\Support\Atlas\Metrics\SettlementComparisonService;
use App\Support\Atlas\Metrics\SettlementMetricRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoCompareController extends Controller
{
    public function show(
        Request $request,
        string $municipalityA,
        string $settlementA,
        string $municipalityB,
        string $settlementB,
        AtlasQueryService $atlas,
        SettlementComparisonService $comparison,
        SettlementMetricRegistry $registry,
    ): JsonResponse {
        $left = $atlas->settlementInMunicipality($municipalityA, $settlementA);
        $right = $atlas->settlementInMunicipality($municipalityB, $settlementB);
        abort_if($left === null || $right === null, 404);

        $codes = $this->parseMetrics($request->query('metrics'), $registry);

        return response()->json($comparison->compare($left, $right, $codes));
    }

    /**
     * @return list<string>|null
     */
    private function parseMetrics(mixed $raw, SettlementMetricRegistry $registry): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $codes = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $metric = $registry->find($part);
            abort_if($metric === null, 422, 'Nepoznat metrik.');
            abort_if(! in_array($part, SettlementMetricRegistry::COMPARISON_CODES, true), 422, 'Metrik nije dozvoljen za poređenje.');
            $codes[] = $part;
        }

        return $codes === [] ? null : array_values(array_unique($codes));
    }
}
