"""Download CLC vector clip and HRL rasters for the Modriča+Šamac pilot.

One regional request, not one request per settlement. Rasters stay in
tools/atlas/cache/landcover/ (gitignored). Manifests with hashes are written
beside the files and copied to docs.

Usage (from backend/):
  python tools/atlas/landcover/fetch_landcover.py --pilot
"""

from __future__ import annotations

import argparse
import hashlib
import json
import ssl
import sys
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

import certifi
from pyproj import Transformer

CLC_QUERY = "https://image.discomap.eea.europa.eu/arcgis/rest/services/Corine/CLC2018_LAEA/MapServer/0/query"
HRL_EXPORT = "https://image.discomap.eea.europa.eu/arcgis/rest/services/GioLandPublic/{service}/ImageServer/exportImage"

PRODUCTS = {
    "clc2018": {
        "kind": "vector",
        "title": "CORINE Land Cover 2018 (vector, EEA DISCOMAP CLC2018_LAEA)",
        "provider": "European Environment Agency / Copernicus Land Monitoring Service",
        "reference_year": 2018,
        "doi": "https://doi.org/10.2909/71c95a07-e296-44fc-b22b-415f42acfdf0",
        "catalogue": "https://land.copernicus.eu/en/products/corine-land-cover/clc2018",
        "version": "v2020_20u1",
        "resolution_m": None,
        "crs": "EPSG:3035",
        "licence": "Copernicus Land Monitoring Service data policy / EEA standard re-use",
        "retrieval_url": CLC_QUERY,
        "thematic": "44 CLC classes, MMU 25 ha, minimum mapping width 100 m. Generalized land-cover inventory, not 10 m parcels.",
    },
    "hrl_tcd_2018": {
        "kind": "raster",
        "service": "HRL_TreeCoverDensity_2018",
        "title": "Copernicus HRL Tree Cover Density 2018",
        "provider": "European Environment Agency / Copernicus Land Monitoring Service",
        "reference_year": 2018,
        "doi": "https://doi.org/10.2909/e677441e-fb94-431c-b4f9-304f10e4dfd8",
        "catalogue": "https://sdi.eea.europa.eu/catalogue/srv/api/records/486f77da-d605-423e-93a9-680760ab6791",
        "version": "2018",
        "resolution_m": 10,
        "crs": "EPSG:3035",
        "licence": "Copernicus Land Monitoring Service data policy",
        "nodata": 255,
        "thematic": "Tree cover density 0–100 (crown cover). Not a forest-area mask. 0 is valid; 255 is nodata.",
    },
    "hrl_imd_2018": {
        "kind": "raster",
        "service": "HRL_ImperviousnessDensity_2018",
        "title": "Copernicus HRL Imperviousness Density 2018",
        "provider": "European Environment Agency / Copernicus Land Monitoring Service",
        "reference_year": 2018,
        "doi": "https://doi.org/10.2909/3bf542bd-eebd-4d73-b53c-a0243f2ed862",
        "catalogue": "https://land.copernicus.eu/en/products/high-resolution-layer-imperviousness/imperviousness-density-2018",
        "version": "2018",
        "resolution_m": 10,
        "crs": "EPSG:3035",
        "licence": "Copernicus Land Monitoring Service data policy",
        "nodata": 255,
        "thematic": "Imperviousness density 0–100 (sealing). Not a binary built-up footprint. 0 is valid; 255 is nodata.",
    },
    "hrl_waw_2018": {
        "kind": "raster",
        "service": "HRL_WaterWetness_2018",
        "title": "Copernicus HRL Water and Wetness 2018",
        "provider": "European Environment Agency / Copernicus Land Monitoring Service",
        "reference_year": 2018,
        "doi": "https://sdi.eea.europa.eu/catalogue/srv/api/records/0dbafef2-b481-4993-8d7e-63ad74934559",
        "catalogue": "https://land.copernicus.eu/en/products/high-resolution-layer-water-and-wetness/water-and-wetness-2018",
        "version": "2018",
        "resolution_m": 10,
        "crs": "EPSG:3035",
        "licence": "Copernicus Land Monitoring Service data policy",
        "nodata": 255,
        "thematic": "Classified water/wetness: 0 dry, 1 permanent water, 2 temporary water, 3 permanent wet, 4 temporary wet, 254 unclassifiable, 255 nodata. Not a river network or flood zone.",
    },
    "hrl_fty_2018": {
        "kind": "raster",
        "service": "HRL_ForestType_2018",
        "title": "Copernicus HRL Forest Type 2018",
        "provider": "European Environment Agency / Copernicus Land Monitoring Service",
        "reference_year": 2018,
        "doi": "https://sdi.eea.europa.eu/catalogue/srv/api/records/59b0620c-7bb4-4c82-b3ce-f16715573137",
        "catalogue": "https://land.copernicus.eu/en/products/high-resolution-layer-forest-type/forest-type-2018",
        "version": "2018",
        "resolution_m": 10,
        "crs": "EPSG:3035",
        "licence": "Copernicus Land Monitoring Service data policy",
        "nodata": 255,
        "thematic": "FAO-like forest mask after agricultural/urban tree removal. 0 non-forest, 1 broadleaved, 2 coniferous. Distinct from Tree Cover Density.",
    },
}

