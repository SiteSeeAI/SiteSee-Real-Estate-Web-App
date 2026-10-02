#!/usr/bin/env python3
import base64,hashlib,json,pathlib,re,zlib
from portal_source_chain import before_email_update
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
 data=json.loads((ROOT/'documents/portal/re-draft-source.json').read_text())
 for n,item in data['files'].items():
  expected=base64.b64decode(item['after'])
  polish=json.loads((ROOT/'documents/portal/polish-source.json').read_text())['files'].get('private/'+n)
  if polish:
   if base64.b64decode(polish['before'])!=expected:raise RuntimeError('Portal polish baseline changed: '+n)
   expected=base64.b64decode(polish['after'])
  staff=json.loads((ROOT/'documents/portal/staff-review-source.json').read_text())['files'].get('private/'+n)
  if staff:
   if base64.b64decode(staff['before'])!=expected:raise RuntimeError('Staff review baseline changed: '+n)
   expected=base64.b64decode(staff['after'])
  if before_email_update(ROOT,'_private/'+n)!=expected:raise RuntimeError('Reviewed source changed: '+n)
 calendar=(ROOT/'_private/server/booking-microsoft-calendar.php').read_text()
 data['calendar_uid']='microsoft:'+re.search(r"const BOOKING_MS_CALENDAR = '([^']+)'",calendar)[1]
 raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
 template=(ROOT/'tools/re-draft-recovery-template.py').read_text()
 code=template.replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
 (ROOT/'tools/install-re-draft-recovery-r1.py').write_text(code)
if __name__=='__main__':build()
