"""Match and download OSM admin_level=9 naselje polygons for the Modriča/Šamac pilot.

Identity rules:
- Never match by name similarity / fuzzy strings.
- Ignore place=village nodes.
- Ignore relations whose bounds fall outside Bosnia and Herzegovina.
- Ignore names explicitly qualified as (FBiH) when targeting RS municipalities.
- Unique exact normalized name in the BiH candidate set is accepted in pass 1.
- Remaining names are accepted only if exactly one candidate centre lies inside the
  pass-1 municipality cluster envelope (padded). Otherwise unmatched/ambiguous.
- OSM relation 8758298 is Serbia (wrong wikidata Q533433) and is excluded.
- OSM relation 13426749 is tagged with municipality wikidata Q391033 but its
  geometry is town-scale around urban Šamac; it is used as naselje Šamac.

Geometries are retrieved from polygons.openstreetmap.fr (OSM polygon export),
not invented.
"""

from __future__ import annotations

import json
import re
import ssl
import sys
import time
import unicodedata
import urllib.request
from pathlib import Path

from shapely.geometry import MultiPolygon, Point, box, mapping, shape
from shapely.ops import unary_union

ROOT = Path(__file__).resolve().parents[2]
CENSUS = ROOT / "database" / "imports" / "atlas" / "census-2013-pilot-settlements.json"
OUT_DIR = ROOT / "database" / "imports" / "atlas"
OVERPASS = "https://overpass-api.de/api/interpreter"
POLYGONS = "https://polygons.openstreetmap.fr/get_geojson.py"
USER_AGENT = "Milosevac Atlas M4 (milosevac.com)"
SSL = ssl._create_unverified_context()

BIH = box(15.6, 42.5, 19.7, 45.3)
EXCLUDED_OSM_IDS = {
    8758298: "Geometry is in Serbia (Kruševac area); wikidata Q533433 is incorrectly applied.",
}

CYR = {
    "А": "A", "а": "a", "Б": "B", "б": "b", "В": "V", "в": "v", "Г": "G", "г": "g",
    "Д": "D", "д": "d", "Ђ": "Đ", "ђ": "đ", "Е": "E", "е": "e", "Ж": "Ž", "ж": "ž",
    "З": "Z", "з": "z", "И": "I", "и": "i", "Ј": "J", "ј": "j", "К": "K", "к": "k",
    "Л": "L", "л": "l", "Љ": "Lj", "љ": "lj", "М": "M", "м": "m", "Н": "N", "н": "n",
    "Њ": "Nj", "њ": "nj", "О": "O", "о": "o", "П": "P", "п": "p", "Р": "R", "р": "r",
    "С": "S", "с": "s", "Т": "T", "т": "t", "Ћ": "Ć", "ћ": "ć", "У": "U", "у": "u",
    "Ф": "F", "ф": "f", "Х": "H", "х": "h", "Ц": "C", "ц": "c", "Ч": "Č", "ч": "č",
    "Џ": "Dž", "џ": "dž", "Ш": "Š", "ш": "š",
}
DIAC = str.maketrans({"č": "c", "ć": "c", "đ": "d", "š": "s", "ž": "z"})
QUALIFIER = re.compile(r"\s*\((?:rs|fbih|рс|фбих|odžak)\)\s*$", re.IGNORECASE)


def http_json(url: str, data: bytes | None = None) -> dict | list:
    req = urllib.request.Request(
        url,
        data=data,
        headers={"User-Agent": USER_AGENT, "Content-Type": "text/plain; charset=utf-8"},
    )
    with urllib.request.urlopen(req, timeout=180, context=SSL) as response:
        return json.loads(response.read().decode("utf-8"))


def transliterate(text: str) -> str:
    return "".join(CYR.get(ch, ch) for ch in text)


