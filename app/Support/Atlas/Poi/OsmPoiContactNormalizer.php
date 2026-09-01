<?php

namespace App\Support\Atlas\Poi;

final class OsmPoiContactNormalizer
{
    /**
     * @param  array<string, mixed>  $tags
     * @return array{
     *     operator: string|null,
     *     address: string|null,
     *     phone: string|null,
     *     website: string|null,
     *     opening_hours: string|null,
     *     website_warning: string|null
     * }
     */
    public function normalize(array $tags): array
    {
        $website = $this->stringTag($tags, 'website') ?? $this->stringTag($tags, 'contact:website');
        $websiteWarning = null;
        if ($website !== null && ! $this->looksLikeUrl($website)) {
            $websiteWarning = 'website_malformed';
        }

        return [
            'operator' => $this->clip($this->stringTag($tags, 'operator'), 255),
            'address' => $this->clip($this->displayAddress($tags), 255),
            'phone' => $this->clip($this->stringTag($tags, 'phone') ?? $this->stringTag($tags, 'contact:phone'), 64),
            'website' => $this->clip($website, 512),
            'opening_hours' => $this->clip($this->stringTag($tags, 'opening_hours'), 512),
            'website_warning' => $websiteWarning,
        ];
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    public function displayAddress(array $tags): ?string
    {
        $street = $this->stringTag($tags, 'addr:street');
        $number = $this->stringTag($tags, 'addr:housenumber');
        $place = $this->stringTag($tags, 'addr:place');
        $city = $this->stringTag($tags, 'addr:city');
        $postcode = $this->stringTag($tags, 'addr:postcode');

        $line = trim(implode(' ', array_filter([$street, $number], fn ($part) => $part !== null && $part !== '')));
        $parts = array_values(array_filter([$line !== '' ? $line : $place, $postcode, $city]));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $tags
     * @return array<string, string>
     */
    public function snapshot(array $tags): array
    {
        $keys = config('atlas_poi.source_tag_keys', []);
        $kept = [];
        foreach ($keys as $key) {
            if (! is_string($key) || ! array_key_exists($key, $tags)) {
                continue;
            }
            $value = $tags[$key];
            if (is_scalar($value) && trim((string) $value) !== '') {
                $kept[$key] = (string) $value;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function stringTag(array $tags, string $key): ?string
    {
        $value = $tags[$key] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function looksLikeUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return true;
        }

        return (bool) preg_match('/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(\/.*)?$/i', $value);
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
