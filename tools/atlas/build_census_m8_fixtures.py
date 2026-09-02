"""Build checked-in M8 historical census fixtures from verified official totals.

Operator/dev helper. Does not download. Writes JSON under database/imports/atlas/.
"""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "database" / "imports" / "atlas"

SOURCE = {
    "name": "Popis stanovništva 1991. — uporedni podaci 1971, 1981. i 1991. (naseljena mjesta)",
    "provider": "Federalni zavod za statistiku Federacije Bosne i Hercegovine",
    "source_class": "A",
    "authority_rank": 100,
    "source_type": "census_settlements_historical",
    "url": "https://fzs.ba/wp-content/uploads/2016/06/stanovnistvo-uporedni-podaci-1971_1981_-1991-bilten-265.pdf",
    "attribution": "Federalni zavod za statistiku, Statistički bilten 265, Sarajevo, april 1998. Uporedni podaci 1971, 1981. i 1991.",
    "dataset_version": "bilten-265-1998",
    "publication_title": "Popis stanovništva, domaćinstava/kućanstava, stanova i poljoprivrednih gazdinstava 1991. Stanovništvo. Uporedni podaci 1971, 1981. i 1991.",
    "publication_date": "1998-04",
    "bulletin_number": 265,
    "sha256_bilten_265": "7B273266D5BA4F5180AC5412A96B76AC7C2EC49B040A4CF427CD40CC8865CFD9",
    "sha256_nacion_mz_1991": "BC52CDB60F6576F8391E08C01219FC78EFD10BA9F60F125B0CF148454D29AF56",
    "url_mz_1991": "https://fzs.ba/wp-content/uploads/2016/06/nacion-po-mjesnim.pdf",
    "retrieval_date": "2026-09-02",
    "transcription": {
        "role": "machine-readable settlement-level totals",
        "url": "http://pop-stat.mashke.org/bosnia-census.htm",
        "not_canonical": True,
        "verification": [
            "Municipality totals for Modriča and Bosanski Šamac match FZS Statistički bilten 265 (1998) pages 38 and 20.",
            "1991 settlement totals for Miloševac, Modriča town and Šamac town match FZS 1991 mjesne zajednice PDF aggregations.",
            "Wikipedia/encyclopedia pages were QA cross-checks only and are not import sources.",
        ],
    },
}

# census_year -> population. Missing year omitted (not zero).
MODRICA_HIST = {
    "Babešnica": {1971: 163, 1981: 705, 1991: 147},
    "Botajica": {1971: 1237, 1981: 1104, 1991: 1019},
    "Čardak": {1971: 1026, 1981: 1046, 1991: 1006},
    "Dobra Voda": {1971: 234, 1981: 113, 1991: 89},
    "Dobrinja": {1971: 585, 1981: 588, 1991: 437},
    "Dugo Polje": {1971: 1918, 1981: 1798, 1991: 1596},
    "Garevac": {1971: 2057, 1981: 2751, 1991: 2795},
    "Jakeš": {1971: 1704, 1981: 1960, 1991: 2546},
    "Kladari Donji": {1971: 1010, 1981: 989, 1991: 1046},
    "Kladari Gornji": {1971: 428, 1981: 429, 1991: 448},
    "Koprivna": {1971: 2540, 1981: 2539, 1991: 2218},
    "Kužnjača": {1971: 221, 1981: 284, 1991: 272},
    "Miloševac": {1971: 1880, 1981: 1825, 1991: 1735},
    "Modriča": {1971: 7324, 1981: 9630, 1991: 10454},
    "Modrički Lug": {1991: 1033},
    "Pećnik": {1971: 1739, 1981: 1774, 1991: 1487},
    "Riječani Donji": {1971: 742, 1981: 754, 1991: 835},
    "Riječani Gornji": {1971: 496, 1981: 398, 1991: 305},
    "Skugrić Gornji": {1971: 1470, 1981: 1438, 1991: 1453},
    "Tarevci": {1971: 1965, 1981: 2242, 1991: 2322},
    "Vranjak": {1971: 2883, 1981: 2174, 1991: 2370},
}

GRADACAC = {
    "Donji Skugrić": {1971: 1481, 1981: 1410, 1991: 1402},
    "Gornje Krečane": {1971: 360, 1981: 315, 1991: 289},
    "Jasenica": {1971: 553, 1981: 617, 1991: 579},
    "Krčevljani": {1991: 282},
    "Tolisa": {1971: 1495, 1981: 1274, 1991: 858},
}

