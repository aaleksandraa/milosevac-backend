<?php

namespace App\Support;

/**
 * Single source of truth for the ad slots offered in the CMS and exposed to the
 * React frontend through `/api/ads`.
 */
class AdPositions
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'top_banner' => 'Top banner ispod navigacije',
            'home_after_featured' => 'Naslovna poslije izdvojenih vijesti',
            'home_mid_feed' => 'Naslovna između liste vijesti',
            'archive_top' => 'Arhive/kategorije ispod zaglavlja',
            'archive_mid_feed' => 'Arhive/kategorije između vijesti',
            'sidebar_primary' => 'Sidebar 300x250',
            'sidebar_secondary' => 'Sidebar manji/native oglas',
            'article_inline' => 'Unutar članka / utakmice',
            'article_top' => 'Članak ispod uvoda',
            'article_mid' => 'Sredina članka',
            'club_after_results' => 'FK Posavina poslije rezultata',
            'match_gallery_top' => 'Utakmica iznad galerije',
            'match_mid' => 'Sredina izvještaja utakmice',
            'footer_banner' => 'Banner iznad footera',
        ];
    }
}
