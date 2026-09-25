import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('writer_setup', Path(__file__).parents[1] / 'tools/setup-zoho-confirmation.py')
writer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(writer)


class ConfirmationSetupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.reader = self.root / 'zoho-calendar.json'
        self.target = self.root / 'zoho-confirmation.json'
        self.config = dict(schema_version=1, setup='sitesee-calendar-readonly', enabled=True,
            accounts_base=writer.base.ACCOUNTS, calendar_base=writer.base.CALENDAR,
            timezone='America/Chicago', calendar_uid='a' * 32, calendar_owner_id='42',
            connection_verified_at=1, client_id='client-fixture', client_secret='secret-fixture', refresh_token='reader-fixture')
        writer.base.atomic_save(self.reader, self.config, os.getuid(), os.getgid())
        self.original = self.reader.read_bytes()
        self.calls = []
        self.fail_calendar = False

    def tearDown(self):
        self.temp.cleanup()

    def request(self, url, form=None, token=None):
        self.calls.append((url, form, token))
        if form:
            self.assertEqual(url, writer.base.ACCOUNTS + '/oauth/v2/token')
            if form['grant_type'] == 'authorization_code':
                return dict(access_token='token-fixture', refresh_token='writer-fixture', scope=','.join(writer.SCOPES))
            return dict(access_token='token-fixture')
        if '/calendars?' in url:
            if self.fail_calendar:
                raise writer.base.SetupError('Fixture calendar failure.')
            return {'calendars': [dict(uid='a' * 32, name='SiteSee Photography', owner='42', category='own', privilege='owner',
                timezone='Asia/Calcutta', include_infreebusy=True, status=True)]}
        return {'events': [{'message': 'No events found.'}]}

    def run_setup(self, *args):
        output = io.StringIO()
        with mock.patch.multiple(writer, ROOT=self.root, READER=self.reader, WRITER=self.target), \
            mock.patch.object(writer.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())), \
            mock.patch.object(writer.os, 'geteuid', return_value=0), \
            mock.patch.object(writer.base, 'request_json', side_effect=self.request), \
            mock.patch.object(writer.base, 'secret', return_value='code-fixture') as secret, \
            mock.patch('builtins.input', return_value='agent@example.com'), \
            mock.patch.object(writer.sys, 'argv', ['setup'] + list(args)), contextlib.redirect_stdout(output):
            writer.main()
        self.assertEqual(self.reader.read_bytes(), self.original)
        self.assertNotIn('secret-fixture', output.getvalue())
        self.assertNotIn('writer-fixture', output.getvalue())
        return json.loads(self.target.read_text()), secret.call_count

    def test_new_connection_preserves_reader_and_defaults_off(self):
        config, prompts = self.run_setup()
        self.assertEqual(prompts, 1)
        self.assertEqual(config['refresh_token'], 'writer-fixture')
        self.assertEqual(config['client_secret'], self.config['client_secret'])
        self.assertFalse(config['confirmation_enabled'])
        self.assertFalse(config['invitations_enabled'])
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o600)

    def test_resume_does_not_request_another_code(self):
        self.run_setup()
        _, prompts = self.run_setup()
        self.assertEqual(prompts, 0)

    def test_failed_verification_preserves_writer_for_resume(self):
        self.fail_calendar = True
        with self.assertRaises(writer.base.SetupError):
            self.run_setup()
        self.assertEqual(self.reader.read_bytes(), self.original)
        self.assertEqual(json.loads(self.target.read_text())['refresh_token'], 'writer-fixture')
        self.fail_calendar = False
        config, prompts = self.run_setup()
        self.assertEqual(prompts, 0)
        self.assertGreater(config['connection_verified_at'], 0)

    def test_flags_enable_in_separate_steps(self):
        self.run_setup()
        with self.assertRaises(writer.base.SetupError):
            self.run_setup('--enable-invitations')
        config, _ = self.run_setup('--enable-calendar')
        self.assertTrue(config['confirmation_enabled'])
        self.assertFalse(config['invitations_enabled'])
        config, _ = self.run_setup('--enable-invitations')
        self.assertTrue(config['invitations_enabled'])
        config, _ = self.run_setup('--disable')
        self.assertFalse(config['confirmation_enabled'])
        self.assertFalse(config['invitations_enabled'])

    def test_symlink_and_insecure_permissions_are_refused(self):
        alias = self.root / 'alias.json'
        alias.symlink_to(self.reader)
        with self.assertRaises(OSError):
            writer.load_private(alias, os.getuid())
        self.reader.chmod(0o644)
        with self.assertRaises(writer.base.SetupError):
            writer.load_private(self.reader, os.getuid())

    def test_setup_never_posts_to_calendar(self):
        self.run_setup()
        self.assertTrue(all(url.endswith('/oauth/v2/token') for url, form, _ in self.calls if form))
        self.assertEqual(writer.SCOPES, ['ZohoCalendar.calendar.READ', 'ZohoCalendar.event.READ', 'ZohoCalendar.event.CREATE'])


if __name__ == '__main__':
    unittest.main()
