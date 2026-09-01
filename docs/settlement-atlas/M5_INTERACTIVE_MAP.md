# M5 — Interactive settlement atlas map

**Status: implemented** on `feature/bih-settlement-atlas`. M6 (POIs, climate, roads, rankings, compare) was not started.

## Map engine

| Field | Value |
|---|---|
| Library | `maplibre-gl` **6.6.0** |
| Leaflet | not used |
| Mapbox SDK | not used |
| Data delivery | GeoJSON FeatureCollection (no PMTiles / MBTiles / Tegola / Martin) |
| Source abstraction | `atlas-map-engine.ts` `AtlasMapSourceSpec` (`geojson` now, `vector` later) |

MapLibre is encapsulated in `useMapLibre`, `atlas-map-engine.ts`, and `src/components/atlas/map/*`. Page components do not call MapLibre APIs.

## Basemap

| Field | Value |
|---|---|
| Provider | [OpenFreeMap](https://openfreemap.org/) public instance |
| Style URL | `https://tiles.openfreemap.org/styles/positron` |
| Config | `VITE_ATLAS_MAP_STYLE_URL` (frontend `.env`) |
| API key | none |
| Licence / attribution | OpenFreeMap + OpenMapTiles + © OpenStreetMap contributors. MapLibre attribution control is left visible. |
| Production terms | Public instance is free and used in production by other sites; no published hard cap. Service is as-is (no SLA). Self-host if the map becomes operationally critical. |
| CORS / HTTPS | `https://tiles.openfreemap.org` |

Do not use Google tiles or Mapbox proprietary tiles.

## Map API

`GET /api/geo/map/settlements`

Optional: `?municipality=modrica` or `?municipality=samac`. Unknown municipality → 404.

Response is a GeoJSON `FeatureCollection` of settlements that have a **valid** canonical M4 boundary. Settlements without geometry are omitted from the payload (they remain in list/search/detail pages).

Pilot measurement (2026-09-02, 37 features, **no simplification**):

* **390 404 bytes** (~381 KB JSON)
* Feature count: 37
* Geometry strategy: `canonical_geojson`

Simplification was not added. Canonical `geom_geojson` in the database is unchanged.

### Feature properties

`settlement_id`, `settlement_name`, `settlement_slug`, `municipality_name`, `municipality_slug`, `path`, `indexable`, `area_km2`, `population_2013` (0 preserved), `elevation_*_m`, `slope_mean_deg`, `boundary_source_type`, `terrain_quality_flag`.

`id` on the Feature equals `settlement_id`. Identity is never slug-only.

Collection `meta` carries compact source notes (OSM boundaries, Copernicus DEM, 37/45 coverage). No filesystem paths, no `authority_rank`.

### Caching

Application cache key: `geo.map.settlements.{municipality|all}.{datasetVersion}` for 600 s.

`datasetVersion` hashes max `updated_at` of boundaries and terrain stats plus valid-boundary count.

HTTP: `Cache-Control: public, max-age=300, stale-while-revalidate=600`, `ETag`, `Last-Modified`. `If-None-Match` → 304.

## Thematic modes

Scale is **linear interpolation from min–max of currently loaded features**, not official physical classes.

| Mode | Property | Units |
|---|---|---|
| Granice | uniform fill | — |
| Prosječna visina | `elevation_mean_m` | m |
| Visinski raspon | `elevation_range_m` | m |
| Prosječan nagib | `slope_mean_deg` | ° |
| Stanovništvo — Popis 2013 | `population_2013` | count (not current population) |

Missing values render grey. Hover/selected outlines are independent of fill colour.

Density is not calculated.

## Missing boundaries

Eight pilot settlements have no OSM polygon (see M4). They stay in search, municipality lists, and settlement pages. Map UI: “Granica trenutno nije dostupna na karti.” No fabricated points or circles.

## Search

Normalized atlas search still filters the accessible list. A unique settlement match also selects that `settlement_id` on the map (municipality-qualified). Popup: **Detalji o naselju** → `/mjesta/{municipality}/{settlement}`.

## Fallback

* WebGL / MapLibre init failure: fallback copy + list/search still works.
* GeoJSON API failure: banner; map chrome remains; lists still work.
* Basemap style failure: blank style + warning; polygons may still draw.

Map container reserves height (`min-height: 18rem`) before init. `cooperativeGestures` is on for mobile page-scroll.

## Attribution (must stay visible)

1. OpenFreeMap / OSM / OpenMapTiles (basemap, MapLibre control)
2. OSM settlement polygons (ODbL) — custom source attribution + on-page copy
3. Copernicus DEM GLO-30 where terrain values are shown

Public wording does **not** call polygons cadastral or census boundaries.

## When to introduce PMTiles / vector tiles

Not now (37 polygons, ~381 KB). Reconsider when any of:

* national polygon coverage
* payload becomes materially large (multi-MB) or parse/render is slow on mobile
* national POI / climate layers need tiled sources
* geometry must be simplified for serving (never for terrain stats)
* repeated measurable mobile jank

Swap `AtlasMapSourceSpec.kind` from `geojson` to `vector` without rewriting the UI chrome.

## Commands

```bash
# backend
php artisan test
vendor/bin/pint --dirty

# frontend
npm test
npm run lint
npm run build
```
