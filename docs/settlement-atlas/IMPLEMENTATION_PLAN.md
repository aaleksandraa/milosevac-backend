# Implementation plan — BiH Settlement Atlas

Branch: `feature/bih-settlement-atlas`

| ID | Status | Scope |
|---|---|---|
| M0 | Done | Audit and policy docs |
| M1 | Complete | Core schema |
| M2 | Complete | Pilot Modriča + Šamac settlements |
| **M3** | **Complete** | Public `/api/geo` + crawler/sitemap |
| **M4** | **Complete** | Settlement boundaries + DEM terrain stats (37/45) |

See [M3_PUBLIC_ATLAS.md](M3_PUBLIC_ATLAS.md).

## M4 result

- OSM admin_level=9 polygons (ODbL) for 37/45 pilot settlements; 8 correctly left unmatched
- GeoJSON + bbox storage (production MySQL spatial types unverified)
- Copernicus DEM GLO-30 offline terrain stats (algorithm `m4.1`)
- Public `geography` API block
- See [M4_BOUNDARIES_TERRAIN.md](M4_BOUNDARIES_TERRAIN.md)

M5 was not started.
