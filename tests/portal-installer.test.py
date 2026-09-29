import copy, importlib.util, json, os, pathlib, tempfile, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('installer',ROOT/'tools/install-customer-portal.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class InstallerTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name)/'private';self.public=pathlib.Path(self.tmp.name)/'public';self.root.mkdir();self.public.mkdir();self.uid=os.getuid();self.gid=os.getgid();self.obj,self.files=m.load()
  self.obj=copy.deepcopy(self.obj);self.obj['manifests']=['legacy.json'];self.old=b'<?php /* original webhook */\n';self.obj['webhook_before']=[m.sha(self.old)]
  (self.root/'server').mkdir();(self.root/'views').mkdir();(self.public/'portal-assets').mkdir();(self.root/'server/booking-webhook.php').write_bytes(self.old)
  (self.root/'legacy.json').write_bytes(m.encode({'files':{'server/booking-webhook.php':m.sha(self.old)},'retain':'yes'}))
  self.initial={str(p.relative_to(self.root)):p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
 def install(self,writer=None):return m.install(self.obj,self.files,self.root,self.public,self.uid,self.gid,writer)
 def values(self):return m.desired(self.obj,self.files,self.root,self.uid)
 def assert_restored(self):
  for name,data in self.initial.items():self.assertEqual((self.root/name).read_bytes(),data)
  for name in self.files:
   if name!='private/server/booking-webhook.php':self.assertFalse(m.target(name,self.root,self.public).exists())
 def test_payload_scope(self):
  self.assertEqual(len(self.files),19)
  self.assertNotIn('private/real-estate-form-config.php',self.files)
  self.assertTrue(all('/portal' in n or n=='public/account.php' or n=='private/server/booking-webhook.php' for n in self.files))
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
if __name__=='__main__':unittest.main()
