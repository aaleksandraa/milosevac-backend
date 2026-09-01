# M6 — Settlement POI catalog + OSM import pipeline

**Status: implemented** on `feature/bih-settlement-atlas`. M7 (climate / ERA5 / landcover / rivers / rankings / compare / national import / SEO POI pages) was **not** started.

Pilot: Modriča + Šamac, 45 settlements, 37 with M4 OSM `admin_level=9` boundaries.

## Architecture

Atlas POIs live in `geo_place_types` + `geo_places`. The outage/news `places` table is untouched.

Visitor/API requests never call Overpass. Pipeline:

Overpass (operator) → `storage/app/private/atlas/osm/` → classifier → settlement assignment → Laravel `geo_places` → public `/api/geo` → MapLibre.

No Google POIs, Google coordinates, or Google reviews are imported.

## Schema

`geo_place_types`: `family` (string, not a DB ENUM), unique `code`, labels, `icon_key`, `default_visible`, `sort_order`, `metadata`. Seeded registry: **57** types (education, health, sport, religion, culture, historic, tourism, government, transport, nature). A new OSM subtype is a new row, not a migration.

`geo_places`: `place_type_id`, nullable `settlement_id`, required `municipality_id`, `source_id`, nullable `name`/`slug`/`name_cyrillic`, `DECIMAL(10,7)` `lat`/`lng` (production MySQL spatial types remain unverified), OSM identity `osm_type` + `unsignedBigInteger osm_id`, `osm_version`/`osm_timestamp`, contact fields, `verification_status`, `classification_status`, `assignment_status`, `point_algorithm`, `source_properties` JSON, `metadata` JSON, `is_active`, `source_deleted_at`.

Unique identity: `(source_id, osm_type, osm_id)`. Names and coordinates are not identity.

## Classification

`config/atlas_poi.php` + `OsmPoiClassifier`. First matching rule wins. Combinations are explicit:

- `leisure=pitch` + `sport=soccer|football` → `football_pitch` (before generic pitch)
- `amenity=place_of_worship` + `religion=christian|muslim` does **not** become church/mosque unless `building` / precise amenity supports it
- Names are never used as classification heuristics

Original OSM tags needed for later reclassification are stored in `source_properties` (whitelist, not every tag as a column).

Classification status: `exact_osm_mapping` / `inferred_osm_mapping`. OSM imports use `verification_status=osm_imported`, never “officially verified”.

## Location

Display coordinate is a **representative point**, not a building entrance.

- OSM node → node coordinates
- Closed way / relation outer ring → geographic centroid if inside, otherwise deterministic bbox scan (point-on-surface)
- Open way → vertex midpoint
- Overpass `center` only if geometry is absent (`overpass_center`)

## Settlement assignment

Point-in-polygon against stored M4 GeoJSON (PHP, SQLite-safe). Not name matching.

An assignment `boundary_match` means: assigned using the currently available atlas boundary (OSM admin_level=9 approximation), **not** official statistical/cadastral membership.

States: `boundary_match`, `municipality_only`, `ambiguous`, `unassigned`. Eight settlements without M4 polygons never receive fabricated assignments.

Municipality is taken from the union of M4 bboxes (padded), not `addr:city`. Points outside the BiH envelope or municipality envelope are skipped (cross-border protection; M4 relation 8758298 lesson).

## Overpass

Commands:

```
php artisan atlas:fetch-osm-pois --pilot
php artisan atlas:import-pois --pilot
php artisan atlas:import-pois --pilot --dry-run
php artisan atlas:import-pois --municipality=modrica --from-file=...
```

Config: `ATLAS_OVERPASS_URL`, sequential municipality queries, timeout, finite retry/backoff (`ATLAS_OVERPASS_RETRY_SLEEP_MS`), endpoint fallback. Cache is outside Git.

Pilot retrieval (this implementation):

| Municipality | Endpoint | Retrieved | OSM base timestamp | Elements | SHA-256 |
|---|---|---|---|---|---|
| Modriča | `https://overpass-api.de/api/interpreter` | 2026-09-01T23:48:54Z | 2026-09-01T23:46:58Z | 490 | `c090afcb…ce3600` |
| Šamac | `https://overpass-api.de/api/interpreter` | 2026-09-01T23:49:00Z | 2026-09-01T23:46:58Z | 434 | `0adb9f90…d71bd32` |

## Import counts (persisted unique OSM identities)

Raw OSM objects considered: **924** (490+434, including bbox overlap).

Classified: **923**. Failed geometry: **1** (`relation/16251402`, no representative point). Unclassified after Overpass filter: **0**.

Persisted active `geo_places`: **761** (69 OSM objects appeared in both municipality downloads and were not duplicated).

| | Count |
|---|---|
| Modriča | 416 |
| Šamac | 345 |
| Settlement-assigned (`boundary_match`) | 325 |
| Municipality-only / no polygon match | 436 |
| Unnamed (`name` null) | 440 |

By family (classified Overpass objects, including skipped/overlap; persisted unique is 761):

education 47, health 24, sport 204, religion 136, culture 3, historic 162, tourism 15, government 53, transport 143, nature 43.

Second identical import: **0 inserted, 0 updated, 830 unchanged** (830 = persistable classified objects including the 69 overlapping identities counted once per file).

## Deletion policy

Missing from one Overpass response does **not** deactivate or hard-delete a POI. Causes include query changes, truncated downloads, and OSM edits. `source_deleted_at` exists for a future curated full snapshot; M6 does not auto-deactivate.

## Duplicates

OSM node/way/relation identity is kept separate even when names match. M6 does not merge building polygons + POI nodes + site relations. QA flagged possible real-world duplicates (two “Apoteka” nodes, two “Groblje” ways, two “NK Vida Gradačac” ways, two border-crossing ways) — left unmerged.

## Public API

- `GET /api/geo/places?municipality=&settlement=&family=&type=`
- `GET /api/geo/municipalities/{m}/settlements/{s}/places`
- `GET /api/geo/map/places` GeoJSON FeatureCollection (ETag / Last-Modified)

Unknown municipality/settlement combinations 404. Unknown family/type 422. `source_properties` is not public.

Pilot map payload: **353 490 bytes**, 761 Point features. Combined with M5 settlement GeoJSON (~390 KB) this is still fine as GeoJSON. **Vector tiles / PMTiles are not warranted.**

## Licensing / ODbL

`geo_places` for this milestone is a derived database from OpenStreetMap (ODbL). The project:

- keeps OSM type/id/version/timestamp
- stores source tags in `source_properties`
- attributes OSM on the map, methodology panel, and this document
- does not make a legal determination beyond “OSM data is used under ODbL and must be attributed; a public extract of `geo_places` is OSM-derived”

Do not treat imported counts as an official inventory. OSM is community-maintained and incomplete.

## Known gaps

- 79 `place_of_worship_other`: OSM often lacks `building=church|mosque`
- 45 `school_other`: missing ISCED / school subtype tags
- 113 cemeteries and 92 parking lots dominate density; they are catalog objects, not a claim of completeness
- 436 municipality-only: 8 settlements have no M4 polygon; bbox padding also captures objects outside admin_level=9 rings
- Health/sport/religion records are mapped OSM objects, not operating status, specialty, or denomination beyond tags
- National scale would need a national boundary store, rate-limited regional Overpass, and likely vector tiles only after payload grows well beyond the current ~345 KB

See frontend `docs/settlement-atlas/M6_POI_CATALOG.md` for map UI.
