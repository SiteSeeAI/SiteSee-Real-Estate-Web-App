#!/usr/bin/env python3
"""Build the complete pinned TEST phone-login package, offline and reproducibly."""
import base64, hashlib, json, pathlib, zlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
 data=json.loads((ROOT/'documents/portal/complete-source.json').read_text())
 # Application bytes must remain exactly those reviewed in the current source tree.
 for name,encoded in data['files'].items():
  source=name.replace('private/','_private/',1)
  if name.startswith('private/tools/'):source=name[len('private/'):]
  if (ROOT/source).read_bytes()!=base64.b64decode(encoded):raise RuntimeError('Pinned application source differs: '+source)
 raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
 template=(ROOT/'tools/portal-complete-template.py').read_text()
 code=template.replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
 code+='\n'+(ROOT/'tools/portal-complete-operations.py').read_text()
 (ROOT/'tools/install-portal-complete.py').write_text(code)
if __name__=='__main__':build()
