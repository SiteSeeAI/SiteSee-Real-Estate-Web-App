#!/usr/bin/env python3
"""WHM/root setup for read-only SiteSee Photography calendar access.

Secrets are read from the terminal without echo, saved outside the web root,
and never included in command arguments or diagnostic output. No calendar
writes, invitations, PHP-FPM edits, or application activation are performed.
"""
import datetime as dt
import getpass
import json
import os
from pathlib import Path
import pwd
import re
import stat
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import warnings

ROOT = Path('/home/sitesee/.sitesee-real-estate')
CONFIG = ROOT / 'zoho-calendar.json'
NAME = 'SiteSee Photography'
SCOPES = 'ZohoCalendar.calendar.READ,ZohoCalendar.event.READ'
ACCOUNTS = 'https://accounts.zoho.com'
CALENDAR = 'https://calendar.zoho.com'


class SetupError(Exception):
    """Only fixed, non-sensitive messages may be displayed."""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def request_json(url, form=None, token=None):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or parsed.netloc not in ('accounts.zoho.com', 'calendar.zoho.com'):
        raise SetupError('Unexpected Zoho endpoint; stopped.')
    if form is not None and url != ACCOUNTS + '/oauth/v2/token':
        raise SetupError('Only the OAuth token endpoint permits a POST.')
    if token is not None and parsed.netloc != 'calendar.zoho.com':
        raise SetupError('Calendar authorization cannot be sent to this host.')
    headers = {'Accept': 'application/json'}
    data = None
    if form is not None:
        data = urllib.parse.urlencode(form).encode('ascii')
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    if token is not None:
        headers['Authorization'] = 'Zoho-oauthtoken ' + token
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    try:
        with opener.open(urllib.request.Request(url, data=data, headers=headers), timeout=25) as response:
            if response.status != 200:
                raise SetupError('Zoho returned an unexpected HTTP status.')
            raw = response.read(2 * 1024 * 1024 + 1)
        if len(raw) > 2 * 1024 * 1024:
            raise SetupError('Zoho response exceeded the setup size limit.')
        body = json.loads(raw)
    except urllib.error.HTTPError as error:
        raise SetupError('Zoho request failed (HTTP %d). No response details displayed.' % error.code) from None
    except (urllib.error.URLError, TimeoutError, OSError, ValueError):
        raise SetupError('Zoho request could not be completed or decoded. No credentials displayed.') from None
    if not isinstance(body, dict):
        raise SetupError('Unexpected Zoho response format.')
    if 'error' in body or 'errors' in body:
        code = body.get('error')
        if code == 'invalid_code':
            raise SetupError('Zoho rejected the authorization code. Generate a fresh code and rerun this setup.')
        if code in ('invalid_client', 'invalid_client_secret'):
            raise SetupError('Zoho rejected the client credentials. Check the Client Secret tab and the US data center.')
        raise SetupError('Zoho reported an authorization or API error. No response details displayed.')
    return body


def secret(prompt):
    with warnings.catch_warnings():
        warnings.simplefilter('error', getpass.GetPassWarning)
        try:
            value = getpass.getpass(prompt).strip()
        except getpass.GetPassWarning:
            raise SetupError('A terminal with hidden input is required.') from None
    if not value or len(value) > 4096 or any(ord(char) < 33 or ord(char) > 126 for char in value):
        raise SetupError('A credential was empty or contained unexpected characters.')
    return value


