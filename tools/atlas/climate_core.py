"""Deterministic ERA5 / ERA5-Land climate calculations for the Settlement Atlas.

Temperature uses the ERA5-Land 0.1° grid. Precipitation uses ERA5 0.25° because
ERA5-Land total precipitation is an atmospheric forcing field and the Open-Meteo
ERA5-Land archive returns null precipitation. Do not label precipitation as ERA5-Land.
"""

from __future__ import annotations

import math
from collections import defaultdict
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from statistics import mean
from typing import Iterable
from zoneinfo import ZoneInfo

from shapely.geometry import shape
from shapely.geometry.base import BaseGeometry
from shapely.ops import transform

ALGORITHM_VERSION = "m7.1"
TIMEZONE_NAME = "Europe/Sarajevo"
LOCAL_TZ = ZoneInfo(TIMEZONE_NAME)
NORMAL_START = date(1991, 1, 1)
NORMAL_END = date(2020, 12, 31)
REFERENCE_START = date(1961, 1, 1)
REFERENCE_END = date(1990, 12, 31)
HISTORICAL_START = date(1950, 1, 1)
ERA5_LAND_STEP = 0.1
ERA5_STEP = 0.25
ERA5_LAND_RESOLUTION_KM = 9.0
ERA5_RESOLUTION_KM = 28.0
PRECIP_M_TO_MM = 1000.0
KELVIN_OFFSET = 273.15

INDICES = {
    "hot_days_30c": {"threshold": 30.0, "variable": "tmax", "op": "ge", "unit": "days_per_year"},
    "very_hot_days_35c": {"threshold": 35.0, "variable": "tmax", "op": "ge", "unit": "days_per_year"},
    "frost_days": {"threshold": 0.0, "variable": "tmin", "op": "lt", "unit": "days_per_year"},
    "ice_days": {"threshold": 0.0, "variable": "tmax", "op": "lt", "unit": "days_per_year"},
    "tropical_nights": {"threshold": 20.0, "variable": "tmin", "op": "ge", "unit": "days_per_year"},
    "precipitation_days_1mm": {"threshold": 1.0, "variable": "precip", "op": "ge", "unit": "days_per_year"},
}

MONTH_LABELS_BS = {
    1: "januar",
    2: "februar",
    3: "mart",
    4: "april",
    5: "maj",
    6: "jun",
    7: "jul",
    8: "august",
    9: "septembar",
    10: "oktobar",
    11: "novembar",
    12: "decembar",
}


@dataclass(frozen=True)
class GridCell:
    dataset: str
    lat: float
    lon: float
    step: float

    @property
    def key(self) -> str:
        return f"{self.dataset}:{self.lat:.2f}:{self.lon:.2f}"

    def polygon(self) -> BaseGeometry:
        half = self.step / 2.0
        return shape(
            {
                "type": "Polygon",
                "coordinates": [[
                    [self.lon - half, self.lat - half],
                    [self.lon + half, self.lat - half],
                    [self.lon + half, self.lat + half],
                    [self.lon - half, self.lat + half],
                    [self.lon - half, self.lat - half],
                ]],
            }
        )


@dataclass
class WeightedCell:
    cell: GridCell
    weight: float


@dataclass
class DailyValues:
    time: date
    tmean: float | None = None
    tmin: float | None = None
    tmax: float | None = None
    precip_mm: float | None = None


def kelvin_to_celsius(value: float | None) -> float | None:
    if value is None:
        return None
    if value > 100:
        return value - KELVIN_OFFSET
    return value


def precip_metres_to_mm(value: float | None) -> float | None:
    if value is None:
        return None
    return value * PRECIP_M_TO_MM


def snap_coord(value: float, step: float) -> float:
    return round(round(value / step) * step, 4)


def last_complete_year(today: date | None = None) -> int:
    today = today or date.today()
    return today.year - 1


def local_calendar_date(utc_dt: datetime) -> date:
    if utc_dt.tzinfo is None:
        utc_dt = utc_dt.replace(tzinfo=timezone.utc)
    return utc_dt.astimezone(LOCAL_TZ).date()


