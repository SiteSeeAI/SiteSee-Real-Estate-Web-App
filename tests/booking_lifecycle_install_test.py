import errno,importlib.util,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
PROJECT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(PROJECT/'tools'))
from unified_source import before_unified_update
def load(name,path):
 s=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
x=load('lifecycle_installer',PROJECT/'tools/install-appointment-management.py')
base=load('lifecycle_workflow_fixture',PROJECT/'tests/booking_workflow_install_test.py')
class LifecycleInstall(unittest.TestCase):
 def setUp(self):
  self.fixture=base.WorkflowInstall();self.fixture.setUp();self.addCleanup(self.fixture.doCleanups)
  self.root=self.fixture.root;self.uid,self.gid=os.getuid(),os.getgid();self.w,self.s,self.r,self.m=x.modules();self.files,self.before=x.bundle(self.m)
  base.w.deploy(self.m,self.root,self.fixture.values(),self.uid,self.gid)
  self.public=self.root.parent/'public';self.public.mkdir(mode=0o755)
  self.crondir=self.root.parent/'cron.d';self.crondir.mkdir(mode=0o755);self.cron=self.crondir/'sitesee'
  # The installer deliberately requires root-owned host cron files. Do not mock
  # or relax that guard; the dedicated privileged CI job exercises these cases.
  try:os.chown(self.crondir,0,0)
  except OSError as error:
   if error.errno not in (errno.EPERM,errno.EACCES,errno.EINVAL):raise
   if self._testMethodName!='test_reproducible_payload':self.skipTest('Root-owned cron rehearsal requires a privileged host; run Historical Installer Ownership CI.')
 def values(self):return x.desired(self.m,self.root,self.files)
 def scan(self):return x.inventory(self.w,self.s,self.r,self.m,self.root,self.public,self.cron,base.base.f.baseline.PHP,self.uid,self.files,self.before,credentials=self.fixture.fixture.secret)
 def deploy(self,write=None):return x.deploy(self.m,self.root,self.values(),self.uid,self.gid,self.public,self.cron,write)
 def test_reproducible_payload(self):
  for n,b in self.files.items():
   frozen=PROJECT/'tests/fixtures/appointment-r1'/Path(n).name
   source=n if n.startswith('public/') else '_private/'+n
   self.assertEqual(b,frozen.read_bytes() if frozen.exists() else before_unified_update(PROJECT,source))
  path=PROJECT/'tools/install-appointment-management.py';before=path.read_bytes();subprocess.run(['python3',str(PROJECT/'tools/build-appointment-management.py')],check=True);self.assertEqual(before,path.read_bytes())
 def test_install_rerun_and_preservation(self):
  self.assertEqual(self.scan().errors,[])
  preserved={p:p.read_bytes() for p in [self.root/'data/bookings.sqlite',self.root/'zoho-crm.json',self.root/'zoho-calendar.json',self.root/'microsoft-scheduling.json',self.fixture.fixture.secret]}
  self.deploy();self.assertEqual(self.scan().errors,[]);self.assertIsNone(self.deploy())
  self.assertEqual(self.public.joinpath('manage-appointment.php').stat().st_mode&0o777,0o644)
  self.assertEqual(self.cron.stat().st_mode&0o777,0o644)
  for p,b in preserved.items():self.assertEqual(p.read_bytes(),b)
 def test_each_write_failure_recovers_or_preserves_activated_release(self):
  initial={n:x.read_target(self.m,x.destination(self.m,self.root,n,self.public,self.cron),0 if n=='cron/reconcile' else self.uid) for n in self.values()}
  for step in range(1,len(self.values())+1):
   writes=[0]
   def fail(m,p,b,u,g,public):
    x.write_target(m,p,b,u,g,public);writes[0]+=1
    if writes[0]==step:raise OSError('after rename')
   with self.assertRaises(OSError):self.deploy(fail)
   if step<len(self.values()):
    for n,b in initial.items():
     current=x.read_target(self.m,x.destination(self.m,self.root,n,self.public,self.cron),0 if n=='cron/reconcile' else self.uid)
     if b is not None or n.startswith(('public/','cron/')) or n in (x.ACTIVATION,x.MANIFEST):self.assertEqual(current,b,n)
   else:
    self.assertTrue((self.root/x.ACTIVATION).exists());self.assertIsNone(self.deploy())
 def test_interrupt_and_unknown_edit(self):
  writes=[0]
  def interrupt(m,p,b,u,g,public):
   x.write_target(m,p,b,u,g,public);writes[0]+=1
   if writes[0]==4:raise KeyboardInterrupt()
  with self.assertRaises(KeyboardInterrupt):self.deploy(interrupt)
  with self.assertRaises(self.m.InstallError):x.recover(self.m,self.root,self.uid,self.gid,self.values(),True,self.public,self.cron)
  x.recover(self.m,self.root,self.uid,self.gid,self.values(),False,self.public,self.cron)
  self.assertEqual(self.scan().errors,[])
  with self.assertRaises(KeyboardInterrupt):
   writes[0]=0;self.deploy(interrupt)
  (self.root/'server/booking-staff.php').write_bytes(b'operator changed')
  with self.assertRaises(self.m.InstallError):x.recover(self.m,self.root,self.uid,self.gid,self.values(),False,self.public,self.cron)
  self.assertEqual((self.root/'server/booking-staff.php').read_bytes(),b'operator changed')
 def test_combined_blockers(self):
  (self.root/'server/booking-staff.php').write_bytes(b'unknown')
  (self.public/'manage-appointment.php').write_bytes(b'unknown')
  self.cron.write_bytes(b'unknown')
  errors=self.scan().errors
  self.assertTrue(any('dependency' in e for e in errors));self.assertTrue(any('public' in e for e in errors));self.assertTrue(any('cron' in e for e in errors))
if __name__=='__main__':unittest.main()
