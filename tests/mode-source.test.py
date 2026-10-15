"""Exact reviewed LIVE layer, reversible to accepted TEST source; unknown edits fail."""
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from unified_source import before_mode_update


class ModeSource(unittest.TestCase):
    def test_exact_accepted_test_source_and_complete_current_diff(self):
        manifest=json.loads((ROOT/'documents/unified/mode-source.json').read_text())
        self.assertEqual(manifest['baseline_commit'],'8f025e975d11e43e17230e14ab433118ba98f01e')
        changed=set(subprocess.check_output(['git','diff','--name-only',manifest['baseline_commit'],'--','_private','public'],cwd=ROOT,text=True).splitlines())
        changed|=set(subprocess.check_output(['git','ls-files','--others','--exclude-standard','--','_private','public'],cwd=ROOT,text=True).splitlines())
        self.assertEqual(set(manifest['files']),changed)
        for name,c in manifest['files'].items():
            old=subprocess.run(['git','show',manifest['baseline_commit']+':'+name],cwd=ROOT,capture_output=True)
            self.assertEqual(c['added'],old.returncode!=0)
            self.assertEqual(before_mode_update(ROOT,name),old.stdout if old.returncode==0 else b'')
            self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),c['after_sha256'])
            self.assertEqual(base64.b64decode(c['after'],validate=True),(ROOT/name).read_bytes())

    def test_unknown_mode_edit_and_manifest_tamper_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);name='_private/server/application-mode.php'
            document=root/'documents/unified/mode-source.json';document.parent.mkdir(parents=True)
            document.write_bytes((ROOT/'documents/unified/mode-source.json').read_bytes())
            file=root/name;file.parent.mkdir(parents=True);file.write_bytes((ROOT/name).read_bytes()+b'// unknown\n')
            with self.assertRaisesRegex(RuntimeError,'Reviewed mode source differs'):before_mode_update(root,name)
            file.write_bytes((ROOT/name).read_bytes());manifest=json.loads(document.read_text());manifest['files'][name]['before_sha256']='0'*64
            document.write_text(json.dumps(manifest))
            with self.assertRaisesRegex(RuntimeError,'Reviewed mode source differs'):before_mode_update(root,name)


if __name__=='__main__':unittest.main()