def daily_from_hourly(
    timestamps_utc: Iterable[datetime],
    temperature_c: Iterable[float | None] | None = None,
    precipitation_m: Iterable[float | None] | None = None,
) -> list[DailyValues]:
    """Aggregate hourly UTC series onto Europe/Sarajevo calendar days.

    Precipitation is ERA5-Land/ERA5 accumulated water equivalent in metres per
    hour: daily total mm = sum(hourly metres) * 1000. Instantaneous rates are
    never averaged.
    """
    temps = list(temperature_c) if temperature_c is not None else None
    precips = list(precipitation_m) if precipitation_m is not None else None
    stamps = list(timestamps_utc)
    buckets: dict[date, dict[str, list[float]]] = defaultdict(lambda: {"t": [], "p": []})
    for index, stamp in enumerate(stamps):
        day = local_calendar_date(stamp)
        if temps is not None and index < len(temps) and temps[index] is not None:
            buckets[day]["t"].append(float(kelvin_to_celsius(temps[index])))
        if precips is not None and index < len(precips) and precips[index] is not None:
            buckets[day]["p"].append(float(precips[index]))
    rows: list[DailyValues] = []
    for day in sorted(buckets):
        temps_day = buckets[day]["t"]
        precips_day = buckets[day]["p"]
        rows.append(
            DailyValues(
                time=day,
                tmean=mean(temps_day) if temps_day else None,
                tmin=min(temps_day) if temps_day else None,
                tmax=max(temps_day) if temps_day else None,
                precip_mm=sum(precips_day) * PRECIP_M_TO_MM if precips_day else None,
            )
        )
    return rows


def _equirectangular_transform(lat0: float):
    scale = math.cos(math.radians(lat0))

    def _project(x: float, y: float, z: float | None = None):
        return (x * scale, y)

    return _project


def intersection_area_weight(geometry: BaseGeometry, cell: GridCell) -> float:
    intersection = geometry.intersection(cell.polygon())
    if intersection.is_empty:
        return 0.0
    lat0 = geometry.centroid.y
    projected = transform(_equirectangular_transform(lat0), intersection)
    return float(projected.area)


def cells_for_polygon(geometry: BaseGeometry, dataset: str, step: float, margin: float = 0.0) -> list[WeightedCell]:
    minx, miny, maxx, maxy = geometry.bounds
    minx -= margin
    miny -= margin
    maxx += margin
    maxy += margin
    lat = snap_coord(miny - step, step)
    lat_max = snap_coord(maxy + step, step)
    lon0 = snap_coord(minx - step, step)
    lon_max = snap_coord(maxx + step, step)
    weighted: list[WeightedCell] = []
    while lat <= lat_max + 1e-9:
        lon = lon0
        while lon <= lon_max + 1e-9:
            cell = GridCell(dataset=dataset, lat=round(lat, 4), lon=round(lon, 4), step=step)
            weight = intersection_area_weight(geometry, cell)
            if weight > 0:
                weighted.append(WeightedCell(cell=cell, weight=weight))
            lon = round(lon + step, 4)
        lat = round(lat + step, 4)
    total = sum(item.weight for item in weighted)
    if total <= 0:
        return []
    return [WeightedCell(cell=item.cell, weight=item.weight / total) for item in weighted]


def geometry_from_geojson(geojson: dict) -> BaseGeometry:
    geom = shape(geojson)
    if geom.is_empty:
        raise ValueError("empty geometry")
    return geom