def normalize(value: str) -> str:
    text = QUALIFIER.sub("", transliterate(value))
    text = text.lower().translate(DIAC)
    text = unicodedata.normalize("NFKD", text)
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = re.sub(r"[^a-z0-9\s]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def slugify(name: str) -> str:
    return normalize(name).replace(" ", "-")


def feature_names(tags: dict) -> list[str]:
    names, seen = [], set()
    for key in ("name:sr-Latn", "name:hr", "name:bs", "name:en", "name", "official_name", "name:sr"):
        raw = tags.get(key)
        if raw and raw not in seen:
            names.append(raw)
            seen.add(raw)
    return names


def qualifier(names: list[str]) -> str | None:
    blob = " ".join(names).lower()
    if "(fbih)" in blob or "фбих" in blob:
        return "fbih"
    if "(rs)" in blob or "(рс)" in blob:
        return "rs"
    return None


def as_multipolygon(geom: dict) -> dict:
    poly = shape(geom)
    if isinstance(poly, MultiPolygon):
        return mapping(poly)
    return mapping(MultiPolygon([poly]))


def load_census() -> list[dict]:
    data = json.loads(CENSUS.read_text(encoding="utf-8"))
    rows = []
    for muni in data["municipalities"]:
        for settlement in muni["settlements"]:
            rows.append({
                "municipality_slug": muni["slug"],
                "municipality_name": muni["name"],
                "settlement_name": settlement["name"],
                "settlement_slug": slugify(settlement["name"]),
                "statistical_code": settlement["statistical_code"],
                "key": normalize(settlement["name"]),
            })
    return rows


def load_candidates(path: Path) -> list[dict]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    rows = []
    for element in payload.get("elements", []):
        if element.get("type") != "relation":
            continue
        osm_id = int(element["id"])
        if osm_id in EXCLUDED_OSM_IDS:
            continue
        tags = element.get("tags") or {}
        if tags.get("boundary") != "administrative":
            continue
        if tags.get("admin_level") not in {"9", "10"}:
            continue
        center = element.get("center") or {}
        if "lat" not in center:
            continue
        pt = Point(float(center["lon"]), float(center["lat"]))
        if not BIH.contains(pt):
            continue
        names = feature_names(tags)
        rows.append({
            "osm_id": osm_id,
            "names": names,
            "keys": sorted({normalize(n) for n in names if normalize(n)}),
            "qualifier": qualifier(names),
            "place": tags.get("place"),
            "wikidata": tags.get("wikidata"),
            "admin_level": tags.get("admin_level"),
            "lat": float(center["lat"]),
            "lng": float(center["lon"]),
            "point": pt,
            "tags": {k: tags[k] for k in tags if k.startswith("name") or k in {"wikidata", "place", "admin_level", "boundary", "ref"}},
        })
    return rows


def name_hits(settlement: dict, candidates: list[dict]) -> list[dict]:
    hits = []
    for candidate in candidates:
        if settlement["key"] not in candidate["keys"]:
            continue
        if candidate["qualifier"] == "fbih":
            continue
        hits.append(candidate)
    rs = [h for h in hits if h["qualifier"] == "rs"]
    return rs or hits


def envelope(points: list[Point], pad: float = 0.02):
    if not points:
        return None
    return unary_union(points).envelope.buffer(pad)


def download_polygon(osm_id: int) -> dict | None:
    url = f"{POLYGONS}?id={osm_id}&params=0"
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=60, context=SSL) as response:
        payload = json.loads(response.read().decode("utf-8"))
    if not payload or payload.get("type") not in {"Polygon", "MultiPolygon"}:
        return None
    return as_multipolygon(payload)


def mapping_row(settlement: dict, hit: dict, method: str) -> dict:
    return {
        "municipality_slug": settlement["municipality_slug"],
        "settlement_slug": settlement["settlement_slug"],
        "settlement_name": settlement["settlement_name"],
        "statistical_code": settlement["statistical_code"],
        "osm_type": "relation",
        "osm_id": hit["osm_id"],
        "source_feature_id": f"relation/{hit['osm_id']}",
        "source_name": hit["names"][0] if hit["names"] else None,
        "source_names": hit["names"],
        "wikidata_id": hit["wikidata"],
        "place": hit["place"],
        "admin_level": hit["admin_level"],
        "match_method": method,
        "source_properties": hit["tags"],
    }


