<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_weather_page_displays_open_meteo_forecast(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response($this->weatherPayload()),
        ]);

        $this->get('/api/weather')
            ->assertOk()
            ->assertJsonPath('current.icon', 'sun')
            ->assertJsonPath('daily.0.icon', 'cloud');
    }

    public function test_weather_page_displays_an_unavailable_message_when_provider_fails(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response([], 500),
        ]);

        $this->get('/api/weather')
            ->assertOk()
            ->assertSee('Vremenska prognoza trenutno nije dostupna.');

        Http::assertSentCount(2);
    }

    private function weatherPayload(): array
    {
        return [
            'current' => [
                'time' => now('Europe/Sarajevo')->format('Y-m-d\TH:00'),
                'temperature_2m' => 24.2,
                'apparent_temperature' => 25.1,
                'relative_humidity_2m' => 55,
                'precipitation' => 0,
                'weather_code' => 0,
                'wind_speed_10m' => 8.4,
                'wind_direction_10m' => 180,
                'pressure_msl' => 1015,
                'uv_index' => 4.2,
            ],
            'hourly' => [
                'time' => [now('Europe/Sarajevo')->startOfHour()->format('Y-m-d\TH:i')],
                'temperature_2m' => [24.2],
                'apparent_temperature' => [25.1],
                'precipitation_probability' => [10],
                'weather_code' => [0],
                'wind_speed_10m' => [8.4],
            ],
            'daily' => [
                'time' => [now('Europe/Sarajevo')->toDateString()],
                'weather_code' => [3],
                'temperature_2m_max' => [27],
                'temperature_2m_min' => [15],
                'precipitation_probability_max' => [20],
                'wind_speed_10m_max' => [12],
                'sunrise' => [now('Europe/Sarajevo')->startOfDay()->addHours(5)->format('Y-m-d\TH:i')],
                'sunset' => [now('Europe/Sarajevo')->startOfDay()->addHours(20)->format('Y-m-d\TH:i')],
            ],
        ];
    }
}
