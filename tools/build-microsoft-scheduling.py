#!/usr/bin/env python3
"""Embed reviewed code and recovery helpers in one byte-reproducible installer."""
import base64
import hashlib
import json
from pathlib import Path
import re
import zlib
root=Path(__file__).resolve().parents[1]
def historical(path):
 frozen=root/'tests/fixtures/lifecycle-before'/path.name
 return frozen if '_private' in path.parts and frozen.is_file() else path
names=['booking-scheduling-provider.php','booking-confirmation.php','booking-invitation.php','booking-availability-check.php','booking-staff.php']
files={'server/'+name:historical(root/'_private/server'/name).read_text() for name in names}
# Preserve the already deployed r1 controller when rebuilding its historical installer.
files['server/booking-staff.php']=(root/'tests/fixtures/staff-workflow-before/booking-staff.php').read_text()
files.update({name:(root/name).read_text() for name in ['tools/diagnose-calendar-confirmation.php','tools/audit-calendar-confirmation.php']})
before={('tools/' if p.name.startswith(('audit-','diagnose-')) else 'server/')+p.name:hashlib.sha256(p.read_bytes().replace(b'\r\n',b'\n')).hexdigest() for p in (root/'tests/fixtures/microsoft-scheduling-before').glob('*.php')}
bundle=json.dumps({'files':files,'before':before},sort_keys=True).encode()
recovery=(root/'tools/finish-microsoft-calendar-connection.py').read_bytes()
p=root/'tools/install-microsoft-scheduling.py';source=p.read_text()
for key,raw in [('BUNDLE',bundle),('RECOVERY',recovery)]:
 source=re.sub(r'^'+key+r' = .*$',key+' = '+repr(base64.b64encode(zlib.compress(raw,9)).decode()),source,flags=re.M)
 source=re.sub(r'^'+key+r'_SHA = .*$',key+'_SHA = '+repr(hashlib.sha256(raw).hexdigest()),source,flags=re.M)
p.write_text(source)
