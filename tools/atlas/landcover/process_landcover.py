"""CLI: python -m landcover.process_landcover --jobs jobs.json --cache-dir cache/landcover --output landcover.json

Run from backend/tools/atlas.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
ATLAS = HERE.parent
if str(ATLAS) not in sys.path:
    sys.path.insert(0, str(ATLAS))

from landcover.clc_nomenclature import ALGORITHM_VERSION  # noqa: E402
from landcover.zonal import load_clc_features, load_raster, process_settlement  # noqa: E402


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def optional_raster(path: Path | None):
    if path is None or not path.exists():
        return None
    return load_raster(path)


def load_manifest(cache_dir: Path) -> dict:
    path = cache_dir / "source_manifest.json"
    if not path.exists():
        committed = HERE / "source_manifest.json"
        path = committed if committed.exists() else path
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def qa_row(job: dict, profiles: list[dict]) -> dict:
    by_code = {profile["product_code"]: profile for profile in profiles}
    clc = by_code.get("clc2018") or {}
    summary = clc.get("summary") or {}
    tcd = (by_code.get("hrl_tcd_2018") or {}).get("summary") or {}
    imd = (by_code.get("hrl_imd_2018") or {}).get("summary") or {}
    waw = (by_code.get("hrl_waw_2018") or {}).get("summary") or {}
    fty = (by_code.get("hrl_fty_2018") or {}).get("summary") or {}
    dominant = summary.get("dominant_class") or {}
    return {
        "municipality": job.get("municipality_slug"),
        "settlement": job.get("settlement_slug"),
        "settlement_name": job.get("settlement_name"),
        "boundary_id": job.get("boundary_id"),
        "clc_status": clc.get("quality_flag"),
        "analyzed_area_km2": None if not clc else round(clc.get("valid_area_km2") or 0, 4),
        "dominant_class": dominant.get("code"),
        "dominant_label": dominant.get("label"),
        "artificial_pct": _round(summary.get("landcover_artificial_pct")),
        "agricultural_pct": _round(summary.get("landcover_agricultural_pct")),
        "forest_pct": _round(summary.get("landcover_forest_pct")),
        "seminatural_pct": _round(summary.get("landcover_seminatural_pct")),
        "wetland_pct": _round(summary.get("landcover_wetland_pct")),
        "water_pct": _round(summary.get("landcover_water_pct")),
        "tree_cover_mean_pct": _round(tcd.get("tree_cover_mean_pct")),
        "forest_area_pct": _round(fty.get("forest_area_pct")),
        "imperviousness_mean_pct": _round(imd.get("imperviousness_mean_pct")),
        "permanent_water_pct": _round(waw.get("permanent_water_pct")),
        "water_wetness_pct": _round(waw.get("water_wetness_pct")),
        "quality_flag": clc.get("quality_flag"),
    }


def _round(value):
    return None if value is None else round(float(value), 2)


def process_jobs(jobs_path: Path, cache_dir: Path, output_path: Path) -> dict:
    jobs_payload = json.loads(jobs_path.read_text(encoding="utf-8"))
    manifest = load_manifest(cache_dir)
    clc_path = cache_dir / "clc2018_pilot.geojson"
    clc_features = load_clc_features(clc_path) if clc_path.exists() else []
    tcd = optional_raster(cache_dir / "hrl_tcd_2018_pilot.tif")
    imd = optional_raster(cache_dir / "hrl_imd_2018_pilot.tif")
    waw = optional_raster(cache_dir / "hrl_waw_2018_pilot.tif")
    fty = optional_raster(cache_dir / "hrl_fty_2018_pilot.tif")
    source_meta = manifest.get("products") or {}
    calculated_at = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    records = []
    qa = []
    jobs = jobs_payload.get("jobs") or []
    missing_products = []
    if not clc_features:
        missing_products.append("clc2018")
    if tcd is None:
        missing_products.append("hrl_tcd_2018")
    if imd is None:
        missing_products.append("hrl_imd_2018")
    if waw is None:
        missing_products.append("hrl_waw_2018")
    if fty is None:
        missing_products.append("hrl_fty_2018")

    for index, job in enumerate(jobs, start=1):
        print(f"job {index}/{len(jobs)} {job.get('settlement_slug')}", flush=True)
        geometry = job.get("geometry")
        if not geometry:
            records.append({
                "settlement_id": job.get("settlement_id"),
                "boundary_id": job.get("boundary_id"),
                "municipality_slug": job.get("municipality_slug"),
                "settlement_slug": job.get("settlement_slug"),
                "status": "unavailable",
                "quality_flag": "unavailable",
                "profiles": [],
            })
            continue
        profiles = process_settlement(
            geometry,
            clc_features=clc_features or None,
            tcd=tcd,
            imd=imd,
            waw=waw,
            fty=fty,
            source_meta=source_meta,
            boundary_id=job.get("boundary_id"),
        )
        for profile in profiles:
            profile["calculated_at"] = calculated_at
        usable = any(profile.get("quality_flag") in {"ok", "high_nodata"} for profile in profiles)
        records.append({
            "settlement_id": job.get("settlement_id"),
            "boundary_id": job.get("boundary_id"),
            "municipality_slug": job.get("municipality_slug"),
            "settlement_slug": job.get("settlement_slug"),
            "settlement_name": job.get("settlement_name"),
            "status": "ok" if usable else "unavailable",
            "quality_flag": (profiles[0].get("quality_flag") if profiles else "unavailable"),
            "algorithm_version": ALGORITHM_VERSION,
            "profiles": profiles,
        })
        qa.append(qa_row(job, profiles))

    document = {
        "algorithm_version": ALGORITHM_VERSION,
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "processing_crs": "EPSG:3035",
        "clc_method": "vector_intersection",
        "hrl_method": "fractional_pixel_area_intersection",
        "missing_products": missing_products,
        "source": source_meta,
        "records": records,
        "qa": qa,
    }
    output_path.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(document, ensure_ascii=False, indent=2) + "\n"
    document["artifact_sha256"] = hashlib.sha256(text.encode("utf-8")).hexdigest()
    output_path.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    qa_path = output_path.with_suffix(".qa.csv")
    if qa:
        with qa_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=list(qa[0].keys()))
            writer.writeheader()
            writer.writerows(qa)
    return document


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--jobs", required=True)
    parser.add_argument("--cache-dir", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    process_jobs(Path(args.jobs), Path(args.cache_dir), Path(args.output))
    print("wrote", args.output, flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
