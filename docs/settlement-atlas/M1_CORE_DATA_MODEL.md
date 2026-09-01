# M1 — Core data model

**Status: implemented.** See the full record in the frontend repo `docs/settlement-atlas/M1_CORE_DATA_MODEL.md` (copied here for the backend tree).

## Geometry

Production version unverified. Decimal `geographic_centroid_lat/lng` and `label_point_lat/lng` only. No `settlement_boundaries`. No native POINT/MULTIPOLYGON.

## Tables

`data_sources`, `geo_countries`, `geo_entities`, `geo_cantons`, `geo_municipalities`, `geo_settlements`, `data_imports`, `settlement_population`.

UNIQUE `(municipality_id, slug)` on settlements.

## Seeds

BiH, FBiH, RS, Brčko District, 10 FBiH cantons (FZS 2026), Modriča, Šamac, Brčko LGU. No settlement rows.

## Tests

`composer test` — 58 passed including `GeoAtlasHierarchyTest` and `SourcePriorityComparatorTest`.
