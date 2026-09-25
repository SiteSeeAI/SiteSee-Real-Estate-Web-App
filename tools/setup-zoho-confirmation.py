#!/usr/bin/env python3
"""Add a separate, default-off test writer without changing active read credentials."""
import argparse
import copy
import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import pwd
import stat
import sys
import time
import urllib.parse

spec = importlib.util.spec_from_file_location('calendar_setup', Path(__file__).with_name('setup-zoho-calendar.py'))
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
ROOT = Path('/home/sitesee/.sitesee-real-estate')
READER = ROOT / 'zoho-calendar.json'
WRITER = ROOT / 'zoho-confirmation.json'
SCOPES = ['ZohoCalendar.calendar.READ', 'ZohoCalendar.event.READ', 'ZohoCalendar.event.CREATE']


def load_private(path, uid, optional=False):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        if optional:
            return None, None
        raise base.SetupError('The active calendar configuration was not found.') from None
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1 or info.st_size > 32768:
            raise base.SetupError('Unexpected private configuration ownership, type or permissions; left untouched.')
        raw = os.read(fd, 32769)
    finally:
        os.close(fd)
    data = json.loads(raw)
    if not isinstance(data, dict) or data.get('schema_version') != 1 or data.get('setup') != 'sitesee-calendar-readonly':
        raise base.SetupError('Unrecognized private configuration; left untouched.')
    if data.get('accounts_base') != base.ACCOUNTS or data.get('calendar_base') != base.CALENDAR or data.get('timezone') != 'America/Chicago':
        raise base.SetupError('Calendar endpoints or booking timezone do not match the existing integration.')
    for key in ('client_id', 'client_secret', 'refresh_token', 'calendar_uid'):
        value = data.get(key)
        if not isinstance(value, str) or not value or len(value) > 4096 or any(ord(c) < 33 or ord(c) > 126 for c in value):
            raise base.SetupError('Private connection fields are incomplete.')
    return data, raw