SAMAC_HIST = {
    "Batkuša": {1971: 983, 1981: 975, 1991: 924},
    "Bazik": {1971: 571, 1981: 535, 1991: 539},
    "Bosanski Šamac": {1971: 4877, 1981: 5605, 1991: 6239},
    "Brvnik": {1971: 687, 1981: 628, 1991: 609},
    "Crkvina": {1971: 1640, 1981: 1607, 1991: 1704},
    "Domaljevac": {1971: 3699, 1981: 4009, 1991: 4152},
    "Donja Slatina": {1971: 764, 1981: 692, 1991: 623},
    "Donji Hasić": {1971: 951, 1981: 1067, 1991: 1029},
    "Gajevi": {1991: 626},
    "Gornja Slatina": {1971: 2110, 1981: 2055, 1991: 1361},
    "Gornji Hasić": {1971: 1039, 1981: 1037, 1991: 1048},
    "Grebnice": {1971: 2003, 1981: 2094, 1991: 2210},
    "Kornica": {1971: 819, 1981: 832, 1991: 830},
    "Kruškovo Polje": {1971: 743, 1981: 746, 1991: 706},
    "Novo Selo": {1971: 1067, 1981: 1009, 1991: 1095},
    "Obudovac": {1971: 3403, 1981: 3388, 1991: 3199},
    "Pisari": {1971: 509, 1981: 518, 1991: 608},
    "Prud": {1971: 1250, 1981: 1293, 1991: 1293},
    "Škarić": {1971: 333, 1981: 365, 1991: 298},
    "Srednja Slatina": {1971: 1597, 1981: 1289, 1991: 1277},
    "Tišina": {1971: 1804, 1981: 2032, 1991: 2032},
    "Zasavica": {1971: 525, 1981: 544, 1991: 558},
}

SLUG = {
    "Babešnica": "babesnica",
    "Botajica": "botajica",
    "Čardak": "cardak",
    "Dobra Voda": "dobra-voda",
    "Dobrinja": "dobrinja",
    "Dugo Polje": "dugo-polje",
    "Garevac": "garevac",
    "Kladari Donji": "kladari-donji",
    "Kladari Gornji": "kladari-gornji",
    "Koprivna": "koprivna",
    "Kužnjača": "kuznjaca",
    "Miloševac": "milosevac",
    "Modriča": "modrica",
    "Riječani Donji": "rijecani-donji",
    "Riječani Gornji": "rijecani-gornji",
    "Skugrić Gornji": "skugric-gornji",
    "Tarevci": "tarevci",
    "Vranjak": "vranjak",
    "Donji Skugrić": "donji-skugric",
    "Gornje Krečane": "gornje-krecane",
    "Jasenica": "jasenica",
    "Krčevljani": "krcevljani",
    "Tolisa": "tolisa",
    "Batkuša": "batkusa",
    "Brvnik": "brvnik",
    "Crkvina": "crkvina",
    "Donja Slatina": "donja-slatina",
    "Donji Hasić": "donji-hasic",
    "Gajevi": "gajevi",
    "Gornja Slatina": "gornja-slatina",
    "Gornji Hasić": "gornji-hasic",
    "Grebnice": "grebnice",
    "Kornica": "kornica",
    "Kruškovo Polje": "kruskovo-polje",
    "Novo Selo": "novo-selo",
    "Obudovac": "obudovac",
    "Pisari": "pisari",
    "Škarić": "skaric",
    "Srednja Slatina": "srednja-slatina",
    "Tišina": "tisina",
    "Zasavica": "zasavica",
    "Bosanski Šamac": "samac",
}

UNMATCHED_MODRICA = {"Jakeš", "Modrički Lug", "Pećnik"}
UNMATCHED_SAMAC = {"Bazik", "Domaljevac", "Prud"}


def years_sum(table: dict, year: int) -> int:
    return sum(vals.get(year, 0) for vals in table.values())


def record(muni: str, name: str, year: int, population: int) -> dict:
    return {
        "census_year": year,
        "historical_municipality_name": muni,
        "historical_settlement_name": name,
        "population": population,
        "households": None,
    }


