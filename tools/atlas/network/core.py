"""M11.1: metric clipping and explicitly straight-line proximity, never routing."""
import json
from collections import defaultdict
from shapely.geometry import LineString, Polygon, Point, shape, mapping
from shapely.ops import transform, polygonize, unary_union, nearest_points
from pyproj import Transformer, Geod

ALGORITHM = 'm11.1'
PROJECT = Transformer.from_crs(4326, 3035, always_xy=True).transform
GEOD = Geod(ellps='WGS84')
MAJOR = {'motorway', 'trunk', 'primary', 'secondary'}
CLASSES = MAJOR | {'tertiary', 'residential', 'unclassified', 'service', 'track', 'path', 'pedestrian', 'cycleway', 'footway', 'living_street'}
LINE_WATER = {'river', 'stream', 'canal', 'drain', 'ditch'}
POI_METRICS = {
    'nearest_pharmacy': ['pharmacy'], 'nearest_hospital': ['hospital'],
    'nearest_health': ['hospital', 'clinic', 'doctors', 'health_centre'],
    'nearest_school': ['primary_school', 'secondary_school', 'school_other'],
    'nearest_fuel_station': ['fuel_station'], 'nearest_bus_station': ['bus_station'],
    'nearest_railway_station': ['railway_station'],
}


def road_class(tags):
    raw = tags.get('highway', '')
    base = raw.removesuffix('_link')
    return base if base in CLASSES else 'other'


def road_group(cls):
    if cls in MAJOR: return 'major'
    if cls in {'tertiary', 'unclassified'}: return 'local'
    if cls in {'residential', 'living_street'}: return 'residential'
    if cls == 'service': return 'service'
    if cls == 'track': return 'track'
    if cls in {'path', 'pedestrian', 'cycleway', 'footway'}: return 'paths'
    return 'other'


def coordinates(geometry):
    return [(p['lon'], p['lat']) for p in geometry]


def normalize(elements):
    unique = {}
    for e in elements:
        key = (e['type'], e['id'])
        old = unique.get(key)
        if old and old.get('version', 0) == e.get('version', 0) and old != e:
            raise ValueError(f'Conflicting OSM identity {key}')
        if old is None or e.get('version', 0) > old.get('version', 0): unique[key] = e
    objects, rejected = [], []
    # Polygon members represented by their relation must not be counted twice.
    area_members = {m['ref'] for e in unique.values() if e['type'] == 'relation' and
                    (e.get('tags', {}).get('natural') == 'water' or e.get('tags', {}).get('waterway') == 'riverbank')
                    for m in e.get('members', []) if m['type'] == 'way'}
    for key, e in sorted(unique.items()):
        t = e.get('tags', {})
        road = 'highway' in t and e['type'] == 'way' and t.get('area') != 'yes'
        linear = t.get('waterway') in LINE_WATER and e['type'] == 'way'
        area = t.get('natural') == 'water' or t.get('waterway') == 'riverbank'
        if not (road or linear or area): continue
        if area and not linear and e['type'] == 'way' and e['id'] in area_members: continue
        try:
            if e['type'] == 'relation':
                rings = {}
                for role in ['outer', 'inner']:
                    lines = [LineString(coordinates(m['geometry'])) for m in e.get('members', []) if m['type'] == 'way' and m.get('role', 'outer') in ([role, ''] if role == 'outer' else [role])]
                    rings[role] = unary_union(list(polygonize(unary_union(lines))))
                geom = rings['outer'].difference(rings['inner'])
            else:
                pts = coordinates(e['geometry'])
                if area and not linear and not road:
                    if pts[0] != pts[-1]: raise ValueError('Open water polygon')
                    geom = Polygon(pts)
                else: geom = LineString(pts)
            if geom.is_empty or not geom.is_valid: raise ValueError('Invalid geometry')
            cls = road_class(t) if road else t.get('waterway') if linear else t.get('water', 'riverbank' if t.get('waterway') == 'riverbank' else 'other_water')
            if not road and not linear and cls not in {'riverbank', 'lake', 'reservoir', 'pond', 'basin'}: cls = 'other_water'
            objects.append({'osm_type': e['type'], 'osm_id': e['id'], 'osm_version': e.get('version'),
                            'osm_timestamp': e.get('timestamp'), 'kind': 'road' if road else 'hydro',
                            'feature_type': cls, 'group': road_group(cls) if road else cls,
                            'name': t.get('name'), 'source_properties': t, 'geometry': mapping(geom)})
        except (ValueError, KeyError, TypeError) as ex:
            rejected.append({'identity': list(key), 'reason': str(ex)})
    return objects, rejected


def relation_and_clip(geometry, boundary):
    if not geometry.intersects(boundary): return None, geometry.intersection(boundary)
    relation = 'touches_boundary' if geometry.touches(boundary) else 'inside' if boundary.covers(geometry) else 'crosses'
    return relation, geometry.intersection(boundary)


def valid_point(row):
    try: return row.get('lat') is not None and row.get('lng') is not None and -90 <= float(row['lat']) <= 90 and -180 <= float(row['lng']) <= 180
    except (ValueError, TypeError): return False


def geodesic(a, b):
    return abs(GEOD.inv(float(a['lng']), float(a['lat']), float(b['lng']), float(b['lat']))[2])


