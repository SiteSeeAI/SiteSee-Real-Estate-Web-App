"""Current client iteration pins the installed source and rejects unknown additions/edits."""
import base64,hashlib,json,pathlib,subprocess,sys,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from unified_source import before_client_update, before_mode_update
class ClientSource(unittest.TestCase):
    def test_exact_installed_source_and_reviewed_current_layer(self):
        m=json.loads((ROOT/'documents/unified/client-workspace-source.json').read_text())
        self.assertEqual(m['baseline_commit'],'201b1888abad434d37236e3f478b3e0b2068919b')
        self.assertEqual({name for name,c in m['files'].items() if c['added']},{'_private/server/portal-reports.php','_private/server/portal-report-pdf.php'})
        for name,c in m['files'].items():
            with self.subTest(name=name):
                old=subprocess.run(['git','show',m['baseline_commit']+':'+name],cwd=ROOT,capture_output=True)
                self.assertEqual(c['added'],old.returncode!=0)
                self.assertEqual(base64.b64decode(c['before'],validate=True),old.stdout if old.returncode==0 else b'')
                actual=before_mode_update(ROOT,name)
                self.assertEqual(base64.b64decode(c['after'],validate=True),actual)
                self.assertEqual(hashlib.sha256(actual).hexdigest(),c['after_sha256'])
                self.assertEqual(before_client_update(ROOT,name),old.stdout if old.returncode==0 else b'')
    def test_unknown_runtime_edit_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory);manifest='documents/unified/client-workspace-source.json'
            for name in [manifest,'_private/server/portal-reports.php','_private/server/booking-finance.php','public/portal-assets/portal.js']:
                p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes())
            for name in ['_private/server/portal-reports.php','_private/server/booking-finance.php','public/portal-assets/portal.js']:
                with (root/name).open('ab') as f:f.write(b'\nunknown runtime edit\n')
                with self.assertRaisesRegex(RuntimeError,'Reviewed client source differs'):before_client_update(root,name)
if __name__=='__main__':unittest.main()
