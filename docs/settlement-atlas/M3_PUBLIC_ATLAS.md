# M3 — Public atlas API and settlement pages

**Status: implemented.** Backend branch `feature/bih-settlement-atlas`. M4 was not started.

## Public routes (frontend SPA)

| Route | Page |
|---|---|
| `/mjesta` | Pilot atlas index |
| `/mjesta/{municipality}` | Municipality (e.g. `/mjesta/modrica`) |
| `/mjesta/{municipality}/{settlement}` | Settlement (e.g. `/mjesta/modrica/milosevac`) |

Settlement slugs are **not** globally unique. `/omilosevcu` is unchanged.

## API endpoints

Read-only JSON. Laravel API Resources. No `authority_rank` in public payloads.

| Method | Path |
|---|---|
| GET | `/api/geo/municipalities` |
| GET | `/api/geo/municipalities/{municipalitySlug}` |
| GET | `/api/geo/municipalities/{municipalitySlug}/settlements` |
| GET | `/api/geo/municipalities/{municipalitySlug}/settlements/{settlementSlug}` |
| GET | `/api/geo/settlements/{id}` (numeric id only) |

404 for unknown municipality and for settlement/municipality mismatch. Same slug in two municipalities does not cross-resolve.

Index lists only municipalities that already have imported settlements (Modriča, Šamac). Brčko exists in M1 but is omitted from the public index until it has settlements.

Municipality `population.total` is the **sum of imported 2013 settlement rows** using the highest-authority source per settlement, labelled as census 2013 — not a separately stored municipal total.

Geographic `points` are omitted when null. Empty future modules (terrain, climate, POIs) are not rendered.

## Indexability

A settlement is `indexable` when it has:

- a municipality
- sourced identity (`statistical_code` or `official_code`, plus name/slug)
- at least one population row (`0` is valid sourced data)

Indexable pages are `index,follow` and included in `sitemap-settlements.xml`. Thin/unready pages are omitted from that sitemap and would receive `noindex, follow` if opened.

## SEO / crawlers

Laravel `FrontendRedirectController` + `Seo.php`:

- browsers redirect to the SPA
- social/search crawlers receive title, description, canonical, WebPage / AdministrativeArea / Place JSON-LD, BreadcrumbList
- no `GeoCoordinates` when points are absent

Sitemap: `sitemap-settlements.xml` (index, two municipalities, 45 settlements). Linked from `sitemap.xml` and `robots.txt`.

## Source UI

Public methodology states that M2 data is a **normalized extract** of 2013 census settlement tables, retrieved 2026-09-01 via statistika.ba, **not** a direct BHAS file dump.

## Known missing modules (later milestones)

Maps, boundaries, DEM/elevation, climate, OSM POIs, sports/religious objects, compare, rankings, national import. M3 hides those sections entirely.

## Commands / tests

`composer test`. Feature tests: `GeoAtlasApiTest`, `GeoAtlasSeoTest`.
