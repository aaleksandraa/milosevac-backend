<?php

namespace App\Enums;

enum MetricCategory: string
{
    case Population = 'population';
    case Geography = 'geography';
    case Climate = 'climate';
    case Places = 'places';

    public function publicLabel(): string
    {
        return match ($this) {
            self::Population => 'Stanovništvo',
            self::Geography => 'Geografija i teren',
            self::Climate => 'Klima',
            self::Places => 'Objekti i sadržaji',
        };
    }
}
