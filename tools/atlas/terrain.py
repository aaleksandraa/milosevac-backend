"""Offline DEM statistics for settlement polygons.

Cell inclusion: a DEM pixel is counted when its centre lies inside the polygon
(shapely coverage, including boundary). The same rule is used for every settlement.

Slope uses Horn's 3x3 formula on the DEM grid (degrees). Pixels without a full
valid 3x3 neighbourhood are excluded from slope, not from elevation.

Nodata is taken from the GeoTIFF/sidecar tag. Elevation 0 is a valid value.
"""

from __future__ import annotations

import json
import math
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import tifffile
from shapely.geometry import Point, shape
from shapely.prepared import prep

ALGORITHM_VERSION = "m4.1"
CELL_INCLUSION = "pixel_center"
MIN_SAMPLE_FOR_SLOPE = 9
MIN_SAMPLE_OK = 9
HIGH_NODATA_FRACTION = 0.5
DEFAULT_NODATA = -32767.0


@dataclass
class GeoRaster:
    path: Path
    data: np.ndarray
    west: float
    north: float
    xres: float
    yres: float  # negative when north-up
    nodata: float | None

    @property
    def height(self) -> int:
        return int(self.data.shape[0])

    @property
    def width(self) -> int:
        return int(self.data.shape[1])

    def east(self) -> float:
        return self.west + self.width * self.xres

    def south(self) -> float:
        return self.north + self.height * self.yres

    def contains_lonlat(self, lon: float, lat: float) -> bool:
        return self.west <= lon < self.east() and self.south() < lat <= self.north

    def pixel_centre(self, row: int, col: int) -> tuple[float, float]:
        lon = self.west + (col + 0.5) * self.xres
        lat = self.north + (row + 0.5) * self.yres
        return lon, lat

    def is_nodata(self, value: float) -> bool:
        if not np.isfinite(value):
            return True
        if self.nodata is None:
            return False
        return math.isclose(float(value), float(self.nodata), rel_tol=0.0, abs_tol=1e-6)

    def window_for_bounds(self, minx: float, miny: float, maxx: float, maxy: float) -> tuple[int, int, int, int]:
        col0 = int(math.floor((minx - self.west) / self.xres)) - 1
        col1 = int(math.ceil((maxx - self.west) / self.xres)) + 1
        row0 = int(math.floor((maxy - self.north) / self.yres)) - 1
        row1 = int(math.ceil((miny - self.north) / self.yres)) + 1
        col0 = max(0, col0)
        row0 = max(0, row0)
        col1 = min(self.width, col1)
        row1 = min(self.height, row1)
        return row0, row1, col0, col1


def load_raster(path: Path) -> GeoRaster:
    sidecar = path.with_suffix(path.suffix + ".meta.json")
    if not sidecar.exists():
        sidecar = path.with_suffix(".meta.json")
    with tifffile.TiffFile(path) as tif:
        page = tif.pages[0]
        data = np.asarray(page.asarray(), dtype=np.float64)
        west, north, xres, yres, nodata = _georef_from_tiff(page)
    if sidecar.exists():
        meta = json.loads(sidecar.read_text(encoding="utf-8"))
        west = float(meta["west"])
        north = float(meta["north"])
        xres = float(meta["xres"])
        yres = float(meta["yres"])
        nodata = meta.get("nodata", nodata)
    if data.ndim == 3:
        data = data[:, :, 0]
    return GeoRaster(path=path, data=data, west=west, north=north, xres=xres, yres=yres, nodata=nodata)


def _georef_from_tiff(page) -> tuple[float, float, float, float, float | None]:
    nodata = None
    tags = page.tags
    if "GDAL_NODATA" in tags:
        try:
            nodata = float(str(tags["GDAL_NODATA"].value).split("|")[0])
        except (TypeError, ValueError):
            nodata = None
    if "ModelPixelScaleTag" in tags and "ModelTiepointTag" in tags:
        scale = tags["ModelPixelScaleTag"].value
        tie = tags["ModelTiepointTag"].value
        xres = float(scale[0])
        yres = -abs(float(scale[1]))
        west = float(tie[3])
        north = float(tie[4])
        return west, north, xres, yres, nodata
    return 0.0, 0.0, 1.0, -1.0, nodata


