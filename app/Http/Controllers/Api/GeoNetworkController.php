<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeoNetworkController extends Controller
{
    public function show(Request $request, string $layer)
    {
        $validated = $request->validate(['municipality' => 'required|in:modrica,samac']);
        $road = $layer === 'roads';
        $table = $road ? 'geo_transport_segments' : 'geo_hydro_features';
        $rows = DB::table($table.' as n')->whereExists(function ($q) use ($validated, $road) {
            $q->selectRaw('1')->from('settlement_network_relations as r')
                ->join('geo_settlements as s', 's.id', '=', 'r.settlement_id')->join('geo_municipalities as m', 'm.id', '=', 's.municipality_id')
                ->whereColumn('r.osm_type', 'n.osm_type')->whereColumn('r.osm_id', 'n.osm_id')
                ->where('r.kind', $road ? 'road' : 'hydro')->where('m.slug', $validated['municipality']);
        })->when($road, fn ($q) => $q->whereIn('n.feature_type', ['motorway', 'trunk', 'primary', 'secondary']))->orderBy('n.osm_type')->orderBy('n.osm_id')->get();

        return response()->json(['type' => 'FeatureCollection', 'features' => $rows->map(fn ($r) => [
            'type' => 'Feature', 'id' => $r->osm_type.'/'.$r->osm_id, 'geometry' => json_decode($r->geometry),
            'properties' => ['name' => $r->name, 'feature_type' => $r->feature_type, 'osm_type' => $r->osm_type, 'osm_id' => $r->osm_id],
        ]), 'meta' => ['attribution' => '© OpenStreetMap contributors', 'license' => 'ODbL-1.0', 'license_url' => 'https://opendatacommons.org/licenses/odbl/1-0/', 'geometry' => 'whole_source_objects_intersecting_municipality_settlements']]);
    }
}
