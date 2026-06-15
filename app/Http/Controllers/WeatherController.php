<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use App\Support\WeatherService;

class WeatherController extends Controller
{
    public function json(WeatherService $weather)
    {
        return response()->json($weather->forecast());
    }

    public function show(WeatherService $weather)
    {
        $forecast = $weather->forecast();

        return view('portal.weather', [
            'seo' => Seo::page(
                'Vrijeme u Miloševcu',
                'Trenutna temperatura i sedmodnevna vremenska prognoza za Miloševac, Modriča, Republika Srpska.',
                route('weather.show')
            ),
            'forecast' => $forecast,
        ]);
    }
}
