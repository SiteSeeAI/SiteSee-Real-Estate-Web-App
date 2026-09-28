#!/usr/bin/env python3
import base64,hashlib,json,re,zlib
from pathlib import Path
root=Path(__file__).resolve().parents[1]
# Helpers first; existing controllers last; the installer writes its TEST switch last of all.
new=['booking-lifecycle-store.php','booking-lifecycle.php','booking-lifecycle-ui.php','booking-manage.php','booking-lifecycle-reconcile.php']
changed=['booking-schedule.php','booking-scheduling-provider.php','booking-confirmation.php','booking-communication.php','booking-invitation.php','booking-workflow.php','booking-staff.php']
files={'server/'+n:(root/'_private/server'/n).read_text() for n in new+changed}
files['public/manage-appointment.php']=(root/'public/manage-appointment.php').read_text()
dependencies=['real-estate-form-config.php','real-estate-pricing.php']+['server/'+n for n in changed+['booking-store.php','booking-calendar-client.php','booking-microsoft-calendar.php','booking-mail-client.php','booking-crm.php','booking-availability.php']]
before={}
for n in dependencies:
 frozen=root/'tests/fixtures/lifecycle-before'/Path(n).name
 p=frozen if frozen.exists() else root/'_private'/n
 before[n]=hashlib.sha256(p.read_bytes()).hexdigest()
bundle=json.dumps({'files':files,'before':before},sort_keys=False).encode()
p=root/'tools/install-appointment-management.py';source=p.read_text()
for key,raw in [('BASE',(root/'tests/fixtures/lifecycle-before/install-booking-workflow.py').read_bytes()),('BUNDLE',bundle)]:
 source=re.sub(r'^'+key+r' = .*$',key+' = '+repr(base64.b64encode(zlib.compress(raw,9)).decode()),source,flags=re.M)
 source=re.sub(r'^'+key+r'_SHA = .*$',key+'_SHA = '+repr(hashlib.sha256(raw).hexdigest()),source,flags=re.M)
p.write_text(source)
manifest={'release':'appointment-management-20260928','revision':'20260928-r1','files':{n:hashlib.sha256(v.encode()).hexdigest() for n,v in files.items()}}
(root/'documents/deployment/Appointment_Management_Manifest_20260928.json').write_text(json.dumps(manifest,indent=2)+'\n')
