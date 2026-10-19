# M11 — Hydrography, roads, accessibility and proximity

Implementation and actual pilot import are available. **Acceptance QA passed on 2026-10-19:** desktop/mobile page QA, road/hydro layers against the basemap and desktop render timing. Only render time on a real mobile device is unmeasured. M12 has not started. No merge or push.

## Scope and source

45 canonical settlements in Modriča and Šamac; 37 usable M4 polygons, 37 road profiles, 37 hydro profiles and 37 settlements with proximity results (370 metric rows). Eight missing boundaries/origins remain missing, never zero. No national import.

The operator downloaded one regional Overpass query with a 0.1° halo around the combined M4 bounding box. Full way/relation geometries were requested with `out meta geom`. This is a community-mapped baseline, not an official inventory.

- Endpoint: `https://overpass.kumi.systems/api/interpreter`
- Retrieval: **2026-09-05T15:54:58.871995+00:00**
- OSM data timestamp reported by endpoint: **2026-05-06T03:25:00Z**. Do not represent retrieval date as data freshness.
- Raw SHA-256: `c8a473039ffa8dd84c2831c8344aedbfbfd031f185cdcf24e5d6cc11b20b97cc`
- Query SHA-256: `e5c97dbdb110f50666ef0146d948869b286a46bc85357f9a133affdd6856a70d`
- Raw size: **30,296,916 bytes**; 19,385 highway-tagged objects and 1,204 hydro objects.
- Normalized: **19,334 roads + 1,200 hydro objects**; 236 named and 964 unnamed hydro objects. Area highways are excluded from line inventory; water-relation members are not counted a second time. No geometry rejects in this acquisition.
- **3,777 settlement/object relationships**. These are not new OSM identities and are not individual connected-part counts.
- Pilot clipped totals: **1,186.366778 km roads**, **129.983349 km waterways**. Shared-boundary lengths may contribute to both neighboring polygons, so these totals are settlement sums, not a deduplicated union inventory.

Exact query, bbox, licensing, counts, class inventory and QA values are in [m11/source-manifest.json](m11/source-manifest.json), [m11/network-summary.json](m11/network-summary.json) and [m11/pilot-qa.json](m11/pilot-qa.json). Raw source, job exports and normalized result artifacts remain in the private, gitignored `storage/app/private/atlas/network/` directory.