def nearest_point(origin, targets):
    eligible = [t for t in targets if valid_point(t)]
    if not valid_point(origin) or not eligible: return None
    return min(((geodesic(origin, t), t) for t in eligible), key=lambda pair: (round(pair[0], 6), str(pair[1]['id'])))


def process(jobs, objects):
    projected = [(o, transform(PROJECT, shape(o['geometry']))) for o in objects]
    rows, links = [], []
    centres = {j['municipality_slug']: dict(j, id=j['settlement_id'], name=j['settlement_name']) for j in jobs['jobs'] if j['settlement_slug'] == j['municipality_slug'] and valid_point(j)}
    for j in jobs['jobs']:
        row = {k: j[k] for k in ['settlement_id', 'settlement_slug', 'settlement_name', 'municipality_slug', 'boundary_id', 'origin_type', 'lat', 'lng']}
        row.update(transport=None, hydrography=None, proximity=[])
        if j['geometry']:
            boundary = transform(PROJECT, shape(j['geometry']))
            if not boundary.is_valid: raise ValueError('Invalid settlement boundary')
            roads, groups, water = defaultdict(float), defaultdict(float), defaultdict(float)
            areas, named, surface = [], [], defaultdict(float)
            named_water_length = 0.0
            unnamed_water_length = 0.0
            for o, geom in projected:
                relation, clipped = relation_and_clip(geom, boundary)
                if relation is None: continue
                linear = geom.geom_type in ['LineString', 'MultiLineString']
                length = clipped.length / 1000 if linear else 0
                links.append({'settlement_id': j['settlement_id'], 'kind': o['kind'], 'osm_type': o['osm_type'], 'osm_id': o['osm_id'], 'relation': relation, 'length_km': length, 'area_km2': clipped.area/1e6 if not linear else 0})
                if o['kind'] == 'road':
                    roads[o['feature_type']] += length
                    groups[o['group']] += length
                    tag = o['source_properties'].get('surface')
                    surface['paved' if tag in {'asphalt', 'concrete', 'paving_stones', 'concrete:plates', 'concrete:lanes'} else 'unpaved' if tag in {'gravel', 'ground', 'dirt', 'unpaved', 'compacted', 'fine_gravel', 'sand', 'grass'} else 'unknown'] += length
                else:
                    if linear:
                        water[o['feature_type']] += length
                        if o['name']: named_water_length += length
                        else: unnamed_water_length += length
                    elif clipped.area: areas.append(clipped)
                    if o['name']: named.append({'osm_type': o['osm_type'], 'osm_id': o['osm_id'], 'name': o['name'], 'feature_type': o['feature_type'], 'relation': relation})
            total = sum(roads.values())
            row['transport'] = {'mapped_road_length_km': total, 'major_road_length_km': groups['major'], 'road_length_by_class_km': dict(roads), 'road_length_by_group_km': dict(groups), 'surface_length_km': dict(surface), 'mapped_road_density_km_per_km2': total / float(j['area_km2']) if j.get('area_km2') and float(j['area_km2']) > 0 else None}
            row['hydrography'] = {'waterway_length_km': sum(water.values()), 'length_by_type_km': dict(water), 'named_waterway_length_km': named_water_length, 'unnamed_waterway_length_km': unnamed_water_length, 'mapped_water_area_km2': unary_union(areas).area/1e6 if areas else 0, 'named_features': named}
        if valid_point(j):
            targets = {code: [p for p in jobs['pois'] if p['code'] in types] for code, types in POI_METRICS.items()}
            targets['municipality_centre'] = [centres[j['municipality_slug']]] if j['municipality_slug'] in centres else []
            for code, candidates in targets.items():
                hit = nearest_point(j, candidates)
                row['proximity'].append({'metric_code': code, 'target_type': 'settlement' if code == 'municipality_centre' else 'geo_place', 'target_id': hit[1]['id'] if hit else None, 'target_name': hit[1].get('name') if hit else None, 'distance_straight_m': hit[0] if hit else None, 'method': 'WGS84_geodesic', 'quality_flag': j['origin_type'] if hit else 'no_target', 'target_source_id': hit[1].get('source_id') if hit else None})
            point = transform(PROJECT, Point(float(j['lng']), float(j['lat'])))
            for code, candidates in [('nearest_major_road', [(o,g) for o,g in projected if o['kind']=='road' and o['feature_type'] in MAJOR]), ('nearest_named_watercourse', [(o,g) for o,g in projected if o['kind']=='hydro' and o['name'] and o['feature_type'] in LINE_WATER])]:
                hit = min(((point.distance(g), o) for o,g in candidates), key=lambda p: (round(p[0],6), p[1]['osm_type'], p[1]['osm_id']), default=None)
                row['proximity'].append({'metric_code': code, 'target_type': 'road' if code == 'nearest_major_road' else 'hydro', 'target_id': str(hit[1]['osm_id']) if hit else None, 'target_osm_type': hit[1]['osm_type'] if hit else None, 'target_name': hit[1]['name'] if hit else None, 'distance_straight_m': hit[0] if hit else None, 'method': 'EPSG3035_point_to_geometry', 'quality_flag': j['origin_type'] if hit else 'no_target'})
        rows.append(row)
    return rows, links
