#!/usr/bin/env python3
"""Read-only calendar preflight for the existing SiteSee Microsoft 365 mailbox."""
import datetime as dt
import json
import os
from pathlib import Path
import pwd
import re
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path('/home/sitesee/.sitesee-real-estate')
CREDENTIALS = Path('/home/sitesee/.sitesee-graph-mail.json')
MAILBOX = 'sales@re.sitesee.ai'
BASE = '/v1.0/users/sales%40re.sitesee.ai'
LIMIT = 2 * 1024 * 1024


class CheckError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise CheckError('Provider redirect refused; no credentials were forwarded.')


def private_json(path, uid):
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as handle:
        info = os.fstat(handle.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid
                or info.st_mode & 0o077 or info.st_nlink != 1 or info.st_size > 65536):
            raise CheckError('Existing private configuration ownership or permissions differ.')
        raw = handle.read(65537)
        if len(raw) > 65536:
            raise CheckError('Existing private configuration exceeds the read limit.')
    value = json.loads(raw)
    if not isinstance(value, dict):
        raise CheckError('Existing private configuration is invalid.')
    return value


def graph_path(value, calendar_list_only=False):
    if not isinstance(value, str) or any(ord(c) < 33 or ord(c) > 126 for c in value):
        raise CheckError('Unexpected calendar request path.')
    parts = urllib.parse.urlsplit(value)
    if parts.scheme or parts.netloc:
        if parts.scheme != 'https' or parts.netloc != 'graph.microsoft.com':
            raise CheckError('Calendar continuation left the authorized Microsoft host.')
    if parts.fragment:
        raise CheckError('Unexpected calendar request fragment.')
    suffixes = ['/calendars'] if calendar_list_only else ['/calendar', '/calendars', '/calendarView']
    allowed = [urllib.parse.unquote(BASE + suffix) for suffix in suffixes]
    if urllib.parse.unquote(parts.path) not in allowed:
        raise CheckError('Calendar request left the authorized mailbox or read endpoints.')
    return parts.path + ('?' + parts.query if parts.query else '')


def json_request(method, url, headers=None, body=None):
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.ProxyHandler({}))
    request = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
    try:
        response = opener.open(request, timeout=15)
    except urllib.error.HTTPError as error:
        # Never display raw provider errors, token responses, or traces.
        status = error.code
        error.close()
        return status, {}
    with response:
        raw = response.read(LIMIT + 1)
        if len(raw) > LIMIT:
            raise CheckError('Provider response exceeds the read limit.')
        payload = json.loads(raw)
        if not isinstance(payload, dict):
            raise CheckError('Provider returned an unexpected response.')
        return response.status, payload


def graph_reader(secret, transport=json_request):
    guid = r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}'
    if any(not re.fullmatch(guid, str(secret.get(key, ''))) for key in ['tenant_id', 'client_id']):
        raise CheckError('Existing Microsoft application identity is invalid.')
    if not isinstance(secret.get('client_secret'), str) or not secret['client_secret']:
        raise CheckError('Existing Microsoft credential is missing.')
    status, data = transport('POST', 'https://login.microsoftonline.com/' + secret['tenant_id'] + '/oauth2/v2.0/token',
        {'Content-Type': 'application/x-www-form-urlencoded'},
        urllib.parse.urlencode({'client_id': secret['client_id'], 'client_secret': secret['client_secret'],
            'scope': 'https://graph.microsoft.com/.default', 'grant_type': 'client_credentials'}).encode())
    token = data.get('access_token')
    if status != 200 or not isinstance(token, str) or not re.fullmatch(r'[\x21-\x7e]{1,32768}', token):
        raise CheckError('Existing Microsoft authentication failed; credentials were not changed.')

    def get(path):
        safe = graph_path(path)
        return transport('GET', 'https://graph.microsoft.com' + safe,
            {'Authorization': 'Bearer ' + token, 'Accept': 'application/json',
             'Prefer': 'IdType="ImmutableId"'}, None)
    return get


def clean(value):
    return ''.join(c if c.isprintable() else ' ' for c in str(value))[:2048]


def checked(get, path, label):
    status, data = get(graph_path(path))
    if status != 200 or 'error' in data:
        if status in (401, 403):
            raise CheckError(label + ': calendar permission or mailbox access was denied (HTTP ' + str(status) + ').')
        if status == 404:
            raise CheckError(label + ': the mailbox/calendar could not be resolved (HTTP 404).')
        raise CheckError(label + ': Microsoft read failed (HTTP ' + str(status) + ').')
    return data


