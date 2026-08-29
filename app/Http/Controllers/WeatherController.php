<?php

namespace App\Http\Controllers;

use App\Support\WeatherService;

class WeatherController extends Controller
{
    public function json(WeatherService $weather)
    {
        return response()->json($weather->forecast());
    }
}
