"""Download Copernicus DEM GLO-30 COG tiles for the Modriča/Šamac pilot.

Does not commit rasters. Tiles are cached under tools/atlas/dem/ (gitignored).
"""

from __future__ import annotations

import ssl
import urllib.request
from pathlib import Path

USER_AGENT = "Milosevac Atlas M4 (milosevac.com)"
SSL = ssl._create_unverified_context()
OUT = Path(__file__).resolve().parent / "dem"

# GLO-30 public COG naming: Copernicus_DSM_COG_10 = 1 arc-second ~30 m
TILES = [
    "Copernicus_DSM_COG_10_N44_00_E018_00_DEM",
    "Copernicus_DSM_COG_10_N45_00_E018_00_DEM",
]
BASES = [
    "https://copernicus-dem-30m.s3.amazonaws.com",
    "https://copernicus-dem-30m.s3.eu-central-1.amazonaws.com",
]


def download(name: str, dest: Path) -> None:
    if dest.exists() and dest.stat().st_size > 1_000_000:
        print("exists", dest.name, dest.stat().st_size)
        return
    dest.parent.mkdir(parents=True, exist_ok=True)
    last_error = None
    for base in BASES:
        url = f"{base}/{name}/{name}.tif"
        print("GET", url)
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        try:
            with urllib.request.urlopen(req, timeout=300, context=SSL) as response:
                dest.write_bytes(response.read())
            print("saved", dest, dest.stat().st_size)
            return
        except Exception as exc:
            last_error = exc
            print("fail", exc)
    raise SystemExit(f"Could not download {name}: {last_error}")


def main() -> int:
    for name in TILES:
        download(name, OUT / f"{name}.tif")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
