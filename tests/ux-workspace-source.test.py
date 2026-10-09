"""UX layers preserve the exact installed source and reject unknown edits."""
import base64
import hashlib
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
from unified_source import before_unified_update


class WorkspaceSource(unittest.TestCase):
    def test_exact_installed_before_and_reviewed_after(self):
        manifest = json.loads((ROOT / 'documents/unified/ux-workspace-source.json').read_text())
        for name, change in manifest['files'].items():
            with self.subTest(name=name):
                original = subprocess.run(['git', 'show', manifest['baseline_commit'] + ':' + name], cwd=ROOT, capture_output=True)
                self.assertEqual(change['added'], original.returncode != 0)
                self.assertEqual(base64.b64decode(change['before'], validate=True), original.stdout if original.returncode == 0 else b'')
                actual=(ROOT / name).read_bytes()
                finance=json.loads((ROOT / 'documents/unified/finance-source.json').read_text())['files'].get(name)
                if finance:
                    self.assertEqual(hashlib.sha256(actual).hexdigest(),finance['after_sha256'])
                    actual=base64.b64decode(finance['before'],validate=True)
                self.assertEqual(hashlib.sha256(actual).hexdigest(), change['after_sha256'])

    def test_new_asset_reversal_and_unknown_edit_rejection(self):
        name = 'public/portal-assets/application.js'
        manifest_path = 'documents/unified/ux-workspace-source.json'
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            for path in [name, manifest_path]:
                destination = root / path
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes((ROOT / path).read_bytes())
            self.assertEqual(before_unified_update(root, name), b'')
            with (root / name).open('ab') as output:
                output.write(b'// Unknown edit\n')
            with self.assertRaisesRegex(RuntimeError, 'Reviewed unified source differs'):
                before_unified_update(root, name)


if __name__ == '__main__':
    unittest.main()
