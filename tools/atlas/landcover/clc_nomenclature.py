"""Official CLC 2018 class codes and the atlas public grouping.

Mapping lives here only. The frontend must not invent class groups.
"""

from __future__ import annotations

from typing import TypedDict

ALGORITHM_VERSION = "m10.1"
SHARE_SUM_WARN_PP = 0.5
SHARE_SUM_FAIL_PP = 2.0
MIN_VALID_COVERAGE = 0.95
HIGH_NODATA_COVERAGE = 0.50


class ClcClass(TypedDict):
    code: str
    level1: str
    atlas_group: str
    label_en: str
    label_bs: str


# Official 3-digit CLC nomenclature. Atlas groups are a stable public taxonomy,
# not a replacement for the source class.
CLC_CLASSES: dict[str, ClcClass] = {
    "111": {"code": "111", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Continuous urban fabric", "label_bs": "Kontinuirano urbano tkivo"},
    "112": {"code": "112", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Discontinuous urban fabric", "label_bs": "Diskontinuirano urbano tkivo"},
    "121": {"code": "121", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Industrial or commercial units", "label_bs": "Industrijske ili komercijalne jedinice"},
    "122": {"code": "122", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Road and rail networks and associated land", "label_bs": "Cestovna i željeznička mreža i prateće zemljište"},
    "123": {"code": "123", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Port areas", "label_bs": "Lučka područja"},
    "124": {"code": "124", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Airports", "label_bs": "Aerodromi"},
    "131": {"code": "131", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Mineral extraction sites", "label_bs": "Površinski kopovi"},
    "132": {"code": "132", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Dump sites", "label_bs": "Deponije"},
    "133": {"code": "133", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Construction sites", "label_bs": "Gradilišta"},
    "141": {"code": "141", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Green urban areas", "label_bs": "Urbane zelene površine"},
    "142": {"code": "142", "level1": "1", "atlas_group": "artificial_surfaces", "label_en": "Sport and leisure facilities", "label_bs": "Sportski i rekreativni objekti"},
    "211": {"code": "211", "level1": "2", "atlas_group": "agricultural", "label_en": "Non-irrigated arable land", "label_bs": "Neirigirane oranice"},
    "212": {"code": "212", "level1": "2", "atlas_group": "agricultural", "label_en": "Permanently irrigated land", "label_bs": "Stalno navodnjavano zemljište"},
    "213": {"code": "213", "level1": "2", "atlas_group": "agricultural", "label_en": "Rice fields", "label_bs": "Rižina polja"},
    "221": {"code": "221", "level1": "2", "atlas_group": "agricultural", "label_en": "Vineyards", "label_bs": "Vinogradi"},
    "222": {"code": "222", "level1": "2", "atlas_group": "agricultural", "label_en": "Fruit trees and berry plantations", "label_bs": "Voćnjaci i nasadi bobičastog voća"},
    "223": {"code": "223", "level1": "2", "atlas_group": "agricultural", "label_en": "Olive groves", "label_bs": "Maslinici"},
    "231": {"code": "231", "level1": "2", "atlas_group": "agricultural", "label_en": "Pastures", "label_bs": "Pašnjaci"},
    "241": {"code": "241", "level1": "2", "atlas_group": "agricultural", "label_en": "Annual crops associated with permanent crops", "label_bs": "Jednogodišnji usjevi udruženi sa višegodišnjim"},
    "242": {"code": "242", "level1": "2", "atlas_group": "agricultural", "label_en": "Complex cultivation patterns", "label_bs": "Složeni uzgojni obrasci"},
    "243": {"code": "243", "level1": "2", "atlas_group": "agricultural", "label_en": "Land principally occupied by agriculture, with significant areas of natural vegetation", "label_bs": "Pretežno poljoprivredno zemljište sa značajnim udjelom prirodne vegetacije"},
    "244": {"code": "244", "level1": "2", "atlas_group": "agricultural", "label_en": "Agro-forestry areas", "label_bs": "Agrošumske površine"},
    "311": {"code": "311", "level1": "3", "atlas_group": "forest", "label_en": "Broad-leaved forest", "label_bs": "Širokolisna šuma"},
    "312": {"code": "312", "level1": "3", "atlas_group": "forest", "label_en": "Coniferous forest", "label_bs": "Četinarska šuma"},
    "313": {"code": "313", "level1": "3", "atlas_group": "forest", "label_en": "Mixed forest", "label_bs": "Mješovita šuma"},
    "321": {"code": "321", "level1": "3", "atlas_group": "shrub_herbaceous_or_seminatural", "label_en": "Natural grasslands", "label_bs": "Prirodni travnjaci"},
    "322": {"code": "322", "level1": "3", "atlas_group": "shrub_herbaceous_or_seminatural", "label_en": "Moors and heathland", "label_bs": "Vrištine i vrijesišta"},
    "323": {"code": "323", "level1": "3", "atlas_group": "shrub_herbaceous_or_seminatural", "label_en": "Sclerophyllous vegetation", "label_bs": "Sklerofilna vegetacija"},
    "324": {"code": "324", "level1": "3", "atlas_group": "shrub_herbaceous_or_seminatural", "label_en": "Transitional woodland-shrub", "label_bs": "Prijelazna šumsko-žbunasta vegetacija"},
    "331": {"code": "331", "level1": "3", "atlas_group": "open_or_sparse", "label_en": "Beaches, dunes, sands", "label_bs": "Plaže, dine, pijesak"},
    "332": {"code": "332", "level1": "3", "atlas_group": "open_or_sparse", "label_en": "Bare rocks", "label_bs": "Golo kamenje"},
    "333": {"code": "333", "level1": "3", "atlas_group": "open_or_sparse", "label_en": "Sparsely vegetated areas", "label_bs": "Rijetko vegetirane površine"},
    "334": {"code": "334", "level1": "3", "atlas_group": "open_or_sparse", "label_en": "Burnt areas", "label_bs": "Spaljene površine"},
    "335": {"code": "335", "level1": "3", "atlas_group": "open_or_sparse", "label_en": "Glaciers and perpetual snow", "label_bs": "Lednici i vječni snijeg"},
    "411": {"code": "411", "level1": "4", "atlas_group": "wetlands", "label_en": "Inland marshes", "label_bs": "Unutrašnje močvare"},
    "412": {"code": "412", "level1": "4", "atlas_group": "wetlands", "label_en": "Peat bogs", "label_bs": "Tresetišta"},
    "421": {"code": "421", "level1": "4", "atlas_group": "wetlands", "label_en": "Salt marshes", "label_bs": "Slane močvare"},
    "422": {"code": "422", "level1": "4", "atlas_group": "wetlands", "label_en": "Salines", "label_bs": "Solane"},
    "423": {"code": "423", "level1": "4", "atlas_group": "wetlands", "label_en": "Intertidal flats", "label_bs": "Plimne zaravni"},
    "511": {"code": "511", "level1": "5", "atlas_group": "water", "label_en": "Water courses", "label_bs": "Vodeni tokovi"},
    "512": {"code": "512", "level1": "5", "atlas_group": "water", "label_en": "Water bodies", "label_bs": "Vodene površine"},
    "521": {"code": "521", "level1": "5", "atlas_group": "water", "label_en": "Coastal lagoons", "label_bs": "Obalne lagune"},
    "522": {"code": "522", "level1": "5", "atlas_group": "water", "label_en": "Estuaries", "label_bs": "Estuariji"},
    "523": {"code": "523", "level1": "5", "atlas_group": "water", "label_en": "Sea and ocean", "label_bs": "More i okean"},
}

ATLAS_GROUP_LABELS = {
    "artificial_surfaces": {"en": "Artificial surfaces", "bs": "Umjetne površine"},
    "agricultural": {"en": "Agricultural areas", "bs": "Poljoprivredne površine"},
    "forest": {"en": "Forest", "bs": "Šumske površine"},
    "shrub_herbaceous_or_seminatural": {"en": "Shrub, herbaceous or semi-natural", "bs": "Žbunasta, zeljasta ili poluprirodna vegetacija"},
    "open_or_sparse": {"en": "Open or sparsely vegetated", "bs": "Otvorene ili rijetko vegetirane površine"},
    "wetlands": {"en": "Wetlands", "bs": "Močvare"},
    "water": {"en": "Water bodies", "bs": "Vode"},
}

PUBLIC_GROUP_METRICS = {
    "landcover_artificial_pct": "artificial_surfaces",
    "landcover_agricultural_pct": "agricultural",
    "landcover_forest_pct": "forest",
    "landcover_seminatural_pct": "shrub_herbaceous_or_seminatural",
    "landcover_wetland_pct": "wetlands",
    "landcover_water_pct": "water",
}

# HRL Water and Wetness 2018 classified layer (PUM).
WAW_CLASSES = {
    0: {"code": "0", "label_en": "Dry", "label_bs": "Suho", "atlas_group": "dry"},
    1: {"code": "1", "label_en": "Permanent water", "label_bs": "Stalna voda", "atlas_group": "permanent_water"},
    2: {"code": "2", "label_en": "Temporary water", "label_bs": "Privremena voda", "atlas_group": "temporary_water"},
    3: {"code": "3", "label_en": "Permanent wet", "label_bs": "Stalno vlažno", "atlas_group": "permanent_wet"},
    4: {"code": "4", "label_en": "Temporary wet", "label_bs": "Privremeno vlažno", "atlas_group": "temporary_wet"},
}

# HRL Forest Type 2018 (FAO-like forest mask after agricultural/urban tree removal).
FTY_CLASSES = {
    0: {"code": "0", "label_en": "Non-forest", "label_bs": "Nije šuma", "atlas_group": "non_forest"},
    1: {"code": "1", "label_en": "Broadleaved forest", "label_bs": "Širokolisna šuma", "atlas_group": "forest"},
    2: {"code": "2", "label_en": "Coniferous forest", "label_bs": "Četinarska šuma", "atlas_group": "forest"},
}


def normalize_clc_code(value: object) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    if text.endswith(".0"):
        text = text[:-2]
    if text.isdigit() and len(text) <= 3:
        text = text.zfill(3)
    return text if text in CLC_CLASSES else None


def clc_class(code: str) -> ClcClass:
    info = CLC_CLASSES.get(code)
    if info is None:
        raise KeyError(f"Nepoznat CLC razred: {code}")
    return info


def atlas_group_for_clc(code: str) -> str:
    return clc_class(code)["atlas_group"]


def group_shares_from_class_areas(class_areas_m2: dict[str, float], valid_area_m2: float) -> dict[str, float]:
    shares = {group: 0.0 for group in ATLAS_GROUP_LABELS}
    if valid_area_m2 <= 0:
        return shares
    for code, area in class_areas_m2.items():
        info = CLC_CLASSES.get(code)
        if info is None:
            continue
        shares[info["atlas_group"]] += 100.0 * area / valid_area_m2
    return shares


def dominant_entry(class_areas_m2: dict[str, float]) -> dict[str, object] | None:
    if not class_areas_m2:
        return None
    # Tie: larger area wins; equal areas use the lower CLC code (deterministic, not settlement id).
    code = sorted(class_areas_m2.items(), key=lambda item: (-item[1], item[0]))[0][0]
    info = CLC_CLASSES.get(code)
    if info is None:
        return {"code": code, "label": code, "atlas_group": None, "area_m2": class_areas_m2[code]}
    return {
        "code": code,
        "label": info["label_bs"],
        "label_en": info["label_en"],
        "atlas_group": info["atlas_group"],
        "area_m2": class_areas_m2[code],
    }


def quality_from_coverage(valid_area_m2: float, total_area_m2: float, share_sum_pp: float | None = None) -> str:
    if total_area_m2 <= 0 or valid_area_m2 <= 0:
        return "unavailable"
    coverage = valid_area_m2 / total_area_m2
    if share_sum_pp is not None and abs(share_sum_pp - 100.0) > SHARE_SUM_FAIL_PP:
        return "inconsistent_totals"
    if coverage < HIGH_NODATA_COVERAGE:
        return "insufficient_coverage"
    if coverage < MIN_VALID_COVERAGE:
        return "high_nodata"
    return "ok"
