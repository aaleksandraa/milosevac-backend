<?php

namespace App\Support\Atlas\LandCover;

final class ClcNomenclature
{
    public const ALGORITHM_VERSION = 'm10.1';

    public const SHARE_SUM_FAIL_PP = 2.0;

    public const PRODUCT_CLC = 'clc2018';

    public const PRODUCT_TCD = 'hrl_tcd_2018';

    public const PRODUCT_IMD = 'hrl_imd_2018';

    public const PRODUCT_WAW = 'hrl_waw_2018';

    public const PRODUCT_FTY = 'hrl_fty_2018';

    /** @var array<string, string> */
    public const GROUP_LABELS = [
        'artificial_surfaces' => 'Umjetne površine',
        'agricultural' => 'Poljoprivredne površine',
        'forest' => 'Šumske površine',
        'shrub_herbaceous_or_seminatural' => 'Žbunasta, zeljasta ili poluprirodna vegetacija',
        'open_or_sparse' => 'Otvorene ili rijetko vegetirane površine',
        'wetlands' => 'Močvare',
        'water' => 'Vode',
    ];

    public static function atlasGroup(string $code): ?string
    {
        $code = str_pad($code, 3, '0', STR_PAD_LEFT);

        return match (true) {
            str_starts_with($code, '1') => 'artificial_surfaces',
            str_starts_with($code, '2') => 'agricultural',
            in_array($code, ['311', '312', '313'], true) => 'forest',
            in_array($code, ['321', '322', '323', '324'], true) => 'shrub_herbaceous_or_seminatural',
            str_starts_with($code, '3') => 'open_or_sparse',
            str_starts_with($code, '4') => 'wetlands',
            str_starts_with($code, '5') => 'water',
            default => null,
        };
    }

    public static function groupLabel(string $group): string
    {
        return self::GROUP_LABELS[$group] ?? $group;
    }

    public static function sharesSumOk(float $sum): bool
    {
        return abs($sum - 100.0) <= self::SHARE_SUM_FAIL_PP;
    }
}
