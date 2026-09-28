#!/usr/bin/env python3
"""Local read-only evidence; never print customer data, private links or payment identifiers."""
import json,sqlite3
from pathlib import Path
from collections import deque

def report(root,reference='3EB7F85259'):
 db=sqlite3.connect((root/'data/bookings.sqlite').as_uri()+'?mode=ro',uri=True)
 db.row_factory=sqlite3.Row;db.execute('PRAGMA query_only=ON');db.execute('BEGIN')
 def rows(table):return [dict(r) for r in db.execute('SELECT * FROM '+table+' WHERE reference=?',(reference,))]
 def out(label,value):print(label+': '+json.dumps(value,sort_keys=True))
 bookings=rows('bookings')
 if len(bookings)!=1:
  print('Booking '+reference+': reference not found; no records changed.');db.close();return
 b=bookings[0];out('Booking',reference);out('Payment state',b['status'])
 states=rows('booking_lifecycle');out('Appointment state',[{k:s.get(k) for k in ('state','revision','checked_at','diagnostic')} for s in states])
 changes=rows('booking_lifecycle_operations')
 for op in sorted(changes,key=lambda r:r['revision']):
  saved=json.loads(op['payload_json']);old=saved.get('row',{})
  # Compare the real base-table columns only. booking_get also joins scheduling fields.
  keys=set(b)-{'requested_utc'}
  changed=sorted(k for k in keys if k not in old or old[k]!=b[k])
  claim=rows('booking_confirmations')
  out('Change',{'revision':op['revision'],'action':op['action'],'actor':op['actor'],'state':op['state'],
   'base_booking_fields_preserved':not changed,'changed_field_names':changed,
   'same_provider_event':bool(claim) and saved.get('claim',{}).get('event_uid')==claim[0].get('event_uid')})
 for n in sorted(rows('booking_communications'),key=lambda r:r['created_at']):
  out('Communication',{k:n.get(k) for k in ('kind','submission_state','delivery_state','crm_state')})
 out('Pending operations',sum(o['state'] in ('prepared','uncertain') for o in changes))
 out('Local booking hold released',bool(states) and states[0]['state']=='cancelled' and not any(o['state'] in ('prepared','uncertain') for o in changes))
 db.close()
 log=root/'lifecycle-reconcile.log'
 print('Scheduled reconciliation log (recent safe status lines):')
 if not log.is_file():print('No log yet; scheduled execution unverified.')
 else:
  with log.open(errors='replace') as f:lines=list(deque(f,maxlen=12))
  import re
  safe=[line.rstrip() for line in lines if re.fullmatch(r'[0-9T:+-]+ (?:Worker started\.|Worker stopped: PHP failure; inspect private server logs\.|Worker stopped: stage=[a-z]+; type=[A-Za-z]+; code=[A-Za-z0-9_-]+\.|Reconciled: [0-9]+; review required: [0-9]+\. No calendar or mail writes\.)\n?',line)]
  print('\n'.join(safe) if safe else 'No completed scheduled run established. Inspect cron diagnostics below.')
 print('READ-ONLY BOOKING REPORT COMPLETE')

if __name__=='__main__':report(Path('/home/sitesee/.sitesee-real-estate'))
