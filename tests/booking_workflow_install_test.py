import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import unittest
from unittest.mock import patch

PROJECT=Path(__file__).resolve().parents[1]
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
w=load('workflow_installer',PROJECT/'tools/install-booking-workflow.py')
base=load('workflow_fixture',PROJECT/'tests/microsoft_scheduling_install_test.py')

class WorkflowInstall(unittest.TestCase):
 def setUp(self):
  self.fixture=base.SchedulingInstall();self.fixture.setUp();self.addCleanup(self.fixture.doCleanups)
  self.root=self.fixture.root;self.uid,self.gid=os.getuid(),os.getgid()
  self.s,self.r,self.m=w.modules();self.files,self.before=w.bundle(self.m)
  base.s.deploy(self.m,self.root,self.fixture.values(),self.uid,self.gid)
  writer=self.fixture.fixture.fixture.write
  for name in self.before:
   path=PROJECT/'tests/fixtures/staff-workflow-before/booking-staff.php' if name==w.CONTROLLER else PROJECT/'_private'/name
   writer(name,path.read_bytes())
  # Keep the fixture's active manifest consistent with the real reviewed dependencies.
  manifest=json.loads((self.root/'calendar-confirmation-release.json').read_bytes())
  manifest['files'].update({n:h for n,h in self.before.items() if n.startswith('server/')})
  manifest['files'].pop(w.NAMES[0],None)
  if (self.root/w.NAMES[0]).exists():(self.root/w.NAMES[0]).unlink()
  writer('calendar-confirmation-release.json',self.m.encode(manifest))
  mail=json.loads((self.root/'booking-mail.json').read_bytes());mail['enabled']=True;writer('booking-mail.json',self.m.encode(mail))
  writer('zoho-crm.json',self.m.encode(dict(client_id='fixture',client_secret='fixture',refresh_token='fixture',org_id='100',user_id='200',
    api_domain='https://www.zohoapis.com',accounts_domain='https://accounts.zoho.com',sync_mode='api',original_sync_mode='api',sync_reviewed_at='reviewed',owner_verified_at='reviewed')))
  db=sqlite3.connect(str(self.root/'data/bookings.sqlite'));db.executescript('''
    CREATE TABLE bookings(reference TEXT,email TEXT,status TEXT,deposit_paid_at TEXT,crm_contact_id TEXT,approved_at TEXT);
    CREATE TABLE booking_contact_links(reference TEXT,org_id TEXT,contact_id TEXT,email TEXT,verified_at TEXT);
    CREATE TABLE booking_communications(communication_key TEXT,reference TEXT,kind TEXT,submission_state TEXT,
      provider_message_id TEXT,internet_message_id TEXT,sent_at TEXT,delivery_state TEXT,crm_state TEXT,crm_contact_id TEXT,crm_org_id TEXT);
    INSERT INTO bookings VALUES('D32FFC7458','cro@sitesee.ai','deposit_paid_test','paid','300','reviewed');
    INSERT INTO booking_communications VALUES('invitation:D32FFC7458','D32FFC7458','invitation','uncertain','immutable',NULL,NULL,'unverified','pending',NULL,NULL);
  ''');db.close()
 def scan(self):
  return w.inventory(self.s,self.r,self.m,self.root,base.f.baseline.PHP,self.uid,self.files,self.before,credentials=self.fixture.secret)
 def values(self):return w.desired(self.m,self.root,self.files)
 def test_payload_and_rebuild(self):
  for n,b in self.files.items():self.assertEqual((PROJECT/'_private'/n).read_bytes(),b)
  p=PROJECT/'tools/install-booking-workflow.py';before=p.read_bytes()
  subprocess.run(['python3',str(PROJECT/'tools/build-booking-workflow.py')],check=True)
  self.assertEqual(before,p.read_bytes())
 def test_install_controller_last_rerun_preserves_booking_attempt_and_settings(self):
  self.assertEqual(self.scan().errors,[])
  before={p:p.read_bytes() for p in (self.root/'data/bookings.sqlite',self.fixture.secret,self.root/'microsoft-scheduling.json',self.root/'zoho-crm.json')}
  writes=[]
  def write(p,b,u,g):writes.append(p.relative_to(self.root).as_posix());self.m.atomic_write(p,b,u,g)
  w.deploy(self.m,self.root,self.values(),self.uid,self.gid,write)
  self.assertEqual(writes[-1],w.CONTROLLER);self.assertEqual(self.scan().errors,[])
  self.assertIsNone(w.deploy(self.m,self.root,self.values(),self.uid,self.gid))
  for p,b in before.items():self.assertEqual(p.read_bytes(),b)
 def test_failure_after_every_write_restores_microsoft_controller_and_manifests(self):
  originals={n:(self.root/n).read_bytes() if (self.root/n).exists() else None for n in self.values()}
  for step in range(1,len(self.values())+1):
   count=[0]
   def fail(p,b,u,g):
    self.m.atomic_write(p,b,u,g);count[0]+=1
    if count[0]==step:raise OSError('post-rename failure')
   with self.assertRaises(OSError):w.deploy(self.m,self.root,self.values(),self.uid,self.gid,fail)
   for n,b in originals.items():
    if n==w.NAMES[0]:self.assertEqual((self.root/n).read_bytes(),self.files[n])
    else:self.assertEqual((self.root/n).read_bytes() if (self.root/n).exists() else None,b,n)
   self.assertEqual(self.scan().errors,[])
 def test_process_interruption_recovers_on_same_command(self):
  count=[0]
  def interrupt(p,b,u,g):
   self.m.atomic_write(p,b,u,g);count[0]+=1
   if count[0]==3:raise KeyboardInterrupt()
  with self.assertRaises(KeyboardInterrupt):w.deploy(self.m,self.root,self.values(),self.uid,self.gid,interrupt)
  with self.assertRaises(self.m.InstallError):w.recover(self.m,self.root,self.uid,self.gid,check=True)
  w.recover(self.m,self.root,self.uid,self.gid)
  self.assertEqual(self.scan().errors,[])
  w.deploy(self.m,self.root,self.values(),self.uid,self.gid);self.assertEqual(self.scan().errors,[])
 def test_unknown_edits_not_overwritten_by_recovery(self):
  def interrupt(p,b,u,g):self.m.atomic_write(p,b,u,g);raise KeyboardInterrupt()
  with self.assertRaises(KeyboardInterrupt):w.deploy(self.m,self.root,self.values(),self.uid,self.gid,interrupt)
  (self.root/w.CONTROLLER).write_bytes(b'operator edit')
  with self.assertRaises(self.m.InstallError):w.recover(self.m,self.root,self.uid,self.gid)
  self.assertEqual((self.root/w.CONTROLLER).read_bytes(),b'operator edit')
 def test_permission_repairs_collected_in_one_inventory(self):
  for n in ['server/booking-staff.php','server/booking-crm.php','zoho-crm.json']:(self.root/n).chmod(0o666)
  (self.root/'server').chmod(0o777)
  report=self.scan();self.assertEqual(report.errors,[]);self.assertEqual(len(report.repairs),4)
  self.r.apply_repairs(self.m,report,self.uid,self.gid)
  self.assertFalse(self.scan().repairs);self.assertEqual(self.scan().errors,[])
 def test_settings_code_and_schema_errors_are_reported_together(self):
  (self.root/'server/booking-crm.php').write_bytes(b'changed')
  (self.root/'microsoft-scheduling.json').write_bytes(b'{}')
  crm=json.loads((self.root/'zoho-crm.json').read_bytes());crm['sync_mode']='native';(self.root/'zoho-crm.json').write_bytes(self.m.encode(crm))
  db=sqlite3.connect(str(self.root/'data/bookings.sqlite'));db.execute('DROP TABLE booking_contact_links');db.close()
  errors='\n'.join(self.scan().errors)
  for term in ('Unreviewed application','activation differs','CRM identity','booking_contact_links'):self.assertIn(term,errors)
  self.assertNotIn('refresh_token',errors)
 def test_inspection_is_read_only(self):
  snap={p:p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
  self.assertEqual(self.scan().errors,[])
  for p,b in snap.items():self.assertEqual(p.read_bytes(),b)
 def test_provider_failures_do_not_hide_other_checks(self):
  calls=[]
  def fail(*args,**kwargs):calls.append(args);raise RuntimeError('secret provider payload')
  with patch.object(self.s,'fresh_read',side_effect=fail),patch.object(w.subprocess,'run',side_effect=fail):
   with self.assertRaises(self.m.InstallError) as e:w.provider_reads(self.s,self.m,self.root,'php',None)
  self.assertEqual(len(calls),3);self.assertNotIn('secret',str(e.exception))

if __name__=='__main__':unittest.main()
