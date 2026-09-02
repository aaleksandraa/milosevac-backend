# M10 — Land cover + surface analytics

Branch: `feature/bih-settlement-atlas`

## Scope

Pilot Modriča + Šamac only. Composition is clipped to M4 OSM `admin_level=9` boundaries (37/45). Settlements without a polygon remain unavailable (no buffers, averages, or fabricated geometry).

## Sources actually used

Retrieved **2026-09-02** via EEA DISCOMAP (regional clip once — not per settlement).

| Product | Provider | Year / version | Resolution | CRS | DOI / catalogue | SHA-256 (file) |
|---|---|---|---|---|---|---|
| CORINE Land Cover 2018 vector (`CLC2018_LAEA`) | EEA / CLMS | 2018 / v2020_20u1 | vector (MMU 25 ha, min width 100 m) | EPSG:3035 | [10.2909/71c95a07-e296-44fc-b22b-415f42acfdf0](https://doi.org/10.2909/71c95a07-e296-44fc-b22b-415f42acfdf0) | `7e31e1e10c45f1fb…` (`clc2018_pilot.geojson`) |
| HRL Tree Cover Density 2018 | EEA / CLMS | 2018 | 10 m | EPSG:3035 export | [10.2909/e677441e-fb94-431c-b4f9-304f10e4dfd8](https://doi.org/10.2909/e677441e-fb94-431c-b4f9-304f10e4dfd8) | `fff9caa3f112bfe9…` |
| HRL Imperviousness Density 2018 | EEA / CLMS | 2018 | 10 m | EPSG:3035 export | [10.2909/3bf542bd-eebd-4d73-b53c-a0243f2ed862](https://doi.org/10.2909/3bf542bd-eebd-4d73-b53c-a0243f2ed862) | `331ef6dee480384c…` |
| HRL Water and Wetness 2018 | EEA / CLMS | 2018 | 10 m | EPSG:3035 export | [catalogue 0dbafef2-…](https://sdi.eea.europa.eu/catalogue/srv/api/records/0dbafef2-b481-4993-8d7e-63ad74934559) | `1a66cda06116efb8…` |
| HRL Forest Type 2018 | EEA / CLMS | 2018 | 10 m | EPSG:3035 export | [catalogue 59b0620c-…](https://sdi.eea.europa.eu/catalogue/srv/api/records/59b0620c-7bb4-4c82-b3ce-f16715573137) | `9a790671000a814a…` |

Full hashes and bbox live in `tools/atlas/landcover/source_manifest.json`. Large GeoTIFF/GeoJSON caches are gitignored.

### Semantics kept separate

- **CLC forest (311–313)** ≠ HRL tree-cover density ≠ HRL Forest Type mask
- **CLC artificial (1xx)** ≠ HRL imperviousness density
- **WAW temporary wetness** is not a river, flood zone, or catchment

Built-up binary HRL was inspected in catalogue but **not** imported as a duplicate of IMD for M10.

## Processing

- Algorithm: `m10.1`
- CLC: **vector intersection in EPSG:3035** (areas in m²)
- HRL: **fractional pixel-area intersection** in EPSG:3035 (not all-touched; not pixel-centre)
- Nodata (typically 255; WAW also 254) never treated as water/forest/artificial/zero density
- Valid coverage &lt; 95% → `high_nodata`; &lt; 50% → `insufficient_coverage` (not public); share-sum fail &gt; 2 pp → reject

## Public atlas groups

Central mapping in `tools/atlas/landcover/clc_nomenclature.py` and `App\Support\Atlas\LandCover\ClcNomenclature` (not frontend):

`artificial_surfaces`, `agricultural`, `forest` (311–313 only), `shrub_herbaceous_or_seminatural`, `open_or_sparse`, `wetlands`, `water`

## Data model

- `settlement_surface_profiles` — one row per settlement × product (`clc2018`, `hrl_tcd_2018`, `hrl_imd_2018`, `hrl_waw_2018`, `hrl_fty_2018`)
- `settlement_land_cover_classes` — CLC (and classified HRL) class areas + shares

## Commands

```bash
php artisan atlas:export-landcover-jobs --pilot
python tools/atlas/landcover/fetch_landcover.py --pilot
python -m landcover.process_landcover --jobs storage/app/private/atlas/landcover/jobs-pilot.json --cache-dir tools/atlas/cache/landcover --output storage/app/private/atlas/landcover/landcover-pilot.json
php artisan atlas:import-landcover --pilot --from-file=storage/app/private/atlas/landcover/landcover-pilot.json
php artisan atlas:report-landcover --pilot
```

Second identical import: **0 inserted, 0 updated, 185 unchanged**.

## Pilot result

- **37/37** boundary settlements with usable CLC + HRL profiles (`ok`)
- **8** settlements without M4 polygon: land cover omitted
- Ranking denominators (pilot): eligible **37**, missing **8**, universe **45** for all M10 metrics

### Miloševac (actual)

| Metric | Value |
|---|---|
| Agricultural (CLC) | 70.0% |
| Forest (CLC 311–313) | 16.2% |
| Artificial (CLC) | 6.6% |
| Semi-natural | 5.6% |
| Water (CLC) | 1.6% |
| Dominant class | 211 Neirigirane oranice |
| Tree cover mean (HRL TCD) | 26.3% |
| Imperviousness mean (HRL IMD) | 1.3% |
| Water/wetness (HRL WAW) | 5.1% |

### Outlier QA (not “fixed”)

| Settlement | Notable | Note |
|---|---|---|
| Modriča | artificial 45.0%, IMD 15.7% | Town core |
| Šamac | artificial 39.6%, water 11.1%, IMD 14.8% | Town + water |
| Lugovi | artificial 96.9%, area 0.36 km² | Tiny M4 polygon — boundary caveat |
| Koprivna / Vranjak / Jasenica | forest-dominated CLC | Forest % vs TCD differ by design |

Pilot ranges (CLC): agricultural 3.1–100%, forest 0–71.8%, artificial 0–96.9%, water 0–53.7%. TCD 0.9–72.1%, IMD 0–18.3%.

## API / map / M9

- Settlement detail: `land_cover` only when usable
- Map GeoJSON compact properties only (`landcover_*_pct`, `tree_cover_mean_pct`, `imperviousness_mean_pct`)
- Map payload ~397.8 KB → ~403.3 KB (+~5.5 KB)
- Rankable metrics: `landcover_agricultural_pct`, `landcover_forest_pct`, `landcover_artificial_pct`, `landcover_water_pct`, `tree_cover_mean_pct`, `imperviousness_mean_pct`
- Compare differences for share metrics use **percentage points** (`percentage_points`), not relative %

## Public wording

Always: “u okviru trenutno dostupne atlas granice naselja” and “prema podacima za referentnu 2018. godinu”. No fertility, timber, urbanization, or protected-area claims.

## National scaling

Download BiH/regional product once → process many polygons. Do not request Copernicus once per settlement.

## Not started (M11+)

Rivers/networks, flood risk, roads, travel time, protected areas, soil/productivity, national import.
