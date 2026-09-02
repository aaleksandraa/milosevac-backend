# M8 — Historical census population + demographic change

**Status: implemented** on `feature/bih-settlement-atlas`. **M9 was not started.**

Pilot: current Modriča + Šamac municipalities, 45 canonical atlas settlements. Years: **1971, 1981, 1991, 2013**. 2013 remains the M2 official series. M8 does **not** estimate current (2026) population, interpolate, or extrapolate.

## Critical identity rule

A settlement that belongs to Modriča or Šamac **today** did not necessarily belong to the same municipality in 1971/1981/1991. Historical municipality identity and current atlas municipality identity are stored separately.

- Historical municipality is **source context** (`settlement_census_mappings.historical_municipality_name`).
- Current municipality comes from `geo_settlements.municipality_id`.
- Official historical municipality totals (Bilten 265) are **source QA only**. They are never shown as “Modriča 1991 vs Modriča 2013”.

Post-Dayton changes affecting the pilot:

| Historical 1991 municipality | Left current atlas (now FBiH) | Joined current atlas from elsewhere |
|---|---|---|
| Modriča (21 naselja, 35 613 in 1991) | Jakeš, Modrički Lug, Pećnik → today Vukosavlje | Donji Skugrić, Gornje Krečane, Jasenica, Tolisa, Krčevljani from **Gradačac** |
| Bosanski Šamac (32 960 in 1991) | Bazik, Domaljevac, Prud → today Domaljevac-Šamac | Town rename **Bosanski Šamac → Šamac** |

New/unlisted 1971–1991 statistical settlements in the current atlas: **Borovo Polje**, **Lugovi**, **Njegoševo** (2013 = 0). No 1971/1981/1991 values.

## Official sources

### FZS Statistički bilten 265 (municipality QA)

| Field | Value |
|---|---|
| Title | *Popis stanovništva, domaćinstava/kućanstava, stanova i poljoprivrednih gazdinstava 1991. Stanovništvo. Uporedni podaci 1971, 1981. i 1991.* |
| Publisher | Federalni zavod za statistiku, Sarajevo, **april 1998.** |
| URL | https://fzs.ba/wp-content/uploads/2016/06/stanovnistvo-uporedni-podaci-1971_1981_-1991-bilten-265.pdf |
| SHA256 | `7B273266D5BA4F5180AC5412A96B76AC7C2EC49B040A4CF427CD40CC8865CFD9` |
| Retrieval | 2026-09-02 |
| Pages used | 20 (Bos. Šamac), 38 (Modriča) |
| Grain | **Municipality** age/sex tables, not naseljena mjesta |

Official municipality totals read from Bilten 265:

| Historical municipality | 1971 | 1981 | 1991 |
|---|---|---|---|
| Bosanski Šamac | 31 374 | 32 320 | 32 960 |
| Modriča | 31 622 | 34 541 | 35 613 |

Parsed settlement sums in `census-historical-pilot.json` match these totals exactly. Wikipedia’s Modriča 1981 total 32 871 is **wrong**.

### FZS 1991 mjesne zajednice (1991 settlement QA)

| Field | Value |
|---|---|
| URL | https://fzs.ba/wp-content/uploads/2016/06/nacion-po-mjesnim.pdf |
| SHA256 | `BC52CDB60F6576F8391E08C01219FC78EFD10BA9F60F125B0CF148454D29AF56` |
| Grain | **MZ, not naseljena mjesta** — not imported as settlements |

Used only to verify 1991 settlement totals (e.g. Miloševac MZ = 1735; Modriča 1–4 = 10 454).

### Settlement-level 1971/1981/1991 series

Bilten 265 is not settlement-level. Settlement totals are a machine-readable transcription of published 1991 comparative naselje tables (mashke.org compilation of the FZS/RZS lineage), **verified against Bilten 265 municipality totals and the 1991 MZ PDF**. Wikipedia/encyclopedia pages were QA cross-checks only and are **not** import sources. Ethnicity/nationality columns were not stored.

Households for 1971–1991 are **not imported** (no matching settlement-level household table with the same semantics). 2013 households stay on the M2 rows only.

## Reconciliation model

Table `settlement_census_mappings`:

- `settlement_id` nullable (unmatched / ambiguous / split without canonical target)
- `source_id`, `census_year`
- `historical_settlement_name` (source-era spelling, never overwrites `geo_settlements.name`)
- `historical_municipality_name` / optional codes
- `mapping_status`: `exact_identity`, `renamed`, `boundary_changed`, `split`, `merged`, `partial`, `ambiguous`, `unmatched`
- `mapping_method`: `checked_in_mapping` for the pilot
- `comparability`: `comparable`, `comparable_with_caveat`, `not_directly_comparable`, `unresolved`
- `notes`, `source_properties` JSON

