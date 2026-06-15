<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    private const CACHE_KEY = 'weather.milosevac.open_meteo.forecast.v2';

    public const LATITUDE = 44.995;

    public const LONGITUDE = 18.3961;

    public const LOCATION = 'Miloševac, Modriča, Republika Srpska';

    public function current(): ?array
    {
        return $this->forecast()['current'] ?? null;
    }

    public function forecast(): array
    {
        $cached = cache()->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $forecast = $this->fetch();
        $ttl = $forecast['current'] ? now()->addMinutes(20) : now()->addMinute();

        cache()->put(self::CACHE_KEY, $forecast, $ttl);

        return $forecast;
    }

    private function fetch(): array
    {
        try {
            $response = Http::withOptions([
                'verify' => config('services.weather.verify_ssl', true),
            ])->timeout(8)->retry(2, 250)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => self::LATITUDE,
                'longitude' => self::LONGITUDE,
                'timezone' => 'Europe/Sarajevo',
                'current' => implode(',', [
                    'temperature_2m',
                    'apparent_temperature',
                    'relative_humidity_2m',
                    'precipitation',
                    'weather_code',
                    'wind_speed_10m',
                    'wind_direction_10m',
                    'pressure_msl',
                    'uv_index',
                ]),
                'hourly' => implode(',', [
                    'temperature_2m',
                    'apparent_temperature',
                    'precipitation_probability',
                    'weather_code',
                    'wind_speed_10m',
                ]),
                'daily' => implode(',', [
                    'weather_code',
                    'temperature_2m_max',
                    'temperature_2m_min',
                    'precipitation_probability_max',
                    'wind_speed_10m_max',
                    'sunrise',
                    'sunset',
                ]),
                'forecast_days' => 7,
            ])->throw()->json();

            return $this->normalize($response);
        } catch (\Throwable $exception) {
            Log::warning('Open-Meteo forecast unavailable', ['message' => $exception->getMessage()]);

            return [
                'configured' => true,
                'source' => 'Open-Meteo',
                'location' => self::LOCATION,
                'message' => 'Vremenska prognoza trenutno nije dostupna.',
                'current' => null,
                'hourly' => [],
                'daily' => [],
            ];
        }
    }

    private function normalize(array $payload): array
    {
        $current = $payload['current'] ?? [];
        $hourly = $payload['hourly'] ?? [];
        $daily = $payload['daily'] ?? [];

        return [
            'configured' => true,
            'source' => 'Open-Meteo',
            'location' => self::LOCATION,
            'updated_at' => $current['time'] ?? now('Europe/Sarajevo')->toIso8601String(),
            'current' => $current ? [
                'temperature' => $this->number($current['temperature_2m'] ?? null),
                'feels_like' => $this->number($current['apparent_temperature'] ?? null),
                'humidity' => $current['relative_humidity_2m'] ?? null,
                'precipitation' => $this->number($current['precipitation'] ?? null),
                'pressure' => $this->number($current['pressure_msl'] ?? null),
                'uv_index' => $this->number($current['uv_index'] ?? null),
                'wind_speed' => $this->number($current['wind_speed_10m'] ?? null),
                'wind_direction' => $this->windDirection($current['wind_direction_10m'] ?? null),
                'weather_text' => $this->label($current['weather_code'] ?? null),
                'icon' => $this->icon($current['weather_code'] ?? null),
            ] : null,
            'hourly' => collect($hourly['time'] ?? [])
                ->map(fn ($time, $index) => [
                    'datetime' => $time,
                    'time' => substr($time, 11, 5),
                    'temperature' => $this->number($hourly['temperature_2m'][$index] ?? null),
                    'real_feel' => $this->number($hourly['apparent_temperature'][$index] ?? null),
                    'rain_probability' => $hourly['precipitation_probability'][$index] ?? null,
                    'precipitation_probability' => $hourly['precipitation_probability'][$index] ?? null,
                    'wind_speed' => $this->number($hourly['wind_speed_10m'][$index] ?? null),
                    'weather_text' => $this->label($hourly['weather_code'][$index] ?? null),
                    'icon' => $this->icon($hourly['weather_code'][$index] ?? null),
                ])
                ->filter(fn ($item) => strtotime($item['datetime']) >= now('Europe/Sarajevo')->startOfHour()->timestamp)
                ->take(24)
                ->values()
                ->all(),
            'daily' => collect($daily['time'] ?? [])->map(fn ($date, $index) => [
                'date' => $date,
                'label' => $this->dayName($date),
                'min' => $this->number($daily['temperature_2m_min'][$index] ?? null),
                'max' => $this->number($daily['temperature_2m_max'][$index] ?? null),
                'day_text' => $this->label($daily['weather_code'][$index] ?? null),
                'night_text' => $this->label($daily['weather_code'][$index] ?? null),
                'rain_probability' => $daily['precipitation_probability_max'][$index] ?? null,
                'wind_speed' => $this->number($daily['wind_speed_10m_max'][$index] ?? null),
                'sunrise' => isset($daily['sunrise'][$index]) ? substr($daily['sunrise'][$index], 11, 5) : null,
                'sunset' => isset($daily['sunset'][$index]) ? substr($daily['sunset'][$index], 11, 5) : null,
                'icon' => $this->icon($daily['weather_code'][$index] ?? null),
            ])->values()->all(),
            'headline' => null,
        ];
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function label(?int $code): string
    {
        return match (true) {
            $code === 0 => 'Vedro',
            in_array($code, [1, 2], true) => 'Pretežno sunčano',
            $code === 3 => 'Oblačno',
            in_array($code, [45, 48], true) => 'Magla',
            in_array($code, [51, 53, 55, 56, 57], true) => 'Rosulja',
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => 'Kiša',
            in_array($code, [71, 73, 75, 77, 85, 86], true) => 'Snijeg',
            in_array($code, [95, 96, 99], true) => 'Nevrijeme',
            default => 'Promjenjivo',
        };
    }

    private function icon(?int $code): string
    {
        return match (true) {
            $code === 0 => 'sun',
            in_array($code, [1, 2], true) => 'partly',
            in_array($code, [3, 45, 48], true) => 'cloud',
            in_array($code, [71, 73, 75, 77, 85, 86], true) => 'snow',
            in_array($code, [95, 96, 99], true) => 'storm',
            in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], true) => 'rain',
            default => 'partly',
        };
    }

    private function windDirection(mixed $degrees): ?string
    {
        if (! is_numeric($degrees)) {
            return null;
        }

        $directions = ['S', 'SSI', 'SI', 'ISI', 'I', 'IJI', 'JI', 'JJI', 'J', 'JJZ', 'JZ', 'ZJZ', 'Z', 'ZSZ', 'SZ', 'SSZ'];

        return $directions[(int) round(((float) $degrees % 360) / 22.5) % 16];
    }

    private function dayName(string $date): string
    {
        $timestamp = strtotime($date);

        if (date('Y-m-d', $timestamp) === now('Europe/Sarajevo')->toDateString()) {
            return 'Danas';
        }

        return ucfirst(now('Europe/Sarajevo')->setTimestamp($timestamp)->locale('bs')->translatedFormat('l'));
    }
}
