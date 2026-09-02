"""Deterministic polygon/raster and polygon/polygon land-cover intersection.

CLC: vector intersection in EPSG:3035 (true polygon areas).
HRL: fractional pixel-area intersection in EPSG:3035. Interior pixels take the
full pixel area; boundary pixels use shapely intersection area. This is not
all-touched and is not pixel-centre inclusion.

Nodata is tracked separately and is never treated as water, forest, artificial,
or zero density.
"""

from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from pathlib import Path

import numpy as np
from shapely.geometry import box, shape
from shapely.prepared import prep
from shapely.strtree import STRtree

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from terrain import GeoRaster, load_raster, write_test_raster  # noqa: E402

from .clc_nomenclature import (  # noqa: E402
    ALGORITHM_VERSION,
    ATLAS_GROUP_LABELS,
    CLC_CLASSES,
    FTY_CLASSES,
    SHARE_SUM_FAIL_PP,
    WAW_CLASSES,
    atlas_group_for_clc,
    dominant_entry,
    group_shares_from_class_areas,
    normalize_clc_code,
    quality_from_coverage,
)
from .crs import area_km2, area_m2, to_3035  # noqa: E402

PROCESSING_CLC = "clc_vector_intersection_epsg3035"
PROCESSING_HRL = "hrl_fractional_pixel_area_epsg3035"
PIXEL_METHOD = "fractional_intersection"


def geometry_from_geojson(geojson: dict):
    return shape(geojson)