def weighted_daily_series(
    weights: list[WeightedCell],
    series_by_key: dict[str, list[DailyValues]],
) -> list[DailyValues]:
    days: dict[date, dict[str, list[tuple[float, float]]]] = defaultdict(
        lambda: {"tmean": [], "tmin": [], "tmax": [], "precip_mm": []}
    )
    for item in weights:
        rows = series_by_key.get(item.cell.key, [])
        for row in rows:
            for field_name in ("tmean", "tmin", "tmax", "precip_mm"):
                value = getattr(row, field_name)
                if value is None:
                    continue
                days[row.time][field_name].append((item.weight, float(value)))

    def _combine(pairs: list[tuple[float, float]]) -> float | None:
        if not pairs:
            return None
        total_w = sum(weight for weight, _ in pairs)
        if total_w <= 0:
            return None
        return sum(weight * value for weight, value in pairs) / total_w

    return [
        DailyValues(
            time=day,
            tmean=_combine(fields["tmean"]),
            tmin=_combine(fields["tmin"]),
            tmax=_combine(fields["tmax"]),
            precip_mm=_combine(fields["precip_mm"]),
        )
        for day, fields in sorted(days.items())
    ]


def _in_period(day: date, start: date, end: date) -> bool:
    return start <= day <= end


def monthly_normals(series: list[DailyValues], start: date, end: date) -> list[dict]:
    buckets: dict[int, dict[str, list[float]]] = {
        month: {"tmean": [], "tmin": [], "tmax": [], "precip": []} for month in range(1, 13)
    }
    monthly_precip_totals: dict[tuple[int, int], float] = defaultdict(float)
    monthly_precip_complete: dict[tuple[int, int], bool] = defaultdict(lambda: True)
    for row in series:
        if not _in_period(row.time, start, end):
            continue
        month = row.time.month
        if row.tmean is not None:
            buckets[month]["tmean"].append(row.tmean)
        if row.tmin is not None:
            buckets[month]["tmin"].append(row.tmin)
        if row.tmax is not None:
            buckets[month]["tmax"].append(row.tmax)
        key = (row.time.year, month)
        if row.precip_mm is None:
            monthly_precip_complete[key] = False
        else:
            monthly_precip_totals[key] += row.precip_mm
    for (year, month), total in monthly_precip_totals.items():
        if monthly_precip_complete[(year, month)]:
            buckets[month]["precip"].append(total)

    monthly = []
    for month in range(1, 13):
        tmean = mean(buckets[month]["tmean"]) if buckets[month]["tmean"] else None
        tmin = mean(buckets[month]["tmin"]) if buckets[month]["tmin"] else None
        tmax = mean(buckets[month]["tmax"]) if buckets[month]["tmax"] else None
        precip = mean(buckets[month]["precip"]) if buckets[month]["precip"] else None
        monthly.append(
            {
                "month": month,
                "month_label": MONTH_LABELS_BS[month],
                "temperature_mean_c": _round(tmean, 2),
                "temperature_mean_min_c": _round(tmin, 2),
                "temperature_mean_max_c": _round(tmax, 2),
                "precipitation_mean_mm": _round(precip, 1),
            }
        )
    return monthly


def annual_from_monthly(monthly: list[dict]) -> dict:
    means = [row["temperature_mean_c"] for row in monthly if row["temperature_mean_c"] is not None]
    precips = [row["precipitation_mean_mm"] for row in monthly if row["precipitation_mean_mm"] is not None]
    if not means or not precips or len(means) < 12 or len(precips) < 12:
        raise ValueError("incomplete monthly normals")
    warmest = max(monthly, key=lambda row: row["temperature_mean_c"])
    coldest = min(monthly, key=lambda row: row["temperature_mean_c"])
    wettest = max(monthly, key=lambda row: row["precipitation_mean_mm"])
    driest = min(monthly, key=lambda row: row["precipitation_mean_mm"])
    return {
        "temperature_mean_c": _round(mean(means), 2),
        "precipitation_mm": _round(sum(precips), 1),
        "warmest_month": warmest["month"],
        "coldest_month": coldest["month"],
        "wettest_month": wettest["month"],
        "driest_month": driest["month"],
        "temperature_range_c": _round(warmest["temperature_mean_c"] - coldest["temperature_mean_c"], 2),
    }


