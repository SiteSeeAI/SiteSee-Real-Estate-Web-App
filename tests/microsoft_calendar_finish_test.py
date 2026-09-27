import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
from types import SimpleNamespace
from unittest.mock import patch

PROJECT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


runner = load('finish_ms_calendar', PROJECT / 'tools/finish-microsoft-calendar-connection.py')
baseline = load('ms_installer_fixtures', PROJECT / 'tests/microsoft_calendar_connection_install_test.py')


class FinishTests(unittest.TestCase):
    def setUp(self):
        self.fixture = baseline.ConnectionInstaller()
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.root, self.secret = self.fixture.root, self.fixture.credentials
        self.uid, self.gid = os.getuid(), os.getgid()
        self.m = runner.embedded_installer()
        self.files = self.m.payload()
        self.account = SimpleNamespace(pw_uid=self.uid, pw_gid=self.gid, pw_name='fixture')

    def scan(self):
        return runner.inventory(self.m, self.root, self.files, baseline.PHP, self.uid, credentials=self.secret)

    def snapshot(self):
        return {p: (p.stat().st_mode, p.stat().st_uid, p.stat().st_gid, p.read_bytes() if p.is_file() else None)
                for p in self.root.rglob('*')}

    def journal(self, state='complete'):
        j = {'schema': 1, 'mailbox': self.m.CONFIG['mailbox'], 'calendar_id': self.m.CONFIG['calendar_id'],
             'application_id': self.m.CONFIG['application_id'], 'transaction_id': 'a' * 32,
             'start': 1790000000, 'state': state, 'event_id': 'immutable-ID' if state != 'create_started' else '',
             'verified_at': 1790000001, 'completed_at': 1790000002}
        self.fixture.write('microsoft-calendar-probe.json', self.m.encode(j))
        return j

    def test_embedded_installer_matches_source_and_rebuild_is_reproducible(self):
        import base64, zlib
        self.assertEqual(zlib.decompress(base64.b64decode(runner.INSTALLER)),
                         (PROJECT / 'tools/install-microsoft-calendar-connection.py').read_bytes())
        before = (PROJECT / 'tools/finish-microsoft-calendar-connection.py').read_bytes()
        subprocess.run(['python3', str(PROJECT / 'tools/build-microsoft-calendar-connection.py')], check=True)
        self.assertEqual(before, (PROJECT / 'tools/finish-microsoft-calendar-connection.py').read_bytes())

    def test_all_reported_permission_problems_repair_together_then_install_and_rerun(self):
        # Reproduce both actual server reports, plus likely downstream mode problems.
        for folder in ('server', 'tools'):
            (self.root / folder).chmod(0o777)
        schedule = self.fixture.write('server/booking-schedule.php', (PROJECT / '_private/server/booking-schedule.php').read_bytes())
        manifest_path = self.root / 'calendar-confirmation-release.json'
        manifest = json.loads(manifest_path.read_bytes())
        manifest['files']['server/booking-schedule.php'] = self.m.digest(schedule.read_bytes())
        manifest_path.write_bytes(self.m.encode(manifest))
        for path in [schedule, self.root / 'server/booking-confirmation.php', manifest_path, self.root / 'booking-mail.json']:
            path.chmod(0o666)
        self.secret.chmod(0o644)
        before = self.snapshot()
        secret_before = self.secret.read_bytes()
        report = self.scan()
        self.assertEqual(report.errors, [])
        self.assertEqual(len(report.repairs), 7)
        audit = runner.apply_repairs(self.m, report, self.uid, self.gid)
        saved = json.loads(audit.read_bytes())
        self.assertEqual([r['state'] for r in saved['repairs']], ['applied'] * 7)
        self.assertNotIn('fingerprint', audit.read_text())
        self.assertNotIn('fake-fixture-only', audit.read_text())
        self.assertEqual(self.secret.read_bytes(), secret_before)
        self.assertEqual(schedule.stat().st_mode & 0o777, 0o644)
        self.assertEqual((self.root / 'server').stat().st_mode & 0o777, 0o755)
        for p, (_, uid, gid, content) in before.items():
            self.assertEqual((p.stat().st_uid, p.stat().st_gid), (uid, gid))
            if content is not None:
                self.assertEqual(p.read_bytes(), content)
        verified = self.scan()
        self.assertEqual(verified.errors, [])
        self.assertFalse(verified.repairs)
        desired = self.m.inspect(self.root, self.files, baseline.PHP, self.uid, self.secret)
        self.m.install(self.root, desired, self.uid, self.gid)
        self.assertFalse(self.scan().repairs)
        self.assertEqual(self.scan().errors, [])
        self.assertIsNone(self.m.install(self.root, desired, self.uid, self.gid))

    def test_hash_configuration_journal_capacity_and_runtime_failures_collected_once(self):
        (self.root / 'server').chmod(0o777)
        for name in ('booking-confirmation.php', 'booking-invitation.php'):
            with (self.root / 'server' / name).open('ab') as handle:
                handle.write(b'changed')
        mail = json.loads((self.root / 'booking-mail.json').read_bytes())
        mail['stage'] = 'live'
        self.fixture.write('booking-mail.json', self.m.encode(mail))
        secret = json.loads(self.secret.read_bytes())
        secret['client_id'] = 'wrong-account'
        secret['client_secret'] = 'NEVER-PRINT-THIS-SECRET'
        self.secret.write_bytes(self.m.encode(secret))
        self.fixture.write('microsoft-calendar.json', b'{}')
        self.fixture.write('microsoft-calendar-probe.json', b'{"state":"unknown"}')
        with patch.object(runner.shutil, 'disk_usage', return_value=SimpleNamespace(free=0)), \
             patch.object(self.m, 'runtime_check', side_effect=self.m.InstallError('PHP dependency unavailable')):
            report = self.scan()
        message = '\n'.join(report.errors)
        for value in ('booking-confirmation.php', 'booking-invitation.php', 'TEST mail', 'Microsoft application',
                      'connection file differs', 'recovery journal is invalid', 'free disk space', 'PHP dependency'):
            self.assertIn(value, message)
        self.assertNotIn('NEVER-PRINT-THIS-SECRET', message)
        with self.assertRaises(self.m.InstallError):
            runner.apply_repairs(self.m, report, self.uid, self.gid)
        self.assertEqual((self.root / 'server').stat().st_mode & 0o777, 0o777)

    def test_inspection_is_read_only_and_does_not_open_booking_database(self):
        (self.root / 'server').chmod(0o777)
        before = self.snapshot()
        original = runner.os.open
        def guarded(path, *args, **kwargs):
            self.assertNotIn('bookings.sqlite', str(path))
            return original(path, *args, **kwargs)
        with patch.object(runner.os, 'open', side_effect=guarded):
            report = self.scan()
        self.assertEqual(report.errors, [])
        self.assertEqual(before, self.snapshot())

    def test_unknown_connection_file_and_unsafe_links_are_reported_without_repair(self):
        self.fixture.write('server/booking-microsoft-calendar.php', b'unknown existing file')
        target = self.root / 'server/booking-confirmation.php'
        target.unlink()
        target.symlink_to(self.root / 'booking-mail.json')
        os.link(self.root / 'server/booking-invitation.php', self.root / 'server/hardlink.php')
        report = self.scan()
        message = '\n'.join(report.errors)
        self.assertIn('Existing connection file differs', message)
        self.assertIn('Symbolic link', message)
        self.assertIn('links', message)
        with self.assertRaises(self.m.InstallError):
            runner.apply_repairs(self.m, report, self.uid, self.gid)
        self.assertTrue(target.is_symlink())

    def test_changed_content_between_inventory_and_repair_is_preserved(self):
        target = self.root / 'server/booking-confirmation.php'
        target.chmod(0o666)
        report = self.scan()
        target.write_bytes(b'concurrent change')
        with self.assertRaisesRegex(self.m.InstallError, 'contents changed'):
            runner.apply_repairs(self.m, report, self.uid, self.gid)
        self.assertEqual(target.stat().st_mode & 0o777, 0o666)
        self.assertEqual(target.read_bytes(), b'concurrent change')

    def test_journal_is_durable_before_chmod_and_no_repair_if_journal_save_fails(self):
        folder = self.root / 'server'
        folder.chmod(0o777)
        report = self.scan()
        with patch.object(self.m, 'atomic_write', side_effect=OSError('disk failure')):
            with self.assertRaises(OSError):
                runner.apply_repairs(self.m, report, self.uid, self.gid)
        self.assertEqual(folder.stat().st_mode & 0o777, 0o777)

    def test_interrupted_repair_retains_completed_hardening_and_next_run_finishes(self):
        for name in ('server', 'tools'):
            (self.root / name).chmod(0o777)
        report = self.scan()
        original = runner.os.fchmod
        calls = [0]
        def fail_second(fd, mode):
            if mode == 0o755:
                calls[0] += 1
                if calls[0] == 2:
                    raise OSError('simulated permission repair failure')
            return original(fd, mode)
        with patch.object(runner.os, 'fchmod', side_effect=fail_second):
            with self.assertRaises(OSError):
                runner.apply_repairs(self.m, report, self.uid, self.gid)
        self.assertEqual((self.root / 'server').stat().st_mode & 0o777, 0o755)
        self.assertEqual((self.root / 'tools').stat().st_mode & 0o777, 0o777)
        next_report = self.scan()
        self.assertEqual(len(next_report.repairs), 1)
        runner.apply_repairs(self.m, next_report, self.uid, self.gid)
        self.assertFalse(self.scan().repairs)

    def test_transient_failure_gets_one_recovery_retry_with_same_transaction(self):
        j = self.journal('create_started')
        outputs = []
        def run(*args, **kwargs):
            if not outputs:
                outputs.append('first')
                return subprocess.CompletedProcess([],1,b'',b'STOP: Microsoft connection check could not finish; preserve its journal and report this output.')
            self.assertEqual(json.loads((self.root / 'microsoft-calendar-probe.json').read_bytes())['transaction_id'], j['transaction_id'])
            self.journal('complete')
            return subprocess.CompletedProcess([],0,b'Temporary private TEST event creation and readback: PASS\n',b'')
        with patch.object(runner.subprocess, 'run', side_effect=run) as provider:
            runner.finish_probe(self.m, self.root, baseline.PHP, self.account, emit=lambda _: None, pause=lambda _: None)
        self.assertEqual(provider.call_count, 2)

    def test_permission_and_identity_errors_do_not_retry_or_change_credentials(self):
        secret = self.secret.read_bytes()
        for diagnostic in ('Temporary TEST event creation failed (HTTP 403).',
                           'Temporary event identity or no-attendee safeguards differ; no deletion was attempted.'):
            with patch.object(runner.subprocess, 'run', return_value=subprocess.CompletedProcess([],1,b'',diagnostic.encode())) as provider:
                with self.assertRaises(self.m.InstallError):
                    runner.finish_probe(self.m, self.root, baseline.PHP, self.account, emit=lambda _: None)
            self.assertEqual(provider.call_count, 1)
            self.assertEqual(self.secret.read_bytes(), secret)
        self.assertTrue(runner.retryable_probe('Calendar identity read failed (HTTP 503).'))
        self.assertFalse(runner.retryable_probe('Default calendar identity or editable flag differs.'))

    def test_retry_is_bounded_and_completed_record_required_for_success(self):
        self.journal('create_started')
        failed = subprocess.CompletedProcess([],1,b'',b'Earlier creation is unresolved.')
        with patch.object(runner.subprocess, 'run', return_value=failed) as provider:
            with self.assertRaises(self.m.InstallError):
                runner.finish_probe(self.m, self.root, baseline.PHP, self.account, emit=lambda _: None, pause=lambda _: None)
        self.assertEqual(provider.call_count, 2)
        with patch.object(runner.subprocess, 'run', return_value=subprocess.CompletedProcess([],0,b'',b'')):
            with self.assertRaisesRegex(self.m.InstallError, 'without a completed recovery record'):
                runner.finish_probe(self.m, self.root, baseline.PHP, self.account, emit=lambda _: None)

    def test_completed_prior_probe_is_reused_and_unknown_state_stays_blocked(self):
        j = self.journal('complete')
        with patch.object(runner.subprocess, 'run', return_value=subprocess.CompletedProcess([],0,b'Prior test reused\n',b'')):
            runner.finish_probe(self.m, self.root, baseline.PHP, self.account, emit=lambda _: None)
        self.assertEqual(json.loads((self.root / 'microsoft-calendar-probe.json').read_bytes()), j)
        j['state'] = 'unrecognized'
        self.fixture.write('microsoft-calendar-probe.json', self.m.encode(j))
        with patch.object(runner.subprocess, 'run', return_value=subprocess.CompletedProcess([],1,b'',b'Microsoft connection check could not finish')) as provider:
            with self.assertRaisesRegex(self.m.InstallError, 'no retry was attempted'):
                runner.finish_probe(self.m, self.root, baseline.PHP, self.account, emit=lambda _: None)
        self.assertEqual(provider.call_count, 1)


if __name__ == '__main__':
    unittest.main()
