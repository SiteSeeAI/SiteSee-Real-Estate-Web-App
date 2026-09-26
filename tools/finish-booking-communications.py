#!/usr/bin/env python3
"""Run the installed, guarded booking commands in one supervised session."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time

ROOT = Path('/home/sitesee/.sitesee-real-estate')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
REFERENCE = 'E6E183EF8E'
RECIPIENT = 'cro@sitesee.ai'
ORIGINAL = 'original:' + REFERENCE
PROBE = 'probe:' + REFERENCE
HASHES = {
    'tools/booking-communications.php': 'bf8173d38bfa7f980cf8a6d7e8e09ee07f0efabbdc3a466c87f08c2e1877ad5b',
    'server/booking-crm.php': '42eb3369d40e1fc37712413cde685f76cdf72dac5329b199ac22c6ef42877c39',
    'server/booking-communication.php': 'd2fb1b321f23b73e5c0e8db60426c36429a0ef7b4dd855098dfafaebbc91b254',
    'server/booking-mail-client.php': '189e15f44c3c28856765527efdcf25b71f748add07d6e7d31c396d50d3ed5e5b',
}


class Stop(Exception):
    pass


def clean(value):
    return ''.join(c if c.isprintable() else ' ' for c in str(value))[:700]


def command(*args):
    result = subprocess.run(
        ['runuser', '-u', 'sitesee', '--', PHP,
         str(ROOT / 'tools/booking-communications.php'), *args],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True,
        check=False,
    )
    return result.returncode, result.stdout, result.stderr


class Finish:
    def __init__(self, run=command, ask=input, emit=print, pause=time.sleep):
        self.run, self.ask, self.emit, self.pause = run, ask, emit, pause
        self.problems = {}

    def call(self, label, *args, required=False):
        self.emit(label + '...', flush=True)
        code, output, error = self.run(*args)
        if code:
            detail = clean(error.strip() or 'Installed command did not complete.')
            self.problems[label] = detail
            self.emit('  ' + detail, flush=True)
            if required:
                raise Stop(label + ' failed. No later sending or activation was attempted.')
            return None
        self.problems.pop(label, None)
        return output

    def report(self):
        code, output, error = self.run('report', REFERENCE)
        if code:
            raise Stop('Cannot read saved progress: ' + clean(error))
        try:
            rows = json.loads(output)
            if not isinstance(rows, list):
                raise ValueError()
            result = {}
            for row in rows:
                key = row['communication_key']
                if key in result or not isinstance(row, dict):
                    raise ValueError()
                result[key] = row
            return result
        except (ValueError, TypeError, KeyError):
            raise Stop('Saved progress could not be interpreted. Sending is blocked.')

    def choose_contact(self):
        while True:
            output = self.call('Finding your test Contact', 'lookup', REFERENCE, required=True)
            contacts = []
            for line in output.splitlines():
                if line.startswith('{'):
                    try:
                        item = json.loads(line)
                        if str(item['email']).strip().lower() != RECIPIENT or not str(item['id']).isdigit():
                            raise ValueError()
                        contacts.append(item)
                    except (ValueError, KeyError, TypeError):
                        raise Stop('Contact result could not be verified. Sending is blocked.')
            if not contacts:
                self.emit('\nNo Contact with primary Email cro@sitesee.ai was returned.\n'
                          'In Zoho CRM > Contacts, search for cro@sitesee.ai and David J Cro.\n'
                          'Use the correct existing Contact. If none exists, create a Contact:\n'
                          'First Name: David J | Last Name: Cro | Email: cro@sitesee.ai\n'
                          'This is a customer Contact, not a licensed user or mailbox.\n'
                          'Save it, then return here. No credentials are needed.', flush=True)
                answer = self.ask('Press Enter to search again, or type q to stop: ').strip()
                if answer.lower() == 'q':
                    raise Stop('Stopped before linking or sending.')
                continue
            for number, item in enumerate(contacts, 1):
                self.emit('{}: {} | {} | {} | ID {}'.format(
                    number, clean(item.get('name', '')), RECIPIENT,
                    clean(item.get('account') or 'No company'), item['id']), flush=True)
            answer = self.ask('Choose the correct Contact number{} (q stops): '.format(
                '; Enter selects 1' if len(contacts) == 1 else '')).strip()
            if answer.lower() == 'q':
                raise Stop('Stopped before linking or sending.')
            if not answer and len(contacts) == 1:
                answer = '1'
            if not answer.isdigit() or not 1 <= int(answer) <= len(contacts):
                self.emit('Choose one of the displayed numbers.', flush=True)
                continue
            contact = str(contacts[int(answer) - 1]['id'])
            self.call('Linking the verified Contact', 'link', REFERENCE, contact, required=True)
            return

    @staticmethod
    def complete(row):
        return bool(row and row.get('submission_state') == 'sent_observed'
                    and row.get('delivery_state') == 'recipient_copy_observed'
                    and row.get('crm_state') == 'associated')

    def check_message(self, key, title, retry_crm=False):
        row = self.report().get(key)
        if not row:
            return
        if row.get('submission_state') != 'sent_observed' and row.get('provider_message_id'):
            self.call(title + ' sent copy', 'recheck', key)
            row = self.report()[key]
        if row.get('submission_state') != 'sent_observed':
            return
        if row.get('delivery_state') != 'recipient_copy_observed':
            self.call(title + ' recipient delivery', 'delivery', key)
        # Provider duplicate/candidate review and uncertain associations require inspection.
        if retry_crm and row.get('crm_state') in ('pending', 'retry_pending', 'awaiting_native_sync'):
            self.call(title + ' CRM history', 'crm', key)

    def finish(self, enable=False):
        self.choose_contact()
        rows = self.report()
        if ORIGINAL not in rows:
            self.call('Importing the existing invitation', 'import-original', REFERENCE)
        self.check_message(ORIGINAL, 'Original invitation', retry_crm=True)
        rows = self.report()
        if PROBE not in rows:
            self.call('Sending ONE plain test email to ' + RECIPIENT,
                      'send-probe', REFERENCE, RECIPIENT)
        else:
            self.emit('A test message is already tracked. Checking its saved progress.', flush=True)
        # Recheck delivery for up to 30 seconds. CRM is attempted once per message per run.
        crm_checked = False
        for attempt in range(4):
            row = self.report().get(PROBE)
            if not row:
                break
            self.check_message(PROBE, 'Test email', retry_crm=not crm_checked)
            row = self.report()[PROBE]
            if row.get('submission_state') == 'sent_observed':
                crm_checked = True
            if self.complete(row) or (row.get('submission_state') == 'sent_observed'
                                     and row.get('delivery_state') == 'recipient_copy_observed'):
                break
            if not row.get('provider_message_id'):
                break
            if attempt < 3:
                self.emit('Waiting 10 seconds for the saved message to appear...', flush=True)
                self.pause(10)
        rows = self.report()
        ready = self.complete(rows.get(ORIGINAL)) and self.complete(rows.get(PROBE))
        activated = False
        if ready and enable:
            activated = self.call('Enabling the verified booking sender', 'enable', PROBE) is not None
        self.emit('\nFINAL RESULTS', flush=True)
        for key, title in ((ORIGINAL, 'Original invitation'), (PROBE, 'Test email')):
            row = rows.get(key)
            if not row:
                self.emit(title + ': no saved communication.', flush=True)
                continue
            self.emit('{}: sent={} | delivery={} | CRM={}'.format(
                title, row.get('submission_state'), row.get('delivery_state'), row.get('crm_state')), flush=True)
            if row.get('crm_error'):
                self.emit('  ' + clean(row['crm_error']), flush=True)
        self.emit('Activation: ' + ('verified and enabled' if activated else 'not performed by this run'), flush=True)
        if self.problems:
            self.emit('Command diagnostics:', flush=True)
            for label, detail in self.problems.items():
                self.emit('  ' + label + ': ' + detail, flush=True)
        self.emit('No appointment or payment was created or changed. No invitation was resent.', flush=True)
        if not ready or (enable and not activated):
            self.emit('Paste FINAL RESULTS and diagnostics into the conversation.\n'
                      'This same command can resume saved work; it does not submit an existing test again.', flush=True)
            return 2
        self.emit('Transport and CRM checks passed. A fresh booking still needs the full calendar invitation test.', flush=True)
        return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--send-probe-to', required=True, choices=[RECIPIENT],
                        help='Explicitly authorize one plain test email to this mailbox.')
    parser.add_argument('--enable-after-pass', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise Stop('Run from the root WHM Terminal. Database commands switch to sitesee automatically.')
    for relative, expected in HASHES.items():
        path = ROOT / relative
        if path.is_symlink() or not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != expected:
            raise Stop('Installed booking file differs from the verified version: ' + relative)
    if not Path(PHP).is_file():
        raise Stop('The expected server PHP executable is unavailable.')
    flags = os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0)
    with os.fdopen(os.open('/run/lock/sitesee-booking-comms-finish.lock', flags, 0o600), 'w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('Another combined run is active. Allow that run to finish.')
        print('This run records email history and sends at most one plain test email\n'
              'from sales@re.sitesee.ai to cro@sitesee.ai. No credentials are requested.', flush=True)
        return Finish().finish(enable=args.enable_after_pass)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (Stop, OSError, EOFError) as error:
        print('STOP: ' + clean(error), file=sys.stderr)
        sys.exit(1)
    except KeyboardInterrupt:
        print('\nStopped. Preserve saved progress; resume with this same command.', file=sys.stderr)
        sys.exit(1)
