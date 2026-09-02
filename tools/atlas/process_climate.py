"""CLI: python tools/atlas/process_climate.py --jobs jobs.json --cache-dir cache/climate --output climate.json"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
from datetime import date, datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from climate_core import (  # noqa: E402
    ALGORITHM_VERSION,
    ERA5_LAND_STEP,
    ERA5_STEP,
    HISTORICAL_START,
    DailyValues,
    build_profile,
    cells_for_polygon,
    geometry_from_geojson,
    last_complete_year,
)


def _float_or_none(value) -> float | None:
    return None if value is None else float(value)


def parse_daily(payload: dict) -> list[DailyValues]:
    daily = payload.get("daily") or {}
    times = daily.get("time") or []
    tmean = daily.get("temperature_2m_mean") or []
    tmin = daily.get("temperature_2m_min") or []
    tmax = daily.get("temperature_2m_max") or []
    precip = daily.get("precipitation_sum") or []
    rows = []
    for index, day in enumerate(times):
        rows.append(
            DailyValues(
                time=date.fromisoformat(day[:10]),
                tmean=_float_or_none(tmean[index] if index < len(tmean) else None),
                tmin=_float_or_none(tmin[index] if index < len(tmin) else None),
                tmax=_float_or_none(tmax[index] if index < len(tmax) else None),
                precip_mm=_float_or_none(precip[index] if index < len(precip) else None),
            )
        )
    return rows


def load_cache(cache_dir: Path) -> dict[str, list[DailyValues]]:
    series: dict[str, list[DailyValues]] = {}
    for path in sorted(cache_dir.glob("*.json")):
        if path.name == "manifest.json":
            continue
        payload = json.loads(path.read_text(encoding="utf-8"))
        query = payload.get("query") or {}
        dataset = query.get("dataset") or ("era5_land" if "era5_land" in path.name else "era5")
        lat = float(query.get("lat") if query.get("lat") is not None else path.stem.split("_")[1])
        lon = float(query.get("lon") if query.get("lon") is not None else path.stem.split("_")[2])
        key = f"{dataset}:{lat:.2f}:{lon:.2f}"
        series[key] = parse_daily(payload)
    return series


def process_jobs(jobs_path: Path, cache_dir: Path, output_path: Path, source_meta: dict) -> dict:
    jobs_payload = json.loads(jobs_path.read_text(encoding="utf-8"))
    print("loading cache", cache_dir, flush=True)
    series = load_cache(cache_dir)
    print("cache cells", len(series), flush=True)
    historical_end = date(int(source_meta.get("historical_end_year") or last_complete_year()), 12, 31)
    calculated_at = datetime.now(timezone.utc).date().isoformat()
    records = []
    qa = []
    jobs = jobs_payload.get("jobs") or []
    for index, job in enumerate(jobs, start=1):
        print(f"job {index}/{len(jobs)} {job.get('settlement_slug')}", flush=True)
        geometry = geometry_from_geojson(job["geometry"])
        t_weights = cells_for_polygon(geometry, "era5_land", ERA5_LAND_STEP)
        p_weights = cells_for_polygon(geometry, "era5", ERA5_STEP)
        missing = [item.cell.key for item in t_weights + p_weights if item.cell.key not in series]
        if missing:
            profile = {
                "settlement_id": job["settlement_id"],
                "boundary_id": job.get("boundary_id"),
                "municipality_slug": job.get("municipality_slug"),
                "settlement_slug": job.get("settlement_slug"),
                "settlement_name": job.get("settlement_name"),
                "quality_flag": "insufficient_source",
                "status": "unavailable",
                "missing_cells": missing,
            }
        else:
            combined = {}
            for item in t_weights:
                combined[item.cell.key] = series[item.cell.key]
            for item in p_weights:
                combined[item.cell.key] = series[item.cell.key]
            from climate_core import weighted_daily_series

            daily = weighted_daily_series(t_weights, combined)
            precip_daily = weighted_daily_series(p_weights, combined)
            precip_by_day = {row.time: row.precip_mm for row in precip_daily}
            for row in daily:
                if row.time in precip_by_day:
                    row.precip_mm = precip_by_day[row.time]
            extra_days = [row for row in precip_daily if row.time not in {item.time for item in daily}]
            for row in extra_days:
                daily.append(row)
            daily.sort(key=lambda item: item.time)
            profile = build_profile(
                settlement_id=job["settlement_id"],
                boundary_id=job.get("boundary_id"),
                municipality_slug=job.get("municipality_slug"),
                settlement_slug=job.get("settlement_slug"),
                settlement_name=job.get("settlement_name"),
                boundary_version=job.get("boundary_version"),
                temperature_weights=t_weights,
                precipitation_weights=p_weights,
                series=daily,
                historical_end=historical_end,
                calculated_at=calculated_at,
            )
        records.append(profile)
        annual = profile.get("annual") or {}
        qa.append(
            {
                "municipality": profile.get("municipality_slug"),
                "settlement": profile.get("settlement_slug"),
                "boundary_id": profile.get("boundary_id"),
                "status": profile.get("status"),
                "spatial_method": profile.get("spatial_method"),
                "grid_cell_count": profile.get("grid_cell_count"),
                "annual_temperature_mean_c": annual.get("temperature_mean_c"),
                "annual_precipitation_mm": annual.get("precipitation_mm"),
                "warmest_month": annual.get("warmest_month"),
                "coldest_month": annual.get("coldest_month"),
                "quality_flag": profile.get("quality_flag"),
            }
        )

    raw = json.dumps({"records": records}, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    payload = {
        "algorithm_version": ALGORITHM_VERSION,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "source": {
            "provider": "Copernicus Climate Change Service / ECMWF",
            "temperature_dataset": "ERA5-Land",
            "precipitation_dataset": "ERA5",
            "temperature_doi": "10.24381/cds.e2161bac",
            "precipitation_doi": "10.24381/cds.adbb2d47",
            "licence": "Copernicus C3S/CAMS Licence",
            "retrieval": "Open-Meteo Historical Weather API (archive-api.open-meteo.com), models=era5_land (temperature) and models=era5 (precipitation)",
            "grid": "ERA5-Land 0.1°; ERA5 0.25°",
            "timezone": "Europe/Sarajevo",
            "normal_period": "1991-01-01/2020-12-31",
            "historical_period": f"{HISTORICAL_START.isoformat()}/{historical_end.isoformat()}",
            **source_meta,
        },
        "artifact_sha256": hashlib.sha256(raw).hexdigest(),
        "records": records,
        "qa": qa,
    }
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    qa_path = output_path.with_name(output_path.stem + "-qa.json")
    qa_path.write_text(json.dumps({"rows": qa}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return payload


def main() -> int:
    parser = argparse.ArgumentParser(description="Compute settlement climate profiles from cached ERA5 cells")
    parser.add_argument("--jobs", required=True)
    parser.add_argument("--cache-dir", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--retrieval-date", default=date.today().isoformat())
    parser.add_argument("--historical-end-year", type=int, default=None)
    args = parser.parse_args()
    payload = process_jobs(
        Path(args.jobs),
        Path(args.cache_dir),
        Path(args.output),
        {
            "retrieval_date": args.retrieval_date,
            "historical_end_year": args.historical_end_year or last_complete_year(),
        },
    )
    ok = sum(1 for row in payload["records"] if row.get("status") == "ok")
    print("wrote", args.output)
    print("profiles_ok", ok)
    print("profiles_total", len(payload["records"]))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