def mapping(
    *,
    year: int,
    hist_muni: str,
    hist_name: str,
    current_muni: str | None,
    current_slug: str | None,
    status: str,
    comparability: str,
    method: str,
    notes: str,
    alias: bool = False,
) -> dict:
    row = {
        "census_year": year,
        "historical_municipality_name": hist_muni,
        "historical_settlement_name": hist_name,
        "current_municipality_slug": current_muni,
        "current_settlement_slug": current_slug,
        "mapping_status": status,
        "mapping_method": method,
        "comparability": comparability,
        "notes": notes,
    }
    if alias:
        row["alias"] = True
    return row


def caveat_for(name: str, year: int, hist_muni: str) -> tuple[str, str, str]:
    if name == "Babešnica" and year == 1981:
        return (
            "exact_identity",
            "comparable_with_caveat",
            "1981. je znatno viša od 1971. i 1991.; zbir historijske opštine 1981. se slaže sa FZS biltenom 265.",
        )
    if name == "Gornja Slatina" and year in (1971, 1981):
        return (
            "split",
            "comparable_with_caveat",
            "Prije 1991. obuhvata i kasnije izdvojene Gajeve.",
        )
    if name == "Gajevi":
        return (
            "split",
            "comparable_with_caveat",
            "Izdvojeno iz Gornje Slatine na popisu 1991.",
        )
    if name == "Tolisa" and year in (1971, 1981):
        return (
            "split",
            "comparable_with_caveat",
            "Prije 1991. obuhvata i kasnije izdvojene Krčevljane.",
        )
    if name == "Krčevljani":
        return (
            "split",
            "comparable_with_caveat",
            "Izdvojeno iz Tolise na popisu 1991. Historijska opština Gradačac.",
        )
    if name == "Bosanski Šamac":
        return (
            "renamed",
            "comparable",
            "Izvorni naziv Bosanski Šamac; kanonsko naselje danas je Šamac. Historijski naziv nije preimenovan.",
        )
    if hist_muni == "Gradačac":
        return (
            "exact_identity",
            "comparable",
            "Historijska opština Gradačac; današnja pripadnost je opština Modriča. To nije dokaz da je naselje 1971–1991. bilo u Modriči.",
        )
    return ("exact_identity", "comparable", "Isti popisni identitet naselja u historijskoj opštini "+hist_muni+".")


