import base64, importlib.util, json, os, pathlib, tempfile, unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('polish',ROOT/'tools/install-re-portal-polish-20261002-r1.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class InstallerTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);base=pathlib.Path(self.tmp.name)
  self.root=base/'private';self.public=base/'public';self.root.mkdir(mode=0o700);self.public.mkdir(mode=0o750)
  self.uid=os.getuid();self.gid=os.getgid();self.obj=m.load()
  for n,item in self.obj['files'].items():
   p=self.path(n);p.parent.mkdir(exist_ok=True,parents=True,mode=0o755 if n.startswith('public/') else 0o700)
   if item['before'] is not None:p.write_bytes(base64.b64decode(item['before']));p.chmod(0o644 if n.startswith('public/') else 0o600)
  for n in self.obj['dependencies']:
   source=n.replace('private/','_private/',1) if n.startswith('private/') else n
   p=self.path(n);p.parent.mkdir(exist_ok=True,parents=True);p.write_bytes((ROOT/source).read_bytes());p.chmod(0o600)
  for name in self.obj['manifests']:
   self.put('private/'+name,{'revision':'preserve','files':{n.removeprefix('private/'):m.sha(self.path(n).read_bytes()) for n,v in self.obj['files'].items() if v['before'] is not None},'retain':['unknown historical metadata']})
  self.put('private/booking-checkout.json',{'stage':'TEST','enabled':True,'private':'unchanged'})
  for n in ['microsoft-scheduling.json','booking-mail.json','booking-lifecycle.json','portal-test.json','portal-sms.json']:
   self.put('private/'+n,{'credential':'synthetic sentinel','enabled':True})
  (self.root/'bookings.sqlite').write_bytes(b'orders deposits CRM calendar links')
  self.protected={p:p.read_bytes() for p in self.root.glob('*') if p.is_file() and p.name not in self.obj['manifests']}
  (self.root/'deployment-backups').mkdir(mode=0o700)
 def path(self,n):return m.target(n,self.root,self.public)
 def put(self,n,data):
  p=self.path(n);p.write_bytes(m.encode(data));p.chmod(0o600)
 def begin(self):
  before,after=m.preflight(self.root,self.public,self.uid,self.obj)
  j=m.prepare(self.root,self.public,self.uid,self.gid,self.obj,before,after);return j,before,after
 def apply(self,j,b,a,writer=m.atomic):return m.apply(self.root,self.public,self.uid,self.gid,self.obj,j,b,a,writer)
 def resume(self):
  j=json.loads((self.root/m.JOURNAL).read_bytes());b,a=m.preflight(self.root,self.public,self.uid,self.obj,j);self.apply(j,b,a);return a
 def test_complete_update_rerun_and_preservation(self):
  j,b,a=self.begin();self.apply(j,b,a);self.resume()
  for n,data in a.items():self.assertEqual(self.path(n).read_bytes(),data)
  for p,data in self.protected.items():self.assertEqual(p.read_bytes(),data)
  for n in self.obj['files']:self.assertEqual(self.path(n).stat().st_mode&0o777,0o644 if n.startswith('public/') else 0o600)
  self.assertEqual(self.public.stat().st_mode&0o777,0o750)
 def test_interrupt_at_every_application_write(self):
  for stop in range(len(m.targets(self.obj))+1):
   with self.subTest(stop=stop):
    if (self.root/m.JOURNAL).exists():
     j=json.loads((self.root/m.JOURNAL).read_bytes());folder=self.root/'deployment-backups'/j['backup']
     for n,item in j['entries'].items():
      if item['before'] is None:self.path(n).unlink(missing_ok=True)
      else:self.path(n).write_bytes((folder/(m.sha(n.encode())+'.bin')).read_bytes())
     (self.root/m.JOURNAL).unlink()
    j,b,a=self.begin();count=[0]
    def writer(*args):
     if count[0]==stop:raise RuntimeError('power loss')
     count[0]+=1;m.atomic(*args)
    try:self.apply(j,b,a,writer)
    except RuntimeError:pass
    self.resume()
    for n,data in a.items():self.assertEqual(self.path(n).read_bytes(),data)
    for p,data in self.protected.items():self.assertEqual(p.read_bytes(),data)
 def test_final_journal_failure_resumes(self):
  j,b,a=self.begin();original=m.atomic
  def writer(p,*args):
   if p.name==m.JOURNAL:raise RuntimeError('journal interruption')
   original(p,*args)
  with patch.object(m,'atomic',writer):
   with self.assertRaises(RuntimeError):self.apply(j,b,a)
  self.assertEqual(json.loads((self.root/m.JOURNAL).read_bytes())['state'],'prepared');self.resume()
 def test_unknown_existing_file_and_new_file_are_preserved(self):
  for n in ['private/server/portal-orders.php','public/portal-assets/portal.css']:
   self.path(n).write_bytes(b'unknown');self.path(n).chmod(0o600)
  with self.assertRaises(m.Stop) as e:self.begin()
  self.assertIn('portal-orders.php',str(e.exception));self.assertIn('portal.css',str(e.exception));self.assertFalse((self.root/m.JOURNAL).exists())
 def test_concurrent_edit_before_backup(self):
  b,a=m.inspect(self.root,self.public,self.uid,self.obj);p=self.path('private/views/portal.php');p.write_bytes(b'concurrent')
  with self.assertRaises(m.Stop):m.prepare(self.root,self.public,self.uid,self.gid,self.obj,b,a)
  self.assertEqual(p.read_bytes(),b'concurrent');self.assertFalse((self.root/m.JOURNAL).exists())
 def test_concurrent_edit_before_apply(self):
  j,b,a=self.begin();p=self.path('public/portal-assets/portal.css');p.write_bytes(b'concurrent')
  with self.assertRaises(m.Stop):self.apply(j,b,a)
  self.assertEqual(p.read_bytes(),b'concurrent');self.assertEqual(self.path('private/views/portal.php').read_bytes(),b['private/views/portal.php'])
 def test_concurrent_edit_during_apply_and_resume_preserved(self):
  j,b,a=self.begin();p=self.path('private/views/portal.php');changed=[False]
  def writer(*args):
   m.atomic(*args)
   if not changed[0]:p.write_bytes(b'concurrent');changed[0]=True
  with self.assertRaises(m.Stop):self.apply(j,b,a,writer)
  with self.assertRaises(m.Stop):self.resume()
  self.assertEqual(p.read_bytes(),b'concurrent')
 def test_unknown_edit_after_install_preserved(self):
  j,b,a=self.begin();self.apply(j,b,a);p=self.path('public/portal-assets/portal.css');p.write_bytes(b'newer')
  with self.assertRaises(m.Stop):self.resume()
  self.assertEqual(p.read_bytes(),b'newer')
 def test_concurrent_permission_change_is_preserved(self):
  j,b,a=self.begin();p=self.path('public/portal-assets/portal.css');p.chmod(0o640)
  with self.assertRaises(m.Stop):self.apply(j,b,a)
  self.assertEqual(p.stat().st_mode&0o777,0o640)
 def test_symlink_and_hardlink_preserved(self):
  p=self.path('public/portal-assets/portal.css');p.unlink();p.symlink_to(self.root/'bookings.sqlite')
  with self.assertRaises(m.Stop):self.begin()
  self.assertTrue(p.is_symlink());p.unlink();os.link(self.root/'bookings.sqlite',p)
  with self.assertRaises(m.Stop):self.begin()
  self.assertEqual(p.read_bytes(),b'orders deposits CRM calendar links')
 def test_manifest_mismatch_stops_before_writes(self):
  name='private/'+self.obj['manifests'][0];d=json.loads(self.path(name).read_bytes());d['files']['server/portal-orders.php']='0'*64;self.put(name,d)
  with self.assertRaises(m.Stop):self.begin()
  self.assertFalse((self.root/m.JOURNAL).exists())
 def test_unaffected_manifest_is_byte_identical(self):
  name='private/'+self.obj['manifests'][0];raw=b'{"files": {}, "retain": "spacing"}\n';self.path(name).write_bytes(raw)
  j,b,a=self.begin();self.apply(j,b,a);self.assertEqual(self.path(name).read_bytes(),raw)
 def test_corrupt_backup_and_wrong_journal_stop(self):
  j,b,a=self.begin();folder=self.root/'deployment-backups'/j['backup'];p=next(folder.glob('*.bin'));p.write_bytes(b'bad')
  with self.assertRaises(m.Stop):self.resume()
  j['revision']='different'
  with self.assertRaises(m.Stop):m.resume_plan(self.root,self.public,self.uid,self.obj,j)
 def test_root_owned_historical_backup_matrix(self):
  base=self.root/'deployment-backups';original=pathlib.Path.stat
  for owner,mode,ok in [(0,0o700,True),(1009,0o700,True),(0,0o755,True),(999,0o700,False),(0,0o770,False),(0,0o600,False)]:
   def stat(p,*a,**kw):
    s=list(original(p,*a,**kw))
    if p==self.root:s[4]=1009;s[0]=m.stat.S_IFDIR|0o700
    if p==base:s[4]=owner;s[0]=m.stat.S_IFDIR|mode
    return os.stat_result(s)
   with self.subTest(owner=owner,mode=mode),patch.object(pathlib.Path,'stat',stat):
    if ok:self.assertEqual(m.backup_parent(self.root,1009),base)
    else:
     with self.assertRaises(m.Stop):m.backup_parent(self.root,1009)
 def test_historical_backup_metadata_and_contents_untouched(self):
  base=self.root/'deployment-backups';old=base/'historic';old.mkdir(mode=0o700);saved=old/'old.bin';saved.write_bytes(b'history');saved.chmod(0o600)
  metas={p:m.metadata(p) for p in [base,old,saved]};j,b,a=self.begin();self.apply(j,b,a);self.resume()
  for p,meta in metas.items():self.assertEqual(m.metadata(p),meta)
  self.assertEqual(saved.read_bytes(),b'history')
 def test_missing_backup_parent_and_backup_fsync_failure(self):
  base=self.root/'deployment-backups';base.rmdir();j,b,a=self.begin();self.assertEqual(base.stat().st_mode&0o777,0o700)
  (self.root/m.JOURNAL).unlink();inode=base.stat().st_ino;original=m.os.fsync
  def fail(fd):
   if os.fstat(fd).st_ino==inode:raise OSError('backup fsync')
   original(fd)
  with patch.object(m.os,'fsync',fail):
   with self.assertRaises(OSError):m.prepare(self.root,self.public,self.uid,self.gid,self.obj,b,a)
  self.assertFalse((self.root/m.JOURNAL).exists())
 def test_backup_parent_symlink_and_regular_file_refused(self):
  p=self.root/'deployment-backups';p.rmdir();p.symlink_to(self.public)
  with self.assertRaises(m.Stop):self.begin()
  p.unlink();p.write_bytes(b'keep')
  with self.assertRaises(m.Stop):self.begin()
  self.assertEqual(p.read_bytes(),b'keep')
 def test_crlf_baselines(self):
  for n,item in self.obj['files'].items():
   if item['before'] is not None:self.path(n).write_bytes(self.path(n).read_bytes().replace(b'\n',b'\r\n'))
  for name in self.obj['manifests']:
   d=json.loads((self.root/name).read_bytes());d['files']={n.removeprefix('private/'):m.sha(self.path(n).read_bytes()) for n,i in self.obj['files'].items() if i['before'] is not None};self.put('private/'+name,d)
  j,b,a=self.begin();self.apply(j,b,a);self.resume()
 def test_stripe_and_dependency_blockers_report_together(self):
  self.put('private/booking-checkout.json',{'stage':'LIVE'});n=next(iter(self.obj['dependencies']));self.path(n).write_bytes(b'unknown')
  with self.assertRaises(m.Stop) as e:self.begin()
  self.assertIn('Stripe must remain TEST',str(e.exception));self.assertIn('dependency',str(e.exception));self.assertFalse((self.root/m.JOURNAL).exists())
 def test_prior_interrupted_update_stops(self):
  for name in ['calendar-notice-20261002-r1-install.json','re-draft-recovery-install.json','re-business-workflow-install.json','portal-complete-install.json']:
   with self.subTest(name=name):
    self.put('private/'+name,{'state':'prepared'})
    with self.assertRaises(m.Stop):self.begin()
    self.path('private/'+name).unlink()
 def test_appointment_lock_and_lock_symlink(self):
  fd=m.appointment_lock(self.root,self.uid,self.gid)
  try:
   with self.assertRaises(m.Stop):m.appointment_lock(self.root,self.uid,self.gid)
  finally:os.close(fd)
  p=self.root/'booking-confirmation.lock';p.unlink();p.symlink_to(self.root/'bookings.sqlite')
  with self.assertRaises(m.Stop):m.appointment_lock(self.root,self.uid,self.gid)
 def test_deployment_order_and_lint_never_execute_application(self):
  order=self.obj['order'];self.assertEqual(order[0],'public/portal-assets/portal.css')
  self.assertLess(order.index('private/server/portal-orders.php'),order.index('private/views/portal.php'))
  with patch.object(m.subprocess,'run') as run:
   run.return_value.returncode=0;m.lint(self.obj,'/test/php')
   self.assertEqual(run.call_count,3)
   for call in run.call_args_list:self.assertEqual(call.args[0][:2],['/test/php','-l'])
if __name__=='__main__':unittest.main()
