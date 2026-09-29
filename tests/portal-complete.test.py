import copy, importlib.util, json, os, pathlib, tempfile, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('installer',ROOT/'tools/install-portal-complete.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class InstallerTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name)/'private';self.public=pathlib.Path(self.tmp.name)/'public';self.root.mkdir();self.public.mkdir();self.uid=os.getuid();self.gid=os.getgid();self.obj,self.files=m.load()
  self.obj=copy.deepcopy(self.obj);self.obj['manifests']=['legacy.json'];self.old=b'<?php /* original webhook */\n';self.obj['webhook_before']=[m.sha(self.old)]
  (self.root/'server').mkdir();(self.root/'views').mkdir();(self.public/'portal-assets').mkdir();(self.root/'server/booking-webhook.php').write_bytes(self.old)
  (self.root/'legacy.json').write_bytes(m.encode({'files':{'server/booking-webhook.php':m.sha(self.old)},'retain':'yes'}))
  self.initial={str(p.relative_to(self.root)):p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
 def install(self,writer=None):return m.install(self.obj,self.files,self.root,self.public,self.uid,self.gid,writer)
 def values(self):return m.desired(self.obj,self.files,self.root,self.public,self.uid)
 def assert_restored(self):
  for name,data in self.initial.items():self.assertEqual((self.root/name).read_bytes(),data)
  for name in self.files:
   if name!='private/server/booking-webhook.php':self.assertFalse(m.target(name,self.root,self.public).exists())
 def test_concurrent_edit_before_later_install_write_is_preserved(self):
  changed=[False];p=self.root/'server/booking-webhook.php'
  def writer(*args):
   if not changed[0]:p.write_bytes(b'concurrent upload');changed[0]=True
   m.write_target(*args)
  with self.assertRaises(m.Stop):self.install(writer)
  self.assertEqual(p.read_bytes(),b'concurrent upload');self.assertEqual(m.journal(self.root,self.uid)['state'],'prepared')
 def test_concurrent_edit_before_backup_capture_is_preserved(self):
  from unittest.mock import patch
  original=m.tempfile.mkdtemp;p=self.root/'server/booking-webhook.php'
  def changed(*args,**kwargs):
   folder=original(*args,**kwargs);p.write_bytes(b'concurrent before backup');return folder
  with patch.object(m.tempfile,'mkdtemp',changed):
   with self.assertRaises(m.Stop):self.install()
  self.assertEqual(p.read_bytes(),b'concurrent before backup');self.assertFalse((self.root/m.JOURNAL).exists())
 def test_payload_scope(self):
  self.assertEqual(len(self.files),22)
  self.assertNotIn('private/real-estate-form-config.php',self.files)
  self.assertTrue(all('/portal' in n or n=='public/account.php' or n=='private/server/booking-webhook.php' or n=='private/tools/portal-enroll-phone.php' for n in self.files))
 def test_install_repeat_and_rollback(self):
  self.install();self.assertIn('unchanged',self.install());self.assertEqual(json.loads((self.root/'legacy.json').read_text())['retain'],'yes')
  m.recover(self.root,self.public,self.uid,self.gid,self.values(),True);self.assert_restored()
 def test_every_interruption_recovers(self):
  count=len(self.values())
  for stop in range(count+1):
   with self.subTest(stop=stop):
    calls=[0]
    def writer(*args):
     if calls[0]==stop:raise RuntimeError('power loss')
     calls[0]+=1;m.write_target(*args)
    if stop<count:
     with self.assertRaises(RuntimeError):self.install(writer)
    else:self.install(writer)
    m.recover(self.root,self.public,self.uid,self.gid,self.values(),True);self.assert_restored()
 def test_interrupted_retry(self):
  count=[0]
  def writer(*args):
   count[0]+=1
   if count[0]==8:raise RuntimeError('interrupt')
   m.write_target(*args)
  with self.assertRaises(RuntimeError):self.install(writer)
  self.install();self.assertEqual(m.journal(self.root,self.uid)['state'],'installed')
 def test_activation_blocks_destructive_rollback(self):
  self.install();m.activate(self.root,self.public,self.uid,self.gid,self.values());(self.root/m.FLAG).unlink()
  with self.assertRaises(m.Stop):m.recover(self.root,self.public,self.uid,self.gid,self.values(),True)
  self.assertEqual((self.root/'server/booking-webhook.php').read_bytes(),self.files['private/server/booking-webhook.php'])
 def test_unknown_file_and_changed_webhook_preserved(self):
  (self.public/'account.php').write_bytes(b'unknown')
  with self.assertRaises(m.Stop):self.install()
  self.assertEqual((self.public/'account.php').read_bytes(),b'unknown');self.assertFalse((self.root/m.JOURNAL).exists())
 def test_unknown_edit_blocks_whole_recovery(self):
  self.install();p=self.root/'server/portal-app.php';p.write_bytes(b'unknown')
  with self.assertRaises(m.Stop):m.recover(self.root,self.public,self.uid,self.gid,self.values(),True)
  self.assertTrue((self.public/'account.php').exists());self.assertEqual(p.read_bytes(),b'unknown')
 def test_symlinks_refused(self):
  p=self.public/'account.php';p.symlink_to(self.root/'server/booking-webhook.php')
  with self.assertRaises(m.Stop):self.install()
 def test_corrupt_backup_refused(self):
  self.install();j=m.journal(self.root,self.uid);p=self.root/'deployment-backups'/j['backup'];next(p.glob('*.bin')).write_bytes(b'corrupt')
  with self.assertRaises(m.Stop):m.recover(self.root,self.public,self.uid,self.gid,self.values(),True)
 def test_first_install_under_private_umask(self):
  (self.public/'portal-assets').rmdir();previous=os.umask(0o077)
  try:self.install()
  finally:os.umask(previous)
  self.assertEqual((self.public/'portal-assets').stat().st_mode&0o777,0o755)
 def test_existing_private_public_asset_directory_refused(self):
  os.chmod(str(self.public/'portal-assets'),0o700)
  with self.assertRaises(m.Stop):self.install()
  self.assertFalse((self.root/m.JOURNAL).exists())
 def test_checkout_preflight_matches_runtime(self):
  self.obj['dependencies']={};self.obj['fonts']={}
  (self.root/'appointment-management-release.json').write_text(json.dumps({'revision':'20260928-r4'}))
  (self.root/'data').mkdir(mode=0o700)
  p=self.root/'booking-checkout.json'
  p.write_text(json.dumps({'stage':'TEST','enabled':True,'publishable_key':'pk_test_abcdefghijklmnop'}));os.chmod(str(p),0o600)
  m.verify_dependencies(self.obj,self.root,self.public,self.uid)
  os.chmod(str(p),0o644)
  with self.assertRaises(m.Stop):m.verify_dependencies(self.obj,self.root,self.public,self.uid)
  os.chmod(str(p),0o600);p.write_text(json.dumps({'stage':'TEST','enabled':True,'publishable_key':'pk_test_x'}))
  with self.assertRaises(m.Stop):m.verify_dependencies(self.obj,self.root,self.public,self.uid)
 def test_permissions(self):
  self.install()
  for name in self.files:self.assertEqual(m.target(name,self.root,self.public).stat().st_mode&0o777,0o644 if name.startswith('public/') else 0o600)


class CompleteInspectionTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);base=pathlib.Path(self.tmp.name);self.root=base/'private';self.public=base/'public';self.root.mkdir(mode=0o700);self.public.mkdir(mode=0o755);self.uid=os.getuid();self.gid=os.getgid();self.obj,self.files=m.load()
  self.obj['fonts']={};self.obj['manifests']=['appointment-management-release.json'];self.obj['known']['private/server/booking-webhook.php']=self.obj['retained']['private/server/booking-webhook.php']['accepted']
  for name,item in self.obj['retained'].items():
   data=m.base64.b64decode(item['content'])
   if name=='private/server/booking-webhook.php':
    data=b'<?php /* old webhook */\n';self.obj['webhook_before']=[m.sha(data)];item['content']=m.base64.b64encode(data).decode();item['accepted']=[m.sha(data),m.sha(self.files[name])];self.obj['known'][name]=item['accepted']
   path=m.target(name,self.root,self.public);path.parent.mkdir(parents=True,exist_ok=True,mode=0o700);path.write_bytes(data);path.chmod(0o644 if name.startswith('public/') else 0o600)
  self.entry=self.public/'manage-appointment.php';self.original_entry=self.entry.read_bytes()
  self.manifest=self.root/'appointment-management-release.json';self.manifest.write_bytes(m.encode({'revision':'20260928-r4','files':{'public/manage-appointment.php':m.sha(self.entry.read_bytes()),'server/booking-webhook.php':self.obj['webhook_before'][0]},'preserve':'yes'}));self.manifest.chmod(0o600)
  (self.root/'booking-checkout.json').write_bytes(m.encode({'stage':'TEST','enabled':True,'publishable_key':'pk_test_abcdefghijklmnop'}));(self.root/'booking-checkout.json').chmod(0o600)
  (self.root/'data').mkdir(mode=0o700)
 def scan(self):return m.scan(self.obj,self.files,self.root,self.public,self.uid,self.gid)
 def install(self):return m.install(self.obj,self.files,self.root,self.public,self.uid,self.gid)
 def values(self):return m.desired(self.obj,self.files,self.root,self.public,self.uid)
 def test_cpanel_0750_root_and_three_legacy_scripts_match_server_layout(self):
  self.public.chmod(0o750)
  record=json.loads(self.manifest.read_text())
  for name in ['tools/check-calendar-confirmation.php','tools/setup-zoho-calendar.py','tools/setup-zoho-confirmation.py']:
   source=ROOT/name;data=source.read_bytes();path=self.root/name;path.parent.mkdir(exist_ok=True,mode=0o700);path.write_bytes(data);path.chmod(0o644);record['files'][name]=m.sha(data)
  self.manifest.write_bytes(m.encode(record));report=self.scan();self.assertEqual(report['errors'],[]);self.assertNotIn(str(self.public),report['metadata_repairs']);self.assertEqual(len(report['metadata_repairs']),3)
  before=self.public.stat();m.repair_metadata(report,self.root,self.uid,self.gid);self.install();after=self.public.stat();self.assertEqual((before.st_uid,before.st_gid,before.st_mode),(after.st_uid,after.st_gid,after.st_mode))
 def test_unrecognized_maintenance_edit_stays_blocked(self):
  name='tools/setup-zoho-calendar.py';p=self.root/name;p.parent.mkdir(exist_ok=True,mode=0o700);p.write_bytes(b'unreviewed script');p.chmod(0o644);record=json.loads(self.manifest.read_text());record['files'][name]=m.sha(p.read_bytes());self.manifest.write_bytes(m.encode(record));report=self.scan();self.assertTrue(any('Content requires review' in e and name in e for e in report['errors']));self.assertNotIn(str(p),report['metadata_repairs'])
 def test_group_writable_website_root_still_blocked(self):
  self.public.chmod(0o775);report=self.scan();self.assertTrue(any('Website root' in e for e in report['errors']));self.assertNotIn(str(self.public),report['metadata_repairs'])
 def test_complete_current_baseline(self):
  report=self.scan();self.assertEqual(report['errors'],[]);self.assertEqual(report['metadata_repairs'],{});self.assertGreaterEqual(len(report['checked_files']),49)
 def test_public_path_uses_website_root(self):
  self.assertFalse((self.root/'public').exists());self.assertEqual(self.scan()['errors'],[]);self.values()
 def test_unsafe_availability_metadata_repaired_only_after_hash_match(self):
  path=self.root/'pricing-assets/availability.js';before=path.read_bytes();path.chmod(0o666);report=self.scan();self.assertEqual(report['errors'],[]);self.assertIn(str(path),report['metadata_repairs']);m.repair_metadata(report,self.root,self.uid,self.gid);self.assertEqual(path.stat().st_mode&0o777,0o600);self.assertEqual(path.read_bytes(),before);self.install()
 def test_multiple_unknown_files_reported_together_without_writes(self):
  for name in ['pricing-assets/availability.js','server/booking-manage.php']:(self.root/name).write_bytes(b'unknown')
  report=self.scan();self.assertGreaterEqual(len(report['errors']),2);self.assertFalse((self.root/m.JOURNAL).exists());self.assertFalse((self.root/m.METADATA).exists())
 def test_missing_management_entry_restored_and_rollback_removes_only_restored_copy(self):
  self.entry.unlink();report=self.scan();self.assertEqual(report['errors'],[]);self.assertIn('public/manage-appointment.php',report['missing_files']);self.install();self.assertEqual(self.entry.read_bytes(),self.original_entry);self.assertEqual(self.scan()['errors'],[]);self.install();m.recover(self.root,self.public,self.uid,self.gid,self.values(),True);self.assertFalse(self.entry.exists())
 def test_restore_preserves_crlf_manifest(self):
  crlf=self.original_entry.replace(b'\n',b'\r\n');record=json.loads(self.manifest.read_text());record['files']['public/manage-appointment.php']=m.sha(crlf);self.manifest.write_bytes(m.encode(record));self.entry.unlink();self.assertEqual(self.scan()['errors'],[]);self.install();self.assertEqual(self.entry.read_bytes(),crlf)
 def test_missing_dependency_and_multiple_metadata_repairs(self):
  (self.root/'pricing-assets/availability.js').unlink();(self.root/'server/booking-manage.php').chmod(0o664);self.entry.chmod(0o600);report=self.scan();self.assertEqual(report['errors'],[]);self.assertEqual(len(report['metadata_repairs']),2);m.repair_metadata(report,self.root,self.uid,self.gid);self.install();self.assertTrue((self.root/'pricing-assets/availability.js').is_file())
 def test_changed_file_never_eligible_for_permission_repair(self):
  path=self.root/'pricing-assets/availability.js';path.write_bytes(b'unknown');path.chmod(0o666);report=self.scan();self.assertTrue(report['errors']);self.assertNotIn(str(path),report['metadata_repairs'])
 def test_symlink_and_hardlink_blockers_collected(self):
  path=self.root/'pricing-assets/availability.js';path.unlink();path.symlink_to(self.entry);path2=self.root/'server/booking-manage.php';path2.unlink();os.link(str(self.entry),str(path2));report=self.scan();self.assertTrue(any('Symbolic' in e for e in report['errors']));self.assertTrue(any('single regular' in e for e in report['errors']))
 def test_metadata_concurrent_change_blocks_before_repair(self):
  path=self.root/'pricing-assets/availability.js';path.chmod(0o666);report=self.scan();path.write_bytes(b'changed after inspection')
  with self.assertRaises(m.Stop):m.repair_metadata(report,self.root,self.uid,self.gid)
  self.assertEqual(path.stat().st_mode&0o777,0o666)
 def test_restore_concurrent_file_appearance_preserved(self):
  self.entry.unlink();self.scan();self.entry.write_bytes(b'new concurrent edit');self.entry.chmod(0o644)
  with self.assertRaises(m.Stop):self.install()
  self.assertEqual(self.entry.read_bytes(),b'new concurrent edit')
 def test_legacy_installed_portal_is_reported_not_overwritten(self):
  (self.root/'portal-install.json').write_bytes(m.encode({'state':'installed','release':'portal-20260929-r1'}));(self.root/'portal-install.json').chmod(0o600);self.assertTrue(any('earlier portal' in e for e in self.scan()['errors']))
 def test_interrupted_install_with_restored_entry_can_resume(self):
  self.entry.unlink();self.scan();count=[0]
  def writer(*args):
   count[0]+=1
   if count[0]==12:raise RuntimeError('power failure')
   m.write_target(*args)
  with self.assertRaises(RuntimeError):m.install(self.obj,self.files,self.root,self.public,self.uid,self.gid,writer)
  self.assertEqual(self.scan()['errors'],[]);self.install();self.assertEqual(self.entry.read_bytes(),self.original_entry)
 def test_sms_values_validated_without_provider_calls(self):
  c={'provider':'twilio-verify','stage':'TEST','enabled':True,'account_sid':'AC'+'1'*32,'service_sid':'VA'+'2'*32,'auth_token':'3'*32,'allowed_numbers':['+13125550100']};m.validate_sms(c)
  for key,value in [('stage','LIVE'),('allowed_numbers',[]),('service_sid','https://other.invalid')]:
   bad=dict(c);bad[key]=value
   with self.assertRaises(m.Stop):m.validate_sms(bad)
if __name__=='__main__':unittest.main()
