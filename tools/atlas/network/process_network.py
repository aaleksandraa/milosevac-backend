import argparse
import gzip
import hashlib
import json
from pathlib import Path
from core import normalize, process, ALGORITHM


def run(jobs_path, raw_path, output):
    raw = Path(raw_path)
    source = json.loads(raw.with_suffix('.manifest.json').read_text(encoding='utf-8'))
    if hashlib.sha256(raw.read_bytes()).hexdigest() != source['raw_sha256']: raise ValueError('Raw hash mismatch')
    elements = json.loads(raw.read_bytes())['elements']
    objects, rejected = normalize(elements)
    jobs = json.loads(Path(jobs_path).read_text(encoding='utf-8'))
    rows, links = process(jobs, objects)
    payload = {'algorithm_version': ALGORITHM, 'source': source, 'dependency_hash': jobs['dependency_hash'], 'objects': objects, 'records': rows, 'links': links,
               'summary': {'raw_road_objects': sum('highway' in e.get('tags', {}) for e in elements), 'raw_hydro_objects': sum('waterway' in e.get('tags', {}) or e.get('tags', {}).get('natural') == 'water' for e in elements),
                           'normalized_roads': sum(o['kind']=='road' for o in objects), 'normalized_hydro': sum(o['kind']=='hydro' for o in objects), 'clipped_relationships': len(links), 'rejected': rejected,
                           'pilot_road_km': sum(r['transport']['mapped_road_length_km'] for r in rows if r['transport']), 'pilot_waterway_km': sum(r['hydrography']['waterway_length_km'] for r in rows if r['hydrography'])}}
    path = Path(output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, separators=(',', ':'), allow_nan=False), encoding='utf-8')
    print(json.dumps(dict(payload['summary'], artifact_bytes=path.stat().st_size, gzip_bytes=len(gzip.compress(path.read_bytes()))), ensure_ascii=False))


if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--jobs', required=True); p.add_argument('--raw', required=True); p.add_argument('--output', required=True)
    a = p.parse_args(); run(a.jobs, a.raw, a.output)
