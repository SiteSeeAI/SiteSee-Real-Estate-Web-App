import base64
import contextlib
import datetime as dt
import importlib.util
import io
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('audit', Path(__file__).resolve().parents[1] / 'tools/check-booking-mail-integration.py')
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class AuditTests(unittest.TestCase):
    def setUp(self):
        audit.SECRETS[:] = []
        self.when = dt.datetime(2026, 9, 26, 16, 31, 34, tzinfo=dt.timezone.utc)

    def test_transport_refuses_writes_and_other_hosts_before_network(self):
        with patch.object(audit.urllib.request, 'build_opener') as opener:
            for url, token, form in [
                (audit.GRAPH + '/users/' + audit.SENDER + '/sendMail', 'token', {'message': 'x'}),
                ('https://evil.example/v1.0/users/x/messages', 'token', None),
                ('http://graph.microsoft.com/v1.0/users/x/messages', 'token', None),
                ('https://graph.microsoft.com.evil.example/v1.0/users/x/messages', 'token', None),
                ('https://login.microsoftonline.com/common/oauth2/v2.0/token', None, {}),
                (audit.GRAPH + '/users/x/messages#fragment', 'token', None),
            ]:
                with self.assertRaises(audit.CheckError):
                    audit.request(url, token, form)
            opener.assert_not_called()

    def test_redirects_are_refused(self):
        with self.assertRaises(audit.CheckError):
            audit.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://evil.example/')

    def test_secret_redaction(self):
        audit.SECRETS.extend(['fixture-secret', 'fixture-token'])
        self.assertEqual(audit.safe('fixture-secret\nfixture-token'), '[REDACTED] [REDACTED]')

    def test_private_file_permissions_and_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / 'config.json'
            file.write_text(json.dumps({'client_secret': 'fixture-secret'}))
            file.chmod(0o644)
            with self.assertRaises(audit.CheckError):
                audit.private_json(file, os.getuid())
            file.chmod(0o600)
            self.assertEqual(audit.private_json(file, os.getuid())['client_secret'], 'fixture-secret')
            self.assertIn('fixture-secret', audit.SECRETS)
            link = Path(tmp) / 'link.json'
            link.symlink_to(file)
            with self.assertRaises(OSError):
                audit.private_json(link, os.getuid())

    def test_oauth_error_body_is_not_disclosed(self):
        class Opener:
            def open(self, req, timeout):
                body = json.dumps({'error': 'invalid_client', 'error_description': 'private-sensitive-detail'}).encode()
                raise audit.urllib.error.HTTPError(req.full_url, 401, '', {}, io.BytesIO(body))
        with patch.object(audit.urllib.request, 'build_opener', return_value=Opener()):
            with self.assertRaisesRegex(audit.CheckError, r'HTTP 401 \(invalid_client\)') as failure:
                audit.request(audit.user_url(audit.SENDER, '/messages'), 'token')
            self.assertNotIn('private-sensitive-detail', str(failure.exception))

    def test_complete_pagination_and_exact_subject(self):
        following = audit.user_url(audit.SENDER, '/mailFolders/sentitems/messages?$skiptoken=opaque')
        responses = [
            {'value': [{'subject': 'unrelated'}, {'subject': audit.SUBJECT}], '@odata.nextLink': following},
            {'value': [{'subject': audit.SUBJECT}, {'subject': 'Re: ' + audit.SUBJECT}]},
        ]
        with patch.object(audit, 'request', side_effect=responses) as req:
            matches = audit.matching_messages('token', audit.SENDER, 'sentitems', self.when, audit.SUBJECT)
            self.assertEqual(len(matches), 2)
            self.assertEqual(req.call_args_list[1].args[0], following)

    def test_cross_mailbox_pagination_rejected(self):
        for following in ['https://evil.example/', audit.user_url('unrelated@example.com', '/mailFolders/sentitems/messages')]:
            with patch.object(audit, 'request', return_value={'value': [], '@odata.nextLink': following}):
                with self.assertRaises(audit.CheckError):
                    audit.matching_messages('token', audit.SENDER, 'sentitems', self.when, audit.SUBJECT)

    def test_incomplete_scan_is_not_reported_as_no_message(self):
        following = audit.user_url(audit.SENDER, '/mailFolders/sentitems/messages?$skiptoken=opaque')
        with patch.object(audit, 'request', return_value={'value': [], '@odata.nextLink': following}):
            with self.assertRaisesRegex(audit.CheckError, 'incomplete'):
                audit.matching_messages('token', audit.SENDER, 'sentitems', self.when, audit.SUBJECT)

    def test_mime_detects_bridge_sender_organizer_mismatch(self):
        calendar = ('BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n'
                    'UID:sitesee-arrival-test-' + audit.REF + '@re.sitesee.ai\r\n'
                    'ORGANIZER;CN=SiteSee:mailto:sales@sitesee.ai\r\n'
                    'ATTENDEE;RSVP=TRUE:mailto:' + audit.RECIPIENT + '\r\n'
                    'DTSTART:20260930T120000Z\r\nDTEND:20260930T140000Z\r\n'
                    'DESCRIPTION:PRIVATE BODY MUST NOT BE PRINTED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n')
        raw = ('From: SiteSee <david@contentlabinc.com>\r\nReply-To: sales@sitesee.ai\r\n'
               'MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=test\r\n\r\n'
               '--test\r\nContent-Type: text/html\r\n\r\nPrivate body\r\n'
               '--test\r\nContent-Type: text/calendar; charset=UTF-8; method=REQUEST\r\n'
               'Content-Transfer-Encoding: base64\r\n\r\n' + base64.b64encode(calendar.encode()).decode()
               + '\r\n--test--\r\n').encode()
        result = audit.mime_summary(raw)
        self.assertEqual(len(result['calendar_parts']), 1)
        self.assertFalse(result['calendar_parts'][0]['sender_matches_organizer'])
        self.assertTrue(result['calendar_parts'][0]['booking_uid_matches'])
        self.assertTrue(result['calendar_parts'][0]['recipient_matches'])
        self.assertNotIn('PRIVATE BODY', json.dumps(result))
        raw = raw.replace(b'david@contentlabinc.com', b'sales@sitesee.ai')
        self.assertTrue(audit.mime_summary(raw)['calendar_parts'][0]['sender_matches_organizer'])

    def test_trace_rejects_other_recipient_and_draft(self):
        messages = [
            {'id': 'wrong-person', 'toRecipients': [{'emailAddress': {'address': 'someone@example.com'}}]},
            {'id': 'draft', 'isDraft': True, 'toRecipients': [{'emailAddress': {'address': audit.RECIPIENT}}]},
        ]
        out = io.StringIO()
        with patch.object(audit, 'matching_messages', side_effect=[messages, []]), patch.object(audit, 'request') as req, contextlib.redirect_stdout(out):
            audit.old_invitation_trace('token', audit.SENDER, {'invitation_attempted_at': self.when.isoformat()})
        req.assert_not_called()
        self.assertIn('Sent Items (' + audit.SENDER + '): 0', out.getvalue())
        self.assertNotIn('wrong-person', out.getvalue())

    def test_dsn_requires_exact_original_message_identity(self):
        raw = (b'MIME-Version: 1.0\r\nContent-Type: multipart/report; boundary=dsn\r\n\r\n'
               b'--dsn\r\nContent-Type: message/delivery-status\r\n\r\n'
               b'Reporting-MTA: dns; example.com\r\nOriginal-Message-ID: <original@example.com>\r\n\r\n'
               b'Final-Recipient: rfc822; cro@sitesee.ai\r\nAction: failed\r\nStatus: 5.7.1\r\n'
               b'Diagnostic-Code: smtp; 550 Authentication failure\r\n\r\n--dsn--\r\n')
        result = audit.delivery_status_summary(raw, {'<original@example.com>'})
        self.assertTrue(result['exact_sent_message_id_match'])
        self.assertEqual(result['delivery_status_blocks'][0]['status'], '5.7.1')
        self.assertFalse(audit.delivery_status_summary(raw, {'<other@example.com>'})['exact_sent_message_id_match'])

    def test_recipient_lookup_failure_does_not_claim_no_delivery(self):
        with patch.object(audit, 'request', side_effect=audit.CheckError('HTTP 404')):
            with self.assertRaises(audit.CheckError):
                audit.recipient_trace('token', {'invitation_attempted_at': self.when.isoformat()})

    def test_read_only_database_does_not_create_missing_db(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(audit, 'ROOT', Path(tmp)):
            (Path(tmp) / 'data').mkdir()
            with self.assertRaises(sqlite3.OperationalError):
                audit.booking()
            self.assertFalse((Path(tmp) / 'data/bookings.sqlite').exists())

    def test_one_failed_section_does_not_hide_other_findings(self):
        out = io.StringIO()
        with patch.object(audit, 'booking', side_effect=audit.CheckError('fixture')), \
             patch.object(audit, 'fpm_settings', side_effect=audit.CheckError('fixture')) as fpm, \
             patch.object(audit, 'bridge_details') as bridge, \
             patch.object(audit, 'graph_connection', side_effect=audit.CheckError('fixture')), \
             patch.object(audit, 'dns_checks') as dns, \
             patch.object(audit.os, 'geteuid', return_value=0), \
             patch.object(audit.pwd, 'getpwnam') as account, \
             patch.object(audit.sys, 'argv', ['audit']), contextlib.redirect_stdout(out):
            account.return_value.pw_uid = 1234
            audit.main()
        self.assertEqual(fpm.call_count, 1)
        self.assertEqual(bridge.call_count, 1)
        self.assertEqual(dns.call_count, 1)
        self.assertIn('0 sends; 0 drafts', out.getvalue())


if __name__ == '__main__':
    unittest.main()
