# Implementation plan — BiH Settlement Atlas

Branch: `feature/bih-settlement-atlas`

| ID | Status | Scope |
|---|---|---|
| M0 | Done | Audit and policy docs |
| M1 | Complete | Core schema |
| M2 | Complete | Pilot Modriča + Šamac settlements |
| **M3** | **Complete** | Public `/api/geo` + crawler/sitemap |
| **M4** | **Complete** | Settlement boundaries + DEM terrain stats (37/45) |
| **M5** | **Complete** | Public map GeoJSON endpoint |
| **M6** | **Complete** | `geo_places` catalog + OSM Overpass import + public POI API |
| **M7** | **Complete** | ERA5-Land/ERA5 climate profiles + public climate API |
| **M8** | **Complete** | Historical census 1971/1981/1991 + mappings + change API |
| **M9** | **Complete** | Metric registry, comparison API, rankings API |
| **M10** | **Complete** | CLC/HRL land-cover profiles + public API/map/M9 metrics |

See [M3_PUBLIC_ATLAS.md](M3_PUBLIC_ATLAS.md).

## M4 result

- OSM admin_level=9 polygons (ODbL) for 37/45 pilot settlements; 8 correctly left unmatched
- GeoJSON + bbox storage (production MySQL spatial types unverified)
- Copernicus DEM GLO-30 offline terrain stats (algorithm `m4.1`)
- Public `geography` API block
- See [M4_BOUNDARIES_TERRAIN.md](M4_BOUNDARIES_TERRAIN.md)

## M5 result

- `GET /api/geo/map/settlements` FeatureCollection of valid M4 boundaries
- Optional `?municipality=` filter, ETag / Last-Modified, 10-minute app cache
- Canonical GeoJSON, no serving-layer simplification (pilot ~381 KB / 37 features)
- See [M5_INTERACTIVE_MAP.md](M5_INTERACTIVE_MAP.md)

## M6 result

- `geo_place_types` (57) + `geo_places`; OSM identity `(source_id, osm_type, osm_id)`
- `atlas:fetch-osm-pois` / `atlas:import-pois`; Overpass is operator-only
- 761 unique pilot POIs; `GET /api/geo/map/places` ~345 KB
- See [M6_POI_CATALOG.md](M6_POI_CATALOG.md)

## M7 result

- Tables `settlement_climate_profiles` / `_monthly` / `_extremes`; algorithm `m7.1`
- `atlas:export-climate-jobs`, `fetch_climate.py`, `process_climate.py`, `atlas:import-climate`
- Public `climate` block on settlement detail; map properties for temperature and precipitation
- Pilot import: **35/37** boundary settlements (`coarse_spatial_resolution`); Šamac town and Pisari `insufficient_source` after Open-Meteo 429
- See [M7_CLIMATE.md](M7_CLIMATE.md)

## M8 result

- `settlement_census_mappings`; importer `atlas:import-historical-population` (`m8.1`)
- Fixtures `census-historical-pilot.json` + `census-historical-pilot-mapping.json`
- Public `census` comparability + `population_changes`; map `population_change_1991_2013_pct`
- See [M8_HISTORICAL_POPULATION.md](M8_HISTORICAL_POPULATION.md)

## M9 result

- `SettlementMetricRegistry` + `SettlementRankingService` + `SettlementComparisonService`
- `GET /api/geo/metrics`, `/api/geo/rankings`, `/api/geo/compare/{mA}/{sA}/{mB}/{sB}`
- Settlement `relative_facts`; crawler HTML for `/rang-liste`; `noindex` compare URLs
- See [M9_COMPARISON_RANKINGS.md](M9_COMPARISON_RANKINGS.md)

## M10 result

- Tables `settlement_surface_profiles` / `settlement_land_cover_classes`; algorithm `m10.1`
- `atlas:export-landcover-jobs`, `fetch_landcover.py`, `process_landcover.py`, `atlas:import-landcover`
- Public `land_cover` block; compact map properties; ranking denominators 37 eligible / 8 missing
- See [M10_LAND_COVER.md](M10_LAND_COVER.md)

## M11 implementation (acceptance QA passed)

- Actual regional OSM acquisition, offline GIS processing and idempotent pilot DB import.
- 37 road/hydro profiles; 37 settlements with proximity; M9 ranking/compare integration.
- Optional municipality-scoped network GeoJSON; settlement transport/hydro/proximity sections.
- Automated validation and API measurements completed; desktop/mobile page QA, map-layer inspection and desktop render timing passed 2026-10-19 after fixing the frontend MapLibre worker URL. Proximity targets outside the OSM BiH boundary are flagged (`m11.2`).
- See [M11_NETWORK_ACCESSIBILITY.md](M11_NETWORK_ACCESSIBILITY.md).
- M12 has NOT started.
