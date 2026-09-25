import importlib.util
import contextlib
import io
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch
import urllib.error

SPEC = importlib.util.spec_from_file_location('calendar_setup', Path(__file__).resolve().parents[1] / 'tools/setup-zoho-calendar.py')
setup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(setup)


def config():
    return {'schema_version': 1, 'setup': 'sitesee-calendar-readonly', 'enabled': False,
            'client_id': 'test-client', 'client_secret': 'secret-test-value', 'refresh_token': 'test-refresh'}


def calendar(**changes):
    value = {'name': 'SiteSee Photography', 'category': 'own', 'privilege': 'owner',
             'timezone': 'America/Chicago', 'include_infreebusy': True, 'status': True, 'uid': 'test-uid'}
    value.update(changes)
    return value


def timed_event():
    return {'isallday': False, 'title': 'private-test-event', 'dateandtime': {
        'start': '20260929T140000+0000', 'end': '20260929T150000+0000', 'timezone': 'UTC'}}


class CalendarSetupTests(unittest.TestCase):
    def test_private_atomic_storage_and_resume(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'calendar.json'
            setup.atomic_save(path, config(), os.getuid(), os.getgid())
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.assertEqual(setup.load_existing(path, os.getuid()), config())
            updated = config() | {'calendar_uid': 'test-uid'}
            setup.atomic_save(path, updated, os.getuid(), os.getgid())
            self.assertEqual(json.loads(path.read_text()), updated)
            self.assertEqual([p.name for p in Path(directory).iterdir()], ['calendar.json'])

    def test_failed_replacement_keeps_old_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'calendar.json'
            setup.atomic_save(path, config(), os.getuid(), os.getgid())
            original = path.read_bytes()
            with patch.object(setup.os, 'replace', side_effect=OSError('disk failure')):
                with self.assertRaises(OSError):
                    setup.atomic_save(path, config() | {'refresh_token': 'replacement'}, os.getuid(), os.getgid())
            self.assertEqual(path.read_bytes(), original)
            self.assertEqual(len(list(Path(directory).iterdir())), 1)

    def test_rejects_insecure_or_active_existing_config(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'calendar.json'
            setup.atomic_save(path, config(), os.getuid(), os.getgid())
            path.chmod(0o644)
            with self.assertRaises(setup.SetupError):
                setup.load_existing(path, os.getuid())
            path.chmod(0o600)
            setup.atomic_save(path, config() | {'enabled': True}, os.getuid(), os.getgid())
            with self.assertRaises(setup.SetupError):
                setup.load_existing(path, os.getuid())
            self.assertTrue(json.loads(path.read_text())['enabled'])

    def test_does_not_follow_config_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'target'
            target.write_text('private existing file')
            link = Path(directory) / 'calendar.json'
            link.symlink_to(target)
            with self.assertRaises(OSError):
                setup.load_existing(link, os.getuid())
            self.assertEqual(target.read_text(), 'private existing file')

    def test_missing_file_can_initialize(self):
        with tempfile.TemporaryDirectory() as directory:
            self.assertIsNone(setup.load_existing(Path(directory) / 'missing', os.getuid()))

    def test_selects_only_unique_owned_calendar(self):
        selected = setup.choose_calendar({'calendars': [calendar(), calendar(name='Personal', uid='other')]})
        self.assertEqual(selected['uid'], 'test-uid')
        for calendars in ([], [calendar(), calendar(uid='duplicate')], [calendar(privilege='view')],
                          [calendar(category='app')], [calendar(timezone=None)],
                          [calendar(status=False)], [calendar(include_infreebusy=False)]):
            with self.subTest(calendars=calendars), self.assertRaises(setup.SetupError):
                setup.choose_calendar({'calendars': calendars})
        with self.assertRaises(setup.SetupError):
            setup.choose_calendar({'calendars': [calendar()]}, 'previous-id')

    def test_calendar_metadata_does_not_override_booking_timezone(self):
        for zone in ('Asia/Calcutta', 'UTC', 'America/Chicago', 'US/Central'):
            with self.subTest(zone=zone):
                self.assertEqual(setup.choose_calendar({'calendars': [calendar(timezone=zone)]})['timezone'], zone)

    def test_actual_utc_event_response(self):
        self.assertEqual(setup.event_records({'events': [timed_event()]}), [timed_event()])

    def test_observed_all_day_dates_without_timezone(self):
        for fields in ({'timezone': None}, {}):
            event = {'isallday': True, 'dateandtime': {'start': '20260930', 'end': '20261001', **fields}}
            with self.subTest(fields=fields):
                self.assertEqual(setup.event_records({'events': [event]}), [event])

    def test_empty_calendar_message(self):
        self.assertEqual(setup.event_records({'events': [{'message': 'No events found.'}]}), [])
        self.assertEqual(setup.event_records({'events': []}), [])

    def test_unknown_or_incomplete_response_is_not_empty(self):
        sentinel = {'message': 'No events found.'}
        for body in ({}, {'events': None}, {'events': [None]}, {'events': [{'message': 'Permission denied'}]},
                     {'events': [sentinel, timed_event()]}, {'events': [sentinel, sentinel]},
                     {'events': [sentinel], 'error': 'denied'}, {'events': [sentinel], 'next_page_token': 'more'},
                     {'events': [dict(timed_event(), message='Partial data')]}):
            with self.subTest(body=body), self.assertRaises(setup.SetupError):
                setup.event_records(body)

    def test_invalid_event_times_do_not_pass_connection_check(self):
        for start, end in (('invalid', 'invalid'), ('20260230T140000+0000', '20260301T150000+0000'),
                           ('20260929T150000+0000', '20260929T140000+0000'),
                           ('20260929T140000', '20260929T150000')):
            event = timed_event()
            event['dateandtime'].update(start=start, end=end)
            with self.subTest(start=start), self.assertRaises(setup.SetupError):
                setup.event_records({'events': [event]})

    def test_rejects_untrusted_host_and_calendar_post(self):
        for url in ('https://example.com/api', 'http://calendar.zoho.com/api', 'https://calendar.zoho.com.evil.test/api'):
            with self.subTest(url=url), self.assertRaises(setup.SetupError):
                setup.request_json(url, token='secret')
        with self.assertRaises(setup.SetupError):
            setup.request_json(setup.CALENDAR + '/api/v1/calendars', form={'private': 'secret'})
        with self.assertRaises(setup.SetupError):
            setup.request_json(setup.ACCOUNTS + '/oauth/v2/token', token='secret')

    def test_secret_uses_hidden_input(self):
        with patch.object(setup.getpass, 'getpass', return_value='secret-test-value') as prompt:
            self.assertEqual(setup.secret('Client Secret: '), 'secret-test-value')
            prompt.assert_called_once_with('Client Secret: ')
        with patch.object(setup.getpass, 'getpass', return_value='unsafe\nvalue'):
            with self.assertRaises(setup.SetupError):
                setup.secret('Client Secret: ')

    def test_network_errors_do_not_expose_tokens(self):
        error = urllib.error.HTTPError(setup.CALENDAR, 401, 'secret-test-value', {}, None)
        with patch.object(setup.urllib.request, 'build_opener') as build:
            build.return_value.open.side_effect = error
            with self.assertRaises(setup.SetupError) as caught:
                setup.request_json(setup.CALENDAR + '/api/v1/calendars', token='secret-test-value')
        self.assertNotIn('secret-test-value', str(caught.exception))
        self.assertIn('401', str(caught.exception))

    def test_request_credentials_stay_out_of_url(self):
        with patch.object(setup.urllib.request, 'build_opener') as build:
            reply = build.return_value.open.return_value.__enter__.return_value
            reply.status = 200
            reply.read.return_value = b'{"access_token":"fake"}'
            result = setup.request_json(setup.ACCOUNTS + '/oauth/v2/token', form={'client_secret': 'secret-test-value'})
            request = build.return_value.open.call_args.args[0]
            self.assertNotIn('secret-test-value', request.full_url)
            self.assertIn(b'secret-test-value', request.data)
            self.assertEqual(result, {'access_token': 'fake'})

    def test_setup_preserves_credentials_on_failure_then_resumes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            path = root / 'calendar.json'
            account = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
            tokens = {'access_token': 'first-token', 'refresh_token': 'saved-refresh'}
            output = io.StringIO()
            with patch.object(setup, 'ROOT', root), patch.object(setup, 'CONFIG', path), \
                 patch.object(setup.os, 'geteuid', return_value=0), \
                 patch.object(setup.pwd, 'getpwnam', return_value=account), \
                 patch.object(setup, 'secret', side_effect=['test-client', 'secret-test-value', 'short-code']), \
                 patch.object(setup, 'request_json', side_effect=[tokens, {'access_token': 'refreshed'}, setup.SetupError('Calendar unavailable')]), \
                 contextlib.redirect_stdout(output):
                with self.assertRaises(setup.SetupError):
                    setup.main()
            saved = json.loads(path.read_text())
            self.assertEqual(saved['refresh_token'], 'saved-refresh')
            self.assertFalse(saved['enabled'])
            self.assertIsNone(saved['connection_verified_at'])
            self.assertNotIn('short-code', path.read_text())
            replies = [{'access_token': 'refreshed'}, {'calendars': [calendar(timezone='Asia/Calcutta')]}, {'events': [timed_event()]}]
            with patch.object(setup, 'ROOT', root), patch.object(setup, 'CONFIG', path), \
                 patch.object(setup.os, 'geteuid', return_value=0), \
                 patch.object(setup.pwd, 'getpwnam', return_value=account), \
                 patch.object(setup, 'secret') as prompt, \
                 patch.object(setup, 'request_json', side_effect=replies), contextlib.redirect_stdout(output):
                setup.main()
                prompt.assert_not_called()
            finished = json.loads(path.read_text())
            self.assertEqual(finished['calendar_uid'], 'test-uid')
            self.assertEqual(finished['calendar_timezone'], 'Asia/Calcutta')
            self.assertEqual(finished['timezone'], 'America/Chicago')
            self.assertEqual(finished['refresh_token'], 'saved-refresh')
            self.assertFalse(finished['enabled'])
            self.assertIsInstance(finished['connection_verified_at'], int)
            self.assertNotIn('saved-refresh', output.getvalue())
            self.assertNotIn('secret-test-value', output.getvalue())
            self.assertNotIn('private-test-event', output.getvalue())


if __name__ == '__main__':
    unittest.main()
