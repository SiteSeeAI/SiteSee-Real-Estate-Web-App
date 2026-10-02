#!/usr/bin/env python3
"""Reproduce the pinned file-only portal polish installer."""
import base64, hashlib, json, pathlib, zlib
from portal_source_chain import before_email_update
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
    data=json.loads((ROOT/'documents/portal/polish-source.json').read_text())
    for n,item in data['files'].items():
        source=n.replace('private/','_private/',1) if n.startswith('private/') else n
        expected=base64.b64decode(item['after'])
        staff=json.loads((ROOT/'documents/portal/staff-review-source.json').read_text())['files'].get(n)
        if staff:
            if base64.b64decode(staff['before'])!=expected:raise RuntimeError('Staff review baseline changed: '+source)
            expected=base64.b64decode(staff['after'])
        if before_email_update(ROOT,source)!=expected:raise RuntimeError('Reviewed source changed: '+source)
    for n,accepted in data['dependencies'].items():
        source=n.replace('private/','_private/',1) if n.startswith('private/') else n
        if hashlib.sha256(before_email_update(ROOT,source)).hexdigest() not in accepted:raise RuntimeError('Reviewed dependency changed: '+source)
    raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
    template=(ROOT/'tools/portal-polish-template.py').read_text()
    code=template.replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
    (ROOT/'tools/install-re-portal-polish-20261002-r1.py').write_text(code)
if __name__=='__main__':build()
