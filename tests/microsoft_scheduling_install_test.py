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
 s=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
s=load('scheduling_installer',PROJECT/'tools/install-microsoft-scheduling.py')
f=load('recovery_fixture',PROJECT/'tests/microsoft_calendar_finish_test.py')

class SchedulingInstall(unittest.TestCase):
 def setUp(self):
  self.fixture=f.FinishTests();self.fixture.setUp();self.addCleanup(self.fixture.doCleanups)
  self.root,self.secret=self.fixture.root,self.fixture.secret
  self.uid,self.gid=os.getuid(),os.getgid();self.r,self.m=s.modules();self.files,self.before=s.bundle(self.m)
  for name,data in f.runner.desired_files(self.m,self.m.payload()).items():self.fixture.fixture.write(name,data)
  self.fixture.journal()
  for name in self.before:
   self.fixture.fixture.write(name,(PROJECT/'tests/fixtures/microsoft-scheduling-before'/Path(name).name).read_bytes())
  manifest=json.loads((self.root/'calendar-confirmation-release.json').read_bytes())
  manifest['files'].update(self.before)
  self.fixture.fixture.write('calendar-confirmation-release.json',self.m.encode(manifest))
  for name in ('zoho-calendar.json','zoho-confirmation.json'):
   self.fixture.fixture.write(name,self.m.encode({'calendar_uid':'a'*32,'enabled':True}))
  p=self.root/'data/bookings.sqlite';p.unlink()
  db=sqlite3.connect(str(p));db.executescript('''CREATE TABLE booking_confirmations(reference TEXT PRIMARY KEY,state TEXT,calendar_uid TEXT,event_uid TEXT,event_json TEXT,planned_start INTEGER,planned_end INTEGER,invitation_state TEXT);
   INSERT INTO booking_confirmations VALUES('OLD','confirmed','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','old@zoho','{}',100,200,'sent');
   CREATE TABLE payments(reference TEXT,amount INTEGER);INSERT INTO payments VALUES('OLD',12845);''');db.close();p.chmod(0o600)
 def scan(self):return s.inventory(self.r,self.m,self.root,f.baseline.PHP,self.uid,self.files,self.before,credentials=self.secret)
 def values(self):return s.desired(self.m,self.root,self.files)
 def test_reproducible_payload(self):
  for name,data in self.files.items():
   expected=PROJECT/'tests/fixtures/staff-workflow-before/booking-staff.php' if name=='server/booking-staff.php' else PROJECT/('_private/'+name if name.startswith('server/') else name)
   self.assertEqual(data,expected.read_bytes())
  path=PROJECT/'tools/install-microsoft-scheduling.py';before=path.read_bytes()
  subprocess.run(['python3',str(PROJECT/'tools/build-microsoft-scheduling.py')],check=True)
  self.assertEqual(path.read_bytes(),before)
 def test_preflight_deploy_unchanged_rerun(self):
  self.assertEqual(self.scan().errors,[])
  dbbefore=(self.root/'data/bookings.sqlite').read_bytes();secret=self.secret.read_bytes()
  seen=[]
  def writer(path,data,uid,gid):seen.append(path.relative_to(self.root).as_posix());self.m.atomic_write(path,data,uid,gid)
  backup=s.deploy(self.m,self.root,self.values(),self.uid,self.gid,writer)
  self.assertTrue(backup.is_dir());self.assertEqual(seen[-1],s.ACTIVATION)
  self.assertEqual(self.scan().errors,[])
  self.assertIsNone(s.deploy(self.m,self.root,self.values(),self.uid,self.gid))
  self.assertEqual((self.root/'data/bookings.sqlite').read_bytes(),dbbefore);self.assertEqual(self.secret.read_bytes(),secret)
 def test_each_pre_activation_failure_rolls_back(self):
  original={name:(self.root/name).read_bytes() if (self.root/name).exists() else None for name in self.values()}
  for step in range(1,len(self.values())):
   count=[0]
   def fail(path,data,uid,gid):
    self.m.atomic_write(path,data,uid,gid);count[0]+=1
    if count[0]==step:raise OSError('simulated post-rename disk failure')
   with self.assertRaises(OSError):s.deploy(self.m,self.root,self.values(),self.uid,self.gid,fail)
   for name,data in original.items():self.assertEqual((self.root/name).read_bytes() if (self.root/name).exists() else None,data,name)
   self.assertEqual(s.load_journal(self.m,self.root,self.uid)['state'],'restored')
 def test_interrupted_process_resumes_without_operator_cleanup(self):
  count=[0]
  def interrupt(path,data,uid,gid):
   self.m.atomic_write(path,data,uid,gid);count[0]+=1
   if count[0]==3:raise KeyboardInterrupt()
  with self.assertRaises(KeyboardInterrupt):s.deploy(self.m,self.root,self.values(),self.uid,self.gid,interrupt)
  with self.assertRaises(self.m.InstallError):s.recover(self.m,self.root,self.uid,self.gid,check=True)
  s.recover(self.m,self.root,self.uid,self.gid)
  self.assertEqual(self.scan().errors,[])
  s.deploy(self.m,self.root,self.values(),self.uid,self.gid)
 def test_failure_after_activation_never_restores_old_code(self):
  def fail(path,data,uid,gid):
   self.m.atomic_write(path,data,uid,gid)
   if path.name==s.ACTIVATION:raise OSError('after activation')
  with self.assertRaises(OSError):s.deploy(self.m,self.root,self.values(),self.uid,self.gid,fail)
  self.assertTrue((self.root/s.ACTIVATION).exists())
  self.assertEqual(s.load_journal(self.m,self.root,self.uid)['state'],'installed')
  for name,data in self.files.items():self.assertEqual((self.root/name).read_bytes(),data)
  self.assertIsNone(s.deploy(self.m,self.root,self.values(),self.uid,self.gid))
 def test_all_known_permission_issues_found_and_repaired_together(self):
  for name in self.before:(self.root/name).chmod(0o666)
  (self.root/'server').chmod(0o777);(self.root/'zoho-calendar.json').chmod(0o666)
  report=self.scan();self.assertEqual(report.errors,[]);self.assertEqual(len(report.repairs),8)
  self.r.apply_repairs(self.m,report,self.uid,self.gid)
  self.assertEqual(self.scan().repairs,{})
 def test_unknown_edits_and_invalid_journal_both_reported(self):
  (self.root/'server/booking-staff.php').write_text('<?php /* operator changed */')
  j=json.loads((self.root/'microsoft-calendar-probe.json').read_bytes());j['state']='prepared'
  (self.root/'microsoft-calendar-probe.json').write_bytes(self.m.encode(j))
  errors=self.scan().errors
  self.assertTrue(any('reviewed old/new' in e for e in errors));self.assertTrue(any('completed Microsoft' in e for e in errors))
  self.assertFalse((self.root/s.ACTIVATION).exists())
 def test_recovery_preserves_unrelated_edit(self):
  def interrupt(path,data,uid,gid):self.m.atomic_write(path,data,uid,gid);raise KeyboardInterrupt()
  with self.assertRaises(KeyboardInterrupt):s.deploy(self.m,self.root,self.values(),self.uid,self.gid,interrupt)
  changed=self.root/s.NAMES[0];changed.write_bytes(b'operator-edit')
  with self.assertRaises(self.m.InstallError):s.recover(self.m,self.root,self.uid,self.gid)
  self.assertEqual(changed.read_bytes(),b'operator-edit')
 def test_invalid_stored_reservation_blocks_activation(self):
  db=sqlite3.connect(str(self.root/'data/bookings.sqlite'));db.execute("UPDATE booking_confirmations SET calendar_uid='foreign'");db.commit();db.close()
  self.assertTrue(any('reservation identity' in e for e in self.scan().errors))
 def test_dry_inventory_does_not_mutate_existing_files(self):
  snapshot={p:p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
  self.assertEqual(self.scan().errors,[])
  for p,data in snapshot.items():self.assertEqual(p.read_bytes(),data)

if __name__=='__main__':unittest.main()
