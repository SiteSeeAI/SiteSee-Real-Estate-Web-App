#!/usr/bin/env python3
import base64,hashlib,json,re,zlib
from pathlib import Path
root=Path(__file__).resolve().parents[1]
names=['booking-lifecycle.php','booking-workflow.php','booking-lifecycle-reconcile.php']
data={'files':{'server/'+n:((root/'tests/fixtures/appointment-r2'/n) if (root/'tests/fixtures/appointment-r2'/n).exists() else root/'_private/server'/n).read_text() for n in names},'report':(root/'tools/appointment-management-report.py').read_text()}
p=root/'tools/repair-appointment-management.py';s=p.read_text()
for key,raw in [('BASE',(root/'tests/fixtures/appointment-r1/install-appointment-management.py').read_bytes()),('BUNDLE',json.dumps(data).encode())]:
 s=re.sub(r'^'+key+r' = .*$',key+' = '+repr(base64.b64encode(zlib.compress(raw,9)).decode()),s,flags=re.M)
 s=re.sub(r'^'+key+r'_SHA = .*$',key+'_SHA = '+repr(hashlib.sha256(raw).hexdigest()),s,flags=re.M)
p.write_text(s)
