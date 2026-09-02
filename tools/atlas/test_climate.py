"""Synthetic climate tests. Does not download ERA5."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from datetime import date, datetime, timezone
from zoneinfo import ZoneInfo
from pathlib import Path

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from climate_core import (  # noqa: E402
    ERA5_LAND_STEP,
    DailyValues,
    GridCell,
    WeightedCell,
    annual_from_monthly,
    build_profile,
    cells_for_polygon,
    daily_from_hourly,
    extremes,
    geometry_from_geojson,
    kelvin_to_celsius,
    last_complete_year,
    local_calendar_date,
    monthly_normals,
    precip_metres_to_mm,
    threshold_indices,
    weighted_daily_series,
)
from process_climate import process_jobs  # noqa: E402


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


def local_hours(day: date) -> list[datetime]:
    tz = ZoneInfo("Europe/Sarajevo")
    return [
        datetime(day.year, day.month, day.day, hour, tzinfo=tz).astimezone(timezone.utc)
        for hour in range(24)
    ]


class ClimateCoreTest(unittest.TestCase):
    def test_constant_temperature_series(self) -> None:
        series = [
            DailyValues(time=date(1991, month, 15), tmean=10.0, tmin=5.0, tmax=15.0, precip_mm=0.0)
            for month in range(1, 13)
            for _year in [1991]
        ]
        monthly = monthly_normals(series, date(1991, 1, 1), date(1991, 12, 31))
        self.assertTrue(all(row["temperature_mean_c"] == 10.0 for row in monthly))
        annual = annual_from_monthly(monthly)
        self.assertEqual(annual["temperature_mean_c"], 10.0)
        self.assertEqual(annual["precipitation_mm"], 0.0)

    def test_monthly_means_and_zero_precipitation(self) -> None:
        series = []
        for month, tmean in enumerate([0.0, 2.0, 5.0, 10.0, 15.0, 20.0, 22.0, 21.0, 16.0, 10.0, 5.0, 1.0], start=1):
            series.append(DailyValues(time=date(2000, month, 10), tmean=tmean, tmin=tmean - 3, tmax=tmean + 3, precip_mm=0.0))
        monthly = monthly_normals(series, date(2000, 1, 1), date(2000, 12, 31))
        self.assertEqual(monthly[0]["temperature_mean_c"], 0.0)
        self.assertEqual(monthly[0]["precipitation_mean_mm"], 0.0)
        annual = annual_from_monthly(monthly)
        self.assertEqual(annual["coldest_month"], 1)
        self.assertEqual(annual["warmest_month"], 7)
        self.assertEqual(annual["precipitation_mm"], 0.0)

    def test_daily_min_max_from_hourly(self) -> None:
        stamps = local_hours(date(2020, 1, 1))
        temps = [float(hour) for hour in range(24)]
        rows = daily_from_hourly(stamps, temperature_c=temps)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0].tmin, 0.0)
        self.assertEqual(rows[0].tmax, 23.0)
        self.assertAlmostEqual(rows[0].tmean, mean_range(), places=5)

    def test_leap_year_february_29_is_kept(self) -> None:
        series = [
            DailyValues(time=date(2020, 2, 28), tmean=1.0, tmin=0.0, tmax=2.0, precip_mm=1.0),
            DailyValues(time=date(2020, 2, 29), tmean=3.0, tmin=2.0, tmax=4.0, precip_mm=2.0),
            DailyValues(time=date(2020, 3, 1), tmean=5.0, tmin=4.0, tmax=6.0, precip_mm=0.0),
        ]
        monthly = monthly_normals(series, date(2020, 1, 1), date(2020, 12, 31))
        self.assertEqual(monthly[1]["temperature_mean_c"], 2.0)
        self.assertEqual(monthly[1]["precipitation_mean_mm"], 3.0)

    def test_precipitation_accumulation_metres_to_mm(self) -> None:
        self.assertEqual(precip_metres_to_mm(0.001), 1.0)
        stamps = local_hours(date(2020, 6, 1))
        hourly_m = [0.001] * 24
        rows = daily_from_hourly(stamps, precipitation_m=hourly_m)
        self.assertEqual(rows[0].precip_mm, 24.0)

    def test_missing_values_are_skipped(self) -> None:
        series = [
            DailyValues(time=date(1995, 1, 1), tmean=None, tmin=None, tmax=None, precip_mm=None),
            DailyValues(time=date(1995, 1, 2), tmean=2.0, tmin=-1.0, tmax=5.0, precip_mm=4.0),
        ]
        monthly = monthly_normals(series, date(1995, 1, 1), date(1995, 12, 31))
        self.assertEqual(monthly[0]["temperature_mean_c"], 2.0)
        self.assertIsNone(monthly[0]["precipitation_mean_mm"])

    def test_polygon_smaller_than_grid_cell(self) -> None:
        geom = geometry_from_geojson(square(18.201, 44.901, 18.202, 44.902))
        weights = cells_for_polygon(geom, "era5_land", ERA5_LAND_STEP)
        self.assertEqual(len(weights), 1)
        self.assertAlmostEqual(weights[0].weight, 1.0, places=6)

    def test_multiple_grid_cell_weighted_average(self) -> None:
        geom = geometry_from_geojson(square(18.14, 44.94, 18.26, 45.06))
        weights = cells_for_polygon(geom, "era5_land", ERA5_LAND_STEP)
        self.assertGreaterEqual(len(weights), 2)
        self.assertAlmostEqual(sum(item.weight for item in weights), 1.0, places=6)
        left = GridCell("era5_land", 45.0, 18.1, 0.1)
        right = GridCell("era5_land", 45.0, 18.2, 0.1)
        series = {
            left.key: [DailyValues(time=date(2000, 1, 1), tmean=10.0, tmin=5.0, tmax=15.0, precip_mm=0.0)],
            right.key: [DailyValues(time=date(2000, 1, 1), tmean=20.0, tmin=15.0, tmax=25.0, precip_mm=0.0)],
        }
        merged = weighted_daily_series(
            [WeightedCell(left, 0.25), WeightedCell(right, 0.75)],
            series,
        )
        self.assertEqual(len(merged), 1)
        self.assertAlmostEqual(merged[0].tmean, 17.5)

    def test_extremes_and_dates(self) -> None:
        series = [
            DailyValues(time=date(2001, 7, 20), tmean=30.0, tmin=20.0, tmax=38.0, precip_mm=2.0),
            DailyValues(time=date(2003, 1, 12), tmean=-10.0, tmin=-18.0, tmax=-4.0, precip_mm=0.0),
            DailyValues(time=date(2010, 6, 4), tmean=18.0, tmin=12.0, tmax=24.0, precip_mm=61.5),
        ]
        rows = {item["metric_code"]: item for item in extremes(series, date(1950, 1, 1), date(2025, 12, 31))}
        self.assertEqual(rows["daily_tmax_max"]["occurred_on"], "2001-07-20")
        self.assertEqual(rows["daily_tmax_max"]["value"], 38.0)
        self.assertEqual(rows["daily_tmin_min"]["occurred_on"], "2003-01-12")
        self.assertEqual(rows["daily_precip_max"]["occurred_on"], "2010-06-04")
        self.assertEqual(rows["daily_tmax_max"]["kind"], "gridded_reanalysis_extreme")
        self.assertNotIn("izmjeren", rows["daily_tmax_max"]["wording"].lower())

    def test_threshold_indices(self) -> None:
        series = [
            DailyValues(time=date(1991, 7, 1), tmean=28.0, tmin=21.0, tmax=32.0, precip_mm=0.4),
            DailyValues(time=date(1991, 7, 2), tmean=31.0, tmin=19.0, tmax=36.0, precip_mm=1.2),
            DailyValues(time=date(1991, 1, 3), tmean=-2.0, tmin=-5.0, tmax=-0.5, precip_mm=0.0),
        ]
        by_code = {item["code"]: item for item in threshold_indices(series, date(1991, 1, 1), date(1991, 12, 31))}
        self.assertEqual(by_code["hot_days_30c"]["value"], 2.0)
        self.assertEqual(by_code["very_hot_days_35c"]["value"], 1.0)
        self.assertEqual(by_code["frost_days"]["value"], 1.0)
        self.assertEqual(by_code["ice_days"]["value"], 1.0)
        self.assertEqual(by_code["tropical_nights"]["value"], 1.0)
        self.assertEqual(by_code["precipitation_days_1mm"]["value"], 1.0)
        self.assertEqual(by_code["hot_days_30c"]["threshold"], 30.0)

    def test_timezone_day_boundary(self) -> None:
        winter = datetime(2020, 1, 1, 22, 30, tzinfo=timezone.utc)
        self.assertEqual(local_calendar_date(winter), date(2020, 1, 1))
        next_local = datetime(2020, 1, 1, 23, 30, tzinfo=timezone.utc)
        self.assertEqual(local_calendar_date(next_local), date(2020, 1, 2))

    def test_kelvin_conversion(self) -> None:
        self.assertEqual(kelvin_to_celsius(283.15), 10.0)
        self.assertEqual(kelvin_to_celsius(10.0), 10.0)

    def test_last_complete_year_excludes_partial_current_year(self) -> None:
        self.assertEqual(last_complete_year(date(2026, 9, 2)), 2025)

    def test_process_jobs_from_cached_cells(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            jobs = {
                "jobs": [
                    {
                        "settlement_id": 1,
                        "boundary_id": 11,
                        "municipality_slug": "modrica",
                        "settlement_slug": "milosevac",
                        "settlement_name": "Miloševac",
                        "boundary_version": "osm-test",
                        "geometry": square(18.201, 44.901, 18.202, 44.902),
                    }
                ]
            }
            jobs_path = tmp_path / "jobs.json"
            jobs_path.write_text(json.dumps(jobs), encoding="utf-8")
            cache = tmp_path / "cache"
            cache.mkdir()
            days = []
            tmean = []
            tmin = []
            tmax = []
            precip = []
            for year in range(1950, 2026):
                for month in range(1, 13):
                    days.append(date(year, month, 15).isoformat())
                    month_temp = 5.0 + month
                    tmean.append(month_temp)
                    tmin.append(month_temp - 4)
                    tmax.append(month_temp + 4)
                    precip.append(2.0 if month in (5, 6) else 1.0)
            land = {
                "query": {"dataset": "era5_land", "lat": 44.9, "lon": 18.2},
                "daily": {
                    "time": days,
                    "temperature_2m_mean": tmean,
                    "temperature_2m_min": tmin,
                    "temperature_2m_max": tmax,
                    "precipitation_sum": [None] * len(days),
                },
            }
            era5 = {
                "query": {"dataset": "era5", "lat": 45.0, "lon": 18.25},
                "daily": {
                    "time": days,
                    "temperature_2m_mean": [None] * len(days),
                    "temperature_2m_min": [None] * len(days),
                    "temperature_2m_max": [None] * len(days),
                    "precipitation_sum": precip,
                },
            }
            (cache / "era5_land_44.90_18.20.json").write_text(json.dumps(land), encoding="utf-8")
            (cache / "era5_45.00_18.25.json").write_text(json.dumps(era5), encoding="utf-8")
            # The tiny square around 18.201,44.901 snaps to 44.9/18.2 land and 45.0/18.25 ERA5.
            out = tmp_path / "climate.json"
            payload = process_jobs(jobs_path, cache, out, {"retrieval_date": "2026-09-02", "historical_end_year": 2025})
            self.assertEqual(payload["records"][0]["status"], "ok")
            self.assertEqual(payload["records"][0]["quality_flag"], "coarse_spatial_resolution")
            self.assertEqual(payload["records"][0]["grid_cell_count"], 1)
            self.assertTrue(out.exists())


def mean_range() -> float:
    return sum(range(24)) / 24.0


if __name__ == "__main__":
    unittest.main()
