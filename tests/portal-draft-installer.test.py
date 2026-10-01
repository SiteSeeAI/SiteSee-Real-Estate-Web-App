import base64,copy,importlib.util,json,os,pathlib,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('updater',ROOT/'tools/install-re-draft-recovery-r1.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class UpdateTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=pathlib.Path(self.tmp.name);self.uid=os.getuid();self.gid=os.getgid();self.obj=m.load()
  for n,item in self.obj['files'].items():
   p=self.root/n;p.parent.mkdir(exist_ok=True,parents=True);p.write_bytes(base64.b64decode(item['before']));p.chmod(0o600)
  self.configs={'booking-lifecycle.json':{'schema':1,'stage':'test','enabled':True,'recipient':'sales@re.sitesee.ai'},'booking-mail.json':{'stage':'test','enabled':True,'sender':'sales@re.sitesee.ai','test_recipient_email':'sales@re.sitesee.ai','graph_credentials':'/home/sitesee/.sitesee-graph-mail.json','retain':'unchanged'},'microsoft-scheduling.json':{'schema':1,'stage':'test','provider':'microsoft','enabled':True,'confirmation_stage':'test','confirmation_enabled':True,'invitations_enabled':True,'test_recipient_email':'sales@re.sitesee.ai','calendar_uid':self.obj['calendar_uid']}}
  for n,c in self.configs.items():self.put(n,c)
  self.put(m.FLAG,{'release':'portal-20260929-r2','stage':'TEST','enabled':True})
  for n in self.obj['manifests']:self.put(n,{'revision':'preserve','files':{key:m.sha((self.root/key).read_bytes()) for key in self.obj['files']},'retain':'yes'})
  (self.root/'deployment-backups').mkdir(mode=0o700)
  self.put('portal-sms.json',{'private':'saved credentials'});(self.root/'bookings.sqlite').write_bytes(b'untouched database sentinel')
 def put(self,n,c):
  p=self.root/n;p.write_bytes(m.encode(c));p.chmod(0o600)
 def begin(self):
  before,after=m.inspect(self.root,self.uid,self.obj);j=m.prepare(self.root,self.uid,self.gid,self.obj,before,after);return j,before,after
 def resume(self):
  j=json.loads((self.root/m.JOURNAL).read_bytes());before,after=m.resume_plan(self.root,self.uid,self.obj,j);m.apply(self.root,self.uid,self.gid,self.obj,j,before,after);return after
 def test_historical_root_owned_backup_parent_with_distinct_application_owner(self):
  from unittest.mock import patch
  base=self.root/'deployment-backups';original=pathlib.Path.stat
  def historical(p,*args,**kwargs):
   info=list(original(p,*args,**kwargs))
   if p==self.root:info[4]=1009;info[5]=1011;info[0]=m.stat.S_IFDIR|0o700
   if p==base:info[4]=0;info[5]=0;info[0]=m.stat.S_IFDIR|0o700
   return os.stat_result(info)
  with patch.object(pathlib.Path,'stat',historical):self.assertEqual(m.backup_parent(self.root,1009),base)
 def test_backup_owner_and_mode_matrix(self):
  from unittest.mock import patch
  base=self.root/'deployment-backups';original=pathlib.Path.stat
  for owner,mode,accepted in [(0,0o700,True),(1009,0o700,True),(0,0o750,True),(1009,0o755,True),(999,0o700,False),(0,0o770,False),(1009,0o777,False),(0,0o600,False)]:
   with self.subTest(owner=owner,mode=mode):
    def fixture(p,*args,**kwargs):
     info=list(original(p,*args,**kwargs))
     if p==self.root:info[4]=1009;info[0]=m.stat.S_IFDIR|0o700
     if p==base:info[4]=owner;info[0]=m.stat.S_IFDIR|mode
     return os.stat_result(info)
    with patch.object(pathlib.Path,'stat',fixture):
     if accepted:self.assertEqual(m.backup_parent(self.root,1009),base)
     else:
      with self.assertRaises(m.Stop):m.backup_parent(self.root,1009)
 def test_parent_and_historical_backups_are_preserved(self):
  base=self.root/'deployment-backups';base.chmod(0o755);old=base/'historic';old.mkdir(mode=0o700);p=old/'saved.bin';p.write_bytes(b'historical bytes');p.chmod(0o600)
  metadata=[x.stat() for x in [base,old,p]];j,before,after=self.begin();m.apply(self.root,self.uid,self.gid,self.obj,j,before,after);self.resume()
  for x,info in zip([base,old,p],metadata):self.assertEqual((x.stat().st_uid,x.stat().st_gid,x.stat().st_mode),(info.st_uid,info.st_gid,info.st_mode))
  self.assertEqual(p.read_bytes(),b'historical bytes');new=base/j['backup'];self.assertEqual(new.stat().st_mode&0o777,0o700)
  self.assertTrue(all(x.stat().st_mode&0o777==0o600 for x in new.iterdir()))
 def test_all_environment_and_content_blockers_reported_before_writes(self):
  (self.root/'deployment-backups').chmod(0o777);(self.root/'server/booking-workflow.php').write_bytes(b'unknown');self.put('booking-checkout.json',{'stage':'LIVE'})
  before=(self.root/m.FLAG).read_bytes()
  with self.assertRaises(m.Stop) as e:m.preflight(self.root,self.uid,self.obj)
  for text in ['Backup parent','Stripe must remain TEST','Unrecognized code']:self.assertIn(text,str(e.exception))
  self.assertEqual((self.root/m.FLAG).read_bytes(),before);self.assertFalse((self.root/m.JOURNAL).exists())
 def test_server_0755_directories_pass_preflight(self):
  (self.root/'server').chmod(0o755);self.put('booking-checkout.json',{'stage':'TEST'})
  m.preflight(self.root,self.uid,self.obj)
 def test_backup_parent_symlink_and_regular_file_refused(self):
  base=self.root/'deployment-backups';base.rmdir();base.symlink_to(self.root/'server')
  with self.assertRaises(m.Stop):m.backup_parent(self.root,self.uid)
  base.unlink();base.write_bytes(b'keep')
  with self.assertRaises(m.Stop):m.backup_parent(self.root,self.uid)
  self.assertEqual(base.read_bytes(),b'keep')
 def test_missing_backup_parent_is_created_privately(self):
  base=self.root/'deployment-backups';base.rmdir();j,before,after=self.begin();self.assertEqual(base.stat().st_mode&0o777,0o700)
  m.apply(self.root,self.uid,self.gid,self.obj,j,before,after);self.resume()
 def test_wrong_journal_revision_refused(self):
  j,before,after=self.begin();j['revision']='re-business-workflow-20261001-r2';self.put(m.JOURNAL,j)
  with self.assertRaises(m.Stop):m.resume_plan(self.root,self.uid,self.obj,j)
 def test_prior_personal_configuration_requires_business_update(self):
  self.configs['booking-mail.json']['test_recipient_email']='cro@sitesee.ai';self.put('booking-mail.json',self.configs['booking-mail.json'])
  with self.assertRaises(m.Stop):m.inspect(self.root,self.uid,self.obj)
 def test_backup_directory_fsync_precedes_journal(self):
  from unittest.mock import patch
  base=self.root/'deployment-backups';inode=base.stat().st_ino;before,after=m.inspect(self.root,self.uid,self.obj);original=m.os.fsync
  def interrupted(fd):
   if os.fstat(fd).st_ino==inode:raise OSError('backup parent fsync interrupted')
   original(fd)
  with patch.object(m.os,'fsync',side_effect=interrupted):
   with self.assertRaises(OSError):m.prepare(self.root,self.uid,self.gid,self.obj,before,after)
  self.assertFalse((self.root/m.JOURNAL).exists());self.assertEqual((self.root/m.FLAG).read_bytes(),before[m.FLAG])
 def test_complete_business_update_and_rerun(self):
  j,before,after=self.begin();m.apply(self.root,self.uid,self.gid,self.obj,j,before,after);self.resume()
  for n,b in after.items():self.assertEqual((self.root/n).read_bytes(),b)
  for n,key in m.CONFIGS.items():self.assertEqual(json.loads((self.root/n).read_bytes())[key],'sales@re.sitesee.ai')
  self.assertEqual(json.loads((self.root/'booking-mail.json').read_bytes())['retain'],'unchanged')
  self.assertEqual((self.root/'bookings.sqlite').read_bytes(),b'untouched database sentinel');self.assertEqual(json.loads((self.root/'portal-sms.json').read_bytes()),{'private':'saved credentials'})
 def test_interruption_at_each_write_resumes(self):
  for stop in range(len(self.obj['files'])+len(self.obj['manifests'])+9):
   with self.subTest(stop=stop):
    if (self.root/m.JOURNAL).exists():
     old=json.loads((self.root/m.JOURNAL).read_bytes());folder=self.root/'deployment-backups'/old['backup']
     for n in old['entries']:(self.root/n).write_bytes((folder/(m.hashlib.sha256(n.encode()).hexdigest()+'.bin')).read_bytes())
     (self.root/m.JOURNAL).unlink()
    j,before,after=self.begin();count=[0]
    def writer(*args):
     if count[0]==stop:raise RuntimeError('interrupted')
     count[0]+=1;m.atomic(*args)
    try:m.apply(self.root,self.uid,self.gid,self.obj,j,before,after,writer)
    except RuntimeError:pass
    self.resume()
    for n,b in after.items():self.assertEqual((self.root/n).read_bytes(),b)
 def test_unknown_code_and_config_all_reported_before_writes(self):
  (self.root/'server/booking-workflow.php').write_bytes(b'unknown code');self.configs['booking-mail.json']['stage']='live';self.put('booking-mail.json',self.configs['booking-mail.json'])
  with self.assertRaises(m.Stop) as e:m.inspect(self.root,self.uid,self.obj)
  self.assertIn('Unrecognized code',str(e.exception));self.assertIn('TEST configuration',str(e.exception));self.assertFalse((self.root/m.JOURNAL).exists())
 def test_symlink_preserved(self):
  p=self.root/'server/booking-workflow.php';p.unlink();p.symlink_to(self.root/'server/booking-staff.php')
  with self.assertRaises(m.Stop):m.inspect(self.root,self.uid,self.obj)
  self.assertTrue(p.is_symlink())
 def test_changed_recipient_configuration_refused(self):
  self.configs['booking-mail.json']['test_recipient_email']='unrecognized@example.com';self.put('booking-mail.json',self.configs['booking-mail.json'])
  with self.assertRaises(m.Stop):m.inspect(self.root,self.uid,self.obj)
 def test_concurrent_edit_after_planning_preserved(self):
  j,before,after=self.begin();p=self.root/'server/booking-staff.php';p.write_bytes(b'concurrent')
  with self.assertRaises(m.Stop):m.apply(self.root,self.uid,self.gid,self.obj,j,before,after)
  self.assertEqual(p.read_bytes(),b'concurrent');self.assertEqual((self.root/m.FLAG).read_bytes(),before[m.FLAG])
 def test_concurrent_edit_during_update_preserved(self):
  j,before,after=self.begin();p=self.root/'server/booking-workflow.php';changed=[False]
  def writer(*args):
   m.atomic(*args)
   if not changed[0]:p.write_bytes(b'concurrent');changed[0]=True
  with self.assertRaises(m.Stop):m.apply(self.root,self.uid,self.gid,self.obj,j,before,after,writer)
  self.assertEqual(p.read_bytes(),b'concurrent');self.assertFalse(json.loads((self.root/m.FLAG).read_bytes())['enabled'])
 def test_tampered_backup_refused(self):
  j,before,after=self.begin();next((self.root/'deployment-backups'/j['backup']).glob('*.bin')).write_bytes(b'bad')
  with self.assertRaises(m.Stop):m.resume_plan(self.root,self.uid,self.obj,j)
 def test_new_edit_after_success_is_not_overwritten(self):
  j,before,after=self.begin();m.apply(self.root,self.uid,self.gid,self.obj,j,before,after);p=self.root/'server/booking-staff.php';p.write_bytes(b'newer')
  with self.assertRaises(m.Stop):self.resume()
  self.assertEqual(p.read_bytes(),b'newer')
 def test_configuration_disable_is_not_silently_overridden(self):
  self.configs['booking-lifecycle.json']['enabled']=False;self.put('booking-lifecycle.json',self.configs['booking-lifecycle.json'])
  with self.assertRaises(m.Stop):m.inspect(self.root,self.uid,self.obj)
 def test_manifest_mismatch_refused(self):
  p=self.obj['manifests'][0];c=json.loads((self.root/p).read_bytes());c['files']['server/booking-workflow.php']='0'*64;self.put(p,c)
  with self.assertRaises(m.Stop):m.inspect(self.root,self.uid,self.obj)
 def test_gates_stay_disabled_until_code_and_manifests_verified(self):
  j,before,after=self.begin();seen=[]
  def writer(p,b,uid,gid):
   if str(p.relative_to(self.root)) not in m.gate_bytes(before):
    self.assertTrue(all(not json.loads((self.root/n).read_bytes())['enabled'] for n in m.gate_bytes(before)))
   m.atomic(p,b,uid,gid);seen.append(str(p.relative_to(self.root)))
  m.apply(self.root,self.uid,self.gid,self.obj,j,before,after,writer);self.assertEqual(seen[-1],m.FLAG)
 def test_final_journal_commit_failure_resumes(self):
  from unittest.mock import patch
  j,before,after=self.begin();original=m.atomic
  def fail_journal(p,*args):
   if p.name==m.JOURNAL:raise RuntimeError('journal interrupted')
   original(p,*args)
  with patch.object(m,'atomic',side_effect=fail_journal):
   with self.assertRaises(RuntimeError):m.apply(self.root,self.uid,self.gid,self.obj,j,before,after,writer=original)
  self.assertEqual(json.loads((self.root/m.JOURNAL).read_bytes())['state'],'prepared');self.resume()
 def test_active_booking_lock_blocks_update(self):
  fd=m.appointment_lock(self.root,self.uid,self.gid)
  try:
   with self.assertRaises(m.Stop):m.appointment_lock(self.root,self.uid,self.gid)
  finally:os.close(fd)
  fd=m.appointment_lock(self.root,self.uid,self.gid);os.close(fd)
 def test_lock_symlink_is_preserved(self):
  p=self.root/'booking-confirmation.lock';p.symlink_to(self.root/'bookings.sqlite')
  with self.assertRaises(m.Stop):m.appointment_lock(self.root,self.uid,self.gid)
  self.assertTrue(p.is_symlink())
 def test_crlf_baseline_and_manifests_supported(self):
  for n in self.obj['files']:(self.root/n).write_bytes((self.root/n).read_bytes().replace(b'\n',b'\r\n'))
  for n in self.obj['manifests']:
   c=json.loads((self.root/n).read_bytes());c['files']={key:m.sha((self.root/key).read_bytes()) for key in self.obj['files']};self.put(n,c)
  j,before,after=self.begin();m.apply(self.root,self.uid,self.gid,self.obj,j,before,after);self.resume()
if __name__=='__main__':unittest.main()
