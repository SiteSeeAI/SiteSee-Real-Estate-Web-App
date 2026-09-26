#!/usr/bin/env python3
"""One read-only report for E6E183EF8E and the new Real Estate mailbox.

No sends, drafts, updates, queue operations, or credential changes. OAuth token
issuance is the only POST allowed. Run from WHM Terminal as root (Python 3.6+).
"""
import ast
import base64
import datetime as dt
import glob
import hashlib
import json
import os
import pwd
import re
import shlex
import shutil
import sqlite3
import stat
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from email import policy
from email.parser import BytesParser
from email.utils import parseaddr
from pathlib import Path

ROOT = Path('/home/sitesee/.sitesee-real-estate')
GRAPH_CONFIG = Path('/home/sitesee/.sitesee-graph-mail.json')
BRIDGE = Path('/usr/local/bin/sitesee-graph-sendmail')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
REF = 'E6E183EF8E'
RECIPIENT = 'cro@sitesee.ai'
SENDER = 'sales@re.sitesee.ai'
DOMAIN = 're.sitesee.ai'
GRAPH = 'https://graph.microsoft.com/v1.0'
SUBJECT = '[TEST] SiteSee Photography Arrival Window | ' + REF
KNOWN_INVITATION = '31c7ae062641fb3306dbd5fba3a4ca50f9fd0845bf38ae31c635dc8325f0899a'
MAX_BODY = 2 * 1024 * 1024
SECRETS = []


class CheckError(Exception):
    pass


def safe(value):
    text = str(value)
    for secret in sorted(set(SECRETS), key=len, reverse=True):
        if secret:
            text = text.replace(secret, '[REDACTED]')
    return re.sub(r'[\x00-\x1f\x7f]', ' ', text)[:1400]


def report(label, value):
    if isinstance(value, (dict, list, bool)):
        value = json.dumps(value, sort_keys=True)
    print(safe(label) + ': ' + safe(value), flush=True)


def check(label, fn):
    try:
        return fn()
    except CheckError as error:
        report(label, 'UNAVAILABLE - ' + str(error))
    except Exception as error:
        report(label, 'UNAVAILABLE - ' + type(error).__name__)
    return None


def run(args, timeout=15):
    try:
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                universal_newlines=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise CheckError(type(error).__name__)
    if result.returncode:
        # Do not print arbitrary stderr; PHP/FPM output can contain secrets.
        raise CheckError('command exit ' + str(result.returncode))
    return result.stdout


def private_json(path, owner):
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, owner)
                or stat.S_IMODE(info.st_mode) & 0o077 or info.st_nlink != 1
                or info.st_size > 32768):
            raise CheckError('private file permissions or ownership do not match')
        data = json.loads(os.read(fd, 32769).decode('utf-8'))
    finally:
        os.close(fd)
    if not isinstance(data, dict):
        raise CheckError('configuration is not a JSON object')
    for key in ('client_secret', 'refresh_token', 'access_token', 'password'):
        if isinstance(data.get(key), str):
            SECRETS.append(data[key])
    return data


def booking():
    db = sqlite3.connect('file:' + str(ROOT / 'data/bookings.sqlite') + '?mode=ro', uri=True)
    try:
        db.execute('PRAGMA query_only=ON')
        db.row_factory = sqlite3.Row
        row = db.execute('SELECT b.reference,b.status,b.email,b.approved_at,b.deposit_paid_at,'
                         'b.crm_contact_id,c.state,c.event_uid,c.calendar_uid,c.planned_start,'
                         'c.planned_end,c.invitation_state,c.invitation_recipient,'
                         'c.invitation_attempted_at,c.invitation_sent_at FROM bookings b '
                         'JOIN booking_confirmations c ON c.reference=b.reference '
                         'WHERE b.reference=?', (REF,)).fetchone()
        if not row or row['email'].lower() != RECIPIENT or row['invitation_recipient'].lower() != RECIPIENT:
            raise CheckError('saved booking/recipient does not match this authorized test')
        return dict(row)
    finally:
        db.close()


