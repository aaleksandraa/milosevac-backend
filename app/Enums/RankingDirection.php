<?php

namespace App\Enums;

enum RankingDirection: string
{
    case Desc = 'desc';
    case Asc = 'asc';

    public function higherFirst(): bool
    {
        return $this === self::Desc;
    }
}