def atomic_save(path, config, uid, gid):
    """Write a complete mode-0600 file; failures leave the prior file intact."""
    encoded = (json.dumps(config, indent=2) + '\n').encode('utf-8')
    fd, temp = tempfile.mkstemp(prefix='.zoho-calendar-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(encoded)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), 0o600)
            os.fchown(stream.fileno(), uid, gid)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def load_existing(path, uid):
    try:
        fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return None
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_mode & 0o077 or info.st_nlink != 1:
            raise SetupError('Existing calendar configuration has unexpected ownership, permissions or file type; left untouched.')
        with os.fdopen(fd, 'r') as stream:
            fd = None
            config = json.load(stream)
    finally:
        if fd is not None:
            os.close(fd)
    if not isinstance(config, dict) or config.get('schema_version') != 1 or config.get('setup') != 'sitesee-calendar-readonly':
        raise SetupError('Existing calendar configuration is not recognized; left untouched.')
    if config.get('enabled') is not False:
        raise SetupError('This configuration is already enabled or has an unknown state; left untouched.')
    for key in ('client_id', 'client_secret', 'refresh_token'):
        if not isinstance(config.get(key), str) or not config[key]:
            raise SetupError('Existing calendar credentials are incomplete; left untouched.')
    return config


def access_token(body):
    token = body.get('access_token')
    if not isinstance(token, str) or not token or any(ord(char) < 33 or ord(char) > 126 for char in token):
        raise SetupError('Zoho did not return a usable access token.')
    return token


def choose_calendar(body, previous_uid=None):
    calendars = body.get('calendars')
    if not isinstance(calendars, list) or any(not isinstance(item, dict) for item in calendars):
        raise SetupError('Calendar list could not be verified.')
    matches = [item for item in calendars if item.get('name') == NAME]
    if len(matches) != 1:
        raise SetupError('Expected exactly one owned calendar named SiteSee Photography; credentials remain saved for retry.')
    calendar = matches[0]
    if calendar.get('category') != 'own' or calendar.get('privilege') != 'owner':
        raise SetupError('The named calendar is not owned by the connected account; stopped.')
    # Calendar metadata can differ from the display/event timezone. Read timed
    # events in UTC and keep the booking timezone separately; do not modify Zoho.
    if not isinstance(calendar.get('timezone'), str) or not calendar['timezone']:
        raise SetupError('Calendar timezone metadata is missing; credentials remain saved for retry.')
    if calendar.get('include_infreebusy') is not True or calendar.get('status') is not True:
        raise SetupError('Enable the photography calendar and Include in my Free/Busy sharing, then rerun setup.')
    uid = calendar.get('uid')
    if not isinstance(uid, str) or not uid or len(uid) > 256 or any(ord(char) < 33 for char in uid):
        raise SetupError('Calendar UID is missing or invalid.')
    if previous_uid and uid != previous_uid:
        raise SetupError('The calendar identity changed; existing configuration left bound to the previous calendar.')
    return calendar


def event_records(body):
    """Validate a read response; only the observed empty sentinel means no events."""
    if not isinstance(body, dict) or 'error' in body or 'errors' in body:
        raise SetupError('Calendar event-read response could not be verified; credentials remain saved for retry.')
    events = body.get('events')
    if not isinstance(events, list):
        raise SetupError('Calendar event-read response could not be verified; credentials remain saved for retry.')
    for key in ('next', 'next_page', 'next_page_token', 'next_token', 'more_records', 'has_more', 'pagination', 'info'):
        if body.get(key):
            raise SetupError('Calendar coverage is incomplete; credentials remain saved for retry.')
    if events == [{'message': 'No events found.'}]:
        return []
    for event in events:
        if not isinstance(event, dict) or 'message' in event:
            raise SetupError('Unexpected calendar event record; credentials remain saved for retry.')
        all_day = event.get('isallday', False)
        dates = event.get('dateandtime', event)
        if not isinstance(all_day, bool) or not isinstance(dates, dict):
            raise SetupError('Calendar event dates could not be verified; credentials remain saved for retry.')
        parsed = []
        for key in ('start', 'end'):
            value = dates.get(key)
            pattern = r'\d{8}' if all_day else r'\d{8}T\d{6}(?:Z|[+-]\d{4})'
            if not isinstance(value, str) or not re.fullmatch(pattern, value):
                raise SetupError('Calendar event dates could not be verified; credentials remain saved for retry.')
            value = value[:-1] + '+0000' if value.endswith('Z') else value
            fmt = '%Y%m%d' if all_day else '%Y%m%dT%H%M%S%z'
            try:
                date = dt.datetime.strptime(value, fmt)
            except ValueError:
                raise SetupError('Calendar event dates could not be verified; credentials remain saved for retry.') from None
            if date.strftime(fmt) != value:
                raise SetupError('Calendar event dates could not be verified; credentials remain saved for retry.')
            parsed.append(date)
        if parsed[1] <= parsed[0]:
            raise SetupError('Calendar event interval could not be verified; credentials remain saved for retry.')
    return events


def main():
    if os.geteuid() != 0:
        raise SetupError('Run this setup in WHM Terminal as root.')
    if not ROOT.is_dir() or ROOT.resolve() != ROOT:
        raise SetupError('Expected private Real Estate directory was not found or is a symlink.')
    account = pwd.getpwnam('sitesee')
    root_info = ROOT.stat()
    if root_info.st_uid != account.pw_uid or root_info.st_mode & 0o022:
        raise SetupError('Private Real Estate directory has unexpected ownership or write permissions; left untouched.')
    os.umask(0o077)
    config = load_existing(CONFIG, account.pw_uid)
    if config is None:
        print('Read-only Zoho Calendar setup. Credentials are hidden while pasted.')
        print('In Self Client > Client Secret, copy the following two values privately.')
        client_id = secret('Client ID: ')
        client_secret = secret('Client Secret: ')
        print('\nNow use the Generate Code tab:')
        print('Scope: ' + SCOPES)
        print('Duration: 10 minutes (or the longest offered)')
        print('Description: SiteSee Photography availability read access')
        print('Click CREATE. If asked, select the account owning SiteSee Photography.')
        code = secret('Paste the generated authorization code here: ')
        tokens = request_json(ACCOUNTS + '/oauth/v2/token', form={
            'client_id': client_id, 'client_secret': client_secret,
            'grant_type': 'authorization_code', 'code': code,
        })
        token = access_token(tokens)
        refresh = tokens.get('refresh_token')
        if not isinstance(refresh, str) or not refresh:
            raise SetupError('Zoho did not return a refresh token; nothing was installed.')
        config = {
            'schema_version': 1, 'setup': 'sitesee-calendar-readonly', 'enabled': False,
            'accounts_base': ACCOUNTS, 'calendar_base': CALENDAR,
            'client_id': client_id, 'client_secret': client_secret, 'refresh_token': refresh,
            'requested_scopes': SCOPES.split(','), 'calendar_uid': None,
            'calendar_name': NAME, 'timezone': 'America/Chicago', 'connection_verified_at': None,
        }
        # Persist refresh credentials before any calendar request, so API failures do not consume setup progress.
        atomic_save(CONFIG, config, account.pw_uid, account.pw_gid)
        print('OAuth credentials saved privately. Checking durable access...')
    else:
        print('Existing disabled configuration found; retrying checks without generating another code.')
    refreshed = request_json(ACCOUNTS + '/oauth/v2/token', form={
        'client_id': config['client_id'], 'client_secret': config['client_secret'],
        'grant_type': 'refresh_token', 'refresh_token': config['refresh_token'],
    })
    token = access_token(refreshed)
    print('Token refresh: PASS')
    calendars = request_json(CALENDAR + '/api/v1/calendars?category=own&showhiddencal=true', token=token)
    calendar = choose_calendar(calendars, config.get('calendar_uid'))
    print('Calendar name, ownership and Free/Busy setting: PASS')
    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
    query = urllib.parse.urlencode({
        'range': json.dumps({'start': now.strftime('%Y%m%dT%H%M%SZ'),
                             'end': (now + dt.timedelta(days=14)).strftime('%Y%m%dT%H%M%SZ')}),
        'byinstance': 'true', 'timezone': 'UTC',
    })
    path = '/api/v1/calendars/' + urllib.parse.quote(calendar['uid'], safe='') + '/events?'
    events = request_json(CALENDAR + path + query, token=token)
    records = event_records(events)
    config.update({'calendar_uid': calendar['uid'], 'calendar_owner_id': str(calendar.get('owner', '')),
                   'calendar_timezone': calendar['timezone'], 'timezone': 'America/Chicago',
                   'connection_verified_at': int(time.time())})
    atomic_save(CONFIG, config, account.pw_uid, account.pw_gid)
    print('Calendar event-read permission: PASS (%d event records; details not displayed)' % len(records))
    print('Booking timezone: America/Chicago; timed events are requested in UTC.')
    print('Private configuration saved: PASS (sitesee owner, mode 0600)')
    print('Availability feedback remains DISABLED pending event handling and form integration tests.')
    print('No calendar events, invitations, payments or PHP-FPM settings were changed.')


if __name__ == '__main__':
    try:
        main()
    except SetupError as error:
        print('STOP: ' + str(error), file=sys.stderr)
        sys.exit(1)
    except (KeyboardInterrupt, EOFError):
        print('\nSetup stopped. No credentials displayed.', file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('STOP: Setup could not finish. Any saved private configuration is retained; no exception details displayed.', file=sys.stderr)
        sys.exit(1)