def period_mean_temperature(series: list[DailyValues], start: date, end: date) -> float | None:
    monthly = monthly_normals(series, start, end)
    means = [row["temperature_mean_c"] for row in monthly if row["temperature_mean_c"] is not None]
    if len(means) < 12:
        return None
    return _round(mean(means), 2)


def threshold_indices(series: list[DailyValues], start: date, end: date) -> list[dict]:
    years: dict[int, dict[str, int]] = defaultdict(lambda: {code: 0 for code in INDICES})
    year_present: set[int] = set()
    for row in series:
        if not _in_period(row.time, start, end):
            continue
        year_present.add(row.time.year)
        mapping_values = {"tmax": row.tmax, "tmin": row.tmin, "precip": row.precip_mm}
        for code, spec in INDICES.items():
            value = mapping_values[spec["variable"]]
            if value is None:
                continue
            if spec["op"] == "ge" and value >= spec["threshold"]:
                years[row.time.year][code] += 1
            elif spec["op"] == "lt" and value < spec["threshold"]:
                years[row.time.year][code] += 1
    n_years = len([year for year in year_present if start.year <= year <= end.year])
    if n_years == 0:
        return []
    out = []
    for code, spec in INDICES.items():
        values = [years[year][code] for year in year_present if start.year <= year <= end.year]
        out.append(
            {
                "code": code,
                "value": _round(mean(values), 2) if values else None,
                "threshold": spec["threshold"],
                "unit": spec["unit"],
                "definition": _index_definition(code, spec),
            }
        )
    return out


def _index_definition(code: str, spec: dict) -> str:
    labels = {
        "hot_days_30c": "dnevni Tmax ≥ 30 °C",
        "very_hot_days_35c": "dnevni Tmax ≥ 35 °C",
        "frost_days": "dnevni Tmin < 0 °C",
        "ice_days": "dnevni Tmax < 0 °C",
        "tropical_nights": "dnevni Tmin ≥ 20 °C",
        "precipitation_days_1mm": "dnevne padavine ≥ 1 mm",
    }
    return labels[code]


def extremes(series: list[DailyValues], start: date, end: date) -> list[dict]:
    tmax_row = _extreme(series, start, end, "tmax", max)
    tmin_row = _extreme(series, start, end, "tmin", min)
    precip_row = _extreme(series, start, end, "precip_mm", max)
    out = []
    if tmax_row:
        out.append(_extreme_record("daily_tmax_max", tmax_row.tmax, "°C", tmax_row.time, start, end))
    if tmin_row:
        out.append(_extreme_record("daily_tmin_min", tmin_row.tmin, "°C", tmin_row.time, start, end))
    if precip_row:
        out.append(_extreme_record("daily_precip_max", precip_row.precip_mm, "mm", precip_row.time, start, end))
    return out


def _extreme(series: list[DailyValues], start: date, end: date, field_name: str, picker) -> DailyValues | None:
    candidates = [
        row
        for row in series
        if _in_period(row.time, start, end) and getattr(row, field_name) is not None
    ]
    if not candidates:
        return None
    return picker(candidates, key=lambda row: getattr(row, field_name))


def _extreme_record(code: str, value: float, unit: str, occurred_on: date, start: date, end: date) -> dict:
    wording = {
        "daily_tmax_max": "Najviša dnevna maksimalna temperatura u analiziranom ERA5-Land nizu",
        "daily_tmin_min": "Najniža dnevna minimalna temperatura u analiziranom ERA5-Land nizu",
        "daily_precip_max": "Najveća dnevna količina padavina u analiziranom ERA5 nizu",
    }
    return {
        "metric_code": code,
        "value": _round(value, 2 if unit == "°C" else 1),
        "unit": unit,
        "occurred_on": occurred_on.isoformat(),
        "period_start": start.isoformat(),
        "period_end": end.isoformat(),
        "wording": wording[code],
        "kind": "gridded_reanalysis_extreme",
    }


def quality_flag(cell_count: int) -> str:
    if cell_count <= 0:
        return "unavailable"
    return "coarse_spatial_resolution"


