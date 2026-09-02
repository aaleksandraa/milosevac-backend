"""Deterministic land-cover tests. Does not download Copernicus data."""

from __future__ import annotations

import json
import math
import sys
import tempfile
import unittest
from pathlib import Path

import numpy as np
from shapely.geometry import Polygon, box, mapping, shape

HERE = Path(__file__).resolve().parent
ATLAS = HERE.parent
if str(ATLAS) not in sys.path:
    sys.path.insert(0, str(ATLAS))

from landcover.clc_nomenclature import (  # noqa: E402
    ALGORITHM_VERSION,
    atlas_group_for_clc,
    dominant_entry,
    group_shares_from_class_areas,
    normalize_clc_code,
    quality_from_coverage,
)
from landcover.crs import area_m2, to_3035  # noqa: E402
from landcover.zonal import (  # noqa: E402
    clc_profile,
    density_mean,
    fractional_samples,
    intersect_clc,
    process_settlement,
    write_test_raster,
)
from terrain import load_raster  # noqa: E402


def square_geojson(west: float, south: float, east: float, north: float) -> dict:
    return {
        "type": "Polygon",
        "coordinates": [[
            [west, south],
            [east, south],
            [east, north],
            [west, north],
            [west, south],
        ]],
    }


def square_3035(xmin: float, ymin: float, xmax: float, ymax: float) -> Polygon:
    return box(xmin, ymin, xmax, ymax)


class ClcMappingTest(unittest.TestCase):
    def test_official_codes_and_groups(self) -> None:
        self.assertEqual(atlas_group_for_clc("211"), "agricultural")
        self.assertEqual(atlas_group_for_clc("311"), "forest")
        self.assertEqual(atlas_group_for_clc("324"), "shrub_herbaceous_or_seminatural")
        self.assertEqual(atlas_group_for_clc("112"), "artificial_surfaces")
        self.assertEqual(atlas_group_for_clc("511"), "water")
        self.assertEqual(normalize_clc_code(211), "211")
        self.assertEqual(normalize_clc_code("2"), None)

    def test_forest_is_not_all_level3(self) -> None:
        self.assertEqual(atlas_group_for_clc("321"), "shrub_herbaceous_or_seminatural")
        self.assertNotEqual(atlas_group_for_clc("324"), "forest")

    def test_top_level_aggregation(self) -> None:
        areas = {"211": 60.0, "311": 30.0, "112": 10.0}
        shares = group_shares_from_class_areas(areas, 100.0)
        self.assertAlmostEqual(shares["agricultural"], 60.0)
        self.assertAlmostEqual(shares["forest"], 30.0)
        self.assertAlmostEqual(shares["artificial_surfaces"], 10.0)
        self.assertAlmostEqual(sum(shares.values()), 100.0)

    def test_dominant_tie_uses_lower_code(self) -> None:
        tied = dominant_entry({"311": 50.0, "211": 50.0})
        self.assertEqual(tied["code"], "211")

    def test_quality_flags(self) -> None:
        self.assertEqual(quality_from_coverage(100, 100), "ok")
        self.assertEqual(quality_from_coverage(90, 100), "high_nodata")
        self.assertEqual(quality_from_coverage(40, 100), "insufficient_coverage")
        self.assertEqual(quality_from_coverage(100, 100, 96.0), "inconsistent_totals")
        self.assertEqual(quality_from_coverage(0, 100), "unavailable")