def main() -> int:
    bbox_path = Path(r"C:\Users\aleks\AppData\Local\Temp\overpass-bbox.json")
    census = load_census()
    candidates = load_candidates(bbox_path)
    used: set[int] = set()
    mappings: list[dict] = []
    unmatched: list[dict] = []
    ambiguous: list[dict] = []
    pending: list[tuple[dict, list[dict]]] = []

    for settlement in census:
        hits = name_hits(settlement, candidates)
        unique = list({h["osm_id"]: h for h in hits}.values())
        if len(unique) == 1:
            hit = unique[0]
            if hit["osm_id"] in used:
                ambiguous.append({"settlement": settlement, "reason": "duplicate_osm_id", "osm_id": hit["osm_id"]})
                continue
            used.add(hit["osm_id"])
            mappings.append(mapping_row(settlement, hit, "exact_normalized_name_unique_in_bih"))
        elif not unique:
            unmatched.append(settlement)
        else:
            pending.append((settlement, unique))

    clusters: dict[str, object] = {}
    for slug in ("modrica", "samac"):
        pts = [Point(row["source_properties"].get("lon", 0), 0) for row in mappings if False]
        pts = []
        for row in mappings:
            if row["municipality_slug"] != slug:
                continue
            cand = next(c for c in candidates if c["osm_id"] == row["osm_id"])
            pts.append(cand["point"])
        clusters[slug] = envelope(pts)

    for settlement, unique in pending:
        env = clusters.get(settlement["municipality_slug"])
        inside = [h for h in unique if env is not None and env.contains(h["point"])]
        if len(inside) == 1 and inside[0]["osm_id"] not in used:
            used.add(inside[0]["osm_id"])
            mappings.append(mapping_row(settlement, inside[0], "exact_name_plus_municipality_cluster_envelope"))
        elif len(inside) > 1:
            ambiguous.append({
                "settlement": settlement,
                "reason": "multiple_inside_cluster",
                "osm_ids": [h["osm_id"] for h in inside],
            })
        else:
            unmatched.append({
                **settlement,
                "reason": "name_not_unique_and_not_inside_cluster",
                "osm_ids": [h["osm_id"] for h in unique],
            })

    mappings.sort(key=lambda r: (r["municipality_slug"], r["settlement_name"]))
    sys.stdout.reconfigure(encoding="utf-8")
    print(f"mapped={len(mappings)} unmatched={len(unmatched)} ambiguous={len(ambiguous)}")
    for row in unmatched:
        extra = f" {row.get('osm_ids')}" if "osm_ids" in row else ""
        print("UNMATCHED", row["municipality_slug"], row["settlement_name"] + extra)
    for row in ambiguous:
        print("AMBIGUOUS", row["settlement"]["settlement_name"], row["reason"], row.get("osm_ids") or row.get("osm_id"))

    features = []
    failed = []
    for i, row in enumerate(mappings, start=1):
        print(f"geom {i}/{len(mappings)} {row['source_feature_id']}")
        try:
            geom = download_polygon(row["osm_id"])
        except Exception as exc:
            print("  FAIL", exc)
            failed.append({"statistical_code": row["statistical_code"], "osm_id": row["osm_id"], "error": str(exc)})
            time.sleep(1)
            continue
        if geom is None:
            failed.append({"statistical_code": row["statistical_code"], "osm_id": row["osm_id"], "error": "empty"})
            continue
        poly = shape(geom)
        if poly.area > 0.05:
            failed.append({
                "statistical_code": row["statistical_code"],
                "osm_id": row["osm_id"],
                "error": f"absurdly_large_deg2={poly.area:.4f}",
            })
            continue
        row["bbox"] = [round(v, 7) for v in poly.bounds]
        features.append({
            "type": "Feature",
            "id": row["source_feature_id"],
            "properties": {
                "source_feature_id": row["source_feature_id"],
                "osm_type": "relation",
                "osm_id": row["osm_id"],
                "name": row["source_name"],
                "source_names": row["source_names"],
                "municipality_slug": row["municipality_slug"],
                "municipality_name": next(s["municipality_name"] for s in census if s["statistical_code"] == row["statistical_code"]),
                "settlement_slug": row["settlement_slug"],
                "settlement_name": row["settlement_name"],
                "statistical_code": row["statistical_code"],
                "wikidata_id": row["wikidata_id"],
                "place": row["place"],
                "admin_level": row["admin_level"],
                "match_method": row["match_method"],
                "source_properties": row["source_properties"],
            },
            "geometry": geom,
        })
        time.sleep(0.4)

    mapping_doc = {
        "source": {
            "dataset_name": "OpenStreetMap administrative settlement polygons (admin_level=9)",
            "publisher": "OpenStreetMap contributors",
            "source_url": "https://www.openstreetmap.org/copyright",
            "geometry_export_url": POLYGONS,
            "overpass_url": OVERPASS,
            "retrieval_date": "2026-09-02",
            "license": "ODbL",
            "geographic_crs": "EPSG:4326",
            "dataset_version": "osm-2026-09-01",
            "feature_identifier_field": "osm_id",
            "settlement_name_field": "name / name:sr-Latn / name:sr",
            "municipality_field": "two-pass: unique BiH name, then pass-1 cluster envelope",
            "geometry_type": "MultiPolygon",
            "class": "C",
            "known_limitations": [
                "Not an official cadastral or 2013-census settlement boundary product.",
                "RGURS GeoServer WFS advertised GetFeature but FeatureTypeList was empty; naselja layers returned HTTP 400.",
                "OSM WikiProject BiH still lists admin_level 8/9 as incomplete; coverage is community-mapped.",
                "place=village nodes are not used as boundaries.",
                "Relation 8758298 (wikidata Q533433) is a Serbia polygon and is excluded.",
                "Relation 13426749 carries municipality wikidata Q391033 but the exported polygon is town-scale and is used only for naselje Šamac.",
                "FBiH homonyms are excluded by explicit (FBiH) qualifiers or by falling outside the RS cluster envelope.",
            ],
        },
        "excluded_osm_ids": EXCLUDED_OSM_IDS,
        "mappings": mappings,
        "unmatched_settlements": unmatched,
        "ambiguous_matches": ambiguous,
        "failed_geometries": failed,
    }

    geojson = {
        "type": "FeatureCollection",
        "name": "osm-pilot-settlement-boundaries",
        "crs": {"type": "name", "properties": {"name": "EPSG:4326"}},
        "features": features,
    }

    mapping_path = OUT_DIR / "osm-pilot-boundary-mapping.json"
    geojson_path = OUT_DIR / "osm-pilot-settlement-boundaries.geojson"
    mapping_path.write_text(json.dumps(mapping_doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    geojson_path.write_text(json.dumps(geojson, ensure_ascii=False), encoding="utf-8")
    print("wrote", mapping_path.name, "mappings", len(mappings), "features", len(features), "failed", len(failed))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