def dominant_cell(weights: list[WeightedCell]) -> WeightedCell | None:
    if not weights:
        return None
    return max(weights, key=lambda item: item.weight)


def _round(value: float | None, digits: int) -> float | None:
    if value is None:
        return None
    return round(float(value), digits)


def build_profile(
    *,
    settlement_id: int,
    boundary_id: int | None,
    municipality_slug: str | None,
    settlement_slug: str | None,
    settlement_name: str | None,
    boundary_version: str | None,
    temperature_weights: list[WeightedCell],
    precipitation_weights: list[WeightedCell],
    series: list[DailyValues],
    historical_end: date,
    calculated_at: str,
) -> dict:
    if not temperature_weights or not precipitation_weights or not series:
        return {
            "settlement_id": settlement_id,
            "boundary_id": boundary_id,
            "municipality_slug": municipality_slug,
            "settlement_slug": settlement_slug,
            "settlement_name": settlement_name,
            "quality_flag": "insufficient_source" if series == [] else "unavailable",
            "status": "unavailable",
        }

    monthly = monthly_normals(series, NORMAL_START, NORMAL_END)
    annual = annual_from_monthly(monthly)
    ref_mean = period_mean_temperature(series, REFERENCE_START, REFERENCE_END)
    change = None
    if ref_mean is not None and annual["temperature_mean_c"] is not None:
        change = _round(annual["temperature_mean_c"] - ref_mean, 2)
    temp_dom = dominant_cell(temperature_weights)
    precip_dom = dominant_cell(precipitation_weights)
    return {
        "settlement_id": settlement_id,
        "boundary_id": boundary_id,
        "municipality_slug": municipality_slug,
        "settlement_slug": settlement_slug,
        "settlement_name": settlement_name,
        "status": "ok",
        "quality_flag": quality_flag(len(temperature_weights)),
        "spatial_method": "area_weighted_grid_intersection",
        "algorithm_version": ALGORITHM_VERSION,
        "source_resolution_km": ERA5_LAND_RESOLUTION_KM,
        "grid_cell_count": len(temperature_weights),
        "normal_period_start": NORMAL_START.isoformat(),
        "normal_period_end": NORMAL_END.isoformat(),
        "historical_period_start": HISTORICAL_START.isoformat(),
        "historical_period_end": historical_end.isoformat(),
        "boundary_version": boundary_version,
        "calculated_at": calculated_at,
        "annual": annual,
        "monthly": monthly,
        "indices": threshold_indices(series, NORMAL_START, NORMAL_END),
        "extremes": extremes(series, HISTORICAL_START, historical_end),
        "temperature_mean_1961_1990_c": ref_mean,
        "temperature_mean_change_c": change,
        "metadata": {
            "timezone": TIMEZONE_NAME,
            "temperature_dataset": "ERA5-Land",
            "precipitation_dataset": "ERA5",
            "precipitation_note": "ERA5-Land precipitation is an atmospheric forcing field; Open-Meteo ERA5-Land returns null precipitation. Totals use ERA5 (0.25°).",
            "representative_cell_only": len(temperature_weights) == 1,
            "temperature_cells": [
                {"lat": item.cell.lat, "lon": item.cell.lon, "weight": _round(item.weight, 6)}
                for item in temperature_weights
            ],
            "precipitation_cells": [
                {"lat": item.cell.lat, "lon": item.cell.lon, "weight": _round(item.weight, 6), "resolution_km": ERA5_RESOLUTION_KM}
                for item in precipitation_weights
            ],
            "dominant_temperature_cell": (
                {"lat": temp_dom.cell.lat, "lon": temp_dom.cell.lon, "weight": _round(temp_dom.weight, 6)}
                if temp_dom
                else None
            ),
            "dominant_precipitation_cell": (
                {"lat": precip_dom.cell.lat, "lon": precip_dom.cell.lon, "weight": _round(precip_dom.weight, 6)}
                if precip_dom
                else None
            ),
            "point_semantics": "area_weighted_grid_intersection; not a weather-station observation",
        },
    }
