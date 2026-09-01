<?php

namespace App\Enums;

enum GeoPlaceFamily: string
{
    case Education = 'education';
    case Health = 'health';
    case Sport = 'sport';
    case Religion = 'religion';
    case Culture = 'culture';
    case Historic = 'historic';
    case Tourism = 'tourism';
    case Government = 'government';
    case Transport = 'transport';
    case Infrastructure = 'infrastructure';
    case Nature = 'nature';
    case Commercial = 'commercial';

    public function publicLabel(): string
    {
        return match ($this) {
            self::Education => 'Obrazovanje',
            self::Health => 'Zdravstvo',
            self::Sport => 'Sport i rekreacija',
            self::Religion => 'Vjerski objekti',
            self::Culture => 'Kultura',
            self::Historic => 'Istorija i spomenici',
            self::Tourism => 'Turizam',
            self::Government => 'Javne službe',
            self::Transport => 'Saobraćaj',
            self::Infrastructure => 'Infrastruktura',
            self::Nature => 'Priroda',
            self::Commercial => 'Komercijalno',
        };
    }
}
