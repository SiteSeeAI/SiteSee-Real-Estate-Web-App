import contextlib,importlib.util,io,json,os,subprocess,sys,unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parent))
import booking_lifecycle_install_test as base
PROJECT=Path(__file__).resolve().parents[1]
def load(name,path):
 spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
repair=load('repair',PROJECT/'tools/repair-appointment-management.py')
class Repair(unittest.TestCase):
 def setUp(self):
  if self._testMethodName=='test_bundle_reproducible':
   self.x,self.m,self.old,self.before,self.files,self.report=repair.load();return
  self.fixture=base.LifecycleInstall();self.fixture.setUp();self.addCleanup(self.fixture.doCleanups);self.fixture.deploy()
  self.root=self.fixture.root;self.uid,self.gid=os.getuid(),os.getgid();self.x,self.m,self.old,self.before,self.files,self.report=repair.load()
  self.php=base.base.base.f.baseline.PHP
 def values(self):return repair.plan(self.x,self.m,self.root,self.fixture.public,self.fixture.cron,self.uid,self.old,self.before,self.files,self.php)
 def deploy(self,write=None):return repair.deploy(self.m,self.root,self.fixture.cron,self.values(),self.uid,self.gid,write)
 def test_repair_and_repeat_preserve_ledger_credentials_and_activation(self):
  preserved={p:p.read_bytes() for p in [self.root/'data/bookings.sqlite',self.root/'booking-lifecycle.json',self.root/'zoho-crm.json',self.root/'microsoft-scheduling.json']}
  values=self.values();self.deploy();self.deploy()
  for n,b in values.items():self.assertEqual(repair.target(self.root,self.fixture.cron,n).read_bytes(),b)
  for p,b in preserved.items():self.assertEqual(p.read_bytes(),b)
  self.assertEqual(self.fixture.cron.stat().st_mode&0o777,0o644)
 def test_each_write_interruption_resumes_without_rollback(self):
  for step in range(1,len(self.values())+1):
   # Restore this fixture to its verified r1 bytes between independent failure cases.
   if (self.root/repair.JOURNAL).exists():
    journal=json.loads((self.root/repair.JOURNAL).read_text())
    for n in journal['entries']:
     p=repair.target(self.root,self.fixture.cron,n);p.write_bytes((self.root/'deployment-backups'/journal['backup']/n).read_bytes())
    (self.root/repair.JOURNAL).unlink()
   count=[0]
   def fail(p,b,u,g):
    self.m.atomic_write(p,b,u,g);count[0]+=1
    if count[0]==step:raise KeyboardInterrupt()
   with self.assertRaises(KeyboardInterrupt):self.deploy(fail)
   self.deploy();self.assertEqual(json.loads((self.root/repair.JOURNAL).read_text())['state'],'installed')
 def test_unknown_edit_stops(self):
  p=self.root/'server/booking-lifecycle.php';p.write_bytes(b'operator edit')
  with self.assertRaises(self.m.InstallError):self.values()
  self.assertEqual(p.read_bytes(),b'operator edit')
 def test_corrupt_backup_blocks_resume(self):
  def fail(p,b,u,g):self.m.atomic_write(p,b,u,g);raise KeyboardInterrupt()
  with self.assertRaises(KeyboardInterrupt):self.deploy(fail)
  j=json.loads((self.root/repair.JOURNAL).read_text());(self.root/'deployment-backups'/j['backup']/'server/booking-lifecycle.php').write_bytes(b'bad')
  with self.assertRaises(self.m.InstallError):self.deploy()
 def test_php_probe_requires_cli(self):
  self.assertEqual(repair.cli_php([self.php]),self.php)
  with patch.object(repair.subprocess,'run',return_value=subprocess.CompletedProcess([],0,b'cgi-fcgi',b'')):
   with self.assertRaises(RuntimeError):repair.cli_php([self.php])
 def test_main_runs_bootstrap_without_opening_booking_database(self):
  for name in ('booking-confirmation.lock','lifecycle-worker.lock'):(self.root/name).touch(mode=0o600)
  before=(self.root/'data/bookings.sqlite').read_bytes();run=repair.subprocess.run
  def local_run(args,**kw):
   if args[:3]==['runuser','-u','sitesee']:args=args[4:] # Container forbids setgroups; execute the real CLI bootstrap as the fixture owner.
   return run(args,**kw)
  import pwd
  out=io.StringIO()
  with patch.object(repair,'ROOT',self.root),patch.object(repair,'PUBLIC',self.fixture.public),patch.object(repair,'CRON',self.fixture.cron),patch.object(repair,'cli_php',return_value=self.php),patch.object(repair.pwd,'getpwnam',return_value=pwd.getpwuid(os.getuid())),patch.object(repair.subprocess,'run',side_effect=local_run),patch.object(repair,'diagnostic'),patch.object(sys,'argv',['repair']),contextlib.redirect_stdout(out):
   repair.main()
  self.assertIn('Worker bootstrap: PASS',out.getvalue())
  self.assertEqual((self.root/'data/bookings.sqlite').read_bytes(),before)
 def test_bundle_reproducible(self):
  for n,b in self.files.items():
   frozen=PROJECT/'tests/fixtures/appointment-r2'/Path(n).name
   self.assertEqual(b,(frozen if frozen.exists() else PROJECT/'_private'/n).read_bytes())
  p=PROJECT/'tools/repair-appointment-management.py';before=p.read_bytes();subprocess.run(['python3',str(PROJECT/'tools/build-appointment-repair.py')],check=True);self.assertEqual(before,p.read_bytes())
 def test_report_does_not_treat_joined_fields_as_financial_changes(self):
  module=load('report',PROJECT/'tools/appointment-management-report.py')
  import sqlite3
  db=sqlite3.connect(self.root/'data/bookings.sqlite');db.row_factory=sqlite3.Row
  # Create representative lifecycle snapshot against an existing fixture booking.
  if 'quote_cents' not in [r[1] for r in db.execute('PRAGMA table_info(bookings)')]:db.execute('ALTER TABLE bookings ADD COLUMN quote_cents INTEGER DEFAULT 100')
  row=db.execute('SELECT * FROM bookings LIMIT 1').fetchone()
  if row is None:
   db.execute("INSERT INTO bookings(reference,created_at,status,market,email,request_json,requested_utc,quote_cents,platform_monthly_cents) VALUES('3EB7F85259','now','deposit_paid_test','residential','cro@sitesee.ai','{}','old',100,0)")
   row=db.execute('SELECT * FROM bookings LIMIT 1').fetchone()
  saved=dict(row);saved.update({'schedule_appointment_json':'old','rush_status':'not_requested'})
  db.executescript("CREATE TABLE IF NOT EXISTS booking_lifecycle(reference TEXT,state TEXT,revision INT,checked_at INT,diagnostic TEXT);CREATE TABLE IF NOT EXISTS booking_lifecycle_operations(reference TEXT,revision INT,action TEXT,actor TEXT,state TEXT,payload_json TEXT);")
  db.execute('INSERT INTO booking_lifecycle VALUES(?,\'cancelled\',2,0,NULL)',(row['reference'],))
  db.execute('INSERT INTO booking_lifecycle_operations VALUES(?,1,\'reschedule\',\'customer\',\'applied\',?)',(row['reference'],json.dumps({'row':saved,'claim':{}})))

  if 'created_at' not in [r[1] for r in db.execute('PRAGMA table_info(booking_communications)')]:db.execute("ALTER TABLE booking_communications ADD COLUMN created_at TEXT DEFAULT 'now'")
  db.commit();db.close();out=io.StringIO()
  with contextlib.redirect_stdout(out):module.report(self.root,row['reference'])
  self.assertIn('"base_booking_fields_preserved": true',out.getvalue());self.assertIn('"changed_field_names": []',out.getvalue())
  self.assertNotIn('token_hash',out.getvalue())
  db=sqlite3.connect(self.root/'data/bookings.sqlite');db.execute('UPDATE bookings SET quote_cents=999 WHERE reference=?',(row['reference'],));db.commit();db.close();out=io.StringIO()
  with contextlib.redirect_stdout(out):module.report(self.root,row['reference'])
  self.assertIn('"base_booking_fields_preserved": false',out.getvalue());self.assertIn('"quote_cents"',out.getvalue())
if __name__=='__main__':unittest.main()
