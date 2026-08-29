<?php

namespace App\Support\PowerOutage;

use App\Models\Place;
use Illuminate\Support\Collection;

class PlaceMatcher
{
    public function __construct(private PlaceNormalizer $normalizer = new PlaceNormalizer()) {}

    /** @return list<MatchedPlace> */
    public function match(PowerOutageNotice $notice, ?Collection $places = null): array
    {
        $places ??= Place::query()->orderByDesc('is_primary')->orderBy('sort_order')->get();
        $haystack = implode(' ', [
            $notice->locationsRaw,
            $notice->transformerArea,
            $notice->fieldUnit,
        ]);

        return $this->matchHaystack($haystack, $places);
    }

    /** Prefer the settlement named in the email over the broader field unit (e.g. Vranjak over Modriča). */
    public function primaryForTitle(PowerOutageNotice $notice, array $places, ?Collection $dictionary = null): ?MatchedPlace
    {
        if ($places === []) {
            return null;
        }

        $dictionary ??= Place::query()->orderByDesc('is_primary')->orderBy('sort_order')->get();
        $bySlug = collect($places)->keyBy('slug');

        foreach ([$notice->locationsRaw, $notice->transformerArea, $notice->fieldUnit] as $segment) {
            foreach ($this->matchHaystack($segment, $dictionary) as $match) {
                $place = $bySlug->get($match->slug);
                if ($place !== null && filled($place->locative)) {
                    return $place;
                }
            }
        }

        return collect($places)->first(fn (MatchedPlace $place) => filled($place->locative));
    }

    /** @return list<MatchedPlace> */
    private function matchHaystack(string $haystack, Collection $places): array
    {
        $haystackKey = $this->normalizer->key($haystack);
        $matched = [];

        foreach ($places as $place) {
            $aliases = array_values(array_unique(array_filter([
                $place->name,
                $place->slug,
                ...(array) ($place->aliases ?? []),
            ])));

            foreach ($aliases as $alias) {
                $aliasKey = $this->normalizer->matchKey($alias);
                if ($aliasKey === '' || strlen($aliasKey) < 3) {
                    continue;
                }

                if (str_contains($haystackKey, $aliasKey) || str_contains($this->normalizer->matchKey($haystack), $aliasKey)) {
                    $matched[$place->slug] = new MatchedPlace(
                        slug: $place->slug,
                        name: $place->name,
                        locative: $place->locative,
                        isPrimary: (bool) $place->is_primary,
                    );
                    break;
                }
            }
        }

        return array_values($matched);
    }
}
