#!/usr/bin/env python3
import base64,hashlib,json,pathlib,re,zlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
 data=json.loads((ROOT/'documents/portal/re-workflow-source.json').read_text())
 upgrades=json.loads((ROOT/'documents/portal/re-draft-source.json').read_text())['files']
 for n,item in data['files'].items():
  expected=base64.b64decode(item['after'])
  if n in upgrades:
   if base64.b64decode(upgrades[n]['before'])!=expected:raise RuntimeError('Draft update baseline changed: '+n)
   expected=base64.b64decode(upgrades[n]['after'])
  polish=json.loads((ROOT/'documents/portal/polish-source.json').read_text())['files'].get('private/'+n)
  if polish:
   if base64.b64decode(polish['before'])!=expected:raise RuntimeError('Portal polish baseline changed: '+n)
   expected=base64.b64decode(polish['after'])
  if (ROOT/'_private'/n).read_bytes()!=expected:raise RuntimeError('Reviewed source changed: '+n)
 calendar=(ROOT/'_private/server/booking-microsoft-calendar.php').read_text()
 data['calendar_uid']='microsoft:'+re.search(r"const BOOKING_MS_CALENDAR = '([^']+)'",calendar)[1]
 raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
 template=(ROOT/'tools/re-business-workflow-template.py').read_text()
 code=template.replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
 (ROOT/'tools/install-re-business-workflow.py').write_text(code)
if __name__=='__main__':build()
