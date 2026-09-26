import importlib.util
from pathlib import Path
import json
import unittest

spec = importlib.util.spec_from_file_location('finish', Path(__file__).parents[1] / 'tools/finish-booking-communications.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class Provider:
    def __init__(self):
        self.rows = {}
        self.calls = []
        self.contacts = [{'id': '123456', 'name': 'David J Cro', 'email': module.RECIPIENT, 'account': 'SiteSee'}]
        self.crm_failure = False
        self.send_timeout = False
        self.delivery_delay = 0
        self.invalid_report = False
        self.link_failure = False

    def row(self, key, sent=True):
        return {'communication_key': key, 'submission_state': 'sent_observed' if sent else 'accepted',
                'delivery_state': 'unverified', 'crm_state': 'pending', 'provider_message_id': 'saved-id'}

    def __call__(self, *args):
        self.calls.append(args)
        action = args[0]
        if action == 'lookup':
            return 0, '\n'.join(json.dumps(c) for c in self.contacts), ''
        if action == 'report':
            return 0, 'invalid' if self.invalid_report else json.dumps(list(self.rows.values())), ''
        if action == 'link' and self.link_failure:
            return 1, '', 'STOP: Contact changed.'
        if action == 'import-original':
            self.rows[module.ORIGINAL] = self.row(module.ORIGINAL)
        if action == 'send-probe':
            self.rows[module.PROBE] = self.row(module.PROBE, False)
            if self.send_timeout:
                self.rows[module.PROBE].update(submission_state='uncertain', provider_message_id=None)
                return 1, '', 'STOP: Submission uncertain.'
        if action == 'recheck':
            self.rows[args[1]]['submission_state'] = 'sent_observed'
        if action == 'delivery':
            if args[1] == module.PROBE and self.delivery_delay:
                self.delivery_delay -= 1
                return 1, '', 'STOP: Not yet observed.'
            self.rows[args[1]]['delivery_state'] = 'recipient_copy_observed'
        if action == 'crm':
            self.rows[args[1]]['crm_state'] = 'retry_pending' if self.crm_failure else 'associated'
            if self.crm_failure:
                return 1, '', 'STOP: Mail remains sent. Retry CRM only.'
        return 0, '', ''


class FinishTest(unittest.TestCase):
    def runner(self, provider, answer=None):
        return module.Finish(run=provider, ask=answer or (lambda _: ''), emit=lambda *a, **k: None, pause=lambda _: None)

    def test_success_then_resume_sends_once(self):
        provider = Provider()
        self.assertEqual(self.runner(provider).finish(enable=True), 0)
        self.assertEqual(self.runner(provider).finish(enable=True), 0)
        self.assertEqual(sum(c[0] == 'send-probe' for c in provider.calls), 1)
        self.assertEqual(sum(c[0] == 'import-original' for c in provider.calls), 1)

    def test_missing_contact_stops_before_any_write(self):
        provider = Provider()
        provider.contacts = []
        with self.assertRaises(module.Stop):
            self.runner(provider, lambda _: 'q').finish(enable=True)
        self.assertEqual([c[0] for c in provider.calls], ['lookup'])

    def test_contact_added_during_same_session(self):
        provider = Provider()
        contact = provider.contacts[0]
        provider.contacts = []
        def answer(prompt):
            provider.contacts = [contact]
            return ''
        self.assertEqual(self.runner(provider, answer).finish(enable=True), 0)
        self.assertEqual(sum(c[0] == 'lookup' for c in provider.calls), 2)

    def test_multiple_contacts_require_explicit_choice(self):
        provider = Provider()
        provider.contacts.append(dict(provider.contacts[0], id='999999'))
        self.runner(provider, lambda _: '2').finish()
        self.assertIn(('link', module.REFERENCE, '999999'), provider.calls)
        self.assertNotIn(('link', module.REFERENCE, '123456'), provider.calls)

    def test_link_failure_blocks_send(self):
        provider = Provider()
        provider.link_failure = True
        with self.assertRaises(module.Stop):
            self.runner(provider).finish(enable=True)
        self.assertFalse(any(c[0] in ('send-probe', 'enable') for c in provider.calls))

    def test_crm_failure_collects_both_results_without_activation(self):
        provider = Provider()
        provider.crm_failure = True
        self.assertEqual(self.runner(provider).finish(enable=True), 2)
        self.assertTrue(all(r['delivery_state'] == 'recipient_copy_observed' for r in provider.rows.values()))
        self.assertEqual(sum(c[0] == 'crm' for c in provider.calls), 2)
        self.assertFalse(any(c[0] == 'enable' for c in provider.calls))

    def test_uncertain_send_never_resubmitted_on_resume(self):
        provider = Provider()
        provider.send_timeout = True
        self.assertEqual(self.runner(provider).finish(enable=True), 2)
        self.assertEqual(self.runner(provider).finish(enable=True), 2)
        self.assertEqual(sum(c[0] == 'send-probe' for c in provider.calls), 1)
        self.assertFalse(any(c[0] == 'enable' for c in provider.calls))

    def test_delivery_lag_rechecks_without_duplicate_send_or_crm(self):
        provider = Provider()
        provider.delivery_delay = 2
        self.assertEqual(self.runner(provider).finish(enable=True), 0)
        self.assertEqual(sum(c == ('delivery', module.PROBE) for c in provider.calls), 3)
        self.assertEqual(sum(c == ('crm', module.PROBE) for c in provider.calls), 1)
        self.assertEqual(sum(c[0] == 'send-probe' for c in provider.calls), 1)

    def test_malformed_progress_blocks_send(self):
        provider = Provider()
        provider.invalid_report = True
        with self.assertRaises(module.Stop):
            self.runner(provider).finish(enable=True)
        self.assertFalse(any(c[0] in ('send-probe', 'enable') for c in provider.calls))

    def test_wrong_email_blocks_link(self):
        provider = Provider()
        provider.contacts[0]['email'] = 'other@example.test'
        with self.assertRaises(module.Stop):
            self.runner(provider).finish(enable=True)
        self.assertFalse(any(c[0] == 'link' for c in provider.calls))

    def test_review_candidate_blocks_activation_and_insertion(self):
        provider = Provider()
        provider.rows[module.PROBE] = provider.row(module.PROBE)
        provider.rows[module.PROBE]['crm_state'] = 'existing_candidate_review'
        self.assertEqual(self.runner(provider).finish(enable=True), 2)
        self.assertNotIn(('crm', module.PROBE), provider.calls)
        self.assertFalse(any(c[0] in ('send-probe', 'enable') for c in provider.calls))


if __name__ == '__main__':
    unittest.main()