class VectorIntersectionTest(unittest.TestCase):
    def test_polygon_fully_inside_one_class(self) -> None:
        geom = square_3035(0, 0, 100, 100)
        clc = [(square_3035(-10, -10, 200, 200), "211")]
        areas = intersect_clc(geom, clc)
        self.assertAlmostEqual(areas["211"], 10_000.0, places=3)
        profile = clc_profile(geom, areas, boundary_id=1, source_meta={})
        self.assertAlmostEqual(profile["summary"]["landcover_agricultural_pct"], 100.0)
        self.assertEqual(profile["summary"]["dominant_class"]["code"], "211")
        self.assertEqual(profile["algorithm_version"], ALGORITHM_VERSION)
        self.assertAlmostEqual(sum(row["share_pct"] for row in profile["classes"]), 100.0, places=5)

    def test_two_equal_classes(self) -> None:
        geom = square_3035(0, 0, 100, 100)
        clc = [
            (square_3035(0, 0, 50, 100), "211"),
            (square_3035(50, 0, 100, 100), "311"),
        ]
        areas = intersect_clc(geom, clc)
        self.assertAlmostEqual(areas["211"], 5000.0, places=3)
        self.assertAlmostEqual(areas["311"], 5000.0, places=3)
        profile = clc_profile(geom, areas, boundary_id=1, source_meta={})
        self.assertAlmostEqual(profile["summary"]["landcover_agricultural_pct"], 50.0)
        self.assertAlmostEqual(profile["summary"]["landcover_forest_pct"], 50.0)
        self.assertEqual(profile["summary"]["dominant_class"]["code"], "211")

    def test_zero_share_class_omitted(self) -> None:
        geom = square_3035(0, 0, 10, 10)
        areas = {"211": 100.0}
        profile = clc_profile(geom, areas, boundary_id=1, source_meta={})
        codes = [row["class_code"] for row in profile["classes"]]
        self.assertEqual(codes, ["211"])

    def test_area_conservation_and_share_sum(self) -> None:
        geom = square_3035(0, 0, 100, 50)
        clc = [
            (square_3035(0, 0, 100, 50), "112"),
        ]
        areas = intersect_clc(geom, clc)
        self.assertAlmostEqual(sum(areas.values()), area_m2(geom), places=5)
        profile = clc_profile(geom, areas, boundary_id=1, source_meta={})
        self.assertAlmostEqual(sum(row["share_pct"] for row in profile["classes"]), 100.0, places=6)

    def test_tiny_polygon(self) -> None:
        geom = square_3035(0, 0, 1, 1)
        clc = [(square_3035(-1, -1, 2, 2), "512")]
        areas = intersect_clc(geom, clc)
        self.assertAlmostEqual(areas["512"], 1.0, places=6)

    def test_multipolygon(self) -> None:
        geom = square_3035(0, 0, 10, 10).union(square_3035(20, 0, 30, 10))
        clc = [
            (square_3035(0, 0, 10, 10), "211"),
            (square_3035(20, 0, 30, 10), "311"),
        ]
        areas = intersect_clc(geom, clc)
        self.assertAlmostEqual(areas["211"], 100.0, places=3)
        self.assertAlmostEqual(areas["311"], 100.0, places=3)

    def test_nodata_gap_is_not_a_class(self) -> None:
        geom = square_3035(0, 0, 100, 100)
        clc = [(square_3035(0, 0, 50, 100), "211")]
        areas = intersect_clc(geom, clc)
        profile = clc_profile(geom, areas, boundary_id=1, source_meta={})
        self.assertGreater(profile["nodata_area_km2"], 0)
        self.assertNotIn("0", [row["class_code"] for row in profile["classes"]])
        self.assertEqual(profile["quality_flag"], "high_nodata")
        sparse = clc_profile(geom, {"211": 2000.0}, boundary_id=1, source_meta={})
        self.assertEqual(sparse["quality_flag"], "insufficient_coverage")


