#!/usr/bin/env python3
"""Reproduce the reviewed single-upload release from pinned source bytes."""
import base64, hashlib, json, pathlib, zlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
def digest(b):return hashlib.sha256(b).hexdigest()
def build():
    manifest=json.loads((ROOT/'documents/portal/installation-source.json').read_text())
    files={}
    for source,expected in manifest['sources'].items():
        b=(ROOT/source).read_bytes()
        if digest(b)!=expected:raise RuntimeError('Source differs: '+source)
        name=source.replace('_private/','private/',1)
        files[name]=base64.b64encode(b).decode()
    data={k:v for k,v in manifest.items() if k!='sources'};data['files']=files
    raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
    template=(ROOT/'tools/portal-installer-template.py').read_text()
    output=template.replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',digest(raw))
    (ROOT/'tools/install-customer-portal.py').write_text(output)
if __name__=='__main__':build()