# Padded Modriča + Šamac envelope in EPSG:3035 (ETRS89-LAEA).
PILOT_BBOX_3035 = (4_960_000.0, 2_445_000.0, 5_010_000.0, 2_495_000.0)
MAX_EXPORT_HEIGHT = 4000
PIXEL_M = 10.0


def ssl_context() -> ssl.SSLContext:
    return ssl.create_default_context(cafile=certifi.where())


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def http_get(url: str, dest: Path | None = None) -> bytes:
    request = urllib.request.Request(url, headers={"User-Agent": "milosevac-atlas-m10/1.0"})
    with urllib.request.urlopen(request, context=ssl_context(), timeout=180) as response:
        data = response.read()
    if dest is not None:
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(data)
    return data


def fetch_clc(cache_dir: Path, bbox: tuple[float, float, float, float]) -> Path:
    xmin, ymin, xmax, ymax = bbox
    features = []
    offset = 0
    while True:
        params = {
            "f": "geojson",
            "where": "1=1",
            "outFields": "Code_18,ID,Area_Ha",
            "returnGeometry": "true",
            "geometry": f"{xmin},{ymin},{xmax},{ymax}",
            "geometryType": "esriGeometryEnvelope",
            "inSR": "3035",
            "spatialRel": "esriSpatialRelIntersects",
            "outSR": "3035",
            "resultOffset": str(offset),
            "resultRecordCount": "1000",
        }
        url = CLC_QUERY + "?" + urllib.parse.urlencode(params)
        print(f"CLC query offset={offset}", flush=True)
        payload = json.loads(http_get(url).decode("utf-8"))
        batch = payload.get("features") or []
        features.extend(batch)
        if payload.get("exceededTransferLimit") or len(batch) == 1000:
            offset += len(batch)
            if not batch:
                break
            continue
        break
    dest = cache_dir / "clc2018_pilot.geojson"
    dest.parent.mkdir(parents=True, exist_ok=True)
    document = {
        "type": "FeatureCollection",
        "crs": {"type": "name", "properties": {"name": "EPSG:3035"}},
        "bbox": list(bbox),
        "features": features,
    }
    dest.write_text(json.dumps(document), encoding="utf-8")
    print(f"CLC features: {len(features)} -> {dest}", flush=True)
    return dest


def _tile_boxes(bbox: tuple[float, float, float, float]) -> list[tuple[float, float, float, float]]:
    xmin, ymin, xmax, ymax = bbox
    height_m = ymax - ymin
    max_m = MAX_EXPORT_HEIGHT * PIXEL_M
    tiles = []
    y = ymin
    while y < ymax - 0.1:
        y1 = min(y + max_m, ymax)
        tiles.append((xmin, y, xmax, y1))
        y = y1
    if not tiles:
        tiles.append(bbox)
    print(f"HRL tiles: {len(tiles)} (span {height_m:.0f} m)", flush=True)
    return tiles


def fetch_hrl(cache_dir: Path, product_code: str, bbox: tuple[float, float, float, float]) -> Path:
    spec = PRODUCTS[product_code]
    dest = cache_dir / f"{product_code}_pilot.tif"
    tiles = _tile_boxes(bbox)
    if len(tiles) == 1:
        _export_tile(spec["service"], tiles[0], dest)
        return dest
    import numpy as np
    import tifffile

    arrays = []
    metas = []
    for index, tile in enumerate(tiles):
        part = cache_dir / f"{product_code}_tile{index}.tif"
        _export_tile(spec["service"], tile, part)
        with tifffile.TiffFile(part) as tif:
            arrays.append(np.asarray(tif.pages[0].asarray()))
        metas.append(json.loads(part.with_suffix(".tif.meta.json").read_text(encoding="utf-8")))
    stacked = np.vstack(list(reversed(arrays)))  # south tile first in loop; north is last
    # tiles are south→north; vstack south then north is correct if yres is negative and north is up.
    # First tile has smaller ymin (south). export origin is north of that tile.
    # Easier: write mosaic using first tile (northernmost if we reverse).
    # Loop is south to north. GeoTIFF north-up: northern tile is top rows.
    stacked = np.vstack(list(reversed(arrays)))
    north_meta = metas[-1]
    tifffile.imwrite(dest, stacked)
    sidecar = {
        "west": north_meta["west"],
        "north": north_meta["north"],
        "xres": north_meta["xres"],
        "yres": north_meta["yres"],
        "nodata": spec.get("nodata", 255),
        "crs": "EPSG:3035",
        "width": int(stacked.shape[1]),
        "height": int(stacked.shape[0]),
        "tiles": len(tiles),
    }
    dest.with_suffix(".tif.meta.json").write_text(json.dumps(sidecar, indent=2), encoding="utf-8")
    dest.with_suffix(".meta.json").write_text(json.dumps(sidecar, indent=2), encoding="utf-8")
    return dest


