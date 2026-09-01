# M4 — Settlement boundaries and terrain foundation

**Status: implemented** on `feature/bih-settlement-atlas` (backend + frontend). M5 (interactive map) was not started.

Production MySQL `SELECT VERSION()` / `ST_*` was **not** available from this environment. Canonical geometry is **GeoJSON + bbox scalars** on `settlement_boundaries` (one store). Native `MULTIPOLYGON`/spatial index can replace the JSON column later without a second canonical store.

## Boundary source (selected)

| Field | Value |
|---|---|
| Dataset | OpenStreetMap administrative settlement polygons (`boundary=administrative`, `admin_level=9`) |
| Publisher | OpenStreetMap contributors |
| Source URL | https://www.openstreetmap.org/copyright |
| Geometry export | https://polygons.openstreetmap.fr/get_geojson.py |
| Retrieval date | 2026-09-02 |
| License | ODbL |
| CRS | EPSG:4326 |
| Version | `osm-2026-09-01` (OSM planet timestamp ~2026-09-01) |
| Feature id | `osm_id` / `relation/{id}` |
| Name fields | `name`, `name:sr-Latn`, `name:sr`, `name:bs` |
| Municipality | two-pass: unique BiH exact normalized name, then pass-1 cluster envelope; plus one explicit manual row |
| Geometry type | MultiPolygon (Polygon promoted, never collapsed) |
| Class / rank | C / 50 |

### Why this source

Preferred order was official statistical/cadastral polygons, then entity GIS, then established public GIS, then OSM polygons.

- **A.** No downloadable BHAS/RZS settlement-polygon extract was available here.
- **B.** RGURS GeoServer WFS answers GetCapabilities but **FeatureTypeList is empty**; guessed `naselja` typeNames returned HTTP 400. WMS exists for viewing only. Not treated as an official download.
- **C/D.** Harvard 1991 settlement shapefiles are pre-war, different identity, topology-warned — not used.
- **OSM polygons** for RS naselja at admin_level=9 exist as relations (not `place=village` nodes). Nodes were not used. Voronoi/radius polygons were not generated. Outage `places` were not used.

This is **not** a cadastral or 2013-census boundary product. Public copy must say so.

### Identity matching

Never name-similarity fuzzy match.

1. Exclude OSM relation `8758298` (tagged wikidata Q533433 but geometry is in Serbia).
2. Exact normalized name, unique in the BiH candidate set (FBiH-qualified homonyms dropped).
3. Remaining homonyms: unique candidate whose centre falls in the pass-1 municipality cluster envelope.
4. **Šamac (naselje)** — `relation/13426749` — **explicit manual review**: Wikidata Q391033 is the municipality, but the exported polygon is town-scale (does not cover Obudovac etc.) and is attached only to naselje Šamac.

Checked-in mapping: `database/imports/atlas/osm-pilot-boundary-mapping.json`.

## Geometry storage

`settlement_boundaries.geom_geojson` is canonical in this deploy. Bbox columns support later spatial queries. GeoJSON is also the API/interchange form.

Area: spherical excess on WGS84 authalic radius 6 371 008.8 m (`spherical_excess_authalic_r6371008.8`). Not degree² as m².

Geographic centroid: equirectangular shoelace at mean latitude (`equirectangular_shoelace_mean_latitude`). **Does not write `label_point_*`.** It is not the inhabited village centre.

Polygons larger than 100 km² are rejected.

## DEM / terrain

| Field | Value |
|---|---|
| Product | Copernicus DEM GLO-30 Public |
| Provider | ESA / European Commission |
| Version used | `2021_1` (AWS Open Data COG tiles, retrieved 2026-09-02) |
| Nominal resolution | ~30 m (1 arc-second COG, `Copernicus_DSM_COG_10`) |
| Horizontal CRS | EPSG:4326 |
| Vertical datum | EGM2008 (product documentation) |
| License | Copernicus DEM GLO-30 Public licence |
| Retrieval | `s3://copernicus-dem-30m/` HTTP, tiles `N44_00_E018_00` and `N45_00_E018_00` |
| Algorithm | `m4.1` |

GLO-90 was **not** substituted. Google elevation was not used. Rasters are gitignored (`tools/atlas/dem/`).

Offline sidecar: `python tools/atlas/process_terrain.py`. Not run on visitor requests.

**Cell inclusion:** pixel **centre** inside the polygon (shapely `covers`), same for every settlement.

**Slope:** Horn 3×3 on the DEM grid, degrees. Incomplete neighbourhoods skipped. Elevation and slope sampling share the same inclusion set; slope additionally requires a valid 3×3.

**Nodata:** GeoTIFF/sidecar nodata tag, or non-finite values. **Elevation 0 is valid** and is never treated as nodata.

**Quality:** `unavailable` if no valid cells; `high_nodata` if nodata fraction > 0.5; `low_sample` if valid cells < 9; else `ok`. Mean/median are not invented when there are no valid cells.

Recompute when `boundary_id`, `dem_version`, or `algorithm_version` changes (`settlement_terrain_unique_run`). `--force` overwrites.

