import json
import sqlite3
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
root = Path(__file__).resolve().parents[2]
db = sqlite3.connect(root / "database" / "database.sqlite")
db.row_factory = sqlite3.Row
rows = db.execute(
    """
    select m.slug as muni, m.name as muni_name, s.name, s.slug, s.statistical_code,
     b.source_feature_id, b.area_km2, b.geometry_valid,
     s.geographic_centroid_lat, s.geographic_centroid_lng, s.label_point_lat,
     t.elevation_min_m, t.elevation_max_m, t.elevation_mean_m, t.elevation_median_m, t.elevation_range_m,
     t.slope_mean_deg, t.valid_cell_count, t.nodata_cell_count, t.quality_flag
    from geo_settlements s
    join geo_municipalities m on m.id=s.municipality_id
    left join settlement_boundaries b on b.settlement_id=s.id
    left join settlement_terrain_stats t on t.settlement_id=s.id
    where m.slug in ('modrica','samac')
    order by m.slug, s.name
    """
).fetchall()
print("count", len(rows))
print("| settlement | municipality | feature | status | area_km2 | samples | min | max | mean | median | range | quality |")
print("|---|---|---|---|---|---|---|---|---|---|---|---|")


def n(value, digits=1):
    if value is None:
        return "—"
    return f"{float(value):.{digits}f}"


for row in rows:
    status = "has_boundary" if row["source_feature_id"] else "missing_boundary"
    print(
        f"| {row['name']} | {row['muni_name']} | {row['source_feature_id'] or '—'} | {status} | "
        f"{n(row['area_km2'], 4)} | {row['valid_cell_count'] or '—'} | {n(row['elevation_min_m'])} | "
        f"{n(row['elevation_max_m'])} | {n(row['elevation_mean_m'])} | {n(row['elevation_median_m'])} | "
        f"{n(row['elevation_range_m'])} | {row['quality_flag'] or '—'} |"
    )

print("\n--- milosevac ---")
for row in rows:
    if row["slug"] == "milosevac":
        print(dict(row))
