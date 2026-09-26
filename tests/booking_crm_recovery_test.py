import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest

PROJECT = Path(__file__).parents[1]
spec = importlib.util.spec_from_file_location('recovery', PROJECT / 'tools/recover-booking-crm.py')
recovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recovery)


class State:
    def __init__(self):
        self.calls = []
        self.failure = False
        self.rows = [dict(communication_key=key, sender=sender, recipient='cro@sitesee.ai',
                          submission_state='sent_observed', delivery_state='recipient_copy_observed',
                          internet_message_id='<saved-' + str(i) + '@example.test>', crm_state='pending')
                     for i, (key, sender) in enumerate(zip(recovery.KEYS, ('cro@sitesee.ai', 'sales@re.sitesee.ai')))]

    def __call__(self, *args):
        self.calls.append(args)
        if args[0] == 'report':
            return 0, json.dumps(self.rows), ''
        if args[0] == 'crm':
            row = next(row for row in self.rows if row['communication_key'] == args[1])
            if self.failure:
                row['crm_state'] = 'retry_pending'
                return 1, '', 'STOP: NO_PERMISSION'
            row['crm_state'] = 'associated'
        return 0, '', ''


class RecoveryTests(unittest.TestCase):
    def test_recovery_and_repeat_use_only_crm_report_enable(self):
        state = State()
        self.assertEqual(recovery.recover(state, lambda *a, **k: None), 0)
        self.assertEqual(recovery.recover(state, lambda *a, **k: None), 0)
        self.assertEqual(sum(c[0] == 'crm' for c in state.calls), 2)
        self.assertEqual(set(c[0] for c in state.calls), {'crm', 'report', 'enable'})

    def test_incomplete_delivery_blocks_all_writes(self):
        state = State()
        state.rows[1]['delivery_state'] = 'unverified'
        with self.assertRaises(recovery.Stop):
            recovery.recover(state, lambda *a, **k: None)
        self.assertEqual([c[0] for c in state.calls], ['report'])

    def test_wrong_sender_blocks_all_writes(self):
        state = State()
        state.rows[1]['sender'] = 'wrong@example.test'
        with self.assertRaises(recovery.Stop):
            recovery.recover(state, lambda *a, **k: None)
        self.assertEqual([c[0] for c in state.calls], ['report'])

    def test_crm_failures_collected_and_activation_blocked(self):
        state = State()
        state.failure = True
        self.assertEqual(recovery.recover(state, lambda *a, **k: None), 2)
        self.assertEqual(sum(c[0] == 'crm' for c in state.calls), 2)
        self.assertFalse(any(c[0] == 'enable' for c in state.calls))

    def test_sender_commands_are_not_available(self):
        for action in ('send-probe', 'import-original', 'recheck', 'delivery'):
            with self.assertRaises(recovery.Stop):
                recovery.run(action, recovery.REFERENCE)

    def test_payloads_match_repository(self):
        self.assertEqual(recovery.content('crm'), (PROJECT / '_private/server/booking-crm.php').read_bytes())
        self.assertEqual(recovery.content('runner'), (PROJECT / 'tools/finish-booking-communications.py').read_bytes())

    def test_installer_backup_repeat_and_configuration_preservation(self):
        php = os.environ.get('SITESEE_TEST_PHP', '/workspace/scratch/0620b17ff5df/php-bin/php')
        if not Path(php).is_file():
            self.skipTest('Set SITESEE_TEST_PHP for installer rehearsal.')
        original = PROJECT.parent / 'deliverables/booking-communications-20260926/payload/server/booking-crm.php'
        previous_runner = PROJECT.parent / 'deliverables/finish-booking-communications.py'
        if not original.is_file() or not previous_runner.is_file():
            self.skipTest('Prior deployment fixtures are required for installer rehearsal.')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'private'
            (root / 'server').mkdir(parents=True)
            (root / 'tools').mkdir()
            root.chmod(0o700)
            (root / 'server/booking-crm.php').write_bytes(original.read_bytes())
            for name in ('server/booking-mail-client.php', 'tools/booking-communications.php'):
                source = PROJECT / ('_private/' + name if name.startswith('server/') else name)
                (root / name).write_bytes(source.read_bytes())
            runner = Path(directory) / 'finish-booking-communications.py'
            runner.write_bytes(previous_runner.read_bytes())
            (root / 'zoho-crm.json').write_text('private fixture must remain unchanged')
            (root / 'calendar-confirmation-release.json').write_text(json.dumps({'files': {'server/booking-crm.php': recovery.CRM_OLD}}))
            backup = recovery.install(root, runner, php, os.getuid(), os.getgid())
            self.assertEqual((backup / 'booking-crm.php').read_bytes(), original.read_bytes())
            self.assertEqual((root / 'server/booking-crm.php').read_bytes(), recovery.content('crm'))
            self.assertEqual(runner.read_bytes(), recovery.content('runner'))
            self.assertEqual((root / 'server/booking-crm.php').stat().st_mode & 0o777, 0o600)
            recovery.install(root, runner, php, os.getuid(), os.getgid())
            self.assertEqual((root / 'zoho-crm.json').read_text(), 'private fixture must remain unchanged')
            (root / 'server/booking-crm.php').write_text('unexpected server version')
            with self.assertRaises(recovery.Stop):
                recovery.install(root, runner, php, os.getuid(), os.getgid())


if __name__ == '__main__':
    unittest.main()
