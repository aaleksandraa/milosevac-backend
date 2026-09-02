<?php

namespace App\Enums;

enum RankingScope: string
{
    case Pilot = 'pilot';
    case Municipality = 'municipality';
    case Canton = 'canton';
    case Entity = 'entity';
    case Country = 'country';

    public function isImplemented(): bool
    {
        return $this === self::Pilot || $this === self::Municipality;
    }

    public function publicLabel(): string
    {
        return match ($this) {
            self::Pilot => 'Trenutno obrađena naselja',
            self::Municipality => 'Opština',
            self::Canton => 'Kanton',
            self::Entity => 'Entitet',
            self::Country => 'Bosna i Hercegovina',
        };
    }
}
