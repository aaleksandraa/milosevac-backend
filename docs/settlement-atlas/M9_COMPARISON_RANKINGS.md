# M9 — Settlement comparison + rankings

**Status: implemented** on `feature/bih-settlement-atlas`. **M10 was not started.**

Pilot: current Modriča + Šamac, 45 canonical settlements. Rankings are **not** national. Copy always identifies the currently processed universe.

## Metric registry

`SettlementMetricRegistry` is the single source of metric metadata (code, labels, unit, source family, direction, eligibility, precision, ranking method, zero handling, methodology). Controllers and React pages consume the catalog; they do not hard-code ranking SQL.

Rankable metrics:

| Code | Family |
|---|---|
| `population_2013` | population |
| `population_change_1991_2013_pct` | population (strict comparable only) |
| `area_km2` | geography |
| `elevation_mean_m` `elevation_min_m` `elevation_max_m` `elevation_range_m` `slope_mean_deg` | terrain |
| `temperature_mean_annual_c` `precipitation_annual_mm` `hot_days_30c` `frost_days` | climate |
| `poi_count_total` `poi_count_sport` `poi_count_health` `poi_count_education` `poi_count_religion` `poi_count_historic` | assigned OSM POIs |

Comparison-only: `population_1991`, `population_change_1991_2013_abs`.

No composite quality/livability score. No POIs per capita.

## Scopes

Implemented: `pilot`, `municipality`.

`pilot` = all currently imported canonical settlements (not hard-coded Modriča/Šamac). Future: `canton`, `entity`, `country`. Public wording is always “trenutno obrađena naselja”, never “u Bosni i Hercegovini”.

## Ranking

Computed in application code (SQLite-safe; no `RANK() OVER`). **Competition ranking:** 1, 2, 2, 4. Ties share a rank; display order inside a tie is alphabetical by name. Settlement ID is not used to pretend values differ. Values are ranked after rounding to the metric’s public precision, so the table never shows identical displayed values with different ranks.

**Percentile:** share of *eligible* settlements with a **strictly** higher (`percentile_strictly_above`) or lower (`percentile_strictly_below`) value. Ties are excluded from both counts. Denominator is metric-specific eligible N, not 45.

Missing data is excluded. Zero is valid where the registry says so (2013 population, POI counts, frost/hot days, some terrain minima).

`population_change_1991_2013_pct` requires 1991 and 2013, 1991 ≠ 0, and **directly comparable** mappings (caveat excluded).

POI rankings count only active places assigned to the settlement (`boundary_match` or `explicit_verified`). `municipality_only` is never attributed to a settlement.

## Compare

`GET /api/geo/compare/{municipalityA}/{settlementA}/{municipalityB}/{settlementB}`

Resolves each settlement through its municipality (404 on mismatch; same slugs do not cross-resolve). Differences are A − B. Percentage difference only for headcount (`population_2013`, `population_1991`) and area. Absolute 1991–2013 change, temperature, elevation, precipitation, and POI counts show unit difference only.

## API

- `GET /api/geo/metrics`
- `GET /api/geo/rankings?metric=&scope=pilot|municipality&municipality=&direction=asc|desc&limit=`
- Settlement detail: `relative_facts` (default: population 2013, area, mean elevation, mean temperature, 1991–2013 % if eligible)

## Cache

Ranking tables are cached 600s. Cache key includes metric, scope, municipality, direction, ranking precision, and a **source-family dataset version** (max `updated_at` + count of the relevant table). Population/terrain/climate/POI/boundary updates change the version without a manual flush.

## SEO

- `/rang-liste` is indexable. Canonical is the base path. Crawler HTML includes a real ranking table.
- Sitemap includes `/rang-liste` only (not metric combinations).
- `/uporedi/...` is `noindex,follow`, not in the sitemap.

## Commands

`php artisan atlas:report-rankings` (`--json`)

## Pilot denominators (actual, 2026-09-02)

Universe = 45 currently imported settlements. Eligible N is metric-specific.

| Metric | Eligible | Missing |
|---|---|---|
| `population_2013` | 45 | 0 |
| `population_change_1991_2013_pct` (strict) | 40 | 5 |
| `area_km2` and terrain (`elevation_*`, `slope_mean_deg`) | 37 | 8 |
| climate (`temperature_mean_annual_c`, `precipitation_annual_mm`, `hot_days_30c`, `frost_days`) | 35 | 10 |
| POI counts (assigned OSM objects; zero is valid) | 45 | 0 |

Strict 1991→2013 exclusions: Krčevljani and Gajevi (`comparable_with_caveat`); Borovo Polje, Lugovi, Njegoševo (`unresolved`). Historical rows without a canonical settlement (Jakeš, Prud, …) are not ranked.

POI rankings count only places assigned to the settlement. Zero means no assigned import, not “no objects exist in the real world”.

