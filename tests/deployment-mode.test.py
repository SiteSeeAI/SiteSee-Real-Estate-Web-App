#!/usr/bin/env python3
"""Isolated deployment/activation boundaries; no application or provider operations."""
import contextlib
import importlib.util
import json
import os
import pathlib
import pwd
import sqlite3
import tempfile
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'tools' / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


installer = module('mode_installer', 'install-unified.py')
host = module('mode_host', 'install-appointment-approval-host.py')


class DeploymentMode(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sitesee-mode-deployment-')
        self.addCleanup(self.temporary.cleanup)
        self.base = pathlib.Path(self.temporary.name)
        self.root = self.base / 'private'; self.root.mkdir(mode=0o700)
        self.public = self.base / 'public'; self.public.mkdir(mode=0o755)
        (self.root / 'data').mkdir(mode=0o700)
        self.uid = os.geteuid()
        self.db = self.root / 'data/bookings.sqlite'
        self.make_db(self.db)
        self.write('booking-checkout.json', {'stage': 'TEST', 'enabled': True, 'publishable_key': 'pk_test_synthetic1234567890123456'})
        self.write('portal-test.json', {'stage': 'TEST', 'enabled': True})

    def write(self, name, value):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        path.write_bytes(installer.encode(value)); path.chmod(0o600)

    def make_db(self, path, control=None):
        with contextlib.closing(sqlite3.connect(path)) as db:
            db.execute('CREATE TABLE observations(id TEXT PRIMARY KEY, value TEXT)')
            if control:
                db.execute('CREATE TABLE application_realm(singleton INTEGER PRIMARY KEY, stage TEXT, realm TEXT, stripe_account TEXT)')
                db.execute('INSERT INTO application_realm VALUES(1,?,?,?)', ('LIVE', control['realm'], control['stripe_account']))
            db.commit()
        path.chmod(0o600)

    def live(self, active=False):
        self.control = {'format': 1, 'stage': 'LIVE', 'enabled': True, 'realm': 'a' * 32,
                        'stripe_account': 'acct_synthetic12345678'}
        self.live_db = self.root / 'runtime/live/data/bookings.sqlite'
        self.live_db.parent.mkdir(parents=True, mode=0o700)
        self.make_db(self.live_db, self.control)
        configs = {name: {} for name in installer.LIVE_CONFIGS}
        configs['environment.json'] = {'stage': 'LIVE', 'realm': self.control['realm'], 'values': {
            'SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET': 'sk_' + 'live_' + 'synthetic1234567890123456',
            'SITESEE_REAL_ESTATE_STRIPE_LIVE_WEBHOOK_SECRET': 'whsec_synthetic1234567890123456',
            'SITESEE_REAL_ESTATE_PRICING_GATE_SECRET': 'b' * 64}}
        configs['booking-checkout.json'] = {'stage': 'LIVE', 'enabled': True,
                                          'publishable_key': 'pk_live_synthetic1234567890123456'}
        self.preparation_plan = {'format': 1, 'control': self.control, 'files': configs, 'providers': {}}
        self.write('runtime/live/.preparation-plan.json', self.preparation_plan)
        for name, value in configs.items():
            self.write('runtime/live/' + name, value)
        self.write('application-live-ready.json', self.control)
        self.activation = {'format': 1, 'state': 'prepared', 'realm': self.control['realm'],
                           'stripe_account': self.control['stripe_account'],
                           'plan_sha256': installer.sha(installer.encode(self.preparation_plan)),
                           'configuration': {n: installer.sha(installer.encode(v)) for n, v in configs.items()}}
        self.write('live-activation-journal.json', self.activation)
        if active:
            self.activate()

    def activate(self):
        initial = 'unified-test-' + '1' * 12
        self.write('application-live.json', self.control | {'initial_code_release': initial})
        self.activation.update(state='active', initial_code_release=initial, activated_at=12345)
        self.write('live-activation-journal.json', self.activation)

    def plan(self):
        files = {name: (ROOT / name).read_bytes() for name in installer.PRESERVED_PUBLIC}
        files.update({'private/server/application-mode.php': b'new mode module',
                      'private/server/application.php': b'new bootstrap'})
        before, metas = {}, {}
        for name, raw in files.items():
            path = installer.target(name, self.root, self.public)
            path.parent.mkdir(parents=True, exist_ok=True, mode=0o700 if name.startswith('private/') else 0o755)
            if name.endswith('/application-mode.php'):
                before[name] = None
                metas[name] = {'uid': self.uid, 'gid': os.getegid(), 'mode': 0o600}
            else:
                old = b'old bootstrap' if name.endswith('/application.php') else raw
                path.write_bytes(old); path.chmod(0o600 if name.startswith('private/') else 0o644)
                before[name] = old; metas[name] = installer.metadata(path)
        obj = {'commit': '1' * 40, 'release': 'unified-test-' + '1' * 12, 'runtime_modes': ['TEST', 'LIVE'],
               'default_runtime_mode': 'TEST', 'runtime_schema': installer.RUNTIME_SCHEMA,
               'active_manifests': [], 'files': {n: {'accepted_before': [installer.sha(v)]} for n, v in files.items()}}
        after = dict(files)
        name = 'private/unified-release.json'
        before[name] = None
        metas[name] = {'uid': self.uid, 'gid': os.getegid(), 'mode': 0o600}
        after[name] = installer.release_record(obj, files, after, '2' * 64)
        journal = installer.prepare(obj, '3' * 64, self.root, self.public, self.db, self.uid, before, after, metas)
        return obj, files, journal, before, after, metas

    def recover(self, obj, files, journal):
        return installer.recover_plan(obj, files, journal, '3' * 64, self.root, self.public, self.db, self.uid, '2' * 64)

    def test_default_test_and_nonsecret_binding(self):
        state = installer.runtime_state(self.root, self.db, self.uid)
        self.assertEqual(installer.runtime_identity(state), {'stage': 'TEST', 'realm': None, 'stripe_account': None})
        self.assertEqual(state['databases'], {'TEST': str(self.db)})
        self.assertNotIn('pk_test', json.dumps(state))

    def test_prepared_live_remains_test_and_backs_up_both_ledgers(self):
        self.live()
        state = installer.runtime_state(self.root, self.db, self.uid)
        self.assertEqual(state['stage'], 'TEST')
        self.assertEqual(set(state['databases']), {'TEST', 'LIVE'})
        obj, files, journal, *_ = self.plan()
        self.assertEqual(set(journal['databases']), {'TEST', 'LIVE'})
        self.recover(obj, files, journal)
        self.assertFalse((self.root / 'application-live.json').exists())

    def test_active_realm_exact_and_history_cannot_fall_back(self):
        self.live(active=True)
        state = installer.runtime_state(self.root, self.db, self.uid)
        self.assertEqual(state['stage'], 'LIVE')
        with self.assertRaisesRegex(installer.Stop, 'LIVE activation history'):
            installer.rollback_runtime_guard(state)
        (self.root / 'application-live.json').unlink()
        with self.assertRaisesRegex(installer.Stop, 'prevents fallback'):
            installer.runtime_state(self.root, self.db, self.uid)

    def test_partial_activation_refused(self):
        self.live()
        self.write('application-live.json', self.control | {'initial_code_release': 'unified-test-' + '1' * 12})
        with self.assertRaisesRegex(installer.Stop, 'incomplete or changed'):
            installer.runtime_state(self.root, self.db, self.uid)

    def test_wrong_realm_and_opposite_mode_db_refused_without_schema_write(self):
        self.live()
        with sqlite3.connect(self.live_db) as db:
            db.execute("UPDATE application_realm SET realm=?", ('b' * 32,))
        raw = self.live_db.read_bytes()
        with self.assertRaisesRegex(installer.Stop, 'realm differs'):
            installer.runtime_state(self.root, self.db, self.uid)
        self.assertEqual(self.live_db.read_bytes(), raw)
        with sqlite3.connect(self.db) as db:
            db.execute('CREATE TABLE application_realm(stage TEXT,realm TEXT,stripe_account TEXT)')
            db.execute("INSERT INTO application_realm VALUES('LIVE','wrong','wrong')")
        with self.assertRaisesRegex(installer.Stop, 'mode differs'):
            installer.runtime_state(self.root, self.db, self.uid)

    def test_configuration_alias_and_world_readable_file_refused(self):
        self.live()
        path = self.root / 'runtime/live/environment.json'
        saved = path.read_bytes(); path.unlink()
        target = self.base / 'other'; target.write_bytes(saved); target.chmod(0o600)
        path.symlink_to(target)
        with self.assertRaises(installer.Stop):
            installer.runtime_state(self.root, self.db, self.uid)
        path.unlink(); path.write_bytes(saved); path.chmod(0o644)
        with self.assertRaisesRegex(installer.Stop, 'Unsafe private configuration'):
            installer.runtime_state(self.root, self.db, self.uid)

    def test_backup_configs_immutable_and_drift_blocks_resume(self):
        self.live()
        obj, files, journal, *_ = self.plan()
        self.write('portal-test.json', {'stage': 'TEST', 'enabled': False})
        with self.assertRaisesRegex(installer.Stop, 'interrupted update'):
            self.recover(obj, files, journal)
        folder = self.root / 'unified-deployments' / journal['backup']
        path = folder / 'configuration-metadata.json'; path.write_bytes(path.read_bytes() + b' ')
        with self.assertRaisesRegex(installer.Stop, 'backup inventory'):
            self.recover(obj, files, journal)

    def test_individual_config_and_live_snapshot_corruption_refused(self):
        self.live()
        obj, files, journal, *_ = self.plan()
        folder = self.root / 'unified-deployments' / journal['backup']
        path = folder / ('config-' + installer.sha(b'runtime/live/environment.json') + '.bin')
        original = path.read_bytes(); path.write_bytes(original + b' ')
        with self.assertRaisesRegex(installer.Stop, 'Configuration backup checksum'):
            self.recover(obj, files, journal)
        path.write_bytes(original)
        snapshot = folder / 'live-bookings.sqlite'; snapshot.write_bytes(snapshot.read_bytes() + b'bad')
        with self.assertRaisesRegex(installer.Stop, 'realm backup checksum'):
            self.recover(obj, files, journal)

    def test_later_activation_and_financial_rows_do_not_invalidate_historical_backup(self):
        self.live()
        obj, files, journal, before, after, metas = self.plan()
        installer.apply(self.root, self.public, self.uid, before, after, metas)
        journal['state'] = 'installed'
        self.activate()
        with sqlite3.connect(self.live_db) as db:
            db.execute("INSERT INTO observations VALUES('later-payment','must-survive')")
        self.recover(obj, files, journal)
        with sqlite3.connect(self.live_db) as db:
            self.assertEqual(db.execute('SELECT value FROM observations').fetchone()[0], 'must-survive')
        with self.assertRaisesRegex(installer.Stop, 'LIVE activation history'):
            installer.rollback_runtime_guard(installer.runtime_state(self.root, self.db, self.uid))

    def test_both_wal_snapshots_and_isolated_live_restore(self):
        self.live()
        handles = []
        try:
            for path in (self.db, self.live_db):
                db = sqlite3.connect(path); handles.append(db)
                db.execute('PRAGMA journal_mode=WAL'); db.execute('PRAGMA wal_autocheckpoint=0')
                db.execute("INSERT INTO observations VALUES('wal-only','committed')"); db.commit()
                for suffix in ('-wal', '-shm'):
                    pathlib.Path(str(path) + suffix).chmod(0o600)
            obj, files, journal, *_ = self.plan()
            for item in journal['databases'].values():
                with sqlite3.connect(self.root / 'unified-deployments' / journal['backup'] / item['backup']) as db:
                    self.assertEqual(db.execute('SELECT value FROM observations').fetchone()[0], 'committed')
            destination = self.base / 'isolated-live.sqlite'
            installer.restore_rehearsal(self.root, self.public, self.db, journal, destination, self.uid, 'LIVE')
            with sqlite3.connect(destination) as db:
                self.assertEqual(db.execute('SELECT value FROM observations').fetchone()[0], 'committed')
            with self.assertRaises(installer.Stop):
                installer.restore_rehearsal(self.root, self.public, self.db, journal, self.live_db, self.uid, 'LIVE')
        finally:
            for db in handles:
                db.close()

    def test_helper_first_boundary_crash_resumes_without_missing_dependency(self):
        obj, files, journal, before, after, metas = self.plan()
        original = installer.atomic
        writes = []
        def interrupted(path, data, meta):
            original(path, data, meta); writes.append(path.name)
            if len(writes) == 1:
                raise RuntimeError('simulated interruption after durable helper')
        with mock.patch.object(installer, 'atomic', side_effect=interrupted), self.assertRaises(RuntimeError):
            installer.bootstrap_boundary(self.root, self.uid, before, after, metas)
        self.assertEqual(writes, ['application-mode.php'])
        self.assertEqual((self.root / 'server/application.php').read_bytes(), b'old bootstrap')
        self.recover(obj, files, journal)
        installer.bootstrap_boundary(self.root, self.uid, before, after, metas)
        self.assertEqual((self.root / 'server/application.php').read_bytes(), b'new bootstrap')

    def test_prepared_operational_live_rows_block_rollback(self):
        self.live()
        installer.rollback_runtime_guard(installer.runtime_state(self.root, self.db, self.uid))
        with sqlite3.connect(self.live_db) as db:
            db.execute("INSERT INTO observations VALUES('operation','uncertain')")
        with self.assertRaisesRegex(installer.Stop, 'LIVE operational records'):
            installer.rollback_runtime_guard(installer.runtime_state(self.root, self.db, self.uid))

    def test_runtime_binding_evidence_rejects_missing_and_mixed_values(self):
        self.assertEqual(host.checked_binding({'stage': 'TEST', 'realm': None, 'stripe_account': None})['stage'], 'TEST')
        for value in [None, {'stage': 'TEST'}, {'stage': 'TEST', 'realm': 'a' * 32, 'stripe_account': None},
                      {'stage': 'LIVE', 'realm': 'bad', 'stripe_account': 'acct_synthetic12345678'}]:
            with self.subTest(value=value), self.assertRaises(RuntimeError):
                host.checked_binding(value)


if __name__ == '__main__':
    unittest.main(verbosity=2)
