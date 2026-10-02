#!/usr/bin/env python3
"""Reproduce the pinned file-only calendar notice installer."""
import base64, hashlib, json, pathlib, zlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
    data=json.loads((ROOT/'documents/calendar-notice-source.json').read_text())
    for n,item in data['files'].items():
        source=n.replace('private/','_private/',1) if n.startswith('private/') else n
        if (ROOT/source).read_bytes()!=base64.b64decode(item['after']):raise RuntimeError('Reviewed source changed: '+source)
    for n,accepted in data['dependencies'].items():
        source=n.replace('private/','_private/',1) if n.startswith('private/') else n
        if hashlib.sha256((ROOT/source).read_bytes()).hexdigest() not in accepted:raise RuntimeError('Reviewed dependency changed: '+source)
    raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
    template=(ROOT/'tools/calendar-notice-template.py').read_text()
    code=template.replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
    (ROOT/'tools/install-re-calendar-notice-20261002-r1.py').write_text(code)
if __name__=='__main__':build()
