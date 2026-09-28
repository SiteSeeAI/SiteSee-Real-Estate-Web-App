import json,os,subprocess,sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
import appointment_worker_repair_test as previous
from appointment_repair_install_test import load
PROJECT=Path(__file__).resolve().parents[1]
repair=load('legacy_repair',PROJECT/'tools/repair-legacy-cancellation.py')
class LegacyInstall(unittest.TestCase):
 def setUp(self):
  self.previous=previous.WorkerInstall();self.previous.setUp();self.addCleanup(self.previous.doCleanups);self.previous.deploy()
  self.root=self.previous.root;self.uid,self.gid=os.getuid(),os.getgid();self.cron=self.previous.cron;self.public=self.previous.public
  self.x,self.m,self.old,self.before,self.files,self.report=repair.load()
 def values(self):return repair.plan(self.x,self.m,self.root,self.public,self.cron,self.uid,self.old,self.before,self.files,previous.PHP)
 def deploy(self,write=None):return repair.deploy(self.m,self.root,self.cron,self.values(),self.uid,self.gid,write)
 def test_install_repeat_preserves_ledger_credentials_and_worker(self):
  preserved={p:p.read_bytes() for p in [self.root/'data/bookings.sqlite',self.root/'booking-lifecycle.json',self.root/'zoho-crm.json',self.root/'server/booking-lifecycle-reconcile.php']}
  values=self.values();self.deploy();self.deploy()
  for n,b in values.items():self.assertEqual(repair.target(self.root,self.cron,n).read_bytes(),b)
  for p,b in preserved.items():self.assertEqual(p.read_bytes(),b)
 def test_each_write_interruption_resumes(self):
  writes=sum(repair.target(self.root,self.cron,n).read_bytes()!=b for n,b in self.values().items())
  for step in range(1,writes+1):
   if (self.root/repair.JOURNAL).exists():
    j=json.loads((self.root/repair.JOURNAL).read_text())
    for n in j['entries']:repair.target(self.root,self.cron,n).write_bytes((self.root/'deployment-backups'/j['backup']/n).read_bytes())
    (self.root/repair.JOURNAL).unlink()
   count=[0]
   def fail(p,b,u,g):
    self.m.atomic_write(p,b,u,g);count[0]+=1
    if count[0]==step:raise KeyboardInterrupt()
   with self.assertRaises(KeyboardInterrupt):self.deploy(fail)
   self.deploy();self.assertEqual(json.loads((self.root/repair.JOURNAL).read_text())['state'],'installed')
 def test_unknown_edit_preserved(self):
  p=self.root/'server/booking-lifecycle.php';p.write_bytes(b'operator edit')
  with self.assertRaises(self.m.InstallError):self.values()
  self.assertEqual(p.read_bytes(),b'operator edit')
 def test_reproducible_payload(self):
  for n,b in self.files.items():self.assertEqual(b,(PROJECT/'_private'/n).read_bytes())
  p=PROJECT/'tools/repair-legacy-cancellation.py';before=p.read_bytes();subprocess.run(['python3',str(PROJECT/'tools/build-legacy-cancellation-repair.py')],check=True);self.assertEqual(before,p.read_bytes())
if __name__=='__main__':unittest.main()