def main() -> None:
    records = []
    mappings = []

    for name, series in MODRICA_HIST.items():
        for year, pop in series.items():
            records.append(record("Modriča", name, year, pop))
            if name in UNMATCHED_MODRICA:
                mappings.append(
                    mapping(
                        year=year,
                        hist_muni="Modriča",
                        hist_name=name,
                        current_muni=None,
                        current_slug=None,
                        status="unmatched",
                        comparability="not_directly_comparable",
                        method="checked_in_mapping",
                        notes="Nakon Dejtona u opštini Vukosavlje (FBiH). Nije u kanonskom pilotu današnje opštine Modriča.",
                    )
                )
            else:
                status, comp, notes = caveat_for(name, year, "Modriča")
                mappings.append(
                    mapping(
                        year=year,
                        hist_muni="Modriča",
                        hist_name=name,
                        current_muni="modrica",
                        current_slug=SLUG[name],
                        status=status,
                        comparability=comp,
                        method="checked_in_mapping",
                        notes=notes,
                    )
                )

    for name, series in GRADACAC.items():
        for year, pop in series.items():
            records.append(record("Gradačac", name, year, pop))
            status, comp, notes = caveat_for(name, year, "Gradačac")
            mappings.append(
                mapping(
                    year=year,
                    hist_muni="Gradačac",
                    hist_name=name,
                    current_muni="modrica",
                    current_slug=SLUG[name],
                    status=status,
                    comparability=comp,
                    method="checked_in_mapping",
                    notes=notes,
                )
            )

    for name, series in SAMAC_HIST.items():
        for year, pop in series.items():
            records.append(record("Bosanski Šamac", name, year, pop))
            if name in UNMATCHED_SAMAC:
                mappings.append(
                    mapping(
                        year=year,
                        hist_muni="Bosanski Šamac",
                        hist_name=name,
                        current_muni=None,
                        current_slug=None,
                        status="unmatched",
                        comparability="not_directly_comparable",
                        method="checked_in_mapping",
                        notes="Nakon Dejtona u općini Domaljevac-Šamac (FBiH). Nije u kanonskom pilotu današnje opštine Šamac.",
                    )
                )
            else:
                status, comp, notes = caveat_for(name, year, "Bosanski Šamac")
                mappings.append(
                    mapping(
                        year=year,
                        hist_muni="Bosanski Šamac",
                        hist_name=name,
                        current_muni="samac",
                        current_slug=SLUG[name],
                        status=status,
                        comparability=comp,
                        method="checked_in_mapping",
                        notes=notes,
                        alias=name == "Bosanski Šamac",
                    )
                )

    for slug, hist_name, note in (
        ("borovo-polje", "Borovo Polje", "Nije pronađeno kao samostalno naseljeno mjesto u popisima 1971–1991. za historijske opštine Modriča/Gradačac/Odžak."),
        ("lugovi", "Lugovi", "Nije pronađeno kao samostalno naseljeno mjesto u popisima 1971–1991. za historijsku opštinu Bosanski Šamac (postoji istoimeni zapis u Maglaju — nije ovo naselje)."),
        ("njegosevo", "Njegoševo", "Nije pronađeno u popisima 1971–1991. Pojavljuje se u Popisu 2013. kao naselje današnje opštine Šamac, sa službenih 0 stanovnika."),
    ):
        muni = "modrica" if slug == "borovo-polje" else "samac"
        mappings.append(
            mapping(
                year=1991,
                hist_muni="(nije u popisu 1971–1991)",
                hist_name=hist_name,
                current_muni=muni,
                current_slug=slug,
                status="unmatched",
                comparability="unresolved",
                method="checked_in_mapping",
                notes=note,
            )
        )

    raw = {
        "source": SOURCE,
        "notes": [
            "Sirovi redovi su ukupno stanovništvo naseljenog mjesta. Etnička/nacionalna struktura nije sačuvana.",
            "Domaćinstva nisu uvezena jer tabele naselja u korištenom izvodu ne daju usklađena settlement-level households.",
            "Zbrojevi historijskih opština usklađeni su sa FZS biltenom 265 (Modriča str. 38; Bos. Šamac str. 20).",
            "1991. naseljske ukupnosti usklađene su sa FZS PDF-om nacionalnog sastava po mjesnim zajednicama, uz agregaciju MZ gdje je naselje podijeljeno (npr. Modriča 1–4, Skugrić Gornji + Živkovo Polje).",
        ],
        "municipality_qa": [
            {
                "historical_municipality_name": "Modriča",
                "bulletin_265_page": 38,
                "years": {
                    "1971": {"official_total": 31622, "parsed_sum": years_sum(MODRICA_HIST, 1971)},
                    "1981": {"official_total": 34541, "parsed_sum": years_sum(MODRICA_HIST, 1981)},
                    "1991": {"official_total": 35613, "parsed_sum": years_sum(MODRICA_HIST, 1991)},
                },
            },
            {
                "historical_municipality_name": "Bosanski Šamac",
                "bulletin_265_page": 20,
                "years": {
                    "1971": {"official_total": 31374, "parsed_sum": years_sum(SAMAC_HIST, 1971)},
                    "1981": {"official_total": 32320, "parsed_sum": years_sum(SAMAC_HIST, 1981)},
                    "1991": {"official_total": 32960, "parsed_sum": years_sum(SAMAC_HIST, 1991)},
                },
            },
        ],
        "records": records,
    }

    mapping_doc = {
        "version": "m8.1",
        "notes": [
            "Mapiranje je eksplicitno. Historijska opština je izvorni kontekst, ne današnja administracija.",
            "Importer ne smije fuzzy-matchovati. Samo checked-in mapping + stable current slug.",
        ],
        "mappings": mappings,
    }

    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "census-historical-pilot.json").write_text(json.dumps(raw, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (OUT / "census-historical-pilot-mapping.json").write_text(json.dumps(mapping_doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("records", len(records), "mappings", len(mappings))
    for qa in raw["municipality_qa"]:
        for year, vals in qa["years"].items():
            ok = vals["official_total"] == vals["parsed_sum"]
            print(qa["historical_municipality_name"], year, vals, "OK" if ok else "MISMATCH")


if __name__ == "__main__":
    main()
