"""The financial layer retains the exact installed predecessor and rejects unknown edits."""
import base64,hashlib,json,pathlib,subprocess,sys,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from unified_source import before_unified_update
class FinanceSource(unittest.TestCase):
    def test_exact_installed_before_and_candidate_after(self):
        m=json.loads((ROOT/'documents/unified/finance-source.json').read_text())
        self.assertEqual(m['baseline_commit'],'c40236e0937546e14ebb50d835e3b911f700d616')
        for name,c in m['files'].items():
            with self.subTest(name=name):
                old=subprocess.run(['git','show',m['baseline_commit']+':'+name],cwd=ROOT,capture_output=True)
                self.assertEqual(c['added'],old.returncode!=0)
                self.assertEqual(base64.b64decode(c['before'],validate=True),old.stdout if old.returncode==0 else b'')
                self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),c['after_sha256'])
                self.assertEqual((ROOT/name).read_bytes(),base64.b64decode(c['after'],validate=True))
    def test_new_private_module_reversal_and_unknown_change(self):
        name='_private/server/booking-finance.php';manifest='documents/unified/finance-source.json'
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory)
            for path in (name,manifest):
                target=root/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes((ROOT/path).read_bytes())
            self.assertEqual(before_unified_update(root,name),b'')
            with (root/name).open('ab') as f:f.write(b'// unknown financial change\n')
            with self.assertRaisesRegex(RuntimeError,'Reviewed unified source differs'):before_unified_update(root,name)
if __name__=='__main__':unittest.main()
