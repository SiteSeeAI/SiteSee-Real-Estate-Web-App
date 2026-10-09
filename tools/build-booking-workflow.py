#!/usr/bin/env python3
"""Package the consolidated workflow and its previously reviewed installer helpers."""
import base64
import hashlib
import json
from pathlib import Path
import re
import zlib
root=Path(__file__).resolve().parents[1]
import sys
sys.path.insert(0,str(root/'tests'))
from release_source import historical
files={name:historical(root/'_private'/name).read_text() for name in ('server/booking-workflow.php','server/booking-staff.php')}
dependencies=['real-estate-form-config.php','real-estate-pricing.php',
 'server/booking-store.php','server/booking-schedule.php','server/booking-crm.php','server/booking-communication.php',
 'server/booking-mail-client.php','server/booking-confirmation.php','server/booking-invitation.php',
 'server/booking-calendar-client.php','server/booking-availability.php','server/booking-scheduling-provider.php']
before={name:hashlib.sha256(historical(root/'_private'/name).read_bytes()).hexdigest() for name in dependencies}
before['server/booking-staff.php']=hashlib.sha256((root/'tests/fixtures/staff-workflow-before/booking-staff.php').read_bytes()).hexdigest()
bundle=json.dumps({'files':files,'before':before},sort_keys=True).encode()
path=root/'tools/install-booking-workflow.py';source=path.read_text()
for key,raw in [('BASE',(root/'tools/install-microsoft-scheduling.py').read_bytes()),('BUNDLE',bundle)]:
 source=re.sub(r'^'+key+r' = .*$',key+' = '+repr(base64.b64encode(zlib.compress(raw,9)).decode()),source,flags=re.M)
 source=re.sub(r'^'+key+r'_SHA = .*$',key+'_SHA = '+repr(hashlib.sha256(raw).hexdigest()),source,flags=re.M)
path.write_text(source)
