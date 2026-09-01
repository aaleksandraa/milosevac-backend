<?php

namespace App\Support\Atlas\Poi;

use App\Enums\GeoPlaceClassificationStatus;

final class OsmPoiClassifier
{
    /**
     * @param  array<string, mixed>  $tags
     */
    public function classify(array $tags): ?OsmPoiClassification
    {
        foreach (config('atlas_poi.rules', []) as $index => $rule) {
            if (! is_array($rule) || ! isset($rule['when'], $rule['type'])) {
                continue;
            }

            if (! $this->matches($tags, $rule['when'])) {
                continue;
            }

            $status = $rule['confidence'] ?? GeoPlaceClassificationStatus::InferredOsmMapping;
            if (! $status instanceof GeoPlaceClassificationStatus) {
                $status = GeoPlaceClassificationStatus::from((string) $status);
            }

            return new OsmPoiClassification((string) $rule['type'], $status, (int) $index);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tags
     * @param  list<array<string, mixed>>  $conditions
     */
    private function matches(array $tags, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $tag = (string) ($condition['tag'] ?? '');
            $value = $this->tagValue($tags, $tag);

            if (($condition['present'] ?? false) === true) {
                if ($value === null || $value === '' || $value === 'no') {
                    return false;
                }

                continue;
            }

            if ($value === null) {
                return false;
            }

            if (isset($condition['equals']) && ! $this->equals($value, (string) $condition['equals'])) {
                return false;
            }

            if (isset($condition['in'])) {
                $allowed = $condition['in'];
                if (! is_array($allowed) || ! $this->inList($value, $allowed)) {
                    return false;
                }
            }

            if (isset($condition['contains_token'])) {
                $tokens = $condition['contains_token'];
                if (! is_array($tokens) || ! $this->containsToken($value, $tokens)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function tagValue(array $tags, string $key): ?string
    {
        if ($key === '' || ! array_key_exists($key, $tags)) {
            return null;
        }

        $value = $tags[$key];
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function equals(string $value, string $expected): bool
    {
        return mb_strtolower($value) === mb_strtolower($expected);
    }

    /**
     * @param  list<mixed>  $allowed
     */
    private function inList(string $value, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            if (is_scalar($candidate) && $this->equals($value, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $tokens
     */
    private function containsToken(string $value, array $tokens): bool
    {
        $parts = preg_split('/[;|,]+/', mb_strtolower($value)) ?: [];
        $parts = array_map(trim(...), $parts);

        foreach ($tokens as $token) {
            if (! is_scalar($token)) {
                continue;
            }
            if (in_array(mb_strtolower((string) $token), $parts, true)) {
                return true;
            }
        }

        return false;
    }
}
