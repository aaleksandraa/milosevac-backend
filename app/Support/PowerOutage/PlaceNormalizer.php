<?php

namespace App\Support\PowerOutage;

class PlaceNormalizer
{
    public function __construct(private CyrillicLatin $transliterator = new CyrillicLatin()) {}

    public function key(string $value): string
    {
        $text = $this->transliterator->transliterate($value);
        $text = mb_strtolower($text, 'UTF-8');
        $text = $this->stripDiacritics($text);
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return $text;
    }

    public function matchKey(string $value): string
    {
        $key = $this->key($value);
        $key = preg_replace('/\s+\d+$/u', '', $key) ?? $key;

        return trim($key);
    }

    public function tokenKeys(string $value): array
    {
        $key = $this->key($value);

        return array_values(array_filter(array_unique([
            $key,
            ...preg_split('/\s+/u', $key) ?: [],
        ]), fn (string $token) => strlen($token) >= 3));
    }

    private function stripDiacritics(string $text): string
    {
        $map = [
            'č' => 'c', 'ć' => 'c', 'đ' => 'd', 'š' => 's', 'ž' => 'z',
        ];

        return strtr($text, $map);
    }
}
