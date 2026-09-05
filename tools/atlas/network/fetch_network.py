"""Operator-only regional Overpass acquisition; immutable raw bytes and provenance."""
import argparse
import hashlib
import json
from pathlib import Path
from datetime import datetime, timezone
from urllib.request import Request, urlopen
from urllib.parse import urlencode
from shapely.geometry import shape


def fetch(jobs_path, output, endpoint):
    if Path(output).exists():
        raise FileExistsError('Retain the raw cache; choose a new output path for a new acquisition')
    jobs = json.loads(Path(jobs_path).read_text(encoding='utf-8'))['jobs']
    bounds = [shape(j['geometry']).bounds for j in jobs if j['geometry']]
    # Regional halo supports nearest network targets; all geometries returned whole.
    bbox = [min(b[1] for b in bounds)-.1, min(b[0] for b in bounds)-.1,
            max(b[3] for b in bounds)+.1, max(b[2] for b in bounds)+.1]
    box = ','.join(f'{n:.6f}' for n in bbox)
    query = f'[out:json][timeout:180];(way[highway]({box});way[waterway]({box});way[natural=water]({box});relation[natural=water]({box});relation[waterway=riverbank]({box}););out meta geom;'
    request = Request(endpoint, data=urlencode({'data': query}).encode(), headers={'User-Agent': 'Milosevac-Settlement-Atlas-M11/1.0 (offline research)'})
    with urlopen(request, timeout=240) as response:
        raw = response.read()
    data = json.loads(raw)
    if data.get('remark') or not data.get('elements'):
        raise ValueError('Incomplete/empty Overpass response: '+str(data.get('remark')))
    path = Path(output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(raw)
    meta = {'endpoint': endpoint, 'retrieved_at': datetime.now(timezone.utc).isoformat(),
            'osm_timestamp': data.get('osm3s', {}).get('timestamp_osm_base'), 'bbox': bbox,
            'query': query, 'query_sha256': hashlib.sha256(query.encode()).hexdigest(),
            'raw_sha256': hashlib.sha256(raw).hexdigest(), 'raw_bytes': len(raw),
            'license': 'ODbL-1.0', 'attribution': '© OpenStreetMap contributors',
            'license_url': 'https://opendatacommons.org/licenses/odbl/1-0/'}
    path.with_suffix('.manifest.json').write_text(json.dumps(meta, indent=2, ensure_ascii=False), encoding='utf-8')
    print(json.dumps(meta, ensure_ascii=False))


if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--jobs', required=True)
    p.add_argument('--output', required=True)
    p.add_argument('--endpoint', default='https://overpass-api.de/api/interpreter')
    a = p.parse_args()
    fetch(a.jobs, a.output, a.endpoint)