def _export_tile(service: str, bbox: tuple[float, float, float, float], dest: Path) -> None:
    xmin, ymin, xmax, ymax = bbox
    width = int(round((xmax - xmin) / PIXEL_M))
    height = int(round((ymax - ymin) / PIXEL_M))
    params = {
        "bbox": f"{xmin},{ymin},{xmax},{ymax}",
        "bboxSR": "3035",
        "imageSR": "3035",
        "size": f"{width},{height}",
        "format": "tiff",
        "pixelType": "U8",
        "interpolation": "RSP_NearestNeighbor",
        "f": "image",
    }
    url = HRL_EXPORT.format(service=service) + "?" + urllib.parse.urlencode(params)
    print(f"HRL export {service} {width}x{height}", flush=True)
    dest.parent.mkdir(parents=True, exist_ok=True)
    http_get(url, dest)
    sidecar = {
        "west": xmin,
        "north": ymax,
        "xres": PIXEL_M,
        "yres": -PIXEL_M,
        "nodata": 255,
        "crs": "EPSG:3035",
        "width": width,
        "height": height,
        "retrieval_url": url.split("?")[0],
    }
    dest.with_suffix(".tif.meta.json").write_text(json.dumps(sidecar, indent=2), encoding="utf-8")
    dest.with_suffix(".meta.json").write_text(json.dumps(sidecar, indent=2), encoding="utf-8")


def write_manifest(cache_dir: Path, files: dict[str, Path], bbox: tuple[float, float, float, float]) -> Path:
    retrieved = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    products = {}
    for code, path in files.items():
        spec = dict(PRODUCTS[code])
        spec.pop("kind", None)
        spec.pop("service", None)
        products[code] = {
            **spec,
            "file": path.name,
            "sha256": sha256_file(path),
            "bytes": path.stat().st_size,
            "retrieval_date": retrieved[:10],
            "retrieved_at": retrieved,
            "geographic_scope": "Modriča + Šamac pilot bbox, EPSG:3035",
            "bbox_epsg3035": list(bbox),
        }
    transformer = Transformer.from_crs("EPSG:3035", "EPSG:4326", always_xy=True)
    sw = transformer.transform(bbox[0], bbox[1])
    ne = transformer.transform(bbox[2], bbox[3])
    payload = {
        "algorithm_version": "m10.1",
        "retrieved_at": retrieved,
        "bbox_epsg3035": list(bbox),
        "bbox_epsg4326": [sw[0], sw[1], ne[0], ne[1]],
        "products": products,
        "notes": [
            "CLC is a generalized 25 ha inventory. Do not imply parcel-level precision.",
            "HRL Tree Cover Density is crown-cover density, not CLC forest area.",
            "HRL Imperviousness Density is sealing density, not a binary building footprint.",
            "HRL Water and Wetness classes are not a river network, flood zone, or catchment.",
            "Settlement composition is clipped to M4 OSM admin_level=9 boundaries.",
        ],
    }
    dest = cache_dir / "source_manifest.json"
    dest.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    committed = Path(__file__).resolve().parent / "source_manifest.json"
    committed.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return dest


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pilot", action="store_true")
    parser.add_argument("--cache-dir", default=None)
    parser.add_argument("--skip-clc", action="store_true")
    parser.add_argument("--skip-hrl", action="store_true")
    args = parser.parse_args()
    if not args.pilot:
        print("Navedite --pilot.", file=sys.stderr)
        return 2
    cache_dir = Path(args.cache_dir) if args.cache_dir else Path(__file__).resolve().parents[1] / "cache" / "landcover"
    cache_dir.mkdir(parents=True, exist_ok=True)
    bbox = PILOT_BBOX_3035
    files: dict[str, Path] = {}
    if not args.skip_clc:
        files["clc2018"] = fetch_clc(cache_dir, bbox)
    if not args.skip_hrl:
        for code in ("hrl_tcd_2018", "hrl_imd_2018", "hrl_waw_2018", "hrl_fty_2018"):
            files[code] = fetch_hrl(cache_dir, code, bbox)
    if files:
        write_manifest(cache_dir, files, bbox)
        print("manifest written", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
