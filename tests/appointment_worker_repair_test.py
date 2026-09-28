import contextlib,io,json,os,shutil,sqlite3,subprocess,sys,tempfile,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
import appointment_repair_install_test as previous
PROJECT=Path(__file__).resolve().parents[1]
repair=previous.load('worker_repair',PROJECT/'tools/repair-appointment-worker.py')
PHP=previous.base.base.base.f.baseline.PHP
class WorkerRuntime(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.home=Path(self.temp.name);self.root=self.home/'.sitesee-real-estate'
  shutil.copytree(PROJECT/'_private',self.root);(self.home/'public_html/re').mkdir(parents=True)
  cfg=self.root/'booking-lifecycle.json';cfg.write_text(json.dumps(dict(schema=1,stage='test',enabled=True,recipient='cro@sitesee.ai')));cfg.chmod(0o600)
  self.env=os.environ.copy();self.env.pop('SITESEE_REAL_ESTATE_BOOKING_DB',None)
 def run_worker(self,*args):
  return subprocess.run([PHP,str(self.root/'server/booking-lifecycle-reconcile.php'),*args],cwd=self.home,env=self.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
 def test_reproduces_r2_failure_then_repairs_home_directory_execution(self):
  worker=self.root/'server/booking-lifecycle-reconcile.php';fixed=worker.read_bytes();worker.write_bytes((PROJECT/'tests/fixtures/appointment-r2/booking-lifecycle-reconcile.php').read_bytes())
  old=self.run_worker();self.assertNotEqual(old.returncode,0);self.assertIn('Booking database must remain outside the document root.',old.stdout+old.stderr)
  worker.write_bytes(fixed);result=self.run_worker();self.assertEqual(result.returncode,0,result.stdout+result.stderr)
  self.assertIn('Reconciled: 0; review required: 0.',result.stdout)
 def test_diagnostic_reaches_database_and_selection(self):
  r=self.run_worker('--diagnose');self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertIn('CLI, database and worker selection: PASS.',r.stdout)
  self.assertTrue((self.root/'data/bookings.sqlite').exists());self.assertNotIn('Reconciled:',r.stdout)
 def test_public_database_is_still_rejected(self):
  self.env['SITESEE_REAL_ESTATE_BOOKING_DB']=str(self.home/'public_html/re/bookings.sqlite')
  r=self.run_worker();self.assertNotEqual(r.returncode,0);self.assertIn('stage=database; type=RuntimeException; code=database-path-guard',r.stdout)
  self.assertFalse((self.home/'public_html/re/bookings.sqlite').exists())
 def test_protected_booking_is_not_processed(self):
  self.run_worker('--diagnose');db=sqlite3.connect(self.root/'data/bookings.sqlite')
  db.execute("INSERT INTO bookings(reference,created_at,status,market,email,request_json,requested_utc,quote_cents,platform_monthly_cents) VALUES('D32FFC7458','now','deposit_paid_test','residential','cro@sitesee.ai','{}','now',100,0)")
  db.execute("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at) VALUES('D32FFC7458','confirmed','protected','protected',9999999000,9999999999,'{}','now')");db.commit();db.close()
  r=self.run_worker();self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertIn('Reconciled: 0; review required: 0.',r.stdout)
 def test_report_includes_safe_worker_failures_and_two_references(self):
  self.run_worker('--diagnose')
  db=sqlite3.connect(self.root/'data/bookings.sqlite');db.execute("INSERT INTO bookings(reference,created_at,status,market,email,request_json,requested_utc,quote_cents,platform_monthly_cents) VALUES('B0AC5BEFEB','now','deposit_paid_test','residential','cro@sitesee.ai','{}','now',100,0)");db.commit();db.close()
  (self.root/'lifecycle-reconcile.log').write_text('2026-09-28T18:15:01+00:00 Worker started.\n2026-09-28T18:15:01+00:00 Worker stopped: stage=database; type=RuntimeException; code=database-path-guard.\nSECRET-TOKEN-DO-NOT-PRINT\n')
  module=previous.load('worker_report',PROJECT/'tools/appointment-worker-report.py')
  # Missing-reference output must identify the reference, and report logs even before a booking exists.
  out=io.StringIO()
  with contextlib.redirect_stdout(out):module.report(self.root,'B0AC5BEFEB')
  self.assertNotIn('SECRET-TOKEN',out.getvalue());self.assertIn('stage=database; type=RuntimeException',out.getvalue());self.assertIn('B0AC5BEFEB',out.getvalue())
class WorkerInstall(unittest.TestCase):
 def setUp(self):
  self.previous=previous.Repair();self.previous.setUp();self.addCleanup(self.previous.doCleanups);self.previous.deploy()
  self.root=self.previous.root;self.uid,self.gid=os.getuid(),os.getgid();self.x,self.m,self.old,self.before,self.files,self.report=repair.load()
  self.cron=self.previous.fixture.cron;self.public=self.previous.fixture.public
 def values(self):return repair.plan(self.x,self.m,self.root,self.public,self.cron,self.uid,self.old,self.before,self.files,PHP)
 def deploy(self,write=None):return repair.deploy(self.m,self.root,self.cron,self.values(),self.uid,self.gid,write)
 def test_install_and_repeat_preserve_database_and_credentials(self):
  preserved={p:p.read_bytes() for p in [self.root/'data/bookings.sqlite',self.root/'booking-lifecycle.json',self.root/'zoho-crm.json',self.root/'microsoft-scheduling.json']}
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
 def test_unknown_worker_preserved(self):
  p=self.root/'server/booking-lifecycle-reconcile.php';p.write_bytes(b'operator edit')
  with self.assertRaises(self.m.InstallError):self.values()
  self.assertEqual(p.read_bytes(),b'operator edit')
 def test_reproducible_bundle(self):
  for n,b in self.files.items():self.assertEqual(b,(PROJECT/'_private'/n).read_bytes())
  p=PROJECT/'tools/repair-appointment-worker.py';before=p.read_bytes();subprocess.run(['python3',str(PROJECT/'tools/build-appointment-worker-repair.py')],check=True);self.assertEqual(before,p.read_bytes())
if __name__=='__main__':unittest.main()
