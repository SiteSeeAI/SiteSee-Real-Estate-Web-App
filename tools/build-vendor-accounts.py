#!/usr/bin/env python3
"""Reproduce the reviewed separate vendor-account update."""
import base64, hashlib, json, pathlib, zlib
from unified_source import before_unified_update
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
    data=json.loads((ROOT/'documents/portal/vendor-accounts-source.json').read_text())
    for name,item in data['files'].items():
        source=name.replace('private/','_private/',1) if name.startswith('private/') else name
        if before_unified_update(ROOT,source)!=base64.b64decode(item['after']):raise RuntimeError('Reviewed source changed: '+source)
    for name,accepted in data['dependencies'].items():
        source=name.replace('private/','_private/',1) if name.startswith('private/') else name
        current=before_unified_update(ROOT,source)
        variants=list(dict.fromkeys(hashlib.sha256(b).hexdigest() for b in [current,current.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')]))
        if accepted!=variants:raise RuntimeError('Reviewed dependency or line-ending compatibility changed: '+source)
    raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
    code=(ROOT/'tools/vendor-accounts-template.py').read_text().replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
    (ROOT/'tools/install-re-vendor-accounts-20261005-r1.py').write_text(code)
if __name__=='__main__':build()
