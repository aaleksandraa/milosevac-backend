"""Operator-only acquisition of the OSM BiH national boundary used to flag cross-border targets."""
import argparse
import hashlib
import json
import time
from pathlib import Path
from datetime import datetime, timezone
from urllib.error import HTTPError
from urllib.request import Request, urlopen
from urllib.parse import urlencode


def fetch(output, endpoint, iso):
    if Path(output).exists():
        raise FileExistsError('Retain the raw cache; choose a new output path for a new acquisition')
    query = f'[out:json][timeout:90];relation["boundary"="administrative"]["admin_level"="2"]["ISO3166-1"="{iso}"];out meta geom;'
    request = Request(endpoint, data=urlencode({'data': query}).encode(), headers={'User-Agent': 'Milosevac-Settlement-Atlas-M11/1.0 (offline research)'})
    for attempt in range(5):
        try:
            with urlopen(request, timeout=240) as response:
                raw = response.read()
            break
        except HTTPError as error:
            if error.code not in {429, 502, 503, 504} or attempt == 4: raise
            time.sleep(10 * 2 ** attempt)
    data = json.loads(raw)
    relations = [e for e in data.get('elements', []) if e['type'] == 'relation']
    if data.get('remark') or len(relations) != 1:
        raise ValueError('Expected exactly one national boundary relation: '+str(data.get('remark')))
    path = Path(output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(raw)
    meta = {'endpoint': endpoint, 'retrieved_at': datetime.now(timezone.utc).isoformat(),
            'osm_timestamp': data.get('osm3s', {}).get('timestamp_osm_base'), 'iso3166_1': iso,
            'osm_relation_id': relations[0]['id'], 'osm_version': relations[0].get('version'),
            'query': query, 'query_sha256': hashlib.sha256(query.encode()).hexdigest(),
            'raw_sha256': hashlib.sha256(raw).hexdigest(), 'raw_bytes': len(raw),
            'license': 'ODbL-1.0', 'attribution': '© OpenStreetMap contributors',
            'license_url': 'https://opendatacommons.org/licenses/odbl/1-0/'}
    path.with_suffix('.manifest.json').write_text(json.dumps(meta, indent=2, ensure_ascii=False), encoding='utf-8')
    print(json.dumps(meta, ensure_ascii=False))


if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--output', required=True)
    p.add_argument('--endpoint', default='https://overpass-api.de/api/interpreter')
    p.add_argument('--iso', default='BA')
    a = p.parse_args()
    fetch(a.output, a.endpoint, a.iso)