Matching is **never** fuzzy-persisted. Priority: checked-in mapping fixture only. Fuzzy similarity is not used to write rows.

Checked-in artifacts:

- `database/imports/atlas/census-historical-pilot.json` — normalized historical rows (official values, no current-settlement rewrite)
- `database/imports/atlas/census-historical-pilot-mapping.json` — explicit reconciliation

Importer: `php artisan atlas:import-historical-population --pilot` (`m8.1`), also `--municipality=`, `--from-file=`, `--mapping-file=`, `--dry-run`. Idempotent: second run 0 inserted / 0 updated / N unchanged; unchanged population `updated_at` is not touched.

Population remains a time series on `settlement_population` (`settlement_id`, `census_year`, `source_id`). No `population_1971` columns on `geo_settlements`. Existing M2 2013 `census_settlements` rows are not duplicated or overwritten.

## Comparability and change analytics

Identity (same canonical settlement) ≠ geographic comparability.

Changes are calculated only when both endpoints exist and comparability is `comparable` or `comparable_with_caveat`:

`change = later - earlier`  
`percentage_change = ((later - earlier) / earlier) * 100` (null when earlier = 0)

Pairs: 1971→1981, 1981→1991, 1991→2013, 1971→2013. Trend labels: `growth` / `decline` / `unchanged`. No “dying village”. No causal language for 1991→2013 (war/displacement is not inferred).

Map mode **Promjena stanovništva 1991–2013 (%)** uses **only** `comparable` (not caveat). Missing/non-comparable polygons stay grey. Diverging scale centred on 0%. No historical density (current OSM area is not a 1991 boundary).

## Pilot coverage (design)

Importable historical identities (checked-in, not 45/45):

- Most current Modriča/Šamac settlements with exact identity in their **historical** municipality
- Rename: Bosanski Šamac → Šamac (`renamed`, source name preserved)
- Municipality-change: Gradačac → current Modriča (historical municipality remains Gradačac)
- Split/caveat: Gajevi (1991 from Gornja Slatina), Krčevljani (1991 from Tolisa), Babešnica 1981 spike, Gornja Slatina/Tolisa pre-split years
- Unmatched historical records that left the current atlas (Jakeš, Modrički Lug, Pećnik, Bazik, Domaljevac, Prud)
- Unresolved current settlements with no 1971–1991 naselje row (Borovo Polje, Lugovi, Njegoševo)

## QA figures (official / verified)

**Miloševac:** 1971 **1880**, 1981 **1825**, 1991 **1735**, 2013 **1366** (M2). Historical municipality Modriča.

**Modriča town** (not the municipality total 35 613): 1971 **7324**, 1981 **9630**, 1991 **10454**, 2013 **9419**.

**Šamac town** as source-name Bosanski Šamac: 1971 **4877**, 1981 **5605**, 1991 **6239**, 2013 Šamac **5133**.

## Commands

```
php artisan atlas:import-historical-population --pilot
php artisan atlas:import-historical-population --municipality=modrica
php artisan atlas:import-historical-population --pilot --dry-run
php artisan atlas:report-historical-population
php artisan atlas:report-historical-population --json
```

QA report lists current settlement × 1971/1981/1991/2013 identities, historical municipality, mapping method, comparability, notes.

## Public API

Settlement detail keeps `population` (latest, usually 2013) and `census` (newest year first). M8 adds:

- census fields: `comparability`, `mapping_status`, `historical_settlement_name`, `historical_municipality_name`, `reconciliation_note`
- `population_changes[]`

Map GeoJSON: `population_change_1991_2013_pct` when comparable.

SEO may mention available historical census years. Canonical URL stays the settlement page.

## Limitations / national scale

- Settlement-level OCR of Bilten 234 was not available from probed FZS URLs; transcription is verified against official municipality totals and 1991 MZ aggregations.
- No ethnicity/national composition UI (deferred).
- No reconstructed “official historical municipality population” on municipality pages.
- Future national import must keep `(source, year, historical municipality, historical settlement name)` as the mapping key — never assume `(current municipality, current slug)` across decades.

## Tests

`tests/Feature/AtlasHistoricalPopulationImportTest.php`, `tests/Unit/PopulationChangeCalculatorTest.php`, plus M3–M7 regressions. Fixtures under `tests/fixtures/atlas/census-historical-mini*.json` (no live HTTP).