def fpm_settings():
    paths = glob.glob('/opt/cpanel/ea-php82/root/etc/php-fpm.d/*re.sitesee.ai*.conf')
    if len(paths) != 1:
        raise CheckError('expected exactly one matching PHP-FPM pool')
    raw = Path(paths[0]).read_text(errors='replace')
    def value(pattern):
        hits = re.findall(pattern, raw, re.M)
        if len(hits) > 1:
            raise CheckError('duplicate FPM setting requires review')
        return hits[0].strip().strip('\"\'') if hits else None
    smtp = value(r'^\s*env\[SITESEE_SMTP_HOST\]\s*=\s*(.*?)\s*$')
    sender = value(r'^\s*env\[SITESEE_FROM_EMAIL\]\s*=\s*(.*?)\s*$')
    mailpath = value(r'^\s*php_(?:admin_)?value\[sendmail_path\]\s*=\s*(.*?)\s*$')
    report('FPM SMTP host configured', bool(smtp))
    report('FPM sender', sender or 'sales@sitesee.ai (application default)')
    report('FPM sendmail executable', shlex.split(mailpath)[0] if mailpath else 'NO POOL OVERRIDE')
    ini = Path('/opt/cpanel/ea-php82/root/etc/php.ini')
    ini_paths = [ini] + [Path(p) for p in sorted(glob.glob('/opt/cpanel/ea-php82/root/etc/php.d/*.ini'))]
    ini_mail = []
    for path in ini_paths:
        if not path.is_file():
            continue
        for match in re.findall(r'^\s*sendmail_path\s*=\s*(.*?)\s*$', path.read_text(errors='replace'), re.M):
            tokens = shlex.split(match.strip().strip('\"\''))
            if tokens:
                ini_mail.append(tokens[0])
    report('PHP INI sendmail executables', ini_mail or 'DEFAULT/UNDETERMINED')
    report('Transport inference', 'SMTP branch' if smtp else
           ('GRAPH BRIDGE configured in pool' if mailpath and shlex.split(mailpath)[0] == str(BRIDGE)
            else 'PHP mail; review pool/INI executable above'))
    report('Runtime qualification', 'Settings read from disk; active FPM worker configuration not directly queried')
    return sender or 'sales@sitesee.ai'


def bridge_details():
    source = BRIDGE.read_text(errors='strict')
    tree = ast.parse(source)
    strings = [n.s for n in ast.walk(tree) if isinstance(n, ast.Str)]
    report('Graph bridge installed', True)
    report('Graph bridge SHA256', hashlib.sha256(source.encode()).hexdigest())
    report('Bridge references known private Graph config', str(GRAPH_CONFIG) in strings)
    report('Bridge MIME send path present', 'text/plain' in strings and 'base64.b64encode' in source and 'sendMail' in source)
    report('Bridge sender replacement marker', 'msg["From"]=formataddr' in source.replace(' ', ''))
    report('Bridge inspection', 'Static markers only; exact previous invitation MIME is checked below if found')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise CheckError('redirect refused')


def request(url, token=None, form=None, raw=False):
    parts = urllib.parse.urlsplit(url)
    oauth = bool(re.fullmatch(r'https://login\.microsoftonline\.com/[0-9a-fA-F-]{36}/oauth2/v2\.0/token', url))
    graph = (parts.scheme == 'https' and parts.netloc == 'graph.microsoft.com'
             and parts.path.startswith('/v1.0/users/') and not parts.fragment)
    if (form is not None and (not oauth or token is not None)) or (form is None and (not graph or not token)):
        raise CheckError('non-allowlisted request refused')
    headers = {'Accept': 'application/json'}
    data = None
    if form is not None:
        data = urllib.parse.urlencode(form).encode()
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    else:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(url, data=data, headers=headers, method='POST' if data is not None else 'GET')
    try:
        with urllib.request.build_opener(NoRedirect()).open(req, timeout=15) as reply:
            body = reply.read(MAX_BODY + 1)
            if len(body) > MAX_BODY:
                raise CheckError('response exceeds safe size limit')
            return body if raw else json.loads(body.decode())
    except urllib.error.HTTPError as error:
        # Avoid raw OAuth/Graph error bodies and HTTP headers in shared output.
        code = ''
        try:
            body = json.loads(error.read(65536).decode())
            code = body.get('error', '')
            if isinstance(code, dict):
                code = code.get('code', '')
        except Exception:
            pass
        code = code if re.fullmatch(r'[A-Za-z0-9_.-]{1,100}', str(code)) else 'unclassified'
        raise CheckError('HTTP {} ({})'.format(error.code, code))


