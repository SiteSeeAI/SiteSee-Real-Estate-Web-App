#!/usr/bin/env python3
"""Rehearse the real fixed-commit release on isolated files and a synthetic WAL ledger."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import pwd
import shutil
import sqlite3
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('unified_installer', ROOT / 'tools/install-unified.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
BASELINE = 'ff5e625a8ff4c51af336e9203fa4be29b3611570'


class UnifiedRelease(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workspace = tempfile.TemporaryDirectory(prefix='sitesee-unified-rehearsal-')
        cls.base = pathlib.Path(cls.workspace.name)
        cls.uid = os.geteuid()
        cls.gid = os.getegid()
        cls.account = pwd.getpwuid(cls.uid).pw_name
        cls.commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
        committed = subprocess.check_output(['git', 'show', cls.commit + ':tools/install-unified.py'], cwd=ROOT)
        if committed != pathlib.Path(installer.__file__).read_bytes():
            raise RuntimeError('Commit the installer before testing its fixed-commit artifact.')
        cls.packages = []
        for name in ('build-one', 'build-two'):
            result = subprocess.run(['python3', str(ROOT / 'tools/build-unified-release.py'), '--commit', cls.commit,
                                     '--output', str(cls.base / name)], cwd=ROOT, capture_output=True, text=True, check=True)
            cls.packages.append(json.loads(result.stdout))
        cls.package = pathlib.Path(cls.packages[0]['package'])
        cls.package_sha = cls.packages[0]['sha256']
        cls.obj, cls.files, cls.manifest_sha = installer.load(cls.package, cls.package_sha)
        cls.original = {}
        for name in cls.files:
            source = '_private/' + name[8:] if name.startswith('private/') else name
            p = subprocess.run(['git', 'show', BASELINE + ':' + source], cwd=ROOT, capture_output=True)
            cls.original[name] = p.stdout if p.returncode == 0 else None
        cls.template = cls.base / 'template'
        cls.template.mkdir(mode=0o700)
        private = cls.template / 'private'; private.mkdir(mode=0o700)
        public = cls.template / 'public'; public.mkdir(mode=0o755)
        for name, data in cls.original.items():
            path = installer.target(name, private, public)
            path.parent.mkdir(parents=True, exist_ok=True, mode=0o700 if name.startswith('private/') else 0o755)
            if data is not None:
                path.write_bytes(data); path.chmod(0o600 if name.startswith('private/') else 0o644)
        (private / 'data').mkdir(mode=0o700)
        db = private / 'data/bookings.sqlite'
        with contextlib.closing(sqlite3.connect(db)) as connection:
            connection.executescript('''
                CREATE TABLE bookings(reference TEXT PRIMARY KEY, status TEXT, deposit_cents INTEGER, provider_id TEXT);
                CREATE TABLE vendor_grants(vendor TEXT, reference TEXT);
                CREATE TABLE final_bills(reference TEXT PRIMARY KEY, amount_cents INTEGER, commission_cents INTEGER);
                CREATE TABLE provider_operations(id TEXT PRIMARY KEY, state TEXT);
                INSERT INTO bookings VALUES('5D99D336572661A00885','deposit_paid_test',12500,'pi_test_existing_a');
                INSERT INTO bookings VALUES('8D20B4EBFCD0BADC4DE5','complete',17500,'pi_test_existing_b');
                INSERT INTO bookings VALUES('D32FFC7458','deposit_paid_test',15000,'legacy_event_existing');
                INSERT INTO vendor_grants VALUES('synthetic-vendor','5D99D336572661A00885');
                INSERT INTO final_bills VALUES('8D20B4EBFCD0BADC4DE5',35000,1800);
                INSERT INTO provider_operations VALUES('existing-attempt','uncertain');
            ''')
            connection.commit()
        db.chmod(0o600)
        settings = {'booking-checkout.json': {'stage': 'TEST', 'enabled': True, 'publishable_key': 'pk_test_synthetic1234567890123456'},
                    'portal-test.json': {'release': 'portal-20260929-r2', 'stage': 'TEST', 'enabled': True},
                    'portal-sms.json': {'stage': 'TEST', 'approved_test_numbers': ['synthetic-approved-phone']}}
        for name, value in settings.items():
            (private / name).write_bytes(installer.encode(value)); (private / name).chmod(0o600)
        for name in cls.obj['active_manifests']:
            value = {'revision': 'synthetic-existing-history', 'files': {
                'server/booking-staff.php': installer.sha(cls.original['private/server/booking-staff.php']),
                'server/booking-store.php': installer.sha(cls.original['private/server/booking-store.php'])}}
            (private / name).write_bytes(installer.encode(value)); (private / name).chmod(0o600)
        for name in ('portal-sessions', 'vendor-sessions'):
            folder = private / 'data' / name; folder.mkdir(mode=0o700)
            (folder / 'existing-session').write_bytes(b'synthetic-session-preserved'); (folder / 'existing-session').chmod(0o600)
        historical = private / 'deployment-backups'; historical.mkdir(mode=0o700)
        (historical / 'prior-backup').write_bytes(b'original-backup-history'); (historical / 'prior-backup').chmod(0o600)

    @classmethod
    def tearDownClass(cls):
        cls.workspace.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='case-', dir=self.base)
        self.folder = pathlib.Path(self.temp.name)
        shutil.copytree(self.template, self.folder / 'host')
        self.private = self.folder / 'host/private'
        self.public = self.folder / 'host/public'
        self.db = self.private / 'data/bookings.sqlite'
        self.journal_path = self.private / ('unified-' + self.commit[:12] + '-install.json')
        self.owner = {'uid': self.uid, 'gid': self.gid, 'mode': 0o600}

    def tearDown(self):
        self.temp.cleanup()

    def plan(self):
        installer.check_dirs(self.private, self.public, self.db, self.uid, self.files)
        installer.test_settings(self.private, self.uid, self.journal_path.name)
        return installer.inspect(self.obj, self.files, self.private, self.public, self.uid, self.manifest_sha)

    def prepared(self):
        before, after, metas = self.plan()
        journal = installer.prepare(self.obj, self.package_sha, self.private, self.public, self.db, self.uid, before, after, metas)
        installer.atomic(self.journal_path, installer.encode(journal), self.owner)
        return journal, before, after, metas

    def recovered(self, journal):
        return installer.recover_plan(self.obj, self.files, journal, self.package_sha, self.private, self.public, self.db, self.uid, self.manifest_sha)

    def cli(self, action, success=True):
        command = ['python3', str(ROOT / 'tools/install-unified.py'), '--package', str(self.package), '--sha256', self.package_sha,
                   '--private-root', str(self.private), '--public-root', str(self.public), '--account', self.account,
                   '--php', shutil.which('php'), action]
        result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=90)
        self.assertEqual(result.returncode, 0 if success else 1, result.stdout + result.stderr)
        return result.stdout

    def test_fixed_commit_artifact_is_deterministic(self):
        self.assertEqual(self.packages[0]['sha256'], self.packages[1]['sha256'])
        self.assertEqual(self.package.read_bytes(), pathlib.Path(self.packages[1]['package']).read_bytes())
        self.assertGreater(self.packages[0]['source_test_files'], self.packages[0]['deployment_files'])
        self.assertEqual(self.obj['commit'], self.commit)

    def test_package_sha_tampering_rejected(self):
        path = self.folder / 'tampered.tar.gz'; path.write_bytes(self.package.read_bytes() + b'changed')
        with self.assertRaises(installer.Stop): installer.load(path, self.package_sha)

    def test_artifact_contains_no_runtime_database_or_credentials(self):
        self.assertTrue(all(n.startswith(('private/', 'public/')) for n in self.files))
        self.assertFalse(any('/data/' in n or n.endswith(('.sqlite', '.json')) for n in self.files))
        self.assertNotIn('private/portal-sms.json', self.files)

    def test_unknown_source_and_missing_baseline_refused(self):
        path = self.private / 'server/booking-store.php'; path.write_bytes(path.read_bytes() + b'\n// unknown change\n')
        with self.assertRaisesRegex(installer.Stop, 'Unknown deployed edit'): self.plan()
        path.unlink()
        with self.assertRaisesRegex(installer.Stop, 'Required file missing'): self.plan()

    def test_new_file_unknown_edit_refused(self):
        path = self.private / 'server/application.php'; path.write_bytes(b'unknown'); path.chmod(0o600)
        with self.assertRaisesRegex(installer.Stop, 'Unknown deployed edit'): self.plan()

    def test_links_refused_without_touching_target(self):
        path = self.private / 'server/booking-store.php'; data = path.read_bytes(); path.unlink()
        other = self.folder / 'outside'; other.write_bytes(data); other.chmod(0o600); path.symlink_to(other)
        with self.assertRaises(installer.Stop): self.plan()
        self.assertEqual(other.read_bytes(), data)

    def test_hardlinks_refused(self):
        path = self.private / 'server/booking-store.php'; os.link(path, self.folder / 'second-link')
        with self.assertRaises(installer.Stop): self.plan()

    def test_private_and_public_overlap_refused(self):
        with self.assertRaises(installer.Stop): installer.check_dirs(self.private, self.private, self.db, self.uid, self.files)

    def test_public_database_refused(self):
        with self.assertRaises(installer.Stop): installer.check_dirs(self.private, self.public, self.public / 'bookings.sqlite', self.uid, self.files)

    def test_private_directory_and_file_modes_refused(self):
        self.private.chmod(0o755)
        with self.assertRaises(installer.Stop): self.plan()
        self.private.chmod(0o700)
        (self.private / 'server/booking-store.php').chmod(0o666)
        with self.assertRaises(installer.Stop): self.plan()

    def test_foreign_ownership_refused(self):
        path = self.private / 'server/booking-store.php'
        foreign = 65534 if self.uid == 0 else 0
        try: os.chown(path, foreign, foreign)
        except OSError as error:
            if os.getenv('SITESEE_REQUIRE_PRIVILEGED_TESTS') == '1': self.fail('Required ownership capability unavailable: ' + str(error))
            self.skipTest('Filesystem lacks real foreign ownership capability.')
        with self.assertRaises(installer.Stop): self.plan()

    def test_live_flags_and_keys_refused(self):
        path = self.private / 'booking-checkout.json'
        for stage, key in [('LIVE', 'pk_test_synthetic1234567890123456'), ('TEST', 'pk_live_synthetic1234567890123456')]:
            path.write_bytes(installer.encode({'stage': stage, 'enabled': True, 'publishable_key': key}))
            with self.assertRaises(installer.Stop): self.plan()

    def test_live_environment_refused(self):
        with mock.patch.dict(os.environ, {'SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET': 'sk_live_synthetic_rejected'}):
            with self.assertRaises(installer.Stop): self.plan()

    def test_prior_interrupted_update_refused(self):
        path = self.private / 'vendor-accounts-20261005-r1-install.json'
        path.write_bytes(installer.encode({'state': 'prepared'})); path.chmod(0o600)
        with self.assertRaises(installer.Stop): self.plan()

    def test_manifest_hash_mismatch_refused(self):
        path = self.private / self.obj['active_manifests'][0]
        record = json.loads(path.read_bytes()); record['files']['server/booking-staff.php'] = '0' * 64
        path.write_bytes(installer.encode(record))
        with self.assertRaises(installer.Stop): self.plan()

    def test_insufficient_disk_capacity_refused(self):
        before, after, metas = self.plan()
        with mock.patch.object(installer.shutil, 'disk_usage', return_value=shutil._ntuple_diskusage(10, 9, 1)):
            with self.assertRaises(installer.Stop): installer.space(self.private, self.public, self.db, before, after)

    def test_cli_preflight_is_read_only(self):
        names_before = sorted(p.relative_to(self.private) for p in self.private.rglob('*'))
        self.assertIn('PREFLIGHT PASS', self.cli('--preflight'))
        self.assertEqual(names_before, sorted(p.relative_to(self.private) for p in self.private.rglob('*')))
        self.assertFalse(self.journal_path.exists())
        self.assertFalse((self.private / 'unified-deployments').exists())

    def test_cli_install_resume_and_verify(self):
        original_db = self.db.read_bytes()
        self.assertIn('INSTALLED AND VERIFIED', self.cli('--install'))
        journal = json.loads(self.journal_path.read_bytes()); self.assertEqual(journal['state'], 'installed')
        self.assertIn('INSTALLED AND VERIFIED', self.cli('--resume'))
        self.assertIn('VERIFY PASS', self.cli('--verify'))
        self.assertEqual(self.db.read_bytes(), original_db)
        for name, value in self.files.items(): self.assertEqual(installer.target(name, self.private, self.public).read_bytes(), value)

    def test_configuration_sessions_and_old_backups_preserved(self):
        protected = [self.private / n for n in ['booking-checkout.json', 'portal-test.json', 'portal-sms.json', 'deployment-backups/prior-backup',
                     'data/portal-sessions/existing-session', 'data/vendor-sessions/existing-session']]
        before = {str(p): (p.read_bytes(), installer.metadata(p)) for p in protected}
        journal, original, after, metas = self.prepared()
        installer.apply(self.private, self.public, self.uid, original, after, metas)
        for p in protected: self.assertEqual((p.read_bytes(), installer.metadata(p)), before[str(p)])
        with contextlib.closing(installer.readonly_db(self.db)) as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM bookings').fetchone()[0], 3)
            self.assertEqual(db.execute('SELECT state FROM provider_operations').fetchone()[0], 'uncertain')
            self.assertEqual(db.execute('SELECT commission_cents FROM final_bills').fetchone()[0], 1800)

    def test_committed_wal_rows_included_in_snapshot_and_restore(self):
        connection = sqlite3.connect(self.db)
        try:
            connection.execute('PRAGMA journal_mode=WAL'); connection.execute('PRAGMA wal_autocheckpoint=0')
            connection.execute("INSERT INTO provider_operations VALUES('committed-wal-operation','prepared')"); connection.commit()
            self.assertTrue(pathlib.Path(str(self.db) + '-wal').exists())
            with contextlib.closing(sqlite3.connect('file:' + str(self.db) + '?immutable=1', uri=True)) as main_only:
                self.assertEqual(main_only.execute("SELECT COUNT(*) FROM provider_operations WHERE id='committed-wal-operation'").fetchone()[0], 0)
            journal, before, after, metas = self.prepared()
            destination = self.folder / 'isolated-restored.sqlite'
            installer.restore_rehearsal(self.private, self.public, self.db, journal, destination, self.uid)
            with contextlib.closing(sqlite3.connect(destination)) as restored:
                self.assertEqual(restored.execute("SELECT state FROM provider_operations WHERE id='committed-wal-operation'").fetchone()[0], 'prepared')
                self.assertEqual(installer.db_fingerprint(restored), journal['database'])
            self.assertEqual(connection.execute('SELECT COUNT(*) FROM bookings').fetchone()[0], 3)
        finally: connection.close()

    def test_database_overwrite_rehearsal_forbidden(self):
        journal, before, after, metas = self.prepared()
        for destination in [self.db, self.private / 'data/other.sqlite', self.public / 'other.sqlite']:
            with self.assertRaises(installer.Stop): installer.restore_rehearsal(self.private, self.public, self.db, journal, destination, self.uid)

    def test_snapshot_tampering_refused(self):
        journal, before, after, metas = self.prepared()
        path = self.private / 'unified-deployments' / journal['backup'] / 'bookings.sqlite'; path.write_bytes(path.read_bytes() + b'changed')
        with self.assertRaises(installer.Stop): self.recovered(journal)

    def test_backup_tampering_refused(self):
        journal, before, after, metas = self.prepared()
        name = 'private/server/booking-staff.php'
        path = self.private / 'unified-deployments' / journal['backup'] / (installer.sha(name.encode()) + '.bin')
        path.write_bytes(b'changed')
        with self.assertRaises(installer.Stop): self.recovered(journal)

    def test_concurrent_edit_and_permissions_preserved(self):
        journal, before, after, metas = self.prepared()
        path = self.private / 'server/booking-staff.php'; path.write_bytes(b'concurrent-edit')
        with self.assertRaises(installer.Stop): self.recovered(journal)
        self.assertEqual(path.read_bytes(), b'concurrent-edit')
        path.write_bytes(before['private/server/booking-staff.php']); path.chmod(0o640)
        with self.assertRaises(installer.Stop): self.recovered(journal)

    def test_crlf_restored_exactly(self):
        path = self.private / 'server/booking-staff.php'; original = path.read_bytes().replace(b'\n', b'\r\n'); path.write_bytes(original)
        for filename in self.obj['active_manifests']:
            p = self.private / filename; record = json.loads(p.read_bytes()); record['files']['server/booking-staff.php'] = installer.sha(original); p.write_bytes(installer.encode(record))
        journal, before, after, metas = self.prepared()
        installer.apply(self.private, self.public, self.uid, before, after, metas)
        installer.apply(self.private, self.public, self.uid, before, after, metas, rollback=True)
        self.assertEqual(path.read_bytes(), original)

    def test_code_recovery_does_not_erase_new_payment_state(self):
        journal, before, after, metas = self.prepared()
        installer.apply(self.private, self.public, self.uid, before, after, metas)
        with contextlib.closing(sqlite3.connect(self.db)) as db:
            db.execute("UPDATE provider_operations SET state='succeeded'"); db.commit()
        installer.apply(self.private, self.public, self.uid, before, after, metas, rollback=True)
        with contextlib.closing(sqlite3.connect(self.db)) as db:
            self.assertEqual(db.execute('SELECT state FROM provider_operations').fetchone()[0], 'succeeded')
        self.assertFalse((self.private / 'server/application.php').exists())
        self.assertEqual((self.public / 'account.php').read_bytes(), self.original['public/account.php'])

    def test_every_changed_file_interruption_resumes(self):
        before, after, metas = self.plan()
        changes = sum(before[n] != after[n] for n in after)
        for boundary in range(changes):
            with self.subTest(write_boundary=boundary):
                case = self.folder / ('interruption-' + str(boundary)); shutil.copytree(self.template, case)
                root, public = case / 'private', case / 'public'; db = root / 'data/bookings.sqlite'
                old, new, meta = installer.inspect(self.obj, self.files, root, public, self.uid, self.manifest_sha)
                journal = installer.prepare(self.obj, self.package_sha, root, public, db, self.uid, old, new, meta)
                journal_path = root / self.journal_path.name; installer.atomic(journal_path, installer.encode(journal), self.owner)
                calls = [0]
                def interrupted_writer(path, data, metadata):
                    installer.atomic(path, data, metadata)
                    if calls[0] == boundary: raise OSError('synthetic interruption after durable replacement')
                    calls[0] += 1
                with self.assertRaises(OSError): installer.apply(root, public, self.uid, old, new, meta, writer=interrupted_writer)
                installer.test_settings(root, self.uid, journal_path.name)
                old, new, meta = installer.recover_plan(self.obj, self.files, journal, self.package_sha, root, public, db, self.uid, self.manifest_sha)
                installer.apply(root, public, self.uid, old, new, meta)
                for name, value in new.items(): self.assertEqual(installer.target(name, root, public).read_bytes(), value)
                shutil.rmtree(case)
        print('Unified release interruption rehearsal: ' + str(changes) + ' durable write boundaries resumed.', flush=True)


if __name__ == '__main__':
    result = unittest.main(verbosity=2, exit=False).result
    if not result.wasSuccessful(): raise SystemExit(1)
    print('Unified release: ' + str(result.testsRun) + ' cases completed; ' + str(len(result.skipped)) + ' privileged skips.', flush=True)
