<?php

namespace App\Support\Atlas\Import;

use App\Models\GeoSettlement;
use App\Models\Place;
use App\Support\PowerOutage\PlaceNormalizer;

final class OutagePlaceLinker
{
    public const LINKED = 'linked';

    public const NOT_FOUND = 'not_found';

    public const AMBIGUOUS = 'ambiguous';

    public const UNCHANGED = 'unchanged';

    public function __construct(private PlaceNormalizer $normalizer = new PlaceNormalizer) {}

    /**
     * Exact unique name/alias match only. Never substring matching.
     *
     * @return array{status: string, place_id: int|null}
     */
    public function resolve(GeoSettlement $settlement): array
    {
        $target = $this->normalizer->key($settlement->name);
        if ($target === '') {
            return ['status' => self::NOT_FOUND, 'place_id' => null];
        }

        $matches = Place::query()->get()->filter(function (Place $place) use ($target) {
            $names = array_filter([
                $place->name,
                ...((array) ($place->aliases ?? [])),
            ]);

            foreach ($names as $candidate) {
                if ($this->normalizer->key((string) $candidate) === $target) {
                    return true;
                }
            }

            return false;
        })->values();

        if ($matches->count() > 1) {
            return ['status' => self::AMBIGUOUS, 'place_id' => null];
        }

        if ($matches->isEmpty()) {
            return ['status' => self::NOT_FOUND, 'place_id' => null];
        }

        $placeId = (int) $matches->first()->id;
        if ($settlement->outage_place_id === $placeId) {
            return ['status' => self::UNCHANGED, 'place_id' => $placeId];
        }

        return ['status' => self::LINKED, 'place_id' => $placeId];
    }
}