## Pilot completeness (45 census settlements)

**37 / 45** have a boundary and a terrain record. **0** ambiguous. **0** invalid imported geometries.

Missing OSM polygon (correctly left empty):

| Settlement | Municipality | Why |
|---|---|---|
| Botajica | Modriča | No unique OSM admin_level=9 polygon |
| Donji Skugrić | Modriča | No unique in-scope OSM polygon (FBiH homonym exists) |
| Gornje Krečane | Modriča | No unique OSM polygon |
| Gornji Hasić | Šamac | No unique OSM polygon |
| Kruškovo Polje | Šamac | No unique OSM polygon |
| Njegoševo | Šamac | No unique OSM polygon |
| Tišina | Šamac | No unique OSM polygon |
| Škarić | Šamac | No unique OSM polygon |

## Results table

| settlement | municipality | feature | status | area_km2 | samples | min | max | mean | median | range | quality |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Babešnica | Modriča | relation/14590886 | has_boundary | 2.7261 | 4028 | 125.8 | 311.8 | 202.1 | 202.4 | 186.0 | ok |
| Borovo Polje | Modriča | relation/14590948 | has_boundary | 5.3079 | 7843 | 90.5 | 123.4 | 100.9 | 100.3 | 32.9 | ok |
| Botajica | Modriča | — | missing_boundary | — | — | — | — | — | — | — | — |
| Dobra Voda | Modriča | relation/14590744 | has_boundary | 7.0345 | 10422 | 183.1 | 353.6 | 263.7 | 263.5 | 170.6 | ok |
| Dobrinja | Modriča | relation/14590942 | has_boundary | 8.3147 | 12316 | 104.3 | 348.4 | 196.4 | 200.6 | 244.1 | ok |
| Donji Skugrić | Modriča | — | missing_boundary | — | — | — | — | — | — | — | — |
| Dugo Polje | Modriča | relation/14590840 | has_boundary | 29.2469 | 43322 | 106.5 | 373.2 | 199.9 | 203.3 | 266.7 | ok |
| Garevac | Modriča | relation/14590928 | has_boundary | 17.7108 | 26234 | 90.5 | 114.7 | 101.9 | 102.9 | 24.2 | ok |
| Gornje Krečane | Modriča | — | missing_boundary | — | — | — | — | — | — | — | — |
| Jasenica | Modriča | relation/14590907 | has_boundary | 4.8313 | 7140 | 283.7 | 538.9 | 426.2 | 430.4 | 255.2 | ok |
| Kladari Donji | Modriča | relation/14590935 | has_boundary | 8.1335 | 12049 | 89.0 | 115.8 | 98.1 | 98.7 | 26.8 | ok |
| Kladari Gornji | Modriča | relation/14590943 | has_boundary | 4.6820 | 6934 | 101.1 | 115.8 | 105.5 | 105.2 | 14.7 | ok |
| Koprivna | Modriča | relation/14590875 | has_boundary | 38.9778 | 57666 | 109.0 | 621.1 | 257.3 | 262.0 | 512.1 | ok |
| Krčevljani | Modriča | relation/14590904 | has_boundary | 13.2910 | 19648 | 195.0 | 550.7 | 382.1 | 384.6 | 355.7 | ok |
| Kužnjača | Modriča | relation/14590869 | has_boundary | 2.4047 | 3556 | 108.0 | 262.7 | 154.4 | 152.3 | 154.7 | ok |
| Miloševac | Modriča | relation/14590939 | has_boundary | 17.3782 | 25772 | 88.0 | 119.0 | 96.4 | 95.1 | 31.0 | ok |
| Modriča | Modriča | relation/14590926 | has_boundary | 13.0286 | 19296 | 93.5 | 261.4 | 116.1 | 106.2 | 167.9 | ok |
| Riječani Donji | Modriča | relation/14590890 | has_boundary | 9.0845 | 13448 | 130.0 | 335.5 | 212.3 | 212.2 | 205.4 | ok |
| Riječani Gornji | Modriča | relation/14590894 | has_boundary | 13.5808 | 20089 | 185.0 | 617.5 | 356.8 | 343.0 | 432.5 | ok |
| Skugrić Gornji | Modriča | relation/14590945 | has_boundary | 21.4784 | 31791 | 97.3 | 361.4 | 174.9 | 144.8 | 264.1 | ok |
| Tarevci | Modriča | relation/14590864 | has_boundary | 10.9398 | 16211 | 98.0 | 271.0 | 156.2 | 153.4 | 173.0 | ok |
| Tolisa | Modriča | relation/14590914 | has_boundary | 15.2329 | 22532 | 139.0 | 493.3 | 272.6 | 264.3 | 354.2 | ok |
| Vranjak | Modriča | relation/14590876 | has_boundary | 33.5626 | 49669 | 105.5 | 620.4 | 261.7 | 243.7 | 514.9 | ok |
| Čardak | Modriča | relation/14590940 | has_boundary | 9.6533 | 14312 | 94.2 | 115.5 | 100.1 | 99.6 | 21.2 | ok |
| Batkuša | Šamac | relation/14578428 | has_boundary | 11.8380 | 17548 | 84.4 | 113.5 | 89.1 | 87.8 | 29.0 | ok |
| Brvnik | Šamac | relation/14578443 | has_boundary | 8.5274 | 12639 | 83.6 | 112.6 | 87.3 | 86.1 | 29.0 | ok |
| Crkvina | Šamac | relation/14579029 | has_boundary | 8.1314 | 12060 | 82.5 | 102.7 | 90.1 | 90.5 | 20.2 | ok |
| Donja Slatina | Šamac | relation/14578386 | has_boundary | 8.4627 | 12529 | 86.2 | 119.4 | 90.7 | 89.5 | 33.2 | ok |
| Donji Hasić | Šamac | relation/14578973 | has_boundary | 2.9472 | 4369 | 85.5 | 103.8 | 88.8 | 88.0 | 18.3 | ok |
| Gajevi | Šamac | relation/13426744 | has_boundary | 5.4096 | 7995 | 89.3 | 123.3 | 95.6 | 95.0 | 34.0 | ok |
| Gornja Slatina | Šamac | relation/14578378 | has_boundary | 22.1217 | 32793 | 86.2 | 117.1 | 92.9 | 91.5 | 30.8 | ok |
| Gornji Hasić | Šamac | — | missing_boundary | — | — | — | — | — | — | — | — |
| Grebnice | Šamac | relation/14578458 | has_boundary | 5.4893 | 8139 | 80.6 | 107.6 | 86.3 | 85.7 | 27.0 | ok |
| Kornica | Šamac | relation/13426742 | has_boundary | 7.3582 | 10903 | 91.7 | 108.2 | 98.2 | 97.3 | 16.4 | ok |
| Kruškovo Polje | Šamac | — | missing_boundary | — | — | — | — | — | — | — | — |
| Lugovi | Šamac | relation/5969840 | has_boundary | 0.3601 | 534 | 89.3 | 93.2 | 90.2 | 90.1 | 3.9 | ok |
| Njegoševo | Šamac | — | missing_boundary | — | — | — | — | — | — | — | — |
| Novo Selo | Šamac | relation/13501029 | has_boundary | 2.2293 | 3299 | 86.0 | 106.2 | 90.5 | 90.9 | 20.2 | ok |
| Obudovac | Šamac | relation/14578396 | has_boundary | 31.6510 | 46905 | 83.0 | 118.8 | 89.9 | 89.2 | 35.8 | ok |
| Pisari | Šamac | relation/13426748 | has_boundary | 2.7693 | 4105 | 82.0 | 97.0 | 87.9 | 88.2 | 15.0 | ok |
| Srednja Slatina | Šamac | relation/13426743 | has_boundary | 10.8096 | 16006 | 87.2 | 115.3 | 93.1 | 92.6 | 28.1 | ok |
| Tišina | Šamac | — | missing_boundary | — | — | — | — | — | — | — | — |
| Zasavica | Šamac | relation/14579023 | has_boundary | 2.6491 | 3928 | 83.5 | 104.6 | 89.4 | 89.8 | 21.1 | ok |
| Šamac | Šamac | relation/13426749 | has_boundary | 5.6758 | 8426 | 80.0 | 97.2 | 86.4 | 86.8 | 17.2 | ok |
| Škarić | Šamac | — | missing_boundary | — | — | — | — | — | — | — | — |

