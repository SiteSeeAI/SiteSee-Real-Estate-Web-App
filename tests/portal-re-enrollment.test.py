import importlib.util, json, os, pathlib, pwd, shutil, sqlite3, subprocess, sys, tempfile, unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('installer',ROOT/'tools/install-portal-complete.py'); m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class EnrollmentTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
  self.root=pathlib.Path(self.tmp.name);self.data=self.root/'data';self.data.mkdir(mode=0o700);self.db=self.data/'bookings.sqlite'
  self.owner=pwd.getpwuid(os.geteuid())
  with sqlite3.connect(self.db) as d:
   d.execute('CREATE TABLE bookings (reference TEXT PRIMARY KEY, email TEXT)');d.execute("INSERT INTO bookings VALUES ('existing','unrelated@example.com')")
  for p in [self.root,self.data,self.db]:
   os.chmod(p,0o600 if p==self.db else 0o700)
   if os.geteuid()==0:os.chown(p,self.owner.pw_uid,self.owner.pw_gid)
  self.phone='+13125550100'
 def run_worker(self,phone=None):
  # This runtime maps only uid 0. Exercise real SQLite operations in a child
  # with just the non-root guard stubbed; test the real guard separately.
  code=m.RE_ENROLL_WORKER.replace("require(os.geteuid()!=0, 'OWNER_REQUIRED')","require(True, 'OWNER_REQUIRED')") if os.geteuid()==0 else m.RE_ENROLL_WORKER
  cmd=[sys.executable,'-c',code]
  return subprocess.run(cmd,input=json.dumps({'root':str(self.root),'database_path':str(self.db),'phone':phone or self.phone}).encode(),capture_output=True,timeout=10)
 def query(self,sql,args=()):
  with sqlite3.connect(self.db) as d:return d.execute(sql,args).fetchall()
 def update(self,sql,args=()):
  with sqlite3.connect(self.db) as d:d.execute(sql,args)
 def test_root_execution_is_rejected_by_unmodified_worker(self):
  if os.geteuid()!=0:self.skipTest('Requires root fixture')
  r=subprocess.run([sys.executable,'-c',m.RE_ENROLL_WORKER],input=json.dumps({'root':str(self.root),'database_path':str(self.db),'phone':self.phone}).encode(),capture_output=True)
  self.assertEqual(r.stderr.strip(),b'OWNER_REQUIRED')
 def test_new_business_without_pricing_approval_and_unrelated_records_preserved(self):
  approvals=self.root/'real-estate-pricing-pending';approvals.mkdir();p=approvals/'old.json';p.write_text('{"email":"unrelated@example.com","status":"approved"}');before=p.read_bytes()
  r=self.run_worker();self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(r.stdout.strip(),b'ENROLLED')
  self.assertEqual(self.query('SELECT email,disabled FROM portal_accounts'),[('sales@re.sitesee.ai',0)])
  row=self.query('SELECT p.phone,a.email,p.evidence FROM portal_phone_identities p JOIN portal_accounts a ON a.id=p.account_id')[0]
  self.assertEqual(row[:2],(self.phone,'sales@re.sitesee.ai'));self.assertGreaterEqual(len(row[2]),12);self.assertLessEqual(len(row[2]),500)
  self.assertEqual(self.query('SELECT * FROM bookings'),[('existing','unrelated@example.com')]);self.assertEqual(p.read_bytes(),before)
  self.assertEqual(self.db.stat().st_uid,self.owner.pw_uid)
 def test_rerun_is_idempotent(self):
  self.assertEqual(self.run_worker().returncode,0);before=self.query('SELECT * FROM portal_phone_identities')
  r=self.run_worker();self.assertEqual(r.stdout.strip(),b'ALREADY_ENROLLED');self.assertEqual(self.query('SELECT * FROM portal_phone_identities'),before)
 def test_disabled_business_not_reactivated(self):
  self.run_worker();self.update('UPDATE portal_accounts SET disabled=1');r=self.run_worker()
  self.assertEqual(r.stderr.strip(),b'ACCOUNT_DISABLED');self.assertEqual(self.query('SELECT disabled FROM portal_accounts'),[(1,)])
 def test_phone_owned_by_other_account_not_reassigned(self):
  self.run_worker();self.update("UPDATE portal_accounts SET email='other@example.com'");r=self.run_worker()
  self.assertEqual(r.stderr.strip(),b'IDENTITY_CONFLICT');self.assertEqual(self.query('SELECT email FROM portal_accounts'),[('other@example.com',)])
 def test_business_with_other_phone_not_reassigned(self):
  self.run_worker();before=self.query('SELECT * FROM portal_phone_identities');r=self.run_worker('+13125550101')
  self.assertEqual(r.stderr.strip(),b'IDENTITY_CONFLICT');self.assertEqual(self.query('SELECT * FROM portal_phone_identities'),before)
 def test_failure_rolls_back_new_account(self):
  self.run_worker();self.update('DELETE FROM portal_phone_identities');self.update('DELETE FROM portal_accounts')
  self.update("CREATE TRIGGER reject_identity BEFORE INSERT ON portal_phone_identities BEGIN SELECT RAISE(ABORT,'blocked'); END")
  self.assertNotEqual(self.run_worker().returncode,0);self.assertEqual(self.query('SELECT * FROM portal_accounts'),[])
 def test_phone_format_rejected_before_writes(self):
  self.assertEqual(self.run_worker('bad').stderr.strip(),b'PHONE_FORMAT');self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='portal_accounts'"),[])
 def test_unsafe_database_permissions_rejected(self):
  self.db.chmod(0o644);self.assertEqual(self.run_worker().stderr.strip(),b'DATABASE_PERMISSIONS')
 def test_database_busy_has_specific_error(self):
  d=sqlite3.connect(self.db);d.execute('BEGIN IMMEDIATE')
  try:self.assertEqual(self.run_worker().stderr.strip(),b'DATABASE_BUSY')
  finally:d.rollback();d.close()
 def test_existing_business_account_id_preserved(self):
  self.run_worker();before=self.query('SELECT id FROM portal_accounts');self.update('DELETE FROM portal_phone_identities')
  self.assertEqual(self.run_worker().returncode,0);self.assertEqual(self.query('SELECT id FROM portal_accounts'),before)

class SetupTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name)
  self.config={'provider':'twilio-verify','stage':'TEST','enabled':True,'account_sid':'AC'+'1'*32,'service_sid':'VA'+'2'*32,'auth_token':'3'*32,'allowed_numbers':['+13125550100']}
 def save(self):
  p=self.root/'portal-sms.json';p.write_text(json.dumps(self.config));p.chmod(0o600);return p
 def test_saved_credentials_reused_without_prompts(self):
  p=self.save();before=p.read_bytes()
  with patch.object(m,'enroll_re_business') as enroll, patch('builtins.input',side_effect=AssertionError('No prompts')), patch.object(m.getpass,'getpass',side_effect=AssertionError('No credential prompts')):
   self.assertEqual(m.setup_phone(self.root,os.getuid(),os.getgid(),'unused',self.root/'data/bookings.sqlite'),[])
   enroll.assert_called_once_with(self.root,self.root/'data/bookings.sqlite','+13125550100')
  self.assertEqual(p.read_bytes(),before)
 def test_missing_saved_settings_do_not_create_or_enroll(self):
  with patch.object(m,'enroll_re_business') as enroll:
   self.assertTrue(m.setup_phone(self.root,os.getuid(),os.getgid(),'unused',self.root/'data/bookings.sqlite'));enroll.assert_not_called()
 def test_multiple_test_numbers_are_not_guessed(self):
  self.config['allowed_numbers'].append('+13125550101');self.save()
  with patch.object(m,'enroll_re_business') as enroll:
   with self.assertRaises(m.Stop):m.setup_phone(self.root,os.getuid(),os.getgid(),'unused',self.root/'data/bookings.sqlite')
   enroll.assert_not_called()
 def test_error_output_does_not_expose_secrets(self):
  result=subprocess.CompletedProcess([],1,b'',b'AC-secret sensitive database error')
  with patch.object(m.subprocess,'run',return_value=result):
   with self.assertRaises(m.Stop) as caught:m.enroll_re_business(self.root,self.root/'data/bookings.sqlite','+13125550100')
  self.assertNotIn('AC-secret',str(caught.exception))

if __name__=='__main__':unittest.main()
