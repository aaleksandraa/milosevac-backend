"""Sequential Open-Meteo archive fetch for unique ERA5 / ERA5-Land grid cells.

Operator/import only. Never called from public HTTP requests.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from climate_core import (  # noqa: E402
    ERA5_LAND_STEP,
    ERA5_STEP,
    HISTORICAL_START,
    TIMEZONE_NAME,
    cells_for_polygon,
    geometry_from_geojson,
    last_complete_year,
)

DEFAULT_ARCHIVE_URL = "https://archive-api.open-meteo.com/v1/archive"
DEFAULT_SLEEP_MS = 1500
MAX_ATTEMPTS = 4


def ssl_context() -> ssl.SSLContext:
    try:
        import certifi

        return ssl.create_default_context(cafile=certifi.where())
    except Exception:
        return ssl.create_default_context()


def load_jobs(path: Path) -> dict:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload.get("jobs"), list):
        raise ValueError("jobs JSON mora imati listu jobs")
    return payload


def unique_cells(jobs: list[dict]) -> dict[str, dict]:
    cells: dict[str, dict] = {}
    for job in jobs:
        geometry = geometry_from_geojson(job["geometry"])
        for item in cells_for_polygon(geometry, "era5_land", ERA5_LAND_STEP):
            cells[item.cell.key] = {
                "dataset": "era5_land",
                "model": "era5_land",
                "lat": item.cell.lat,
                "lon": item.cell.lon,
                "daily": "temperature_2m_mean,temperature_2m_min,temperature_2m_max",
            }
        for item in cells_for_polygon(geometry, "era5", ERA5_STEP):
            cells[item.cell.key] = {
                "dataset": "era5",
                "model": "era5",
                "lat": item.cell.lat,
                "lon": item.cell.lon,
                "daily": "precipitation_sum",
            }
    return cells


def decade_windows(start: date, end: date, span_years: int = 24) -> list[tuple[date, date]]:
    """Inclusive calendar windows. Default 25-year spans to limit Open-Meteo request count."""
    windows = []
    cursor = start
    while cursor <= end:
        span_end = date(min(cursor.year + span_years, end.year), 12, 31)
        if span_end > end:
            span_end = end
        windows.append((cursor, span_end))
        cursor = date(span_end.year + 1, 1, 1)
    return windows


def request_json(url: str, timeout: int, retries: int, sleep_s: float) -> dict:
    last_error: Exception | None = None
    headers = {"User-Agent": "milo-evac-settlement-atlas/m7.1 (climate normals; operator fetch)"}
    for attempt in range(retries):
        try:
            request = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(request, timeout=timeout, context=ssl_context()) as response:
                payload = json.loads(response.read().decode("utf-8"))
            if not isinstance(payload, dict):
                raise ValueError("Open-Meteo response nije JSON objekat")
            if "error" in payload:
                raise ValueError(str(payload.get("reason") or payload["error"]))
            return payload
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, ValueError) as exc:
            last_error = exc
            status = getattr(exc, "code", None)
            retryable = status in (None, 429, 500, 502, 503, 504) or isinstance(exc, (TimeoutError, urllib.error.URLError))
            if not retryable or attempt == retries - 1:
                break
            wait = sleep_s * (attempt + 1)
            if status == 429:
                wait = 180.0
            print(f"retry {attempt + 1}/{retries} status={status} wait={wait:.0f}s", flush=True)
            time.sleep(wait)
    raise RuntimeError(f"Open-Meteo fetch failed: {last_error}")


def merge_daily(chunks: list[dict]) -> dict:
    merged = {
        "time": [],
        "temperature_2m_mean": [],
        "temperature_2m_min": [],
        "temperature_2m_max": [],
        "precipitation_sum": [],
    }
    seen: set[str] = set()
    for chunk in chunks:
        daily = chunk.get("daily") or {}
        times = daily.get("time") or []
        for index, day in enumerate(times):
            if day in seen:
                continue
            seen.add(day)
            merged["time"].append(day)
            for key in ("temperature_2m_mean", "temperature_2m_min", "temperature_2m_max", "precipitation_sum"):
                values = daily.get(key)
                merged[key].append(values[index] if values and index < len(values) else None)
    meta = chunks[-1] if chunks else {}
    return {
        "latitude": meta.get("latitude"),
        "longitude": meta.get("longitude"),
        "timezone": meta.get("timezone"),
        "utc_offset_seconds": meta.get("utc_offset_seconds"),
        "daily_units": meta.get("daily_units"),
        "daily": merged,
    }


def fetch_cell(
    cell: dict,
    *,
    archive_url: str,
    start: date,
    end: date,
    timeout: int,
    retries: int,
    sleep_s: float,
    chunk_dir: Path | None = None,
) -> dict:
    chunks = []
    if chunk_dir is not None:
        chunk_dir.mkdir(parents=True, exist_ok=True)
    for window_start, window_end in decade_windows(start, end):
        chunk_path = None
        if chunk_dir is not None:
            chunk_path = chunk_dir / f"{cell['dataset']}_{cell['lat']:.2f}_{cell['lon']:.2f}_{window_start.isoformat()}_{window_end.isoformat()}.json"
        if chunk_path is not None and chunk_path.exists():
            chunks.append(json.loads(chunk_path.read_text(encoding="utf-8")))
            print("cached-chunk", chunk_path.name, flush=True)
            continue
        query = urllib.parse.urlencode(
            {
                "latitude": f"{cell['lat']:.4f}",
                "longitude": f"{cell['lon']:.4f}",
                "start_date": window_start.isoformat(),
                "end_date": window_end.isoformat(),
                "daily": cell["daily"],
                "timezone": TIMEZONE_NAME,
                "models": cell["model"],
            }
        )
        url = f"{archive_url}?{query}"
        payload = request_json(url, timeout=timeout, retries=retries, sleep_s=sleep_s)
        if chunk_path is not None:
            chunk_path.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
        chunks.append(payload)
        if sleep_s:
            time.sleep(sleep_s)
    body = merge_daily(chunks)
    body["query"] = {
        "model": cell["model"],
        "dataset": cell["dataset"],
        "lat": cell["lat"],
        "lon": cell["lon"],
        "start": start.isoformat(),
        "end": end.isoformat(),
        "timezone": TIMEZONE_NAME,
        "archive_url": archive_url,
    }
    return body


def cell_filename(cell: dict) -> str:
    return f"{cell['dataset']}_{cell['lat']:.2f}_{cell['lon']:.2f}.json"


def main() -> int:
    parser = argparse.ArgumentParser(description="Fetch unique ERA5/ERA5-Land cells for atlas climate jobs")
    parser.add_argument("--jobs", required=True)
    parser.add_argument("--cache-dir", required=True)
    parser.add_argument("--archive-url", default=DEFAULT_ARCHIVE_URL)
    parser.add_argument("--timeout", type=int, default=90)
    parser.add_argument("--sleep-ms", type=int, default=2500)
    parser.add_argument("--retries", type=int, default=4)
    parser.add_argument("--historical-end-year", type=int, default=None)
    args = parser.parse_args()

    jobs_payload = load_jobs(Path(args.jobs))
    cells = unique_cells(jobs_payload["jobs"])
    cache_dir = Path(args.cache_dir)
    cache_dir.mkdir(parents=True, exist_ok=True)
    end = date(args.historical_end_year or last_complete_year(), 12, 31)
    sleep_s = max(args.sleep_ms, 0) / 1000.0
    manifest = {
        "retrieved_at": date.today().isoformat(),
        "archive_url": args.archive_url,
        "start": HISTORICAL_START.isoformat(),
        "end": end.isoformat(),
        "timezone": TIMEZONE_NAME,
        "cells": [],
    }
    for key in sorted(cells):
        cell = cells[key]
        path = cache_dir / cell_filename(cell)
        if path.exists():
            raw = path.read_bytes()
            print("cached", path.name, flush=True)
        else:
            body = fetch_cell(
                cell,
                archive_url=args.archive_url.rstrip("/"),
                start=HISTORICAL_START,
                end=end,
                timeout=args.timeout,
                retries=max(1, args.retries),
                sleep_s=sleep_s,
                chunk_dir=cache_dir / "chunks",
            )
            raw = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
            path.write_bytes(raw)
            print("wrote", path, flush=True)
        digest = hashlib.sha256(raw).hexdigest()
        manifest["cells"].append(
            {
                "key": key,
                "file": path.name,
                "sha256": digest,
                "dataset": cell["dataset"],
                "lat": cell["lat"],
                "lon": cell["lon"],
            }
        )
    manifest_path = cache_dir / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("cells", len(cells))
    print("manifest", manifest_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