def load_clc_features(path: Path) -> list[tuple[object, str]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    features = payload.get("features") or payload.get("records") or []
    rows: list[tuple[object, str]] = []
    for feature in features:
        props = feature.get("properties") or {}
        code = normalize_clc_code(props.get("Code_18") or props.get("code") or props.get("CLC_CODE"))
        geom = feature.get("geometry")
        if code is None or geom is None:
            continue
        polygon = shape(geom)
        if polygon.is_empty:
            continue
        # Source CLC_LAEA is already EPSG:3035. Fixtures may be 4326 (lon/lat).
        if abs(polygon.bounds[0]) <= 180 and abs(polygon.bounds[1]) <= 90:
            polygon = to_3035(polygon)
        rows.append((polygon, code))
    return rows


def intersect_clc(geom_3035, clc_features: list[tuple[object, str]]) -> dict[str, float]:
    if geom_3035.is_empty or not clc_features:
        return {}
    geoms = [item[0] for item in clc_features]
    tree = STRtree(geoms)
    areas: dict[str, float] = defaultdict(float)
    for index in tree.query(geom_3035):
        other, code = clc_features[int(index)]
        if not geom_3035.intersects(other):
            continue
        inter = geom_3035.intersection(other)
        if inter.is_empty:
            continue
        areas[code] += float(inter.area)
    return dict(areas)


def _pixel_box(raster: GeoRaster, row: int, col: int):
    x0 = raster.west + col * raster.xres
    x1 = raster.west + (col + 1) * raster.xres
    y0 = raster.north + row * raster.yres
    y1 = raster.north + (row + 1) * raster.yres
    return box(min(x0, x1), min(y0, y1), max(x0, x1), max(y0, y1))


def _is_nodata(raster: GeoRaster, value: float, extra_nodata: set[float]) -> bool:
    if not np.isfinite(value):
        return True
    if value in extra_nodata:
        return True
    return raster.is_nodata(value)


def fractional_samples(
    geom_3035,
    raster: GeoRaster,
    extra_nodata: set[float] | None = None,
) -> tuple[list[tuple[float, float]], float, float, float]:
    """Return (value, weight_area_m2) samples plus total/valid/nodata areas."""
    extra = extra_nodata or set()
    if geom_3035.is_empty:
        return [], 0.0, 0.0, 0.0
    pixel_area = abs(raster.xres * raster.yres)
    prepared = prep(geom_3035)
    minx, miny, maxx, maxy = geom_3035.bounds
    row0, row1, col0, col1 = raster.window_for_bounds(minx, miny, maxx, maxy)
    samples: list[tuple[float, float]] = []
    valid_area = 0.0
    nodata_area = 0.0
    covered = 0.0
    for row in range(row0, row1):
        for col in range(col0, col1):
            pixel = _pixel_box(raster, row, col)
            if not prepared.intersects(pixel):
                continue
            if prepared.contains(pixel):
                weight = pixel_area
            else:
                inter = geom_3035.intersection(pixel)
                if inter.is_empty:
                    continue
                weight = float(inter.area)
            if weight <= 0:
                continue
            covered += weight
            value = float(raster.data[row, col])
            if _is_nodata(raster, value, extra):
                nodata_area += weight
                continue
            samples.append((value, weight))
            valid_area += weight
    total_area = area_m2(geom_3035)
    # Uncovered sliver (polygon outside raster) counts as nodata for coverage.
    outside = max(0.0, total_area - covered)
    nodata_area += outside
    return samples, total_area, valid_area, nodata_area


def categorical_zonal(geom_3035, raster: GeoRaster, extra_nodata: set[float] | None = None) -> dict[int, float]:
    samples, _, _, _ = fractional_samples(geom_3035, raster, extra_nodata)
    areas: dict[int, float] = defaultdict(float)
    for value, weight in samples:
        areas[int(round(value))] += weight
    return dict(areas)


def density_mean(geom_3035, raster: GeoRaster, extra_nodata: set[float] | None = None) -> tuple[float | None, float, float, float]:
    samples, total, valid, nodata = fractional_samples(geom_3035, raster, extra_nodata)
    if valid <= 0 or not samples:
        return None, total, valid, nodata
    weighted = sum(value * weight for value, weight in samples)
    return weighted / valid, total, valid, nodata


def clc_profile(geom_3035, class_areas_m2: dict[str, float], *, boundary_id: int | None, source_meta: dict) -> dict:
    total_m2 = area_m2(geom_3035)
    valid_m2 = sum(class_areas_m2.values())
    nodata_m2 = max(0.0, total_m2 - valid_m2)
    shares = {}
    classes = []
    for code in sorted(class_areas_m2):
        area = class_areas_m2[code]
        share = 0.0 if valid_m2 <= 0 else 100.0 * area / valid_m2
        info = CLC_CLASSES.get(code)
        classes.append({
            "class_code": code,
            "source_class_code": code,
            "source_class_label": info["label_bs"] if info else code,
            "source_class_label_en": info["label_en"] if info else code,
            "atlas_group": atlas_group_for_clc(code) if info else None,
            "area_km2": area / 1_000_000.0,
            "share_pct": share,
        })
        shares[code] = share
    group_shares = group_shares_from_class_areas(class_areas_m2, valid_m2)
    share_sum = sum(item["share_pct"] for item in classes)
    dominant = dominant_entry(class_areas_m2)
    dominant_group = None
    if group_shares:
        group_code = sorted(group_shares.items(), key=lambda item: (-item[1], item[0]))[0][0]
        if group_shares[group_code] > 0:
            dominant_group = {
                "code": group_code,
                "label": ATLAS_GROUP_LABELS[group_code]["bs"],
                "label_en": ATLAS_GROUP_LABELS[group_code]["en"],
                "share_pct": group_shares[group_code],
                "area_km2": (group_shares[group_code] / 100.0) * (valid_m2 / 1_000_000.0) if valid_m2 else 0.0,
            }
    quality = quality_from_coverage(valid_m2, total_m2, share_sum if classes else None)
    summary = {
        "landcover_artificial_pct": group_shares.get("artificial_surfaces", 0.0),
        "landcover_agricultural_pct": group_shares.get("agricultural", 0.0),
        "landcover_forest_pct": group_shares.get("forest", 0.0),
        "landcover_seminatural_pct": group_shares.get("shrub_herbaceous_or_seminatural", 0.0),
        "landcover_open_or_sparse_pct": group_shares.get("open_or_sparse", 0.0),
        "landcover_wetland_pct": group_shares.get("wetlands", 0.0),
        "landcover_water_pct": group_shares.get("water", 0.0),
        "dominant_class": None if dominant is None else {
            "code": dominant["code"],
            "label": dominant["label"],
            "atlas_group": dominant.get("atlas_group"),
            "area_km2": float(dominant["area_m2"]) / 1_000_000.0,
            "share_pct": 0.0 if valid_m2 <= 0 else 100.0 * float(dominant["area_m2"]) / valid_m2,
        },
        "dominant_group": dominant_group,
        "groups": group_shares,
    }
    return {
        "product_code": "clc2018",
        "reference_year": 2018,
        "algorithm_version": ALGORITHM_VERSION,
        "processing_method": PROCESSING_CLC,
        "source_resolution_m": None,
        "crs": "EPSG:3035",
        "total_analysis_area_km2": total_m2 / 1_000_000.0,
        "valid_area_km2": valid_m2 / 1_000_000.0,
        "nodata_area_km2": nodata_m2 / 1_000_000.0,
        "quality_flag": quality,
        "boundary_id": boundary_id,
        "summary": summary,
        "classes": classes,
        "metadata": {
            "share_sum_pct": share_sum,
            "share_sum_fail_pp": SHARE_SUM_FAIL_PP,
            "pixel_method": None,
            "generalized_inventory": True,
            "mmu_ha": 25,
            "minimum_mapping_width_m": 100,
            "source": source_meta,
        },
    }


def density_profile(
    geom_3035,
    raster: GeoRaster,
    *,
    product_code: str,
    metric_code: str,
    extra_nodata: set[float],
    source_meta: dict,
    boundary_id: int | None,
    reference_year: int = 2018,
    resolution_m: int = 10,
) -> dict:
    mean, total, valid, nodata = density_mean(geom_3035, raster, extra_nodata)
    quality = quality_from_coverage(valid, total)
    summary = {metric_code: None if mean is None else float(mean)}
    return {
        "product_code": product_code,
        "reference_year": reference_year,
        "algorithm_version": ALGORITHM_VERSION,
        "processing_method": PROCESSING_HRL,
        "source_resolution_m": resolution_m,
        "crs": "EPSG:3035",
        "total_analysis_area_km2": total / 1_000_000.0,
        "valid_area_km2": valid / 1_000_000.0,
        "nodata_area_km2": nodata / 1_000_000.0,
        "quality_flag": quality if mean is not None else "unavailable",
        "boundary_id": boundary_id,
        "summary": summary,
        "classes": [],
        "metadata": {
            "pixel_method": PIXEL_METHOD,
            "nodata_values": sorted(extra_nodata),
            "zero_is_valid": True,
            "density_range": [0, 100],
            "source": source_meta,
        },
    }


def classified_profile(
    geom_3035,
    raster: GeoRaster,
    *,
    product_code: str,
    class_info: dict[int, dict],
    extra_nodata: set[float],
    source_meta: dict,
    boundary_id: int | None,
    summary_fn,
    reference_year: int = 2018,
    resolution_m: int = 10,
) -> dict:
    areas = categorical_zonal(geom_3035, raster, extra_nodata)
    samples, total, valid, nodata = fractional_samples(geom_3035, raster, extra_nodata)
    # Recompute valid from class areas for consistency.
    valid = sum(areas.values())
    classes = []
    for code in sorted(areas):
        info = class_info.get(code, {"code": str(code), "label_bs": str(code), "label_en": str(code), "atlas_group": None})
        area = areas[code]
        share = 0.0 if valid <= 0 else 100.0 * area / valid
        classes.append({
            "class_code": str(code),
            "source_class_code": str(code),
            "source_class_label": info["label_bs"],
            "source_class_label_en": info["label_en"],
            "atlas_group": info.get("atlas_group"),
            "area_km2": area / 1_000_000.0,
            "share_pct": share,
            "pixel_count": None,
        })
    share_sum = sum(item["share_pct"] for item in classes)
    quality = quality_from_coverage(valid, total, share_sum if classes else None)
    summary = summary_fn(areas, valid)
    return {
        "product_code": product_code,
        "reference_year": reference_year,
        "algorithm_version": ALGORITHM_VERSION,
        "processing_method": PROCESSING_HRL,
        "source_resolution_m": resolution_m,
        "crs": "EPSG:3035",
        "total_analysis_area_km2": total / 1_000_000.0,
        "valid_area_km2": valid / 1_000_000.0,
        "nodata_area_km2": nodata / 1_000_000.0,
        "quality_flag": quality if valid > 0 else "unavailable",
        "boundary_id": boundary_id,
        "summary": summary,
        "classes": classes,
        "metadata": {
            "share_sum_pct": share_sum,
            "pixel_method": PIXEL_METHOD,
            "nodata_values": sorted(extra_nodata),
            "source": source_meta,
            "unused_sample_count": len(samples),
        },
    }


def fty_summary(areas: dict[int, float], valid: float) -> dict:
    forest = areas.get(1, 0.0) + areas.get(2, 0.0)
    return {
        "forest_area_pct": 0.0 if valid <= 0 else 100.0 * forest / valid,
        "broadleaved_forest_pct": 0.0 if valid <= 0 else 100.0 * areas.get(1, 0.0) / valid,
        "coniferous_forest_pct": 0.0 if valid <= 0 else 100.0 * areas.get(2, 0.0) / valid,
    }


def waw_summary(areas: dict[int, float], valid: float) -> dict:
    def share(code: int) -> float:
        return 0.0 if valid <= 0 else 100.0 * areas.get(code, 0.0) / valid

    return {
        "permanent_water_pct": share(1),
        "temporary_water_pct": share(2),
        "permanent_wet_pct": share(3),
        "temporary_wet_pct": share(4),
        "wet_area_pct": share(3) + share(4),
        "water_wetness_pct": share(1) + share(2) + share(3) + share(4),
    }


def process_settlement(
    geometry_geojson: dict,
    *,
    clc_features: list[tuple[object, str]] | None = None,
    tcd: GeoRaster | None = None,
    imd: GeoRaster | None = None,
    waw: GeoRaster | None = None,
    fty: GeoRaster | None = None,
    builtup: GeoRaster | None = None,
    source_meta: dict | None = None,
    boundary_id: int | None = None,
    nodata_hrl: float = 255.0,
) -> list[dict]:
    geom = to_3035(geometry_from_geojson(geometry_geojson))
    meta = source_meta or {}
    extra = {nodata_hrl, 254.0}
    profiles = []
    if clc_features is not None:
        areas = intersect_clc(geom, clc_features)
        profiles.append(clc_profile(geom, areas, boundary_id=boundary_id, source_meta=meta.get("clc2018") or meta))
    if tcd is not None:
        profiles.append(density_profile(
            geom, tcd, product_code="hrl_tcd_2018", metric_code="tree_cover_mean_pct",
            extra_nodata=extra, source_meta=meta.get("hrl_tcd_2018") or {}, boundary_id=boundary_id,
        ))
    if imd is not None:
        profiles.append(density_profile(
            geom, imd, product_code="hrl_imd_2018", metric_code="imperviousness_mean_pct",
            extra_nodata=extra, source_meta=meta.get("hrl_imd_2018") or {}, boundary_id=boundary_id,
        ))
    if fty is not None:
        profiles.append(classified_profile(
            geom, fty, product_code="hrl_fty_2018", class_info=FTY_CLASSES, extra_nodata=extra,
            source_meta=meta.get("hrl_fty_2018") or {}, boundary_id=boundary_id, summary_fn=fty_summary,
        ))
    if waw is not None:
        profiles.append(classified_profile(
            geom, waw, product_code="hrl_waw_2018", class_info=WAW_CLASSES, extra_nodata=extra,
            source_meta=meta.get("hrl_waw_2018") or {}, boundary_id=boundary_id, summary_fn=waw_summary,
        ))
    if builtup is not None:
        def built_summary(areas: dict[int, float], valid: float) -> dict:
            built = sum(area for code, area in areas.items() if code not in (0,))
            return {"built_up_area_pct": 0.0 if valid <= 0 else 100.0 * built / valid}

        profiles.append(classified_profile(
            geom, builtup, product_code="hrl_builtup_2018",
            class_info={0: {"code": "0", "label_bs": "Nije izgrađeno", "label_en": "Non built-up", "atlas_group": "non_built"},
                        1: {"code": "1", "label_bs": "Izgrađeno", "label_en": "Built-up", "atlas_group": "built"}},
            extra_nodata=extra, source_meta=meta.get("hrl_builtup_2018") or {},
            boundary_id=boundary_id, summary_fn=built_summary,
        ))
    return profiles


# Re-export test raster writer for fixtures.
__all__ = [
    "ALGORITHM_VERSION",
    "GeoRaster",
    "area_km2",
    "area_m2",
    "categorical_zonal",
    "clc_profile",
    "density_mean",
    "fractional_samples",
    "intersect_clc",
    "load_clc_features",
    "load_raster",
    "process_settlement",
    "to_3035",
    "write_test_raster",
]