def write_test_raster(path: Path, data: np.ndarray, west: float, north: float, xres: float, yres: float, nodata: float | None) -> None:
    path = Path(path)
    tifffile.imwrite(path, data.astype(np.float32))
    meta = {"west": west, "north": north, "xres": xres, "yres": yres, "nodata": nodata}
    path.with_suffix(".meta.json").write_text(json.dumps(meta), encoding="utf-8")


def horn_slope_deg(window: np.ndarray, dx_m: float, dy_m: float) -> float | None:
    if window.shape != (3, 3) or not np.isfinite(window).all():
        return None
    a, b, c = window[0]
    d, e, f = window[1]
    g, h, i = window[2]
    zx = ((c + 2 * f + i) - (a + 2 * d + g)) / (8.0 * dx_m)
    zy = ((g + 2 * h + i) - (a + 2 * b + c)) / (8.0 * dy_m)
    return math.degrees(math.atan(math.sqrt(zx * zx + zy * zy)))


def quality_flag(sample_count: int, valid_count: int, nodata_count: int, has_mean: bool) -> str:
    if valid_count <= 0 or not has_mean:
        return "unavailable"
    if sample_count > 0 and (nodata_count / sample_count) > HIGH_NODATA_FRACTION:
        return "high_nodata"
    if valid_count < MIN_SAMPLE_OK:
        return "low_sample"
    return "ok"


def process_polygon(geometry: dict, rasters: list[GeoRaster]) -> dict:
    polygon = shape(geometry)
    if polygon.is_empty:
        return _empty_result()
    prepared = prep(polygon)
    minx, miny, maxx, maxy = polygon.bounds
    elevations: list[float] = []
    slopes: list[float] = []
    min_pos = None
    max_pos = None
    sample_count = 0
    nodata_count = 0

    for raster in rasters:
        row0, row1, col0, col1 = raster.window_for_bounds(minx, miny, maxx, maxy)
        mean_lat = (miny + maxy) / 2.0
        dx_m = abs(raster.xres) * 111132.954 * math.cos(math.radians(mean_lat))
        dy_m = abs(raster.yres) * 111132.954
        for row in range(row0, row1):
            for col in range(col0, col1):
                lon, lat = raster.pixel_centre(row, col)
                if not prepared.covers(Point(lon, lat)):
                    continue
                sample_count += 1
                value = float(raster.data[row, col])
                if raster.is_nodata(value):
                    nodata_count += 1
                    continue
                elevations.append(value)
                if min_pos is None or value < min_pos[0]:
                    min_pos = (value, lat, lon)
                if max_pos is None or value > max_pos[0]:
                    max_pos = (value, lat, lon)
                if 0 < row < raster.height - 1 and 0 < col < raster.width - 1:
                    window = raster.data[row - 1:row + 2, col - 1:col + 2]
                    if window.shape == (3, 3) and not any(raster.is_nodata(float(v)) for v in window.ravel()):
                        slope = horn_slope_deg(window, dx_m, dy_m)
                        if slope is not None:
                            slopes.append(slope)

    valid = len(elevations)
    arr = np.array(elevations, dtype=np.float64) if elevations else np.array([], dtype=np.float64)
    has_stats = valid > 0
    result = {
        "elevation_min_m": round(float(arr.min()), 1) if has_stats else None,
        "elevation_max_m": round(float(arr.max()), 1) if has_stats else None,
        "elevation_mean_m": round(float(arr.mean()), 1) if has_stats else None,
        "elevation_median_m": round(float(np.median(arr)), 1) if has_stats else None,
        "elevation_range_m": round(float(arr.max() - arr.min()), 1) if has_stats else None,
        "slope_mean_deg": round(float(np.mean(slopes)), 2) if len(slopes) >= MIN_SAMPLE_FOR_SLOPE else None,
        "slope_median_deg": round(float(np.median(slopes)), 2) if len(slopes) >= MIN_SAMPLE_FOR_SLOPE else None,
        "slope_max_deg": round(float(np.max(slopes)), 2) if len(slopes) >= MIN_SAMPLE_FOR_SLOPE else None,
        "elevation_min_lat": round(min_pos[1], 7) if min_pos else None,
        "elevation_min_lng": round(min_pos[2], 7) if min_pos else None,
        "elevation_max_lat": round(max_pos[1], 7) if max_pos else None,
        "elevation_max_lng": round(max_pos[2], 7) if max_pos else None,
        "sample_count": sample_count,
        "valid_cell_count": valid,
        "nodata_cell_count": nodata_count,
        "quality_flag": quality_flag(sample_count, valid, nodata_count, has_stats),
        "algorithm_version": ALGORITHM_VERSION,
        "metadata": {
            "cell_inclusion": CELL_INCLUSION,
            "slope_algorithm": "horn_3x3_degrees",
            "slope_window": 3,
            "edge_handling": "skip_incomplete_3x3",
            "nodata_is_not_zero": True,
        },
    }
    return result