[OpenStreetMap attribution and licensing](https://www.openstreetmap.org/copyright) apply; derived databases retain [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/). The operator must preserve the raw cache and manifest when distributing derived data. [Overpass geometry documentation](https://dev.overpass-api.de/overpass-doc/en/full_data/osm_types.html) describes `out geom` and relation members. The first endpoint (`overpass-api.de`) failed TLS certificate verification; verification was not disabled.

## Geometry and classifications

Algorithm `m11.2` (adds the cross-border target flag to `m11.1`), Python Shapely + pyproj. EPSG:4326 inputs are transformed to **EPSG:3035, always_xy=True** before intersection, length, area and point-to-line distance. No degree-based measurements. The normalized artifact retains original geometry and relevant source tags, OSM type/id/version/timestamp. Duplicate same-version conflicting identities fail; the newest OSM version wins deterministically otherwise.

Road classes are centralized in `network/core.py`: motorway, trunk, primary, secondary, tertiary, residential, unclassified, service, track, path, pedestrian, cycleway, footway, living_street, other. `_link` classes join their parent controlled class; the original `highway` remains in `source_properties`. Public groups: major (motorway/trunk/primary/secondary), local (tertiary/unclassified), residential (including living_street), service, track, paths, other. Hierarchy does not imply quality, access permission or maintenance.

Linear hydro: river, stream, canal, drain, ditch. Areal: riverbank, lake, reservoir, pond, basin, other_water. Springs remain M6 POIs. Water multipolygons are assembled from outer/inner members; source ways belonging to a water relation are excluded from duplicate areal representation. Water polygon intersections are unioned before area calculation to avoid overlapping water-area double counting.

For each source object: `geometry ∩ boundary`, measured in metres/m², then divided by 1000/1e6. Length is assigned only for linear input; polygon perimeter is never waterway length. Profiles contain length by road class/group, known surface buckets, water type, named/unnamed waterway length, water area and named relationships. Unknown surface stays unknown; no paved-share percentage is published.

Relationships: `touches_boundary` first using the topological touches predicate, `inside` when the boundary covers the object, otherwise intersecting geometry is `crosses`. Boundary-coincident line portions are included in closed-polygon intersection length and explicitly identified as boundary contact. A crossing full OSM way contributes only the clipped portion. Do not describe a touch as crossing. Named water features list preserves source spelling and type.

Road density = clipped road km / stored M4 area km². This is mapped OSM network density, not official infrastructure density. M10 CLC/WAW water shares remain entirely separate from M11 geometric lengths/areas; there is no water or flood score.

## Origins, targets and distances

1. M4 label point only when manually or officially verified and both coordinates exist.
2. Existing M4 geographic centroid fallback. No invented inhabited point.
3. Missing point means unavailable. The actual pilot uses 37 geographic centroids; eight settlements have no usable origin.

Own municipality centre is the canonical settlement whose slug equals its municipality slug, using exactly the same reference-point hierarchy. This measures to the reference point of Modriča/Šamac town, not a town hall. Their self-distances calculate zero naturally. No external city list, national nearest-city claim or hard-coded PHP coordinates.

M6 targets come from active `geo_places` with valid coordinates, regardless of settlement assignment. The existing regional target inventory has **761 POIs**, including neighboring/cross-border objects. Nearest means **nearest in the imported dataset**, not guaranteed nearest in reality or easiest to reach. Assignment is not used as a geographic filter. No M6 redownload.

Cross-border targets keep their value and are flagged. Each hit stores `target_in_country`: `true`/`false` when the target point (or, for roads/water, the nearest point on the geometry) is covered by the OSM BiH national boundary, `null` without a hit. The boundary is relation **2528142** (`admin_level=2`, `ISO3166-1=BA`, version 655), fetched once by `fetch_country.py` from `overpass-api.de` on 2026-10-19 (OSM timestamp 2026-10-19T09:36:02Z, 1,506,500 bytes, SHA-256 `321f53e2…3df078`), assembled from its outer/inner member ways and tested in EPSG:3035. Its manifest is stored in the network source metadata as `country_boundary`; import requires it. M6 municipality assignment is not used for this, because it is bounding-box based and can place Slavonski Šamac objects in Šamac municipality. The API exposes `target_in_country`; the UI adds "Cilj se nalazi izvan Bosne i Hercegovine" without changing the distance.

Controlled targets: pharmacy, hospital, health (hospital/clinic/doctors), school (primary/secondary/unspecified-level school), fuel station, bus station, railway station, municipality centre, major road, named linear watercourse. Kindergarten/university are excluded from school. Unspecified-level schools are not presented as confirmed primary/secondary schools. No emergency availability, opening-hours, response-time or road-condition claim.

- Points: WGS84 ellipsoidal geodesic with `pyproj.Geod.inv`, stored in metres.
- Nearest road/water geometry: EPSG:3035 point-to-geometry distance. A road anywhere inside a settlement does **not** make distance from its reference point zero; the point must lie on the geometry for zero.
- Deterministic ties: distance to 1 µm then stable target identity. Imported distances round to millimetres to avoid MySQL/PDO floating representation churn; display precision is much coarser.
- **Routing not implemented.** `distance_route_m` and `travel_time_s` are always null, separately typed/stored from `distance_straight_m`; no speed-based inference. An offline engine is deferred because M11's geographical comparisons do not justify a new routing service. Source tags and identity support later graph construction, but no routable graph is claimed.

Dependency SHA-256 includes pilot origins, selected boundary geometry/area and the active M6 target snapshot (coordinates, types, identity and timestamps). Import rejects stale dependencies. Public proximity suppresses stale snapshots/origins; profiles suppress stale/replaced boundaries or network source versions. M9 cache identity includes profile content and current dependency fingerprint, including changes within the same timestamp second. All freshness work reads DB only. This conservative pilot-wide invalidation is safe but should become partitioned for national scale.

## Persistence and API

- `geo_transport_segments`, `geo_hydro_features`: separate source geometry tables, unique `(source_id, osm_type, osm_id)`. Tags including bridge/tunnel/oneway/surface/ref/name remain JSON, not sparse columns or POIs.
- `settlement_transport_profiles`, `settlement_hydro_profiles`: one current row per settlement, boundary/source/version/algorithm/quality/dependency, compact JSON summary/metadata; no duplicated source geometry.
- `settlement_proximities`: one current row per settlement/metric, origin coordinates/type, target, explicit method, distinct distance fields, quality/version/dependency. Target M6 source is retained independently of processing/network source.
- `settlement_network_relations`: stable source identity plus settlement, relation and clipped measurement. Identity is preserved on repeat import.
- Import is transactional and SQLite compatible. Exact repeat: **0 inserted, 0 updated, 20,978 unchanged** source/profile rows; 3,777 relationship rows retain identity. Re-importing the `m11.2` artifact over `m11.1` updated 444 rows (370 proximities plus 74 profiles whose algorithm version changed); the repeat after that was again 0 / 0 / 20,978.
- Detail endpoints add optional `transport`, `hydrography`, `proximity`; source attribution and available source timestamp are exposed. Missing/no-target proximity is omitted, zero retained. API hydro zero profiles remain useful to rankings even when the UI hides empty sections.
- `GET /api/geo/map/roads?municipality=modrica|samac`: major roads only.
- `GET /api/geo/map/hydrography?municipality=modrica|samac`: hydro lines/polygons.
- Missing/invalid municipality is 422. Responses use full source geometries intersecting settlements in the requested municipality, not clipped identities. Network geometry is absent from default settlement map payload.
- M9 rank/compare: `mapped_road_length_km`, `mapped_road_density_km_per_km2`, `major_road_length_km`, `waterway_length_km`, `distance_to_municipality_centre_km`, `distance_to_nearest_major_road_km`. Each denominator is **37 eligible / 8 missing / 45 universe**. Compare section **Povezanost i vode**, km and km/km² differences, no relative percentage. Different origin/method types suppress direct comparisons and show a caveat. No “best connected” claim.

## Operator commands

Run from backend root (existing Python GIS requirements and Laravel dependencies):

```sh
php artisan migrate --force
php artisan atlas:export-network-jobs --pilot
python tools/atlas/network/fetch_network.py --jobs storage/app/private/atlas/network/jobs-pilot.json --output storage/app/private/atlas/network/osm-pilot.json --endpoint https://overpass.kumi.systems/api/interpreter
python tools/atlas/network/fetch_country.py --output storage/app/private/atlas/network/osm-country-ba.json
python tools/atlas/network/process_network.py --jobs storage/app/private/atlas/network/jobs-pilot.json --raw storage/app/private/atlas/network/osm-pilot.json --country storage/app/private/atlas/network/osm-country-ba.json --output storage/app/private/atlas/network/accessibility-pilot.json
php artisan atlas:import-accessibility --pilot --from-file=storage/app/private/atlas/network/accessibility-pilot.json
php artisan atlas:import-accessibility --pilot --from-file=storage/app/private/atlas/network/accessibility-pilot.json
php artisan atlas:report-accessibility --pilot
php artisan atlas:report-accessibility --pilot --json
python -m unittest discover -s tools/atlas/network -p test_network.py
python -m unittest discover -s tools/atlas -p 'test_*.py'
composer test
php vendor/bin/pint --dirty
```

Both fetch scripts refuse to overwrite an existing raw file. `fetch_country.py` uses a 90 s Overpass query timeout and retries 429/502/503/504 with exponential backoff, because busy public servers reject longer timeouts immediately. Reuse the checked raw cache for reproduction; use a new output path for a fresh acquisition. No visitor API calls Overpass, Nominatim, OSM API, OSRM, a geocoder or any remote GIS source.

## Actual settlement QA

| Measure | Miloševac | Modriča | Šamac |
|---|---:|---:|---:|
| Mapped road km | 48.043483 | 117.886525 | 51.975207 |
| Major km | 5.924019 | 10.475570 | 6.972929 |
| Local km | 9.631146 | 8.654346 | 4.402993 |
| Residential km | 16.196803 | 66.499162 | 21.699934 |
| Service km | 3.585801 | 19.050843 | 8.331633 |
| Track km | 12.467384 | 7.147545 | 8.239993 |
| Road density km/km² | 2.764583 | 9.048288 | 9.157336 |
| Waterway km | 0.458435 | 9.047485 | 7.066009 |
| Mapped water km² | 0.181795 | 0.548743 | 0.392659 |
| Own centre straight-line km | 8.601 | 0 | 0 |
| Major road straight-line km | 1.459 | 0.443 | 0.192 |

Miloševac: Bosna line `way/678605979`, relation `crosses`, only **458 m** attributed from the full way. Pharmacy **Expera Pharmacy 8.299 km**; unnamed hospital **8.604 km**; health target **Dom zdravlja Odžak 6.752 km**; unnamed school **5.460269 km**. All distances are from the M4 centroid and within the imported M6 candidate set. These surprising service distances warrant local inventory review, not manual replacement with invented values.

Modriča: named water features Bosna, Dusa (source spelling), Zapadni lateralni kanal. Residential road length dominates. Nearest school **Osnovna škola "Sveti Sava" 0.514705 km**.

Šamac: Bosna, Sava and Žandrak. The Sava/Bosna source lines intersect the polygon; clipped river length is 2.983650 km, not the full river. Geometric mismatch between an OSM boundary and later river centreline can create short inside/outside sections; `crosses` is a geometric predicate, not a border interpretation. Nearest school **JU Osnovna škola "Šamac" 0.600988 km**.

Additional API inspection: Koprivna 74.720201 km roads / 20.436857 km water; Jasenica 7.196689 km roads / zero mapped waterways; Lugovi 6.740280 km roads / density 18.717800 km/km² due to its very small M4 polygon. These values were not normalized toward expectations. Per-settlement full QA contains the remaining settlements and distances, including remote municipality-centre cases.

## Performance and national decision

| Optional layer | Features | JSON bytes | gzip bytes | local request ms |
|---|---:|---:|---:|---:|
| Modriča major roads | 69 | 64,564 | 18,205 | 291.0 |
| Šamac major roads | 51 | 46,250 | 13,254 | 189.0 |
| Modriča hydro | 76 | 204,171 | 61,487 | 216.6 |
| Šamac hydro | 80 | 200,269 | 61,127 | 213.7 |

Measurements are local Laravel development-server requests, not production/mobile benchmarks. Representative detail requests were 444–766 ms; cold M11 rankings 606–692 ms. Source artifact is 30.3 MB; normalized result approximately 14.93 MB / 3.16 MB gzip. Normalized DB: 20,534 source objects + 74 profiles + 370 proximities + 3,777 relationships = **24,755 rows**, excluding one source row. See machine-readable performance report. Full network is never initially sent to visitors.

Decision **B: municipality-scoped/lazy GeoJSON only**. Measured optional network payloads do not justify PMTiles now. Desktop browser render (2026-10-19, dev server, Šamac): roads 441 ms / 46,250 B, hydro 307 ms / 200,269 B, map idle about 2.3 s after zoom. Mobile-device render time is not measured.

For national scale: acquire a licensed, timestamped Geofabrik BiH/regional PBF once, normalize source identity once, index projected geometries spatially and intersect many settlements in batches. Replace the pilot's linear candidate scan and whole-pilot freshness hash with STRtree/batched processing and partitioned dependencies. Preserve original identities and include a sufficient cross-border halo. Measure payload again before adopting PMTiles/vector tiles. No thousands of per-municipality Overpass calls; no national settlement import or routing service in M11.

## Validation

Backend regression: **191 passed, 1 skipped** in the final regression run; M11 feature tests cover models, idempotency, zero/missing, API blocks, scoped map, M9, stale origin/POI dependencies, cross-border flag and import rejection (including artifacts without the country boundary or the flag). GIS: **20 network tests + 40 existing tests**, no live OSM in tests. Pint applied to changed PHP. Eight independent spherical distance sanity checks for Miloševac, Modriča, Šamac and Koprivna agree within 0.5% with the ellipsoidal calculation (the spherical values are QA only, never the public measurement).

The real regional artifact exposed quadratic whole-artifact Laravel wildcard validation and exhausted the 512 MB PHP limit. Validation now handles the small header and individual network objects separately. The real import and repeat import were rerun successfully under the existing limit; targeted importer tests passed again.

Frontend: **55 tests pass**, lint zero errors with the existing button fast-refresh warning, production build passes. Additional `tsc --noEmit` reports existing errors in `useOutages.test.ts`, `atlas-map-engine.ts` and `atlas-ranking.ts`; the new network files introduce no remaining reported type error.

**Browser QA 2026-10-19** (local servers, Cursor embedded browser): settlement pages, compare, rankings and 390 px mobile layout pass; details in the frontend M11 document.

**Map layers:** the first QA pass showed a blank map. The cause was a frontend bug, not the browser: MapLibre 6 loads its worker from `./maplibre-gl-worker.mjs` next to its own module, a file that does not exist once Vite relocates the bundle, so the worker received the HTML page (`SyntaxError: Unexpected token '<'`) and no map ever loaded. The frontend now passes Vite's `?worker&url` asset to `setWorkerUrl`. After the fix, Šamac's Sava/Bosna water layers and major roads line up with the basemap, and in Miloševac the Bosna follows the western boundary, consistent with 458 m clipped. The production build emits the worker as a hashed asset, and it starts cleanly.

**Data-quality warnings from QA:** M6 contains a single `railway_station`, Slavonski Šamac, which the national-boundary check places 739 m outside BiH. Every pilot settlement's nearest station is therefore cross-border (37 flagged rows; Jasenica 29.0 km). The only other flagged target is Ordinacija Slavonski Šamac (nearest health target for one settlement, 555 m outside BiH). Šamac town's nearest pharmacy "Ljekarna Vukić" (9.5 km) lies 1.2 km inside BiH, so it is not cross-border. Miloševac's nearest health target is Dom zdravlja Odžak because OSM has no closer mapped object. Values are correct for the imported dataset but should not be read as local service availability.

## Git and file inventory

Branch: `feature/bih-settlement-atlas`. Starting backend HEAD `99ae77a82f090f7dbcecc580107acde1e3d43feb`; starting frontend HEAD `8a1c18ad6e7ad12448b79083a2950d1eebba2bcd`. Local implementation commits only; no merge/push. Exact backend changed-file inventory: [m11/changed-files.txt](m11/changed-files.txt). Frontend maintains `docs/settlement-atlas/M11_CHANGED_FILES.txt`. Final commit hashes are reported in the handoff rather than embedded recursively in their own commit.