def graph_connection(owner):
    cfg = private_json(GRAPH_CONFIG, owner)
    for name in ('tenant_id', 'client_id'):
        if not re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', str(cfg.get(name, ''))):
            raise CheckError('existing Graph ' + name + ' is invalid')
    if not isinstance(cfg.get('client_secret'), str) or not cfg['client_secret']:
        raise CheckError('existing Graph credential missing')
    if not re.fullmatch(r'[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+', str(cfg.get('sender', ''))):
        raise CheckError('existing Graph sender is invalid')
    reply = request('https://login.microsoftonline.com/' + cfg['tenant_id'] + '/oauth2/v2.0/token', form={
        'client_id': cfg['client_id'], 'client_secret': cfg['client_secret'],
        'scope': 'https://graph.microsoft.com/.default', 'grant_type': 'client_credentials'})
    token = reply.get('access_token')
    if not isinstance(token, str) or not token or re.search(r'\s', token):
        raise CheckError('access token not returned')
    SECRETS.append(token)
    report('Existing Graph application authentication', 'PASS')
    report('Existing bridge sender mailbox', cfg['sender'])
    try:
        payload = token.split('.')[1]
        roles = json.loads(base64.urlsafe_b64decode(payload + '=' * (-len(payload) % 4)).decode()).get('roles', [])
        roles = [r for r in roles if isinstance(r, str) and r.startswith('Mail.')]
        report('Token-advertised mail roles', roles)
    except Exception:
        report('Token-advertised mail roles', 'NOT INSPECTABLE')
    report('New mailbox Mail.Send capability', 'NOT PROVEN by token/read checks; no message sent')
    return cfg, token


def user_url(mailbox, suffix):
    return GRAPH + '/users/' + urllib.parse.quote(mailbox, safe='') + suffix


def mailbox_read(token, mailbox):
    request(user_url(mailbox, '/mailFolders/inbox?$select=id'), token)
    report('Mailbox access ' + mailbox, 'PASS (read authorization; not a delivery test)')


def date(value):
    value = re.sub(r'([+-]\d\d):(\d\d)$', r'\1\2', value.replace('Z', '+00:00'))
    return dt.datetime.strptime(value, '%Y-%m-%dT%H:%M:%S%z')


