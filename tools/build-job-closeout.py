#!/usr/bin/env python3
"""Reproduce the reviewed, file-only onsite closeout / Production TEST installer."""
import base64, hashlib, json, pathlib, zlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
    data=json.loads((ROOT/'documents/portal/job-closeout-source.json').read_text())
    for name,item in data['files'].items():
        source=name.replace('private/','_private/',1) if name.startswith('private/') else name
        if (ROOT/source).read_bytes()!=base64.b64decode(item['after']):raise RuntimeError('Reviewed source changed: '+source)
    for name,accepted in data['dependencies'].items():
        source=name.replace('private/','_private/',1) if name.startswith('private/') else name
        current=(ROOT/source).read_bytes()
        variants=list(dict.fromkeys(hashlib.sha256(b).hexdigest() for b in [current,current.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')]))
        if accepted!=variants:raise RuntimeError('Reviewed dependency or line-ending compatibility changed: '+source)
    raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
    code=(ROOT/'tools/job-closeout-template.py').read_text().replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
    (ROOT/'tools/install-re-job-closeout-20261003-r1_1.py').write_text(code)
if __name__=='__main__':build()