def verify_connection(config, reader):
    tokens = base.request_json(base.ACCOUNTS + '/oauth/v2/token', form={
        'client_id': config['client_id'], 'client_secret': config['client_secret'],
        'refresh_token': config['refresh_token'], 'grant_type': 'refresh_token'})
    token = base.access_token(tokens)
    calendars = base.request_json(base.CALENDAR + '/api/v1/calendars?category=own&showhiddencal=true', token=token)
    calendar = base.choose_calendar(calendars, reader['calendar_uid'])
    if reader.get('calendar_owner_id') and str(calendar.get('owner', '')) != reader['calendar_owner_id']:
        raise base.SetupError('The calendar account owner changed; stopped.')
    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
    query = urllib.parse.urlencode({'range': json.dumps({'start': now.strftime('%Y%m%dT%H%M%SZ'),
        'end': (now + dt.timedelta(days=2)).strftime('%Y%m%dT%H%M%SZ')}), 'byinstance': 'true', 'timezone': 'UTC'})
    events = base.request_json(base.CALENDAR + '/api/v1/calendars/' + urllib.parse.quote(reader['calendar_uid'], safe='') + '/events?' + query, token=token)
    base.event_records(events)
    return calendar


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--enable-calendar', action='store_true')
    group.add_argument('--enable-invitations', action='store_true')
    group.add_argument('--disable', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise base.SetupError('Run in WHM Terminal as root.')
    account = pwd.getpwnam('sitesee')
    info = ROOT.stat()
    if ROOT.resolve() != ROOT or info.st_uid != account.pw_uid or info.st_mode & 0o022:
        raise base.SetupError('The private application directory has unexpected ownership or permissions.')
    os.umask(0o077)
    reader, reader_raw = load_private(READER, account.pw_uid)
    if reader.get('enabled') is not True and not args.disable:
        raise base.SetupError('Expected the already-verified availability connection to remain enabled.')
    config, writer_raw = load_private(WRITER, account.pw_uid, optional=True)
    if config is None:
        if args.enable_calendar or args.enable_invitations or args.disable:
            raise base.SetupError('Run connection setup first.')
        print('The active read-only connection will remain unchanged.')
        print('Use the SAME Zoho Self Client and the SAME calendar-owning account.')
        email = input('Your authorized test recipient email (use your own mailbox): ').strip()
        import re
        if not re.fullmatch(r'[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}', email) or len(email) > 254:
            raise base.SetupError('Enter one valid test email address.')
        print('Generate Code scope: ' + ','.join(SCOPES))
        print('Duration: 10 minutes. Description: SiteSee test appointment creation.')
        code = base.secret('Paste the new authorization code here (hidden input): ')
        tokens = base.request_json(base.ACCOUNTS + '/oauth/v2/token', form={
            'client_id': reader['client_id'], 'client_secret': reader['client_secret'],
            'grant_type': 'authorization_code', 'code': code})
        base.access_token(tokens)
        refresh = tokens.get('refresh_token')
        if not isinstance(refresh, str) or not refresh or any(ord(c) < 33 or ord(c) > 126 for c in refresh):
            raise base.SetupError('No valid durable writer token was returned. The active connection is unchanged.')
        scope = tokens.get('scope')
        if scope is not None and not set(SCOPES).issubset(set(re.split(r'[ ,]+', scope))):
            raise base.SetupError('The new token does not include all requested scopes.')
        config = copy.deepcopy(reader)
        config.update(refresh_token=refresh, requested_scopes=SCOPES, confirmation_stage='test',
            confirmation_enabled=False, invitations_enabled=False, test_recipient_email=email,
            connection_verified_at=None)
        base.atomic_save(WRITER, config, info.st_uid, info.st_gid)
        writer_raw = WRITER.read_bytes()
        print('Separate writer credentials saved privately. Checking account and read access...')
    elif config.get('confirmation_stage') != 'test' or config.get('calendar_uid') != reader['calendar_uid'] or config.get('requested_scopes') != SCOPES:
        raise base.SetupError('The existing writer does not match this calendar test stage; left untouched.')
    if args.disable:
        config.update(confirmation_enabled=False, invitations_enabled=False)
    else:
        calendar = verify_connection(config, reader)
        config.update(calendar_owner_id=str(calendar.get('owner', '')), connection_verified_at=int(time.time()))
        if args.enable_calendar:
            config['confirmation_enabled'] = True
        if args.enable_invitations:
            if config.get('confirmation_enabled') is not True:
                raise base.SetupError('Verify calendar creation before enabling invitation tests.')
            config['invitations_enabled'] = True
    if READER.read_bytes() != reader_raw or WRITER.read_bytes() != writer_raw:
        raise base.SetupError('Configuration changed during this check. No activation change was saved.')
    base.atomic_save(WRITER, config, info.st_uid, info.st_gid)
    if READER.read_bytes() != reader_raw:
        raise base.SetupError('The active reader changed concurrently. Inspect its configuration.')
    print('Dedicated calendar identity and read access: PASS' if not args.disable else 'Test controls disabled: PASS')
    print('Active availability credentials and settings unchanged: PASS')
    print('Writer owner and permissions preserved (sitesee, 0600): PASS')
    print('Calendar confirmation: ' + ('ENABLED' if config.get('confirmation_enabled') else 'DISABLED'))
    print('Test invitations: ' + ('ENABLED' if config.get('invitations_enabled') else 'DISABLED'))
    print('Create permission must be verified by the controlled staff booking test.')
    print('No events, invitations, payments or existing mail settings were changed.')


if __name__ == '__main__':
    try:
        main()
    except base.SetupError as error:
        print('STOP: ' + str(error), file=sys.stderr)
        sys.exit(1)
    except (KeyboardInterrupt, EOFError):
        print('STOP: Setup stopped; saved credentials are retained.', file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('STOP: Writer setup could not finish. Active availability credentials remain unchanged; no secret details displayed.', file=sys.stderr)
        sys.exit(1)
