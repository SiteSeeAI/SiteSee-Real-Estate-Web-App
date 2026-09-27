import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('calendar_check', Path(__file__).resolve().parents[1] / 'tools/check-microsoft-calendar.py')
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)


class CalendarCheckTest(unittest.TestCase):
    def calendar(self):
        return {'id': 'default-id', 'name': 'Calendar', 'owner': {'address': check.MAILBOX}, 'canEdit': True}

    def reader(self, next_link=None, denied=False):
        def get(path):
            if denied:
                return 403, {'error': {'message': 'PRIVATE PROVIDER DETAIL'}}
            if '/calendarView?' in path:
                return 200, {'value': [{'id': 'private-event-id'}], '@odata.nextLink': 'not-followed'}
            if '/calendars?' in path:
                result = {'value': [self.calendar()]}
                if next_link:
                    result['@odata.nextLink'] = next_link
                return 200, result
            return 200, self.calendar()
        return get

    def test_read_success_is_not_write_or_migration_success(self):
        output = []
        check.inspect_calendar(self.reader(), output.append)
        text = '\n'.join(output)
        self.assertIn('metadata and inventory reads: PASS', text)
        self.assertIn('Effective calendar write permission: NOT TESTED', text)
        self.assertIn('Calendar migration: NOT ENABLED', text)
        self.assertNotIn('private-event-id', text)

    def test_denial_is_not_treated_as_empty_calendar(self):
        with self.assertRaisesRegex(check.CheckError, 'permission or mailbox access was denied'):
            check.inspect_calendar(self.reader(denied=True), lambda text: None)

    def test_token_post_and_only_three_graph_gets(self):
        requests = []
        output = []
        secret = {'tenant_id': '11111111-1111-1111-1111-111111111111',
                  'client_id': '22222222-2222-2222-2222-222222222222', 'client_secret': 'SECRET_SENTINEL'}
        def transport(method, url, headers, body):
            requests.append((method, url, body))
            if method == 'POST':
                self.assertTrue(url.startswith('https://login.microsoftonline.com/'))
                return 200, {'access_token': 'TOKEN_SENTINEL'}
            self.assertEqual(headers['Authorization'], 'Bearer TOKEN_SENTINEL')
            self.assertIsNone(body)
            return self.reader()(check.graph_path(url))
        get = check.graph_reader(secret, transport)
        check.inspect_calendar(get, output.append, dt.datetime(2026, 9, 27, tzinfo=dt.timezone.utc))
        self.assertEqual([x[0] for x in requests], ['POST', 'GET', 'GET', 'GET'])
        self.assertNotIn('SECRET_SENTINEL', '\n'.join(output))
        self.assertNotIn('TOKEN_SENTINEL', '\n'.join(output))

    def test_cross_host_and_other_mailbox_refused_before_get(self):
        for next_link in ['https://example.com/token',
                          'https://graph.microsoft.com/v1.0/users/cro@sitesee.ai/calendars',
                          'https://graph.microsoft.com/v1.0/users/sales@re.sitesee.ai/messages']:
            with self.subTest(next_link=next_link), self.assertRaises(check.CheckError):
                check.inspect_calendar(self.reader(next_link=next_link), lambda text: None)

    def test_repeated_pagination_stops(self):
        next_link = 'https://graph.microsoft.com' + check.BASE + '/calendars?$skiptoken=repeat'
        with self.assertRaisesRegex(check.CheckError, 'repeated a continuation'):
            check.inspect_calendar(self.reader(next_link=next_link), lambda text: None)

    def test_legitimate_paging_with_at_sign(self):
        seen = []
        def get(path):
            seen.append(path)
            if '$skiptoken=next' in path:
                return 200, {'value': [self.calendar()]}
            if '/calendars?' in path:
                return 200, {'value': [], '@odata.nextLink': 'https://graph.microsoft.com/v1.0/users/sales@re.sitesee.ai/calendars?$skiptoken=next'}
            return self.reader()(path)
        check.inspect_calendar(get, lambda text: None)
        self.assertEqual(len(seen), 4)

    def test_private_file_checks(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'config.json'
            path.write_text(json.dumps({'a': 'b'}))
            path.chmod(0o600)
            self.assertEqual(check.private_json(path, os.getuid()), {'a': 'b'})
            path.chmod(0o640)
            with self.assertRaises(check.CheckError):
                check.private_json(path, os.getuid())
            path.chmod(0o600)
            link = Path(folder) / 'symlink.json'
            link.symlink_to(path)
            with self.assertRaises(OSError):
                check.private_json(link, os.getuid())
            hardlink = Path(folder) / 'hardlink.json'
            os.link(path, hardlink)
            with self.assertRaises(check.CheckError):
                check.private_json(path, os.getuid())

    def test_owner_alias_is_flagged_not_assumed_verified(self):
        def get(path):
            status, data = self.reader()(path)
            if '/calendar?' in path:
                data['owner']['address'] = 'another-primary@sitesee.ai'
            return status, data
        output = []
        check.inspect_calendar(get, output.append)
        self.assertIn('verify the mailbox/alias identity', '\n'.join(output))

    def test_redirect_is_not_followed(self):
        with self.assertRaises(check.CheckError):
            check.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://example.com/')

    def test_failed_auth_does_not_reveal_response(self):
        secret = {'tenant_id': '11111111-1111-1111-1111-111111111111',
                  'client_id': '22222222-2222-2222-2222-222222222222', 'client_secret': 'SECRET_SENTINEL'}
        with self.assertRaises(check.CheckError) as error:
            check.graph_reader(secret, lambda *args: (400, {'error_description': 'SECRET_SENTINEL'}))
        self.assertNotIn('SECRET_SENTINEL', str(error.exception))


if __name__ == '__main__':
    unittest.main()
