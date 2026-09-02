# M7 — Climate normals + historical climate analytics

**Status: implemented** on `feature/bih-settlement-atlas`.

Pilot: Modriča + Šamac. Climate is computed only for settlements with a valid M4 boundary. Settlements without polygons are left unavailable. Climate is **not** taken from the municipality centre.

Existing `WeatherService` / `/api/weather` forecast remains a separate operational product. M7 is historical reanalysis analytics. Visitor HTTP requests never call Copernicus, CDS, Open-Meteo, xarray, or NetCDF.

## Source (verified)

| Field | Temperature | Precipitation |
|---|---|---|
| Dataset | **ERA5-Land** hourly reanalysis | **ERA5** hourly reanalysis |
| Provider | Copernicus Climate Change Service / ECMWF | Copernicus Climate Change Service / ECMWF |
| DOI | [10.24381/cds.e2161bac](https://doi.org/10.24381/cds.e2161bac) | [10.24381/cds.adbb2d47](https://doi.org/10.24381/cds.adbb2d47) |
| Licence | Copernicus C3S/CAMS Licence | Copernicus C3S/CAMS Licence |
| Native grid | CDS regular lat/lon **0.1° × 0.1°** (~9 km) | **0.25° × 0.25°** |
| Coverage | 1950–present, hourly | 1940–present, hourly |
| Retrieval | Open-Meteo Historical Weather API `archive-api.open-meteo.com`, `models=era5_land` | same API, `models=era5` |
| Daily variables | `temperature_2m_mean`, `temperature_2m_min`, `temperature_2m_max` (°C) | `precipitation_sum` (mm) |
| Timezone in API | `Europe/Sarajevo` | `Europe/Sarajevo` |

ERA5-Land total precipitation is an atmospheric **forcing** field. Open-Meteo ERA5-Land returns `precipitation_sum = null`. Precipitation is therefore taken from **ERA5** and labelled as ERA5, never as ERA5-Land.

This is category **B** (gridded reanalysis), not station observations and not a forecast.

`data_sources` row: `copernicus_era5_land` (name *Copernicus Climate Change Service — ERA5-Land*). Derived settlement statistics are not a second authoritative source; provenance is ERA5-Land + ERA5 + algorithm `m7.1` + area-weighted sampling + explicit periods.

## Periods

- **Normals:** 1991-01-01 through 2020-12-31 (WMO standard normal). Stored explicitly. Never “last 30 years”.
- **Historical extremes / indices series:** 1950-01-01 through the last **complete** calendar year in the processed archive (2025-12-31 when processed in 2026). Partial current year is excluded.
- **Change comparison:** mean annual temperature 1961–1990 vs 1991–2020, exposed as a difference between two ERA5-Land reanalysis periods.

## Spatial sampling

Area-weighted intersection:

ERA5 / ERA5-Land grid cell ∩ settlement polygon → intersection area (equirectangular at mean latitude) → weight → weighted daily series.

Settlements smaller than one cell still receive the intersecting cell(s). No extra interpolation. No municipality-centre fallback. No fabricated polygons.

Metadata stores intersecting cells, weights, and the dominant cell. Quality flag for successful profiles is `coarse_spatial_resolution` (expected: the grid is coarser than the settlement).

## Temporal aggregation

Open-Meteo daily products are requested with `timezone=Europe/Sarajevo`, so daily min/max/mean and precipitation totals use Bosnia and Herzegovina civil days including historical DST.

Hourly synthetic tests (and any future direct CDS hourly ingest) bucket UTC hours onto `Europe/Sarajevo` calendar days. UTC and local time are not mixed.

Precipitation: accumulated water equivalent. Daily mm = sum of hourly metres × 1000. Hourly accumulations are never averaged as rates. Public unit is **mm**. Open-Meteo daily `precipitation_sum` is already mm.

Wind, snow, and humidity are **deferred** (ERA5-Land wind fields are not used under an ERA5-Land label; snow semantics were not fully wired).

## Derived products

Stored in MySQL (not hourly archives):

- `settlement_climate_profiles` — period, spatial method, annual summary, indices JSON, quality, algorithm version
- `settlement_climate_monthly` — unique `(climate_profile_id, month)`
- `settlement_climate_extremes` — unique `(climate_profile_id, metric_code, period_start, period_end)`

Indices (climatological days/year over 1991–2020):

| Code | Threshold |
|---|---|
| `hot_days_30c` | daily Tmax ≥ 30 °C |
| `very_hot_days_35c` | daily Tmax ≥ 35 °C |
| `frost_days` | daily Tmin < 0 °C |
| `ice_days` | daily Tmax < 0 °C |
| `tropical_nights` | daily Tmin ≥ 20 °C |
| `precipitation_days_1mm` | daily precip ≥ 1 mm |

Extremes wording is mandatory reanalysis language, e.g. “Najviša dnevna maksimalna temperatura u analiziranom ERA5-Land nizu”. Never “rekord izmjeren u naselju”.

Algorithm version: **`m7.1`**. Recompute when source revision, boundary revision, spatial method, calculation method, or normal period changes. Unique profile key includes algorithm version, so `m7.2` is a new row.

## Pipeline (operator only)

```
php artisan atlas:export-climate-jobs --pilot
python tools/atlas/fetch_climate.py --jobs ... --cache-dir tools/atlas/cache/climate
python tools/atlas/process_climate.py --jobs ... --cache-dir ... --output storage/app/private/atlas/climate/climate-pilot.json
php artisan atlas:import-climate --pilot [--from-file=...] [--dry-run] [--municipality=modrica|samac]
```

One regional fetch for unique grid cells, not one CDS/Open-Meteo request per settlement. National scale: download a BiH bounding box once, then compute many settlements.

Raw NetCDF/GRIB/Zarr/Open-Meteo JSON stay gitignored.

## Public API

`GET /api/geo/municipalities/{municipality}/settlements/{settlement}` includes `climate` only when a usable profile exists. Units: °C and mm. No hourly series.

Map GeoJSON adds `temperature_mean_annual_c`, `precipitation_annual_mm`, `climate_quality_flag`, `climate_normal_period` when present. Features without climate still participate in other map modes.

## Quality flags

`ok` (unused for ERA5-Land at this resolution), `coarse_spatial_resolution`, `representative_cell_only`, `no_boundary`, `insufficient_source`, `unavailable`.

## National scaling

Do not process all of Bosnia and Herzegovina in M7. The jobs exporter + unique-cell fetch is the batch shape for a later BiH bounding box.

## Tests

PHP: `tests/Feature/AtlasClimateImportTest.php` plus map/SEO/hierarchy regressions. Python: `tools/atlas/test_climate.py` (synthetic arrays, no download).

## Pilot processing (2026-09-02)

Jobs: 37 boundary settlements (`php artisan atlas:export-climate-jobs --pilot`). Unique Open-Meteo cells: 16 ERA5-Land + 5 ERA5. Retrieval date **2026-09-02**. Historical series through **2025-12-31**.

Imported: **35** usable profiles (`coarse_spatial_resolution`). Second import: Inserted 0, Updated 0, Unchanged 35, Unavailable 2 (idempotent).

Unavailable (flag `insufficient_source`, API omits `climate`):

| Settlement | Reason |
|---|---|
| Šamac (town) | Missing ERA5-Land cell `45.10/18.40` windows 2000–2024 and 2025 |
| Pisari | Missing ERA5-Land cell `45.10/18.50` |

Open-Meteo archive returned **HTTP 429** on retry; local CDS/`~/.cdsapirc` is not configured. Re-run `fetch_climate.py` then `process_climate.py` and `atlas:import-climate --pilot` when the archive accepts requests.

Temperature range among successful profiles is **9.34–12.33 °C** (not a pipeline crash): Jasenica 9.34 °C / 922.5 mm (2 cells, southern/higher Modriča) vs Batkuša 12.33 °C / 780.1 mm (Posavina). Neighbours often share one ERA5 0.25° precipitation cell.

Example Miloševac: 12.23 °C, 780.9 mm, coldest month 1 (~0.8 °C), warmest 8 (~23.0 °C), 1961–1990 mean 11.22 °C (change +1.01 °C). Extremes in the 1950–2025 reanalysis series: Tmax 40.3 °C (1988-07-06), Tmin −27.2 °C (1963-01-24), wettest day 55.9 mm (2001-09-06). Indices (days/year, 1991–2020): hot 27.2, frost 74.2, precip ≥1 mm 118.4, tropical nights 15.0. `grid_cell_count` 1.

Eight pilot settlements without M4 polygons were never in the climate jobs.