Elevations are **metres according to Copernicus DEM GLO-30 inside the OSM polygon**, not official surveyed heights.

## Data-quality warnings

- OSM polygons can be larger (Koprivna 39 km², Vranjak 34 km²) or much smaller (Lugovi 0.36 km²) than a cadastral naselje.
- Lugovi’s OSM relation looks like a small inhabited core, not a full settlement territory.
- Šamac naselje uses a manually reviewed town-scale polygon that OSM tags with municipality Wikidata.
- Same-name FBiH/RS pairs were not fuzzy-merged; unmatched is preferred over a wrong polygon.

## Commands

```bash
php artisan atlas:import-boundaries --pilot
php artisan atlas:import-boundaries --municipality=modrica --dry-run
php artisan atlas:export-terrain-jobs --pilot
python tools/atlas/download_dem.py
python tools/atlas/process_terrain.py --jobs storage/app/private/atlas/terrain/jobs-pilot.json --dem-dir tools/atlas/dem --output storage/app/private/atlas/terrain/stats-pilot.json
php artisan atlas:import-terrain --pilot
```

Second boundary import: 37 unchanged. Second terrain import: 37 unchanged.

## Public API

Settlement detail may include `geography` with `area_km2`, `geographic_centroid`, `terrain` (omit when absent). Population payloads unchanged. No filesystem paths.

## Frontend

Settlement page section **Geografija i teren** only when data exists. Headline elevation is **Prosječna nadmorska visina prema DEM-u**, not “Nadmorska visina”. Units km² / m / °. Elevation 0 would display as `0,0 m`.
