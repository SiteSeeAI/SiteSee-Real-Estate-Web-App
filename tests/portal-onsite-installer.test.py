"""Installed-closeout upgrade, interruption and preservation checks."""
import base64, hashlib, importlib.util, json, os, pathlib, subprocess, tempfile, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('onsite',ROOT/'tools/install-re-onsite-services-20261005-r1.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class OnsiteInstaller(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);base=pathlib.Path(self.tmp.name)
        self.root=base/'private';self.public=base/'public';self.root.mkdir(mode=0o700);self.public.mkdir(mode=0o750)
        self.uid=os.getuid();self.gid=os.getgid();self.obj=m.load()
        for n,item in self.obj['files'].items():
            p=self.path(n);p.parent.mkdir(exist_ok=True,parents=True,mode=0o755 if n.startswith('public/') else 0o700)
            if item['before'] is not None:self.put(n,base64.b64decode(item['before']))
        for n in self.obj['dependencies']:
            source=n.replace('private/','_private/',1) if n.startswith('private/') else n
            self.path(n).parent.mkdir(exist_ok=True,parents=True,mode=0o755 if n.startswith('public/') else 0o700)
            self.put(n,(ROOT/source).read_bytes())
        self.manifests()
        self.put('private/booking-checkout.json',m.encode({'stage':'TEST','enabled':True,'key':'synthetic-preserved'}))
        self.put('private/job-closeout-20261002-r1-install.json',m.encode({'revision':'job-closeout-20261002-r1','state':'installed','backup':'job-closeout-original'}))
        self.put('private/bookings.sqlite',b'Protected original booking, deposit, CRM, calendar and old job data')
        (self.root/'deployment-backups').mkdir(mode=0o700)
        self.protected={p:(p.read_bytes(),m.metadata(p)) for p in [self.root/'bookings.sqlite',self.root/'booking-checkout.json',self.root/'job-closeout-20261002-r1-install.json']}
    def path(self,n):return m.target(n,self.root,self.public)
    def put(self,n,data):
        p=self.path(n);p.write_bytes(data);p.chmod(0o644 if n.startswith('public/') else 0o600)
    def manifests(self):
        for name in self.obj['manifests']:
            self.put('private/'+name,m.encode({'files':{n.removeprefix('private/'):m.sha(self.path(n).read_bytes()) for n,item in self.obj['files'].items() if item['before'] is not None},'retain':'existing metadata'}))
    def begin(self):
        b,a=m.preflight(self.root,self.public,self.uid,self.obj);j=m.prepare(self.root,self.public,self.uid,self.gid,self.obj,b,a);return j,b,a
    def finish(self,j,b,a,writer=m.atomic):m.apply(self.root,self.public,self.uid,self.gid,self.obj,j,b,a,writer)
    def resume(self):
        j=json.loads((self.root/m.JOURNAL).read_bytes());b,a=m.preflight(self.root,self.public,self.uid,self.obj,j);self.finish(j,b,a);return a
    def check_preserved(self):
        for p,(data,meta) in self.protected.items():self.assertEqual((p.read_bytes(),m.metadata(p)),(data,meta))
    def test_install_rerun_and_metadata(self):
        j,b,a=self.begin();self.finish(j,b,a);self.resume();self.check_preserved()
        for n,data in a.items():self.assertEqual(self.path(n).read_bytes(),data)
        for name in self.obj['manifests']:
            record=json.loads((self.root/name).read_bytes());self.assertEqual(record['retain'],'existing metadata')
            for key,h in record['files'].items():self.assertEqual(h,m.sha(self.path(key if key.startswith('public/') else 'private/'+key).read_bytes()))
    def test_recover_every_write_boundary(self):
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
                    if count[0]==stop:raise RuntimeError('interrupted')
                    count[0]+=1;m.atomic(*args)
                try:self.finish(j,b,a,writer)
                except RuntimeError:pass
                self.resume();self.check_preserved()
                for n,data in a.items():self.assertEqual(self.path(n).read_bytes(),data)
    def test_all_known_crlf_files_and_dependencies(self):
        for n in set(self.obj['files'])|set(self.obj['dependencies']):
            p=self.path(n)
            if p.is_file():p.write_bytes(p.read_bytes().replace(b'\r\n',b'\n').replace(b'\n',b'\r\n'))
        deps={n:self.path(n).read_bytes() for n in self.obj['dependencies']};self.manifests()
        j,b,a=self.begin();self.finish(j,b,a);self.resume()
        for n,data in deps.items():self.assertEqual(self.path(n).read_bytes(),data)
        self.check_preserved()
    def test_unknown_source_or_dependency_stops_without_writes(self):
        for n in ['private/server/booking-job.php','private/real-estate-pricing.php']:
            self.path(n).write_bytes(self.path(n).read_bytes()+b'unknown edit')
        with self.assertRaises(m.Stop):self.begin()
        self.assertFalse((self.root/m.JOURNAL).exists());self.check_preserved()
    def test_prior_incomplete_install_blocks(self):
        self.put('private/job-closeout-20261002-r1-install.json',m.encode({'state':'prepared'}))
        with self.assertRaisesRegex(m.Stop,'earlier update is incomplete'):self.begin()
        self.assertFalse((self.root/m.JOURNAL).exists())
    def test_live_configuration_blocks(self):
        self.put('private/booking-checkout.json',m.encode({'stage':'LIVE'}))
        with self.assertRaisesRegex(m.Stop,'Stripe must remain TEST'):self.begin()
    def test_concurrent_edit_before_write_is_preserved(self):
        j,b,a=self.begin();p=self.path('private/server/booking-job-ui.php');p.write_bytes(b'concurrent')
        with self.assertRaises(m.Stop):self.finish(j,b,a)
        self.assertEqual(p.read_bytes(),b'concurrent');self.check_preserved()
    def test_changed_journal_and_backup_block_recovery(self):
        j,b,a=self.begin();j['payload_sha']='0'*64
        with self.assertRaises(m.Stop):m.resume_plan(self.root,self.public,self.uid,self.obj,j)
        j=json.loads((self.root/m.JOURNAL).read_bytes());backup=next((self.root/'deployment-backups'/j['backup']).glob('*.bin'));backup.write_bytes(b'bad')
        with self.assertRaises(m.Stop):self.resume()
    def test_symbolic_link_is_preserved(self):
        p=self.path('private/server/booking-job-catalog.php');p.symlink_to(self.root/'bookings.sqlite')
        with self.assertRaises(m.Stop):self.begin()
        self.assertTrue(p.is_symlink());self.check_preserved()
    def test_before_source_matches_installed_release_and_after_current_source(self):
        prior=json.loads((ROOT/'documents/portal/job-closeout-source.json').read_text())
        self.assertEqual(len(self.obj['files']),6);self.assertEqual(len(self.obj['dependencies']),40)
        for n,item in self.obj['files'].items():
            source=n.replace('private/','_private/',1) if n.startswith('private/') else n
            self.assertEqual(base64.b64decode(item['after']),(ROOT/source).read_bytes())
            if item['before'] is not None:self.assertEqual(item['before'],prior['files'][n]['after'])
        self.assertEqual(self.obj['order'][0],'private/server/booking-job-catalog.php')
        self.assertEqual(self.obj['order'][-1],'private/server/booking-staff.php')
    def test_builder_reproduces_both_installer_releases(self):
        for builder,package in [('build-onsite-services.py','install-re-onsite-services-20261005-r1.py'),('build-job-closeout.py','install-re-job-closeout-20261003-r1_1.py')]:
            before=(ROOT/'tools'/package).read_bytes();subprocess.run(['python3',str(ROOT/'tools'/builder)],check=True);self.assertEqual((ROOT/'tools'/package).read_bytes(),before)
        self.assertEqual(hashlib.sha256((ROOT/'tools/install-re-job-closeout-20261003-r1_1.py').read_bytes()).hexdigest(),'b8abe2d55101176082e0497013a112650228cc96721ae9e40934f1687ba21378')

if __name__=='__main__':unittest.main()
