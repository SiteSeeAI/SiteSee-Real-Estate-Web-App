#!/usr/bin/env python3
"""Rehearse the real fixed-commit release on isolated files and a synthetic WAL ledger."""
import contextlib
import fcntl
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
CPANEL_HANDLER = '''# php -- BEGIN cPanel-generated handler, do not edit
# Set the “ea-php82” package as the default “PHP” programming language.
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
'''.encode()


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

    def test_package_uses_verified_bytes_if_upload_path_is_replaced(self):
        path = self.folder / 'replaced-upload.tar.gz'
        path.write_bytes(self.package.read_bytes())
        open_archive = tarfile.open
        def replace_upload_then_open(*args, **kwargs):
            path.write_bytes(b'replacement is not the verified archive')
            return open_archive(*args, **kwargs)
        with mock.patch.object(installer.tarfile, 'open', side_effect=replace_upload_then_open):
            obj, files, digest = installer.load(path, self.package_sha)
        self.assertEqual(obj, self.obj)
        self.assertEqual(files, self.files)
        self.assertEqual(digest, self.manifest_sha)

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

    def test_parent_path_alias_cannot_place_database_under_public(self):
        hidden = self.public / 'hidden'; hidden.mkdir(mode=0o700)
        database = hidden / 'bookings.sqlite'; shutil.copy2(self.db, database)
        alias = self.private / '../public/hidden/bookings.sqlite'
        with self.assertRaisesRegex(installer.Stop, 'canonical absolute'):
            installer.check_dirs(self.private, self.public, alias, self.uid, self.files)
        self.assertEqual(database.read_bytes(), self.db.read_bytes())
        for root, public in [(self.private / '../private', self.public), (self.private, self.public / '../public')]:
            with self.assertRaisesRegex(installer.Stop, 'canonical absolute'):
                installer.check_dirs(root, public, self.db, self.uid, self.files)

    def test_private_directory_and_file_modes_refused(self):
        self.private.chmod(0o755)
        with self.assertRaises(installer.Stop): self.plan()
        self.private.chmod(0o700)
        (self.private / 'server/booking-store.php').chmod(0o666)
        with self.assertRaises(installer.Stop): self.plan()

    def test_unsupported_source_modes_rejected_before_journal(self):
        path = self.private / 'server/booking-store.php'
        for mode in (0o1644, 0o2644, 0o4644, 0):
            with self.subTest(mode=oct(mode)):
                path.chmod(mode)
                with self.assertRaisesRegex(installer.Stop, 'Unsafe file or ownership'): self.plan()
                self.assertEqual(installer.metadata(path)['mode'], mode)
                self.assertFalse(self.journal_path.exists())
        path.chmod(0o600)

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

    def test_prior_repair_and_probe_journals_refused(self):
        for name in ['appointment-management-repair.json', 'appointment-worker-repair.json',
                     'legacy-cancellation-repair.json', 'microsoft-calendar-probe.json']:
            path = self.private / name
            states = ['prepared', 'installing', 'failed', 'restoring', 'unknown', None]
            if name == 'microsoft-calendar-probe.json': states += ['create_started', 'identified', 'delete_started']
            for state in states:
                with self.subTest(journal=name, state=state):
                    path.write_bytes(installer.encode({'state': state})); path.chmod(0o600)
                    with self.assertRaisesRegex(installer.Stop, 'requires recovery'): self.plan()
            path.unlink()

    def test_completed_historical_repair_and_probe_journals_preserved(self):
        for name, state in [('appointment-management-repair.json', 'installed'), ('appointment-worker-repair.json', 'installed'),
                            ('legacy-cancellation-repair.json', 'restored'), ('microsoft-calendar-probe.json', 'complete')]:
            path = self.private / name
            data = installer.encode({'state': state}); path.write_bytes(data); path.chmod(0o600)
            self.plan()
            self.assertEqual(path.read_bytes(), data)

    def test_manifest_hash_mismatch_refused(self):
        path = self.private / self.obj['active_manifests'][0]
        record = json.loads(path.read_bytes()); record['files']['server/booking-staff.php'] = '0' * 64
        path.write_bytes(installer.encode(record))
        with self.assertRaises(installer.Stop): self.plan()

    def test_manifest_unchanged_source_hash_mismatch_refused(self):
        path = self.private / self.obj['active_manifests'][0]
        record = json.loads(path.read_bytes()); record['files']['server/booking-store.php'] = '0' * 64
        corrupted = installer.encode(record); path.write_bytes(corrupted)
        with self.assertRaisesRegex(installer.Stop, 'Established manifest hash differs'): self.plan()
        self.assertEqual(path.read_bytes(), corrupted)
        self.assertFalse(self.journal_path.exists())

    def test_recovery_refuses_legacy_journal_with_bad_unchanged_manifest_hash(self):
        before, after, metas = self.plan()
        name = 'private/' + self.obj['active_manifests'][0]
        for values in (before, after):
            record = json.loads(values[name]); record['files']['server/booking-store.php'] = '0' * 64
            values[name] = installer.encode(record)
        installer.target(name, self.private, self.public).write_bytes(before[name])
        # A self-consistent prior journal must not bless a broken integrity record.
        journal = installer.prepare(self.obj, self.package_sha, self.private, self.public, self.db, self.uid, before, after, metas)
        with self.assertRaisesRegex(installer.Stop, 'Backup release metadata differs'): self.recovered(journal)

    def test_insufficient_disk_capacity_refused(self):
        before, after, metas = self.plan()
        with mock.patch.object(installer.shutil, 'disk_usage', return_value=shutil._ntuple_diskusage(10, 9, 1)):
            with self.assertRaises(installer.Stop): installer.space(self.private, self.public, self.db, before, after)

    def test_cli_preflight_is_read_only(self):
        names_before = sorted(p.relative_to(self.private) for p in self.private.rglob('*'))
        self.assertIn('PREFLIGHT PASS', self.cli('--preflight'))
        self.assertEqual(names_before, sorted(p.relative_to(self.private) for p in self.private.rglob('*')))
        self.assertFalse(self.journal_path.exists())

    def test_cpanel_handler_preflight_install_verify_resume_and_recovery_preserve_exact_file(self):
        path = self.public / '.htaccess'
        data = (path.read_bytes() + b'\n' + CPANEL_HANDLER).replace(b'\n', b'\r\n')
        path.write_bytes(data)
        info = path.stat(); original_meta = installer.metadata(path)
        # A mapped hosting digest must still be checked and remain unchanged.
        manifest_path = self.private / self.obj['active_manifests'][0]
        record = json.loads(manifest_path.read_bytes()); record['files']['public/.htaccess'] = installer.sha(data)
        manifest_path.write_bytes(installer.encode(record))
        preflight = json.loads(self.cli('--preflight'))
        self.assertEqual(preflight['preserved_host_files']['public/.htaccess'], installer.sha(data))
        self.assertFalse(self.journal_path.exists())
        for action in ('--install', '--verify', '--resume'):
            self.cli(action)
            self.assertEqual(path.read_bytes(), data)
            self.assertEqual(installer.metadata(path), original_meta)
            self.assertEqual((path.stat().st_ino, path.stat().st_mtime_ns), (info.st_ino, info.st_mtime_ns))
        release = json.loads((self.private / 'unified-release.json').read_bytes())
        self.assertEqual(release['files']['public/.htaccess'], installer.sha(data))
        self.assertEqual(release['preserved_host_files']['public/.htaccess'], installer.sha(data))
        journal = json.loads(self.journal_path.read_bytes())
        self.assertEqual(journal['entries']['public/.htaccess']['before'], journal['entries']['public/.htaccess']['after'])
        self.cli('--rollback-code')
        self.assertEqual(path.read_bytes(), data)
        self.assertEqual(installer.metadata(path), original_meta)
        self.assertEqual(json.loads(manifest_path.read_bytes())['files']['public/.htaccess'], installer.sha(data))

    def test_configured_turnstile_and_crlf_svg_preserved_through_all_actions(self):
        names = ['public/assets/js/turnstile.js', 'public/assets/images/platform/notes-collaboration.svg']
        original = {}
        for name in names:
            path = installer.target(name, self.private, self.public)
            data = path.read_bytes().replace(b'__SITESEE_AUDIT_SITEKEY__', b'0x4ABCDEFGHIJKLMNOPQRSTUV').replace(b'\n', b'\r\n')
            path.write_bytes(data)
            info = path.stat()
            original[name] = (data, installer.metadata(path), info.st_ino, info.st_mtime_ns)
        manifest = self.private / self.obj['active_manifests'][0]
        record = json.loads(manifest.read_bytes())
        record['files'].update({name: installer.sha(values[0]) for name, values in original.items()})
        manifest.write_bytes(installer.encode(record))
        result = json.loads(self.cli('--preflight'))
        self.assertFalse(self.journal_path.exists())
        for name, values in original.items():
            self.assertEqual(result['preserved_host_files'][name], installer.sha(values[0]))
        for action in ('--install', '--verify', '--resume', '--rollback-code'):
            self.cli(action)
            for name, (data, meta, inode, mtime) in original.items():
                path = installer.target(name, self.private, self.public)
                self.assertEqual(path.read_bytes(), data)
                self.assertEqual(installer.metadata(path), meta)
                self.assertEqual((path.stat().st_ino, path.stat().st_mtime_ns), (inode, mtime))
                self.assertEqual(json.loads(manifest.read_bytes())['files'][name], installer.sha(data))

    def test_turnstile_other_code_changes_and_cloudflare_test_keys_refused(self):
        name = 'public/assets/js/turnstile.js'
        path = installer.target(name, self.private, self.public)
        source = self.files[name]
        configured = source.replace(b'__SITESEE_AUDIT_SITEKEY__', b'0x4ABCDEFGHIJKLMNOPQRSTUV')
        variants = [configured.replace(b"token.value = '';", b"token.value = 'bypass';", 1),
                    configured.replace(b'submit.disabled = true;', b'submit.disabled = false;', 1),
                    source.replace(b'__SITESEE_AUDIT_SITEKEY__', b'1x00000000000000000000AA'),
                    source.replace(b'__SITESEE_AUDIT_SITEKEY__', b"validkey123';evil();//")]
        for data in variants:
            with self.subTest(digest=installer.sha(data)):
                path.write_bytes(data)
                with self.assertRaisesRegex(installer.Stop, 'Unknown deployed edit preserved'):
                    self.plan()
                self.assertEqual(path.read_bytes(), data)
                self.assertFalse(self.journal_path.exists())

    def test_svg_changes_beyond_line_endings_preserved_and_refused(self):
        name = 'public/assets/images/platform/notes-collaboration.svg'
        path = installer.target(name, self.private, self.public)
        changed = path.read_bytes().replace(b'SiteSee', b'EditedArtwork', 1).replace(b'\n', b'\r\n')
        path.write_bytes(changed)
        with self.assertRaisesRegex(installer.Stop, 'Unknown deployed edit preserved'):
            self.plan()
        self.assertEqual(path.read_bytes(), changed)

    def test_configured_turnstile_concurrent_key_change_preserved(self):
        name = 'public/assets/js/turnstile.js'
        path = installer.target(name, self.private, self.public)
        path.write_bytes(self.files[name].replace(b'__SITESEE_AUDIT_SITEKEY__', b'0x4ABCDEFGHIJKLMNOPQRSTUV'))
        journal, _, _, _ = self.prepared()
        changed = self.files[name].replace(b'__SITESEE_AUDIT_SITEKEY__', b'0x4ZYXWVUTSRQPONMLKJIHGF')
        path.write_bytes(changed)
        with self.assertRaisesRegex(installer.Stop, 'Concurrent/unknown edit preserved'):
            self.recovered(journal)
        self.assertEqual(path.read_bytes(), changed)

    def test_plain_crlf_apache_file_is_not_rewritten(self):
        path = self.public / '.htaccess'; data = path.read_bytes().replace(b'\n', b'\r\n'); path.write_bytes(data)
        before, after, _ = self.plan()
        self.assertEqual(before['public/.htaccess'], data)
        self.assertEqual(after['public/.htaccess'], data)

    def test_cpanel_handler_unknown_rules_and_versions_refused(self):
        path = self.public / '.htaccess'; original = path.read_bytes()
        examples = [original + CPANEL_HANDLER + b'SetEnv UNKNOWN_FLAG value\n',
                    original.replace(b'no-store, private', b'public') + CPANEL_HANDLER,
                    original + CPANEL_HANDLER.replace(b'ea-php82', b'ea-php83'),
                    original + CPANEL_HANDLER + CPANEL_HANDLER,
                    original.replace(b'\n', b'\x0b') + CPANEL_HANDLER]
        for data in examples:
            with self.subTest(digest=installer.sha(data)):
                path.write_bytes(data)
                with self.assertRaisesRegex(installer.Stop, 'Unknown deployed edit'): self.plan()
                self.assertEqual(path.read_bytes(), data)
                self.assertFalse(self.journal_path.exists())

    def test_joined_apache_directives_require_repair_before_preflight(self):
        path = self.public / '.htaccess'
        data = path.read_bytes().replace(b'Options -Indexes\nDirectoryIndex index.html\n\n',
                                        b'Options -IndexesDirectoryIndex index.html\n', 1) + CPANEL_HANDLER
        path.write_bytes(data)
        with self.assertRaisesRegex(installer.Stop, 'Joined Apache directives'): self.plan()
        self.assertEqual(path.read_bytes(), data)
        self.assertFalse(self.journal_path.exists())

    def test_recovery_rechecks_reviewed_apache_rules_from_backups(self):
        path = self.public / '.htaccess'; path.write_bytes(path.read_bytes() + CPANEL_HANDLER)
        journal, before, after, metas = self.prepared()
        name = 'public/.htaccess'
        data = before[name] + b'RewriteRule ^.*$ https://example.invalid/ [R=302,L]\n'
        backup = self.private / 'unified-deployments' / journal['backup'] / (installer.sha(name.encode()) + '.bin')
        backup.write_bytes(data)
        journal['entries'][name]['before'] = journal['entries'][name]['after'] = installer.sha(data)
        with self.assertRaisesRegex(installer.Stop, 'Unknown deployed edit'): self.recovered(journal)

    def test_concurrent_cpanel_change_is_preserved_during_recovery(self):
        path = self.public / '.htaccess'; path.write_bytes(path.read_bytes() + CPANEL_HANDLER)
        journal, _, _, _ = self.prepared()
        newer = path.read_bytes().replace(b'\n', b'\r\n'); path.write_bytes(newer)
        with self.assertRaisesRegex(installer.Stop, 'Concurrent/unknown edit'): self.recovered(journal)
        self.assertEqual(path.read_bytes(), newer)

    def test_missing_php_path_and_preflight_error_identify_cause_without_resume_hint(self):
        missing = self.folder / 'missing-php'
        command = ['python3', str(ROOT / 'tools/install-unified.py'), '--package', str(self.package), '--sha256', self.package_sha,
                   '--private-root', str(self.private), '--public-root', str(self.public), '--account', self.account,
                   '--php', str(missing), '--preflight']
        result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 1)
        self.assertIn('Required path missing: ' + str(missing), result.stdout)
        self.assertIn('Read-only preflight stopped.', result.stdout)
        self.assertNotIn('Use the same package with --resume', result.stdout)
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

    def test_cli_refuses_a_concurrent_deployment(self):
        fd = os.open(self.private, os.O_RDONLY | os.O_DIRECTORY)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertIn('Another deployment is running.', self.cli('--preflight', success=False))
            self.assertFalse(self.journal_path.exists())
        finally:
            os.close(fd)

    def test_cli_code_recovery_preserves_later_provider_state(self):
        self.cli('--install')
        with contextlib.closing(sqlite3.connect(self.db)) as db:
            db.execute("UPDATE provider_operations SET state='succeeded'"); db.commit()
        self.assertIn('CODE RESTORED', self.cli('--rollback-code'))
        self.assertEqual(json.loads(self.journal_path.read_bytes())['state'], 'restored')
        self.assertEqual((self.public / 'account.php').read_bytes(), self.original['public/account.php'])
        with contextlib.closing(sqlite3.connect(self.db)) as db:
            self.assertEqual(db.execute('SELECT state FROM provider_operations').fetchone()[0], 'succeeded')
        self.assertIn('Installation is not complete.', self.cli('--verify', success=False))

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

    def test_restore_rehearsal_parent_alias_cannot_write_under_public(self):
        journal, before, after, metas = self.prepared()
        hidden = self.public / 'hidden'; hidden.mkdir(mode=0o700)
        alias = self.private / '../public/hidden/restored.sqlite'
        with self.assertRaisesRegex(installer.Stop, 'canonical absolute'):
            installer.restore_rehearsal(self.private, self.public, self.db, journal, alias, self.uid)
        self.assertFalse((hidden / 'restored.sqlite').exists())

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
