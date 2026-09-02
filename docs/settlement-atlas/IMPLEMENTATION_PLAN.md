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

M8 was not started.