def inspect_calendar(get, emit=print, now=None):
    fields = urllib.parse.urlencode({'$select': 'id,name,owner,canEdit,isDefaultCalendar'})
    calendar = checked(get, BASE + '/calendar?' + fields, 'Default calendar')
    if not isinstance(calendar.get('id'), str) or not calendar['id']:
        raise CheckError('Default calendar returned no calendar identifier.')
    owner = calendar.get('owner', {}).get('address', '')
    if not isinstance(owner, str) or not owner:
        raise CheckError('Default calendar owner was not returned.')
    emit('Default calendar name: ' + clean(calendar.get('name', '')))
    emit('Default calendar owner: ' + clean(owner))
    emit('Default calendar ID: ' + clean(calendar['id']))
    emit('Calendar canEdit flag: ' + ('YES' if calendar.get('canEdit') is True else 'NO/UNKNOWN'))
    if owner.lower() != MAILBOX:
        emit('Owner address differs from the requested mailbox; verify the mailbox/alias identity before activation.')

    path = BASE + '/calendars?' + fields + '&%24top=50'
    seen = set()
    found_default = False
    emit('Calendars visible in this mailbox:')
    for page in range(10):
        if path in seen:
            raise CheckError('Calendar list repeated a continuation; inventory is incomplete.')
        seen.add(path)
        data = checked(get, path, 'Calendar inventory')
        if not isinstance(data.get('value'), list):
            raise CheckError('Calendar inventory response is incomplete.')
        for item in data['value']:
            if not isinstance(item, dict) or not isinstance(item.get('id'), str):
                raise CheckError('Calendar inventory contains an invalid record.')
            is_default = item['id'] == calendar['id']
            found_default |= is_default
            emit('  ' + clean(item.get('name', '')) + (' [DEFAULT]' if is_default else '')
                 + ' | owner=' + clean(item.get('owner', {}).get('address', ''))
                 + ' | ID=' + clean(item['id']))
        next_link = data.get('@odata.nextLink')
        if not next_link:
            break
        path = graph_path(next_link, calendar_list_only=True)
    else:
        raise CheckError('Calendar inventory exceeds the diagnostic page limit.')
    if not found_default:
        raise CheckError('Default calendar was absent from the completed inventory; review identity before activation.')

    now = now or dt.datetime.now(dt.timezone.utc)
    query = urllib.parse.urlencode({'startDateTime': now.isoformat(),
        'endDateTime': (now + dt.timedelta(days=14)).isoformat(),
        '$select': 'id', '$top': '1'})
    view = checked(get, BASE + '/calendarView?' + query, 'Default calendar event read')
    if not isinstance(view.get('value'), list):
        raise CheckError('Calendar event-read response is incomplete.')
    # Only access is checked: this is deliberately not an availability inventory.
    emit('FINAL RESULTS')
    emit('Mailbox: ' + MAILBOX)
    emit('Calendar metadata and inventory reads: PASS')
    emit('Default calendar event read: PASS (event details omitted; no availability calculation)')
    emit('Effective calendar write permission: NOT TESTED')
    emit('Calendar migration: NOT ENABLED')
    emit('No application, credential, permission, booking, payment, email, invitation or calendar event was changed.')
    emit('Next: review this output before preparing the TEST calendar connection.')


def main():
    if len(sys.argv) != 1:
        raise CheckError('Usage: python3 /home/sitesee/check-microsoft-calendar.py')
    uid = pwd.getpwnam('sitesee').pw_uid
    if os.geteuid() not in (0, uid):
        raise CheckError('Run in WHM Terminal as root or as sitesee.')
    info = ROOT.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_mode & 0o022:
        raise CheckError('Existing private application directory is unavailable or unsafe.')
    config = private_json(ROOT / 'booking-mail.json', uid)
    if (config.get('stage') != 'test' or config.get('sender') != MAILBOX
            or config.get('graph_credentials') != str(CREDENTIALS)):
        raise CheckError('Existing TEST booking-mail configuration differs; nothing was changed.')
    secret = private_json(CREDENTIALS, uid)
    print('Mode: READ ONLY — Microsoft calendar preflight', flush=True)
    print('Reuses the existing Microsoft application; no new key is required.', flush=True)
    inspect_calendar(graph_reader(secret))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error) if isinstance(error, CheckError) else 'The read-only check could not complete; no changes were made.'
        print('STOP: ' + message, file=sys.stderr)
        sys.exit(1)