def matching_messages(token, mailbox, folder, when, subject, hours=1):
    lower = (when - dt.timedelta(minutes=10)).astimezone(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    upper = (when + dt.timedelta(hours=hours)).astimezone(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    field = 'sentDateTime' if folder == 'sentitems' else 'receivedDateTime'
    query = urllib.parse.urlencode({'$filter': field + ' ge ' + lower + ' and ' + field + ' le ' + upper,
        '$select': 'id,internetMessageId,subject,sentDateTime,receivedDateTime,toRecipients,from,isDraft', '$top': '100'})
    url = user_url(mailbox, '/mailFolders/' + folder + '/messages?') + query
    found = []
    # Preserve complete provider paging URLs, but never allow a different host/mailbox/folder.
    prefix = urllib.parse.urlsplit(url).path
    for _ in range(10):
        reply = request(url, token)
        if not isinstance(reply.get('value'), list):
            raise CheckError('unexpected message-list response')
        for msg in reply['value']:
            if not isinstance(msg, dict):
                raise CheckError('unexpected message-list item')
            text = str(msg.get('subject', ''))
            if (text == subject if folder == 'sentitems' else REF in text):
                found.append(msg)
        following = reply.get('@odata.nextLink')
        if not following:
            return found
        parts = urllib.parse.urlsplit(following)
        if parts.scheme != 'https' or parts.netloc != 'graph.microsoft.com' or parts.path != prefix or parts.fragment:
            raise CheckError('unexpected pagination destination')
        url = following
    raise CheckError('message scan incomplete: more than 1000 messages in the bounded interval')


def mime_summary(raw):
    msg = BytesParser(policy=policy.default).parsebytes(raw)
    summary = {'from': parseaddr(str(msg.get('From', '')))[1],
               'reply_to': parseaddr(str(msg.get('Reply-To', '')))[1],
               'message_id': str(msg.get('Message-ID', '')), 'calendar_parts': []}
    for part in msg.walk():
        if part.get_content_type() != 'text/calendar':
            continue
        body = (part.get_payload(decode=True) or b'').decode('utf-8', 'replace')
        body = re.sub(r'\r?\n[ \t]', '', body)
        details = {'mime_method': part.get_param('method')}
        for key in ('METHOD', 'UID', 'DTSTART', 'DTEND', 'ORGANIZER', 'ATTENDEE', 'SEQUENCE'):
            hits = re.findall(r'^' + key + r'(?:;[^:\r\n]*)?:(.*)$', body, re.M)
            details[key.lower()] = [value.strip() for value in hits]
        details['booking_uid_matches'] = details['uid'] == ['sitesee-arrival-test-' + REF + '@re.sitesee.ai']
        details['sender_matches_organizer'] = [v.lower() for v in details['organizer']] == ['mailto:' + summary['from'].lower()]
        details['recipient_matches'] = [v.lower() for v in details['attendee']] == ['mailto:' + RECIPIENT]
        summary['calendar_parts'].append(details)
    return summary


def delivery_status_summary(raw, sent_ids):
    msg = BytesParser(policy=policy.default).parsebytes(raw)
    identities = {str(p.get('Message-ID', '')) for p in msg.walk()}
    identities.update(str(p.get('Original-Message-ID', '')) for p in msg.walk())
    statuses = []
    for part in msg.walk():
        if part.get_content_type() != 'message/delivery-status':
            continue
        payload = part.get_payload()
        if not isinstance(payload, list):
            continue
        for block in payload:
            values = {key.lower(): str(block.get(key)) for key in
                      ('Final-Recipient', 'Action', 'Status', 'Remote-MTA', 'Diagnostic-Code') if block.get(key)}
            if values:
                statuses.append(values)
    return {'exact_sent_message_id_match': bool((identities - {''}) & sent_ids),
            'delivery_status_blocks': statuses,
            'qualification': 'Only an exact message-ID match ties this report to the recorded sent copy'}


def old_invitation_trace(token, mailbox, row):
    when = date(row['invitation_attempted_at'])
    sent = matching_messages(token, mailbox, 'sentitems', when, SUBJECT)
    sent = [m for m in sent if RECIPIENT in [str(r.get('emailAddress', {}).get('address', '')).lower()
                                           for r in m.get('toRecipients', [])] and not m.get('isDraft')]
    report('Exact previous invitation in Sent Items (' + mailbox + ')', len(sent))
    for msg in sent[:3]:
        mid = msg.get('id')
        if not isinstance(mid, str) or not mid:
            raise CheckError('matching message has no provider ID')
        report('Matched sent-message identity', {'internet_message_id': msg.get('internetMessageId'),
                                               'sent_at': msg.get('sentDateTime')})
        raw = check('Original invitation MIME', lambda: request(user_url(mailbox, '/messages/' + urllib.parse.quote(mid, safe='') + '/$value'), token, raw=True))
        if raw is not None:
            report('Original invitation MIME', mime_summary(raw))
    # Subject-only candidates are not presented as proven nondelivery reports.
    notices = check('Same-booking incoming notices', lambda: matching_messages(token, mailbox, 'inbox', when, SUBJECT, hours=24))
    if notices is not None:
        report('Same-booking incoming notice candidates', [
            {'subject': m.get('subject'), 'received_at': m.get('receivedDateTime')}
            for m in notices[:5]])
        sent_ids = {m.get('internetMessageId') for m in sent if m.get('internetMessageId')}
        for notice in notices[:5]:
            mid = notice.get('id')
            if not isinstance(mid, str) or not mid:
                continue
            raw = check('Incoming notice MIME', lambda: request(user_url(mailbox, '/messages/' + urllib.parse.quote(mid, safe='') + '/$value'), token, raw=True))
            if raw is not None:
                report('Incoming delivery-status evidence', delivery_status_summary(raw, sent_ids))
    report('Delivery qualification', 'Sent Items proves a saved sent copy, not recipient inbox delivery; absence is not proof of no send')


def recipient_trace(token, row):
    # A mailbox alias might not resolve as a Graph user identifier. Never resolve
    # it by enumerating unrelated accounts or treat a 404 as no delivery.
    request(user_url(RECIPIENT, '/mailFolders/inbox?$select=id'), token)
    when = date(row['invitation_attempted_at'])
    for folder in ('inbox', 'junkemail', 'deleteditems'):
        hits = check('Recipient ' + folder, lambda f=folder: matching_messages(token, RECIPIENT, f, when, SUBJECT, hours=24))
        if hits is not None:
            hits = [m for m in hits if m.get('subject') == SUBJECT]
            report('Exact invitation copies in recipient ' + folder, [
                {'internet_message_id': m.get('internetMessageId'), 'received_at': m.get('receivedDateTime')}
                for m in hits[:5]])


def dns_checks():
    dig = shutil.which('dig')
    if not dig:
        raise CheckError('dig unavailable')
    for kind, name in [('MX', DOMAIN), ('TXT', DOMAIN), ('CNAME', 'selector1._domainkey.' + DOMAIN),
                       ('CNAME', 'selector2._domainkey.' + DOMAIN), ('TXT', '_dmarc.' + DOMAIN),
                       ('TXT', '_dmarc.sitesee.ai')]:
        value = check('Public DNS ' + kind + ' ' + name,
                      lambda k=kind, n=name: run([dig, '@1.1.1.1', '+time=2', '+tries=1', '+short', k, n]))
        if value is not None:
            report('Public DNS ' + kind + ' ' + name, value.strip() or 'NO ANSWER')
    report('DNS qualification', 'Published records only; DKIM signing and authentication require message headers')
    for file in ('/etc/localdomains', '/etc/remotedomains'):
        path = Path(file)
        if path.is_file():
            names = set(path.read_text().splitlines())
            report(file, {d: d in names for d in (DOMAIN, 'sitesee.ai')})


CALENDAR_PHP = r'''
$root='/home/sitesee/.sitesee-real-estate';
$out=[];
try {
  require $root.'/tools/diagnose-calendar-confirmation.php';
  require $root.'/server/booking-calendar-client.php';
  $db=new PDO('sqlite:file:'.$root.'/data/bookings.sqlite?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $db->exec('PRAGMA query_only=ON'); $c=cd_claim($db,'E6E183EF8E');
  $expected=json_decode($c['event_json'],true,32,JSON_THROW_ON_ERROR);
  foreach(['reader'=>'zoho-calendar.json','writer'=>'zoho-confirmation.json'] as $name=>$file) {
    try {
      $cfg=booking_calendar_config($root.'/'.$file);
      if($cfg['calendar_uid']!==$c['calendar_uid']) throw new Exception('identity');
      $reply=booking_zoho_request('https://accounts.zoho.com/oauth/v2/token',[
        'grant_type'=>'refresh_token','client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'refresh_token'=>$cfg['refresh_token']]);
      $token=$reply['body']['access_token']??null;
      if($reply['status']!==200 || !is_string($token)) throw new Exception('token');
      $reply=booking_zoho_request('https://calendar.zoho.com'.booking_calendar_event_path($c['calendar_uid'],$c['event_uid']),null,$token);
      cd_verify($reply,$expected,$c['calendar_uid'],$c['event_uid']);
      $out[$name]='PASS: existing event identity, privacy, interval and guests';
    } catch(Throwable $e) {$out[$name]='UNAVAILABLE: saved event could not be verified';}
  }
} catch(Throwable $e) {$out['calendar']='UNAVAILABLE: calendar dependencies or ledger';}
echo json_encode($out);
'''


def calendar_check():
    out = run(['runuser', '-u', 'sitesee', '--', PHP, '-r', CALENDAR_PHP], timeout=55)
    report('Existing Zoho event', json.loads(out))
    report('Calendar scope', 'Existing event only; scheduling conflicts will be rechecked by the send gate before any authorized recovery')


def crm_check(row, owner):
    report('Saved CRM contact ID', row.get('crm_contact_id') or 'NOT RECORDED')
    source = (ROOT / 'server/booking-invitation.php').read_bytes().replace(b'\r\n', b'\n')
    known = hashlib.sha256(source).hexdigest() == KNOWN_INVITATION
    report('Invitation implementation matches audited release', known)
    report('CRM email association in this invitation code', 'NOT IMPLEMENTED' if known else 'SOURCE CHANGED - REVIEW REQUIRED')
    found = []
    for path in sorted(ROOT.glob('zoho*.json')):
        if path.name in ('zoho-calendar-runtime.json',):
            continue
        cfg = check('Connection metadata ' + path.name, lambda p=path: private_json(p, owner))
        if cfg is None:
            continue
        scopes = cfg.get('requested_scopes', [])
        if isinstance(scopes, str):
            scopes = re.split(r'[ ,]+', scopes)
        crm = [s for s in scopes if isinstance(s, str) and s.startswith('ZohoCRM.')] if isinstance(scopes, list) else []
        if crm:
            found.append({'file': path.name, 'declared_crm_scopes': crm})
    report('CRM scope metadata in booking private Zoho configs', found or 'NONE FOUND; calendar access does not establish CRM access')
    report('CRM account qualification', 'Live CRM contact identity and native mailbox sync are not verified by local metadata')


def main():
    if len(sys.argv) != 1 or os.geteuid() != 0:
        raise SystemExit('Run without arguments in WHM Terminal as root. This tool has no write mode.')
    owner = pwd.getpwnam('sitesee').pw_uid
    report('Booking integration report', REF)
    report('Target sender', SENDER)
    report('Mode', 'READ ONLY - collect independent results; do not resend')
    row = check('Booking record', booking)
    if row:
        report('Booking and invitation state', {k: row[k] for k in
               ('status', 'state', 'invitation_state', 'invitation_recipient', 'invitation_attempted_at', 'invitation_sent_at')})
        report('Paid test and staff review recorded', row['status'] == 'deposit_paid_test' and bool(row['deposit_paid_at']) and bool(row['approved_at']))
    check('FPM mail configuration', fpm_settings)
    check('Existing Graph bridge', bridge_details)
    connection = check('Existing Graph connection', lambda: graph_connection(owner))
    if connection:
        cfg, token = connection
        check('New mailbox read access', lambda: mailbox_read(token, SENDER))
        if row and row['invitation_attempted_at']:
            check('Previous invitation trace', lambda: old_invitation_trace(token, cfg['sender'], row))
            check('Recipient mailbox trace (alias lookup may be unsupported)', lambda: recipient_trace(token, row))
    check('DNS and local routing', dns_checks)
    if row:
        check('Zoho calendar', calendar_check)
        check('CRM linkage', lambda: crm_check(row, owner))
        after = check('Final ledger read', booking)
        report('Booking snapshot unchanged during report', after == row)
    report('Integration status', 'READINESS EVIDENCE COLLECTED; application wiring and controlled invitation recovery remain pending')
    report('Changes', '0 sends; 0 drafts; 0 booking/event/CRM writes; 0 payment calls; 0 configuration changes')


if __name__ == '__main__':
    main()