class CrsAndRasterTest(unittest.TestCase):
    def test_crs_transformation_area_not_degrees(self) -> None:
        geom_ll = shape(square_geojson(18.40, 44.90, 18.41, 44.91))
        projected = to_3035(geom_ll)
        self.assertGreater(projected.area, 1000)
        self.assertLess(abs(geom_ll.area), 1)  # degree-space area is tiny and must not be used

    def test_fractional_boundary_pixel(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "frac.tif"
            data = np.array([[211.0, 311.0], [211.0, 311.0]], dtype=np.float32)
            write_test_raster(path, data, west=0.0, north=20.0, xres=10.0, yres=-10.0, nodata=255)
            raster = load_raster(path)
            geom = square_3035(5, 5, 15, 15)
            samples, total, valid, nodata = fractional_samples(geom, raster, {255})
            self.assertAlmostEqual(total, 100.0, places=3)
            self.assertAlmostEqual(valid, 100.0, places=3)
            self.assertEqual(nodata, 0.0)
            by_class = {}
            for value, weight in samples:
                by_class[int(value)] = by_class.get(int(value), 0.0) + weight
            self.assertAlmostEqual(sum(by_class.values()), 100.0, places=3)
            self.assertGreater(by_class[211], 0)
            self.assertGreater(by_class[311], 0)

    def test_nodata_excluded_from_density(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tcd.tif"
            data = np.array([[10.0, 255.0], [0.0, 100.0]], dtype=np.float32)
            write_test_raster(path, data, west=0.0, north=20.0, xres=10.0, yres=-10.0, nodata=255)
            raster = load_raster(path)
            geom = square_3035(0, 0, 20, 20)
            mean, total, valid, nodata = density_mean(geom, raster, {255})
            self.assertAlmostEqual(mean, (10 + 0 + 100) / 3.0, places=5)
            self.assertGreater(nodata, 0)
            self.assertAlmostEqual(valid + nodata, total, places=3)

    def test_zero_and_hundred_density_valid(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "imd.tif"
            data = np.array([[0.0, 0.0], [100.0, 100.0]], dtype=np.float32)
            write_test_raster(path, data, west=0.0, north=20.0, xres=10.0, yres=-10.0, nodata=255)
            raster = load_raster(path)
            zero = density_mean(square_3035(0, 10, 20, 20), raster, {255})[0]
            hundred = density_mean(square_3035(0, 0, 20, 10), raster, {255})[0]
            self.assertAlmostEqual(zero, 0.0, places=5)
            self.assertAlmostEqual(hundred, 100.0, places=5)

    def test_unavailable_coverage(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "empty.tif"
            data = np.full((2, 2), 255.0, dtype=np.float32)
            write_test_raster(path, data, west=0.0, north=20.0, xres=10.0, yres=-10.0, nodata=255)
            raster = load_raster(path)
            mean, total, valid, nodata = density_mean(square_3035(0, 0, 20, 20), raster, {255})
            self.assertIsNone(mean)
            self.assertEqual(valid, 0.0)
            self.assertGreater(nodata, 0)

    def test_process_settlement_combines_sources(self) -> None:
        geom = square_geojson(18.40, 44.90, 18.41, 44.91)
        projected = to_3035(shape(geom))
        minx, miny, maxx, maxy = projected.bounds
        clc = [(box(minx - 10, miny - 10, maxx + 10, maxy + 10), "231")]
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "tcd.tif"
            data = np.full((8, 8), 12.0, dtype=np.float32)
            write_test_raster(path, data, west=minx - 50, north=maxy + 50, xres=50.0, yres=-50.0, nodata=255)
            raster = load_raster(path)
            profiles = process_settlement(geom, clc_features=clc, tcd=raster, source_meta={"clc2018": {"title": "CLC"}})
            codes = [row["product_code"] for row in profiles]
            self.assertIn("clc2018", codes)
            self.assertIn("hrl_tcd_2018", codes)
            clc_row = next(row for row in profiles if row["product_code"] == "clc2018")
            self.assertEqual(clc_row["metadata"]["source"]["title"], "CLC")
            tcd = next(row for row in profiles if row["product_code"] == "hrl_tcd_2018")
            self.assertAlmostEqual(tcd["summary"]["tree_cover_mean_pct"], 12.0, places=1)

    def test_missing_profile_is_not_emitted_as_zero(self) -> None:
        geom = square_geojson(18.40, 44.90, 18.41, 44.91)
        profiles = process_settlement(geom, clc_features=None, tcd=None)
        self.assertEqual(profiles, [])


if __name__ == "__main__":
    unittest.main()
