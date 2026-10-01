#!/usr/bin/env python3
import base64,hashlib,json,pathlib,re,zlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build():
 data=json.loads((ROOT/'documents/portal/re-workflow-source.json').read_text())
 for n,item in data['files'].items():
  if (ROOT/'_private'/n).read_bytes()!=base64.b64decode(item['after']):raise RuntimeError('Reviewed source changed: '+n)
 calendar=(ROOT/'_private/server/booking-microsoft-calendar.php').read_text()
 data['calendar_uid']='microsoft:'+re.search(r"const BOOKING_MS_CALENDAR = '([^']+)'",calendar)[1]
 raw=json.dumps(data,sort_keys=True,separators=(',',':')).encode()
 template=(ROOT/'tools/re-business-workflow-template.py').read_text()
 code=template.replace('__PAYLOAD__',base64.b64encode(zlib.compress(raw,9)).decode()).replace('__PAYLOAD_SHA__',hashlib.sha256(raw).hexdigest())
 (ROOT/'tools/install-re-business-workflow.py').write_text(code)
if __name__=='__main__':build()
