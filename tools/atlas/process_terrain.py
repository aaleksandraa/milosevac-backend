"""CLI: python tools/atlas/process_terrain.py --jobs jobs.json --dem-dir dem/ --output stats.json"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from terrain import process_jobs


def main() -> int:
    parser = argparse.ArgumentParser(description="Compute DEM terrain stats for atlas settlement jobs")
    parser.add_argument("--jobs", required=True)
    parser.add_argument("--dem-dir", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--dem-source", default="Copernicus DEM GLO-30 Public")
    parser.add_argument("--dem-version", default="2021_1")
    parser.add_argument("--dem-resolution-m", type=int, default=30)
    parser.add_argument("--retrieval-date", default="2026-09-02")
    args = parser.parse_args()
    process_jobs(
        Path(args.jobs),
        Path(args.dem_dir),
        Path(args.output),
        {
            "dem_source": args.dem_source,
            "dem_version": args.dem_version,
            "dem_resolution_m": args.dem_resolution_m,
            "retrieval_date": args.retrieval_date,
        },
    )
    print("wrote", args.output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
