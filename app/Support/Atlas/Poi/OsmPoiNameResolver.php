<?php

namespace App\Support\Atlas\Poi;

final class OsmPoiNameResolver
{
    /**
     * Deterministic display-name preference for a Bosnian-language site.
     * Does not translate or invent names.
     *
     * @param  array<string, mixed>  $tags
     * @return array{name: string|null, name_cyrillic: string|null}
     */
    public function resolve(array $tags): array
    {
        $latinKeys = ['name:sr-Latn', 'name:bs', 'name', 'name:hr', 'name:sr'];
        $name = null;
        foreach ($latinKeys as $key) {
            $candidate = $this->stringTag($tags, $key);
            if ($candidate === null) {
                continue;
            }
            if ($key === 'name:sr' && $this->looksCyrillic($candidate)) {
                continue;
            }
            $name = $candidate;
            break;
        }

        $cyrillic = $this->stringTag($tags, 'name:sr-Cyrl');
        if ($cyrillic === null) {
            $sr = $this->stringTag($tags, 'name:sr');
            if ($sr !== null && $this->looksCyrillic($sr)) {
                $cyrillic = $sr;
            }
        }

        return [
            'name' => $name,
            'name_cyrillic' => $cyrillic,
        ];
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

    private function looksCyrillic(string $value): bool
    {
        return (bool) preg_match('/\p{Cyrillic}/u', $value);
    }
}