def _empty_result() -> dict:
    return {
        "elevation_min_m": None,
        "elevation_max_m": None,
        "elevation_mean_m": None,
        "elevation_median_m": None,
        "elevation_range_m": None,
        "slope_mean_deg": None,
        "slope_median_deg": None,
        "slope_max_deg": None,
        "elevation_min_lat": None,
        "elevation_min_lng": None,
        "elevation_max_lat": None,
        "elevation_max_lng": None,
        "sample_count": 0,
        "valid_cell_count": 0,
        "nodata_cell_count": 0,
        "quality_flag": "unavailable",
        "algorithm_version": ALGORITHM_VERSION,
        "metadata": {
            "cell_inclusion": CELL_INCLUSION,
            "slope_algorithm": "horn_3x3_degrees",
            "slope_window": 3,
            "edge_handling": "skip_incomplete_3x3",
            "nodata_is_not_zero": True,
        },
    }


def load_rasters(dem_dir: Path) -> list[GeoRaster]:
    rasters = []
    for path in sorted(dem_dir.glob("*.tif")):
        rasters.append(load_raster(path))
    for path in sorted(dem_dir.glob("*.tiff")):
        rasters.append(load_raster(path))
    return rasters


def process_jobs(jobs_path: Path, dem_dir: Path, output_path: Path, dem_meta: dict) -> dict:
    payload = json.loads(jobs_path.read_text(encoding="utf-8"))
    rasters = load_rasters(dem_dir)
    if not rasters:
        raise SystemExit(f"No DEM GeoTIFF files in {dem_dir}")
    records = []
    for job in payload.get("jobs", []):
        stats = process_polygon(job["geometry"], rasters)
        records.append({
            "settlement_id": job["settlement_id"],
            "boundary_id": job["boundary_id"],
            "municipality_slug": job.get("municipality_slug"),
            "settlement_slug": job.get("settlement_slug"),
            "source_feature_id": job.get("source_feature_id"),
            "boundary_version": job.get("boundary_version"),
            "dem_source": dem_meta.get("dem_source", "Copernicus DEM GLO-30 Public"),
            "dem_version": dem_meta.get("dem_version", "2021_1"),
            "dem_resolution_m": dem_meta.get("dem_resolution_m", 30),
            "calculated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            **stats,
        })
    document = {
        "source": {
            "provider": "European Space Agency / European Commission",
            "url": "https://spacedata.copernicus.eu/collections/copernicus-digital-elevation-model",
            "license": "Copernicus DEM GLO-30 Public licence",
            "dataset_version": dem_meta.get("dem_version", "2021_1"),
            "retrieval_date": dem_meta.get("retrieval_date", "2026-09-02"),
        },
        "records": records,
    }
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return document
