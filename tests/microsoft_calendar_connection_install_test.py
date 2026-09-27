import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

PROJECT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('ms_install', PROJECT / 'tools/install-microsoft-calendar-connection.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
PHP = os.environ.get('SITESEE_TEST_PHP') or shutil.which('php') or '/workspace/scratch/0620b17ff5df/php-bin/php'


class ConnectionInstaller(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'private'
        self.root.mkdir(mode=0o700)
        self.uid, self.gid = os.getuid(), os.getgid()
        self.files = m.payload()
        self.credentials = Path(self.tmp.name) / 'existing-credentials.json'
        self.credentials.write_bytes(m.encode({'tenant_id': '11111111-1111-1111-1111-111111111111',
            'client_id': m.CONFIG['application_id'], 'client_secret': 'fake-fixture-only'}))
        self.credentials.chmod(0o600)
        self.write('booking-mail.json', m.encode({'stage': 'test', 'sender': m.CONFIG['mailbox'],
            'graph_credentials': m.CONFIG['graph_credentials'], 'test_recipient_email': 'cro@sitesee.ai'}))
        for manifest, release, names in [
            ('calendar-confirmation-release.json', 'sitesee-calendar-confirmation-test-v1',
             ['booking-store.php','booking-confirmation.php','booking-invitation.php','booking-mail-client.php','booking-calendar-client.php']),
            ('branded-checkout-release.json', 'sitesee-branded-checkout-test-v1', ['booking-pay.php','booking-checkout.php'])]:
            hashes = {}
            for name in names:
                data = (PROJECT / '_private/server' / name).read_bytes()
                self.write('server/' + name, data)
                hashes['server/' + name] = m.digest(data)
            self.write(manifest, m.encode({'release': release, 'files': hashes}))
        (self.root / 'tools').mkdir(mode=0o700)
        for name in ['data/bookings.sqlite','zoho-calendar.json','zoho-confirmation.json','zoho-crm.json','booking-checkout.json']:
            self.write(name, b'preserve-existing-' + name.encode())

    def write(self, name, data):
        path = self.root / name
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(0o600)
        return path

    def inspect(self):
        return m.inspect(self.root, self.files, PHP, self.uid, self.credentials)

    def test_payload_matches_committed_source_and_rebuild_is_reproducible(self):
        for name, data in self.files.items():
            path = PROJECT / ('_private/' + name if name.startswith('server/') else name)
            self.assertEqual(data, path.read_bytes())
        before = (PROJECT / 'tools/install-microsoft-calendar-connection.py').read_bytes()
        subprocess.run(['python3', str(PROJECT / 'tools/build-microsoft-calendar-connection.py')], check=True)
        self.assertEqual(before, (PROJECT / 'tools/install-microsoft-calendar-connection.py').read_bytes())

    def test_additive_install_rerun_preserves_every_existing_file(self):
        original = {p: p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        desired = self.inspect()
        backup = m.install(self.root, desired, self.uid, self.gid)
        self.assertTrue(backup.is_dir())
        self.assertEqual(self.inspect(), desired)
        self.assertIsNone(m.install(self.root, desired, self.uid, self.gid))
        for name, data in desired.items():
            path = self.root / name
            self.assertEqual(path.read_bytes(), data)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        for p, data in original.items():
            self.assertEqual(p.read_bytes(), data)

    def test_each_completed_write_is_removed_on_local_failure(self):
        desired = self.inspect()
        write = m.atomic_write
        for fail_at in range(1, len(desired) + 1):
            counter = [0]
            def faulty(path, data, uid, gid):
                if path.parent == self.root or path.parent.name in ('server', 'tools'):
                    counter[0] += 1
                    # Failure after rename covers an interrupted commit, too.
                    write(path, data, uid, gid)
                    if counter[0] == fail_at:
                        raise OSError('simulated disk failure')
                else:
                    write(path, data, uid, gid)
            with patch.object(m, 'atomic_write', faulty):
                with self.assertRaises(OSError):
                    m.install(self.root, desired, self.uid, self.gid)
            for name in desired:
                self.assertFalse((self.root / name).exists(), name)
        m.install(self.root, desired, self.uid, self.gid)

    def test_changed_existing_release_is_not_overwritten(self):
        self.write('server/booking-confirmation.php', b'changed')
        with self.assertRaises(m.InstallError):
            self.inspect()
        self.assertFalse((self.root / m.NAMES[0]).exists())

    def test_existing_nonsecret_code_allows_normal_cpanel_upload_permissions(self):
        (self.root / 'server/booking-confirmation.php').chmod(0o644)
        (self.root / 'calendar-confirmation-release.json').chmod(0o644)
        self.inspect()
        (self.root / 'server/booking-confirmation.php').chmod(0o666)
        with self.assertRaises(m.InstallError):
            self.inspect()

    def test_wrong_identity_live_gate_and_wrong_mailbox_stop_before_install(self):
        original = self.credentials.read_bytes()
        secret = json.loads(original)
        secret['client_id'] = '22222222-2222-2222-2222-222222222222'
        self.credentials.write_bytes(m.encode(secret))
        with self.assertRaises(m.InstallError):
            self.inspect()
        self.credentials.write_bytes(original)
        path = self.root / 'booking-mail.json'
        original_mail = json.loads(path.read_bytes())
        for key, value in [('stage','live'), ('sender','cro@sitesee.ai')]:
            self.write('booking-mail.json', m.encode(dict(original_mail, **{key: value})))
            with self.assertRaises(m.InstallError):
                self.inspect()

    def test_existing_unknown_config_and_modified_helper_are_preserved(self):
        for name in ['microsoft-calendar.json', m.NAMES[0]]:
            path = self.write(name, b'unknown-existing-content')
            with self.assertRaises(m.InstallError):
                self.inspect()
            self.assertEqual(path.read_bytes(), b'unknown-existing-content')
            path.unlink()

    def test_unsafe_permissions_symlink_and_hardlink_are_rejected(self):
        path = self.root / 'booking-mail.json'
        path.chmod(0o644)
        with self.assertRaises(m.InstallError):
            self.inspect()
        path.chmod(0o600)
        new = self.root / m.NAMES[0]
        new.symlink_to(path)
        with self.assertRaises(m.InstallError):
            self.inspect()
        new.unlink()
        os.link(str(path), str(new))
        with self.assertRaises(m.InstallError):
            self.inspect()

    def test_missing_php_capability_prevents_install(self):
        with patch.object(m.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1)):
            with self.assertRaises(m.InstallError):
                self.inspect()

    def test_probe_failure_retains_installed_connection_and_recovery_state(self):
        desired = self.inspect()
        m.install(self.root, desired, self.uid, self.gid)
        journal = self.write('microsoft-calendar-probe.json', b'{"state":"create_started"}')
        with patch.object(m.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1)):
            with self.assertRaises(m.InstallError):
                m.run_probe(self.root, PHP, None)
        self.assertTrue(journal.exists())
        for name in desired:
            self.assertTrue((self.root / name).is_file())

    def test_probe_cli_obeys_shared_directory_lock(self):
        import fcntl
        m.install(self.root, self.inspect(), self.uid, self.gid)
        fd = os.open(str(self.root), os.O_RDONLY | os.O_DIRECTORY)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = subprocess.run([PHP, str(self.root / m.NAMES[1]), '--test'], capture_output=True)
            self.assertEqual(result.returncode, 1)
            self.assertIn(b'Another deployment', result.stderr)
            self.assertFalse((self.root / 'microsoft-calendar-probe.json').exists())
        finally:
            os.close(fd)


if __name__ == '__main__':
    unittest.main()
