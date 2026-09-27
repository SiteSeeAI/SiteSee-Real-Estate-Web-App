import importlib.util
import json
import os
import pwd
from pathlib import Path
import shutil
import subprocess
import tempfile
from types import SimpleNamespace
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

    def application_fixture(self):
        if os.geteuid() != 0:
            self.skipTest('Real root/application-user ownership rehearsal requires root.')
        account = pwd.getpwnam('nobody')
        Path(self.tmp.name).chmod(0o755)
        for path in [self.root, self.credentials] + list(self.root.rglob('*')):
            try:
                os.chown(path, account.pw_uid, account.pw_gid)
            except OSError as error:
                if error.errno in (1, 22):
                    self.skipTest('This execution namespace cannot use a second UID; account policies have separate simulated tests.')
                raise
        for name in ('server', 'tools'):
            path = self.root / name
            os.chown(path, 0, 0)
            path.chmod(0o755)
        for path in (self.root / 'server').iterdir():
            os.chown(path, 0, 0)
            path.chmod(0o644)
        return account

    def inspect_as(self, account):
        return m.inspect(self.root, self.files, PHP, account.pw_uid, self.credentials, account)

    def test_root_owned_directories_and_code_work_as_actual_application_user(self):
        account = self.application_fixture()
        before = {p: (p.stat().st_uid, p.stat().st_gid, p.stat().st_mode, p.read_bytes() if p.is_file() else None)
                  for p in [self.root] + list(self.root.rglob('*'))}
        desired = self.inspect_as(account)
        m.install(self.root, desired, account.pw_uid, account.pw_gid)
        self.assertEqual(self.inspect_as(account), desired)
        self.assertIsNone(m.install(self.root, desired, account.pw_uid, account.pw_gid))
        for p, expected in before.items():
            self.assertEqual((p.stat().st_uid, p.stat().st_gid, p.stat().st_mode,
                              p.read_bytes() if p.is_file() else None), expected)

    def test_root_owned_inaccessible_directory_is_not_accepted_or_changed(self):
        account = self.application_fixture()
        folder = self.root / 'server'
        folder.chmod(0o700)
        with self.assertRaisesRegex(m.InstallError, 'Directory is not readable/traversable: .*server'):
            self.inspect_as(account)
        self.assertEqual(folder.stat().st_mode & 0o777, 0o700)
        self.assertFalse((self.root / 'microsoft-calendar.json').exists())

    def test_root_owned_unreadable_runtime_code_is_not_accepted(self):
        account = self.application_fixture()
        path = self.root / 'server/booking-mail-client.php'
        path.chmod(0o600)
        with self.assertRaisesRegex(m.InstallError, 'File is not readable: .*booking-mail-client.php'):
            self.inspect_as(account)
        self.assertEqual(path.stat().st_uid, 0)

    def test_secret_owner_rule_remains_strict(self):
        account = self.application_fixture()
        os.chown(self.credentials, 0, 0)
        with self.assertRaisesRegex(m.InstallError, 'SiteSee-owned private'):
            self.inspect_as(account)

    def test_writable_directories_report_all_modes_without_changing_them(self):
        (self.root / 'server').chmod(0o777)
        (self.root / 'tools').chmod(0o775)
        with self.assertRaises(m.InstallError) as caught:
            self.inspect()
        message = str(caught.exception)
        self.assertIn('server: group/world writable', message)
        self.assertIn('tools: group/world writable', message)
        self.assertIn('mode=0777', message)
        self.assertIn('mode=0775', message)
        self.assertFalse((self.root / 'microsoft-calendar.json').exists())

    def test_unrelated_directory_owner_is_rejected(self):
        folder = self.root / 'server'
        original = folder.stat()
        fake = SimpleNamespace(st_uid=65533, st_gid=65533, st_mode=original.st_mode,
                               st_nlink=original.st_nlink, st_size=original.st_size)
        with patch.object(Path, 'lstat', return_value=fake):
            result = m.directory_check(folder, 1001)
        self.assertIn('owner must be root or SiteSee; uid=65533', result[0])

    def test_existing_r1_release_manifest_is_preserved_on_r2_rerun(self):
        desired = self.inspect()
        expected = {'release': m.RELEASE, 'revision': '20260927-r1',
                    'files': {name: m.digest(data) for name, data in self.files.items()}}
        self.assertEqual(json.loads(desired['microsoft-calendar-connection-release.json']), expected)
        m.install(self.root, desired, self.uid, self.gid)
        journal = self.write('microsoft-calendar-probe.json', b'{"state":"create_started"}')
        self.assertEqual(self.inspect(), desired)
        self.assertIsNone(m.install(self.root, desired, self.uid, self.gid))
        self.assertEqual(journal.read_bytes(), b'{"state":"create_started"}')

    def test_root_owner_policy_accepts_code_only_and_preserves_secret_ownership(self):
        original = (self.root / 'server').stat()
        fake = SimpleNamespace(st_uid=0, st_gid=0, st_mode=0o40755,
                               st_nlink=original.st_nlink, st_size=original.st_size)
        with patch.object(Path, 'lstat', return_value=fake):
            self.assertEqual(m.directory_check(self.root / 'server', 1001), [])
            self.assertTrue(m.directory_check(self.root, 1001, root=True))
        path = self.write('server/owner-fixture.php', b'nonsecret code')
        original = path.stat()
        fake = SimpleNamespace(st_uid=0, st_gid=0, st_mode=0o100644, st_nlink=1, st_size=original.st_size)
        with patch.object(m.os, 'fstat', return_value=fake):
            self.assertEqual(m.private_bytes(path, 1001, private=False), b'nonsecret code')
            with self.assertRaises(m.InstallError):
                m.private_bytes(path, 1001, private=True)

    def test_application_identity_handoff_uses_initgroups_then_gid_then_uid(self):
        account = SimpleNamespace(pw_name='sitesee-fixture', pw_uid=1001, pw_gid=1002)
        calls = []
        with patch.object(m.os, 'initgroups', side_effect=lambda *v: calls.append(('groups',)+v)), \
             patch.object(m.os, 'setgid', side_effect=lambda *v: calls.append(('gid',)+v)), \
             patch.object(m.os, 'setuid', side_effect=lambda *v: calls.append(('uid',)+v)), \
             patch.object(m.os, 'umask', side_effect=lambda *v: calls.append(('mask',)+v)):
            m.account_switch(account)()
        self.assertEqual(calls, [('groups','sitesee-fixture',1002), ('gid',1002), ('uid',1001), ('mask',0o077)])

    def test_application_access_denials_are_collected_before_install(self):
        account = SimpleNamespace(pw_name='sitesee-fixture', pw_uid=1001, pw_gid=1002)
        failures = ['Directory is not readable/traversable: ' + str(self.root / 'server'),
                    'File is not readable: ' + str(self.root / 'server/booking-mail-client.php')]
        result = subprocess.CompletedProcess([],1,stdout=json.dumps(failures).encode(),stderr=b'')
        with patch.object(m.subprocess, 'run', return_value=result) as run:
            with self.assertRaises(m.InstallError) as caught:
                m.runtime_check(PHP, self.root, [self.root / 'server'], [self.root / 'server/booking-mail-client.php'], 1001, account)
            self.assertIsNotNone(run.call_args.kwargs['preexec_fn'])
            self.assertIn('Directory is not readable/traversable', str(caught.exception))
            self.assertIn('File is not readable', str(caught.exception))
            self.assertNotIn('fake-fixture-only', run.call_args.kwargs['input'].decode())
        self.assertFalse((self.root / 'microsoft-calendar.json').exists())


if __name__ == '__main__':
    unittest.main()
