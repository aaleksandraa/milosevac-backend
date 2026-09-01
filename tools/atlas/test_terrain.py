"""Deterministic terrain tests. Does not download Copernicus data."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from terrain import load_raster, process_jobs, process_polygon, write_test_raster  # noqa: E402


def square(west: float, south: float, east: float, north: float) -> dict:
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


class TerrainProcessingTest(unittest.TestCase):
    def test_flat_raster_mean_equals_value(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "flat.tif"
            data = np.full((10, 10), 120.0, dtype=np.float32)
            write_test_raster(path, data, west=18.0, north=45.01, xres=0.001, yres=-0.001, nodata=-9999)
            raster = load_raster(path)
            stats = process_polygon(square(18.002, 45.002, 18.008, 45.008), [raster])
            self.assertEqual(stats["elevation_min_m"], 120.0)
            self.assertEqual(stats["elevation_max_m"], 120.0)
            self.assertEqual(stats["elevation_mean_m"], 120.0)
            self.assertEqual(stats["elevation_median_m"], 120.0)
            self.assertEqual(stats["elevation_range_m"], 0.0)
            self.assertGreater(stats["valid_cell_count"], 0)
            self.assertEqual(stats["nodata_cell_count"], 0)
            self.assertEqual(stats["quality_flag"], "ok")

    def test_known_min_max(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "minmax.tif"
            data = np.arange(100, dtype=np.float32).reshape(10, 10)
            write_test_raster(path, data, west=18.0, north=45.01, xres=0.001, yres=-0.001, nodata=None)
            raster = load_raster(path)
            stats = process_polygon(square(18.0, 45.0, 18.01, 45.01), [raster])
            self.assertEqual(stats["elevation_min_m"], float(data.min()))
            self.assertEqual(stats["elevation_max_m"], float(data.max()))
            self.assertEqual(stats["elevation_range_m"], float(data.max() - data.min()))

    def test_nodata_is_not_zero(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "nodata.tif"
            data = np.array([
                [10.0, -9999.0, 20.0],
                [-9999.0, -9999.0, 30.0],
                [40.0, 0.0, 50.0],
            ], dtype=np.float32)
            write_test_raster(path, data, west=18.0, north=45.003, xres=0.001, yres=-0.001, nodata=-9999)
            raster = load_raster(path)
            stats = process_polygon(square(17.999, 44.999, 18.004, 45.004), [raster])
            self.assertGreater(stats["nodata_cell_count"], 0)
            self.assertNotIn(-9999.0, [stats["elevation_min_m"], stats["elevation_max_m"], stats["elevation_mean_m"]])
            self.assertEqual(stats["elevation_min_m"], 0.0)
            self.assertEqual(stats["elevation_max_m"], 50.0)

    def test_median_and_sample_count(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "median.tif"
            data = np.array([[1.0, 3.0], [5.0, 7.0]], dtype=np.float32)
            write_test_raster(path, data, west=18.0, north=45.002, xres=0.001, yres=-0.001, nodata=None)
            raster = load_raster(path)
            stats = process_polygon(square(18.0, 45.0, 18.002, 45.002), [raster])
            self.assertEqual(stats["valid_cell_count"], 4)
            self.assertEqual(stats["sample_count"], 4)
            self.assertEqual(stats["elevation_median_m"], 4.0)

    def test_polygon_clip_excludes_outside_pixels(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "clip.tif"
            data = np.array([[1.0, 100.0], [1.0, 100.0]], dtype=np.float32)
            write_test_raster(path, data, west=18.0, north=45.002, xres=0.001, yres=-0.001, nodata=None)
            raster = load_raster(path)
            stats = process_polygon(square(18.0, 45.0, 18.001, 45.002), [raster])
            self.assertLess(stats["elevation_max_m"], 50.0)
            self.assertEqual(stats["elevation_min_m"], 1.0)

    def test_slope_increases_on_ramp(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "slope.tif"
            ramp = np.tile(np.linspace(0, 90, 12, dtype=np.float32), (12, 1))
            write_test_raster(path, ramp, west=18.0, north=45.012, xres=0.001, yres=-0.001, nodata=None)
            raster = load_raster(path)
            stats = process_polygon(square(18.001, 45.001, 18.011, 45.011), [raster])
            self.assertIsNotNone(stats["slope_mean_deg"])
            self.assertGreater(stats["slope_mean_deg"], 0.5)
            self.assertGreaterEqual(stats["slope_max_deg"], stats["slope_mean_deg"])

    def test_job_file_roundtrip(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            dem = Path(tmp) / "dem.tif"
            data = np.full((8, 8), 88.0, dtype=np.float32)
            write_test_raster(dem, data, west=18.0, north=45.008, xres=0.001, yres=-0.001, nodata=-9999)
            jobs = {
                "jobs": [{
                    "settlement_id": 1,
                    "boundary_id": 2,
                    "geometry": square(18.001, 45.001, 18.007, 45.007),
                }],
            }
            jobs_path = Path(tmp) / "jobs.json"
            out_path = Path(tmp) / "stats.json"
            jobs_path.write_text(json.dumps(jobs), encoding="utf-8")
            document = process_jobs(jobs_path, Path(tmp), out_path, {"dem_version": "test"})
            self.assertEqual(document["records"][0]["elevation_mean_m"], 88.0)
            self.assertTrue(out_path.exists())


if __name__ == "__main__":
    unittest.main()
