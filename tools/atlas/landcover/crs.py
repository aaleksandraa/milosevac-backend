"""Projected CRS helpers for land-cover area calculations.

Copernicus CLC/HRL products used here are processed in EPSG:3035 (ETRS89-LAEA).
Percentages are never computed in geographic degrees.
"""

from __future__ import annotations

from functools import lru_cache

from pyproj import Transformer
from shapely.geometry import mapping, shape
from shapely.ops import transform

EPSG_4326 = "EPSG:4326"
EPSG_3035 = "EPSG:3035"


@lru_cache(maxsize=4)
def transformer_to_3035() -> Transformer:
    return Transformer.from_crs(EPSG_4326, EPSG_3035, always_xy=True)


@lru_cache(maxsize=4)
def transformer_to_4326() -> Transformer:
    return Transformer.from_crs(EPSG_3035, EPSG_4326, always_xy=True)


def to_3035(geometry) -> object:
    geom = shape(geometry) if isinstance(geometry, dict) else geometry
    if geom.is_empty:
        return geom
    xform = transformer_to_3035()
    return transform(lambda x, y, z=None: xform.transform(x, y), geom)


def to_4326(geometry) -> object:
    geom = shape(geometry) if isinstance(geometry, dict) else geometry
    xform = transformer_to_4326()
    return transform(lambda x, y, z=None: xform.transform(x, y), geom)


def area_m2(geometry_3035) -> float:
    return float(geometry_3035.area)


def area_km2(geometry_3035) -> float:
    return area_m2(geometry_3035) / 1_000_000.0


def geojson_4326(geometry_3035) -> dict:
    return mapping(to_4326(geometry_3035))
