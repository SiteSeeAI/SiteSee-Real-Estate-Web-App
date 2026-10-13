"""The focused-action correction preserves the installed source and refuses unknown edits."""
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


class ReadinessSource(unittest.TestCase):
    def test_exact_installed_before_and_candidate_after(self):
        manifest = json.loads((ROOT / 'documents/unified/readiness-source.json').read_text())
        self.assertEqual(manifest['baseline_commit'], '8c3ae16c726d0a308b4595e827d6ba3ea80ddd0b')
        self.assertEqual(set(manifest['files']), {'public/portal-assets/application.js', '_private/views/application-shell.php'})
        for name, change in manifest['files'].items():
            with self.subTest(name=name):
                before = subprocess.check_output(['git', 'show', manifest['baseline_commit'] + ':' + name], cwd=ROOT)
                self.assertFalse(change['added'])
                self.assertEqual(base64.b64decode(change['before'], validate=True), before)
                self.assertEqual(hashlib.sha256(before).hexdigest(), change['before_sha256'])
                after = (ROOT / name).read_bytes()
                self.assertEqual(base64.b64decode(change['after'], validate=True), after)
                self.assertEqual(hashlib.sha256(after).hexdigest(), change['after_sha256'])

    def test_historical_reversal_rejects_unknown_edit(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            for path in (ROOT / 'documents/unified').glob('*-source.json'):
                destination = root / 'documents/unified' / path.name
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(path.read_bytes())
            for name in ['public/portal-assets/application.js', '_private/views/application-shell.php']:
                target = root / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes((ROOT / name).read_bytes())
                original = subprocess.run(['git', 'show', '631aee7c49924bc123ba335fb24eb918f7e2d314:' + name], cwd=ROOT, capture_output=True)
                self.assertEqual(before_unified_update(root, name), original.stdout if original.returncode == 0 else b'')
                with target.open('ab') as handle:
                    handle.write(b'\nunknown readiness edit\n')
                with self.assertRaisesRegex(RuntimeError, 'Reviewed unified source differs'):
                    before_unified_update(root, name)


if __name__ == '__main__':
    unittest.main()
