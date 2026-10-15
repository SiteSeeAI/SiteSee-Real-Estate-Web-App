#!/usr/bin/env python3
"""Prepare isolated LIVE settings; activation is a separate, explicit operation."""
import argparse
import base64
import contextlib
import fcntl
import getpass
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

PRIVATE = Path('/home/sitesee/.sitesee-real-estate')
PUBLIC = Path('/home/sitesee/public_html/re')
SITE = 'https://re.sitesee.ai'
VERSION = '2026-08-26.dahlia'
EVENTS = {'checkout.session.completed', 'checkout.session.expired',
          'payment_intent.succeeded', 'payment_intent.payment_failed'}
IDENTITIES = {
    'portal_accounts': 'id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE, created_at INTEGER NOT NULL, disabled INTEGER NOT NULL DEFAULT 0',
    'portal_profiles': 'account_id TEXT PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT NOT NULL, company TEXT NOT NULL, phone TEXT NOT NULL, updated_at INTEGER NOT NULL',
    'portal_phone_identities': 'phone TEXT PRIMARY KEY, account_id TEXT NOT NULL UNIQUE, evidence TEXT NOT NULL, revision TEXT NOT NULL, created_at INTEGER NOT NULL',
    'vendor_accounts': 'id TEXT PRIMARY KEY, name TEXT NOT NULL, phone TEXT NOT NULL UNIQUE, revision TEXT NOT NULL, enabled INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL',
}


class Stop(RuntimeError):
    pass


def need(value, message):
    if not value:
        raise Stop(message)


def encoded(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()


def digest(value):
    return hashlib.sha256(value).hexdigest()


def read_private(path, uid):
    """Search-only parent descriptors avoid cPanel's directory-listing restriction."""
    parts = path.parts
    need(path.is_absolute() and '..' not in parts, 'Unexpected private path.')
    parent = os.open('/', os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW)
    file = None
    try:
        for part in parts[1:-1]:
            child = os.open(part, os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            os.close(parent)
            parent = child
        file = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        before = os.fstat(file)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == uid and before.st_nlink == 1
             and not before.st_mode & 0o077 and before.st_size <= 65536, 'Unsafe private configuration preserved: ' + path.name)
        raw = os.read(file, 65537)
        after = os.fstat(file)
        current = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
        fields = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink,
                            s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        need(len(raw) == before.st_size and fields(before) == fields(after) == fields(current),
             'Private configuration changed during reading.')
        return raw
    finally:
        if file is not None:
            os.close(file)
        os.close(parent)


def private_json(path, uid):
    result = json.loads(read_private(path, uid))
    need(isinstance(result, dict), 'Expected a private configuration object.')
    return result


def ensure_directory(path, uid):
    if not path.exists() and not path.is_symlink():
        path.mkdir(mode=0o700)
    value = path.lstat()
    need(stat.S_ISDIR(value.st_mode) and value.st_uid == uid and not value.st_mode & 0o077,
         'Private directory preserved for review: ' + path.name)


def write_json(path, value, uid, previous=None):
    data = encoded(value)
    if path.exists() or path.is_symlink():
        old = read_private(path, uid)
        if old == data:
            return
        need(previous is not None and old == previous, 'Unknown private edit preserved: ' + path.name)
    else:
        need(previous is None, 'Private state disappeared during update.')
    fd, temporary = tempfile.mkstemp(prefix='.live-', dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'wb') as file:
            file.write(data)
            file.flush()
            os.fsync(file.fileno())
        # The application root is exclusively locked by every writer.
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


@contextlib.contextmanager
def root_lock(root, timeout=60):
    file = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    start = time.monotonic()
    try:
        while True:
            try:
                fcntl.flock(file, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                need(time.monotonic() - start < timeout, 'Existing SiteSee requests/worker must finish before activation.')
                time.sleep(0.25)
        yield
    finally:
        os.close(file)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise Stop('Provider redirect refused.')


def provider_read(url, authorization):
    need(url.startswith(('https://api.stripe.com/v1/', 'https://api.twilio.com/2010-04-01/',
                         'https://verify.twilio.com/v2/')), 'Unexpected provider host.')
    request = urllib.request.Request(url, headers={'Authorization': authorization, 'Accept': 'application/json'}, method='GET')
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=25) as response:
            raw = response.read(2097153)
            need(response.status == 200 and len(raw) <= 2097152, 'Provider read could not be verified.')
        result = json.loads(raw)
        need(isinstance(result, dict), 'Provider read returned an unexpected object.')
        return result
    except (urllib.error.URLError, ValueError):
        raise Stop('Provider authentication/readiness could not be verified; no response details exposed.') from None


def provider_check(secret, sms, read=provider_read):
    account = read('https://api.stripe.com/v1/account', 'Bearer ' + secret)
    need(re.fullmatch(r'acct_[A-Za-z0-9]{8,64}', str(account.get('id', '')))
         and account.get('charges_enabled') is True and account.get('details_submitted') is True,
         'Stripe LIVE merchant is not ready for charges.')
    endpoints = read('https://api.stripe.com/v1/webhook_endpoints?limit=100', 'Bearer ' + secret)
    need(endpoints.get('has_more') is False and isinstance(endpoints.get('data'), list), 'Webhook inventory is incomplete.')
    owned = [item for item in endpoints['data'] if item.get('url') == SITE + '/booking-live-webhook.php']
    need(len(owned) == 1 and owned[0].get('status') == 'enabled' and owned[0].get('livemode') is True
         and owned[0].get('api_version') == VERSION and EVENTS <= set(owned[0].get('enabled_events', [])),
         'Configure one enabled LIVE webhook at ' + SITE + '/booking-live-webhook.php with the reviewed API version/events.')
    auth = 'Basic ' + base64.b64encode((sms['account_sid'] + ':' + sms['auth_token']).encode()).decode()
    twilio = read('https://api.twilio.com/2010-04-01/Accounts/' + sms['account_sid'] + '.json', auth)
    need(twilio.get('sid') == sms['account_sid'] and twilio.get('status') == 'active' and twilio.get('type') == 'Full',
         'Twilio must be an active upgraded account.')
    service = read('https://verify.twilio.com/v2/Services/' + sms['service_sid'], auth)
    need(service.get('sid') == sms['service_sid'] and service.get('account_sid') == sms['account_sid'], 'Twilio Verify service ownership differs.')
    return {'stripe_account': account['id'], 'webhook_endpoint': owned[0]['id'], 'twilio': 'active Full account and owned Verify service'}


def copy_identities(source, destination, control, uid):
    """Only nonfinancial identity/profile tables cross realms; no grants or sessions."""
    for path in [source, destination]:
        if not path.exists() and not path.is_symlink():
            need(path == destination, 'Preserved TEST ledger is unavailable.')
            fd=os.open(path, os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW, 0o600)
            os.close(fd)
        value=path.lstat()
        need(stat.S_ISREG(value.st_mode) and value.st_nlink == 1 and value.st_uid == uid
             and not value.st_mode & 0o077, 'Ledger metadata differs: ' + path.name)
        for suffix in ['-wal','-shm','-journal']:
            companion=Path(str(path)+suffix)
            if companion.exists() or companion.is_symlink():
                metadata=companion.lstat()
                need(stat.S_ISREG(metadata.st_mode) and metadata.st_nlink == 1 and metadata.st_uid == uid
                     and not metadata.st_mode & 0o077, 'SQLite companion requires review.')
    counts = {}
    with contextlib.closing(sqlite3.connect(source.as_uri() + '?mode=ro', uri=True)) as old:
        old.execute('PRAGMA query_only=ON')
        old.execute('BEGIN')
        need(old.execute('PRAGMA integrity_check').fetchone() == ('ok',), 'TEST ledger integrity needs review.')
        with sqlite3.connect(destination) as new:
            new.execute('CREATE TABLE IF NOT EXISTS application_realm (singleton INTEGER PRIMARY KEY CHECK(singleton=1), stage TEXT NOT NULL, realm TEXT NOT NULL, stripe_account TEXT NOT NULL)')
            existing = new.execute('SELECT stage,realm,stripe_account FROM application_realm').fetchall()
            expected = ('LIVE', control['realm'], control['stripe_account'])
            need(existing in ([], [expected]), 'Prepared database binding differs.')
            if not existing:
                new.execute('INSERT INTO application_realm VALUES (1,?,?,?)', expected)
            for (table,) in new.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'").fetchall():
                if table not in set(IDENTITIES) | {'application_realm'}:
                    need(new.execute('SELECT COUNT(*) FROM "' + table.replace('"', '""') + '"').fetchone()[0] == 0,
                         'LIVE operational records exist; preserve them and repair forward.')
            for table, schema in IDENTITIES.items():
                new.execute('CREATE TABLE IF NOT EXISTS ' + table + ' (' + schema + ')')
                new.execute('DELETE FROM ' + table)
                if not old.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone():
                    counts[table] = 0
                    continue
                columns = [row[1] for row in new.execute('PRAGMA table_info(' + table + ')')]
                need(columns == [row[1] for row in old.execute('PRAGMA table_info(' + table + ')')], 'Identity schema differs: ' + table)
                rows = old.execute('SELECT ' + ','.join(columns) + ' FROM ' + table).fetchall()
                new.executemany('INSERT INTO ' + table + ' VALUES (' + ','.join('?' for _ in columns) + ')', rows)
                counts[table] = len(rows)
    destination.chmod(0o600)
    return counts


def prepare(root, credentials, read=provider_read):
    uid = root.stat().st_uid
    need(uid == os.geteuid(), 'Run this command in cPanel Terminal as sitesee.')
    need(root==root.resolve() and stat.S_ISDIR(root.lstat().st_mode) and stat.S_IMODE(root.stat().st_mode)==0o700,
         'Application root must remain canonical and private.')
    need(not (root/'application-live.json').exists(), 'LIVE is already active; preparation cannot overwrite it.')
    sms = private_json(root/'portal-sms.json', uid)
    need(sms.get('stage') == 'TEST' and sms.get('provider') == 'twilio-verify', 'Existing SMS configuration needs review.')
    need(re.fullmatch(r'sk_live_[A-Za-z0-9_]{12,}', credentials['secret'])
         and re.fullmatch(r'pk_live_[A-Za-z0-9]{12,512}', credentials['publishable'])
         and re.fullmatch(r'whsec_[A-Za-z0-9_]{12,}', credentials['webhook']), 'Enter LIVE Stripe credentials; TEST credentials are refused.')
    checked = provider_check(credentials['secret'], sms, read)
    live = root/'runtime/live'
    with root_lock(root):
        need(not (root/'application-live.json').exists() and not (root/'application-live.json').is_symlink(),
             'Activation has started or completed; preparation cannot overwrite LIVE identities.')
        history=root/'live-activation-journal.json'
        if history.exists() or history.is_symlink():
            need(private_json(history,uid).get('state')=='prepared',
                 'LIVE history requires repair forward; preparation cannot reset it.')
        for directory in [root/'runtime', live, live/'data']:
            ensure_directory(directory, uid)
        plan_path = live/'.preparation-plan.json'
        if plan_path.exists() or plan_path.is_symlink():
            plan = private_json(plan_path, uid)
            need(plan['control']['stripe_account'] == checked['stripe_account'], 'Prepared merchant differs; preserve files.')
            stored = plan['files']['environment.json']['values']
            need(stored['SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET'] == credentials['secret']
                 and stored['SITESEE_REAL_ESTATE_STRIPE_LIVE_WEBHOOK_SECRET'] == credentials['webhook']
                 and plan['files']['booking-checkout.json']['publishable_key'] == credentials['publishable'], 'Prepared credentials differ; review rotation without overwriting them.')
            if history.exists():
                journal=private_json(history,uid)
                need(journal.get('realm')==plan['control']['realm'] and journal.get('plan_sha256')==digest(encoded(plan)),
                     'Prepared history differs; preserve identity and configuration.')
        else:
            control = {'format': 1, 'stage': 'LIVE', 'enabled': True, 'realm': secrets.token_hex(16), 'stripe_account': checked['stripe_account']}
            values = {'SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET': credentials['secret'],
                      'SITESEE_REAL_ESTATE_STRIPE_LIVE_WEBHOOK_SECRET': credentials['webhook'],
                      'SITESEE_REAL_ESTATE_PRICING_GATE_SECRET': secrets.token_hex(32),
                      'SITESEE_REAL_ESTATE_SITE_URL': SITE}
            files = {'environment.json': {'stage': 'LIVE', 'realm': control['realm'], 'values': values},
                     'booking-checkout.json': {'stage': 'LIVE', 'enabled': True, 'publishable_key': credentials['publishable']},
                     'portal-test.json': {'release': 'portal-20260929-r2', 'stage': 'LIVE', 'enabled': True},
                     'booking-lifecycle.json': {'schema': 1, 'stage': 'live', 'enabled': True, 'recipient': 'booking-customer'}}
            sms.update(stage='LIVE', allowed_numbers=[], recipient_policy='authentication-only')
            files['portal-sms.json'] = sms
            graph_source = root.parent/'.sitesee-graph-mail.json'
            files['graph-mail.json'] = private_json(graph_source, uid)
            for name in ['microsoft-calendar.json','microsoft-scheduling.json','booking-mail.json','zoho-crm.json']:
                value = private_json(root/name, uid)
                if name != 'zoho-crm.json':
                    need(value.get('stage') == 'test', 'Preserved provider mode differs: ' + name)
                    value['stage'] = 'live'
                if 'graph_credentials' in value:
                    need(value['graph_credentials'] == str(graph_source), 'Unexpected Graph credential path preserved.')
                    value['graph_credentials'] = str(live/'graph-mail.json')
                if 'test_recipient_email' in value:
                    value['test_recipient_email'] = ''
                if 'confirmation_stage' in value:
                    value['confirmation_stage'] = 'live'
                files[name] = value
            plan = {'format': 1, 'control': control, 'files': files, 'providers': checked}
            write_json(plan_path, plan, uid)
        for name, value in plan['files'].items():
            write_json(live/name, value, uid)
        counts = copy_identities(root/'data/bookings.sqlite', live/'data/bookings.sqlite', plan['control'], uid)
        write_json(root/'application-live-ready.json', plan['control'], uid)
        journal = {'format': 1, 'state': 'prepared', 'realm': plan['control']['realm'],
                   'stripe_account': checked['stripe_account'], 'plan_sha256': digest(encoded(plan)),
                   'configuration': {name: digest(encoded(value)) for name,value in plan['files'].items()}}
        write_json(root/'live-activation-journal.json', journal, uid)
    return {'result': 'LIVE PREPARED; TEST STILL ACTIVE', 'stripe_account': checked['stripe_account'],
            'identity_counts': counts, 'financial_data_copied': False, 'provider_writes': 'none', 'activation': 'not performed'}


def check_prepared(root, php='php', read=provider_read):
    uid = root.stat().st_uid
    journal = private_json(root/'live-activation-journal.json', uid)
    plan = private_json(root/'runtime/live/.preparation-plan.json', uid)
    need(digest(encoded(plan)) == journal['plan_sha256'], 'Preparation plan changed; originals preserved.')
    need(private_json(root/'application-live-ready.json', uid) == plan['control'], 'Prepared activation identity differs.')
    for name, expected in journal['configuration'].items():
        need(digest(read_private(root/'runtime/live'/name, uid)) == expected, 'Prepared configuration changed: ' + name)
    provider_check(plan['files']['environment.json']['values']['SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET'], plan['files']['portal-sms.json'], read)
    need((root/'server/application-mode.php').is_file(), 'Install the reviewed mode-capable code before activation.')
    code = "define('SITESEE_PREPARE_LIVE',true);foreach(['application','portal-release','portal-sms','portal-purchase','portal-billing','vendor-access'] as $name)require_once $argv[1].'/server/'.$name.'.php';site_application_bootstrap($argv[1]);$db=booking_db();portal_access_schema($db);portal_profile_schema($db);portal_phone_schema($db);portal_purchase_schema($db);portal_billing_schema($db);booking_communication_schema($db);vendor_schema($db);booking_job_schema($db);if(!portal_release_enabled(site_runtime_path('portal-test.json'))||!booking_lifecycle_enabled()||booking_test_enabled())throw new RuntimeException('Prepared gates differ');booking_checkout_config();portal_sms_config();booking_mail_config(true);booking_crm_config();booking_scheduling_config();booking_ms_validate_config(booking_ms_private_json(site_runtime_path('microsoft-calendar.json')));echo json_encode(['mode'=>site_runtime_mode(),'realm'=>site_runtime_control()['realm'],'provider_writes_enabled'=>booking_test_enabled()]);"
    # Loading configuration and creating empty LIVE schemas are preparation only.
    result = subprocess.run([php, '-r', code, str(root)], capture_output=True, text=True, timeout=30)
    need(result.returncode == 0, 'Private LIVE configuration/schema check failed; inspect local PHP configuration without exposing secrets.')
    proof = json.loads(result.stdout)
    need(proof == {'mode':'LIVE','realm':journal['realm'],'provider_writes_enabled':False}, 'Prepared PHP binding differs.')
    return journal, plan


def verify_code_release(root, approved_release, uid):
    installed=private_json(root/'unified-release.json',uid)
    need(installed.get('release')==approved_release and installed.get('runtime_modes')==['TEST','LIVE']
         and installed.get('default_runtime_mode')=='TEST' and installed.get('stage')=='TEST'
         and re.fullmatch('[0-9a-f]{40}',str(installed.get('commit','')))
         and installed['commit'][:12]==approved_release.removeprefix('unified-test-'),
         'Exact approved mode-capable code release must be installed first.')
    files=installed.get('files')
    required={'private/server/application.php','private/server/application-mode.php','public/application-entry.php',
              'public/booking-live-webhook.php','public/booking-live-pay.php','public/manage-live-appointment.php',
              'public/pricing-live-approve.php','public/pricing-live-confirm.php',
              'public/staff-test-bookings.php','public/staff-test-production.php','public/staff-test-vendors.php'}
    need(isinstance(files,dict) and required<=set(files), 'Installed code inventory is incomplete.')
    for name,expected in files.items():
        parts=name.split('/')
        need(len(parts)>1 and parts[0] in ['private','public'] and not set(parts)&{'','..','.'}
             and re.fullmatch('[0-9a-f]{64}',str(expected)), 'Installed code path/hash differs.')
        path=(root if parts[0]=='private' else PUBLIC).joinpath(*parts[1:])
        need(path==path.resolve(), 'Installed code alias preserved.')
        value=path.lstat()
        need(stat.S_ISREG(value.st_mode) and value.st_uid==uid and value.st_nlink==1
             and not value.st_mode&0o022 and value.st_size<=134217728,
             'Installed code metadata differs: '+name)
        need(digest(path.read_bytes())==expected, 'Installed code differs: '+name)
    return installed


def activate(root, approved_release, php='php', read=provider_read):
    uid = root.stat().st_uid
    need(uid == os.geteuid(), 'Run activation as sitesee.')
    journal, plan = check_prepared(root, php, read)
    with root_lock(root):
        verify_code_release(root,approved_release,uid)
        need(not (root/'.unified-maintenance.json').exists(), 'Finish the code installation before activation.')
        old = read_private(root/'live-activation-journal.json', uid)
        current = json.loads(old)
        need(current == journal and current.get('state') in ['prepared','active'], 'Activation journal changed; preserve it.')
        for name, expected in journal['configuration'].items():
            need(digest(read_private(root/'runtime/live'/name, uid)) == expected, 'Prepared configuration changed before activation.')
        control = plan['control'] | {'initial_code_release': approved_release}
        marker=root/'application-live.json'
        if current['state']=='active':
            need(private_json(marker,uid)==control and current.get('initial_code_release')==approved_release,
                 'Active binding differs; preserve history and repair forward.')
            return {'result':'LIVE ALREADY ACTIVE; NO FILES CHANGED','release':approved_release,'stage':'LIVE',
                    'stripe_account':control['stripe_account'],'provider_writes':'none'}
        # If the marker was durably saved before interruption, the prepared journal
        # keeps web traffic closed. Resume only the same activation identity.
        if marker.exists() or marker.is_symlink():
            need(private_json(marker,uid)==control, 'Incomplete activation identity differs; preserve it.')
            counts={}
        else:
            counts = copy_identities(root/'data/bookings.sqlite', root/'runtime/live/data/bookings.sqlite', plan['control'], uid)
        write_json(root/'application-live.json', control, uid)
        write_json(root/'live-activation-journal.json', journal | {'state':'active','initial_code_release':approved_release,'activated_at':int(time.time())}, uid, old)
    return {'result':'LIVE ACTIVATED','release':approved_release,'stage':'LIVE','stripe_account':control['stripe_account'],
            'identity_counts':counts,'test_ledger':'preserved separately','financial_data_copied':False,
            'provider_writes':'none during activation','connected_live_payment_acceptance':'pending'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--prepare', action='store_true')
    action.add_argument('--check', action='store_true')
    action.add_argument('--activate', action='store_true')
    parser.add_argument('--approved-release')
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    args = parser.parse_args()
    try:
        if args.prepare:
            result = prepare(PRIVATE, {'secret':getpass.getpass('Stripe LIVE secret key (hidden): '),
                                      'publishable':getpass.getpass('Matching LIVE publishable key (hidden): '),
                                      'webhook':getpass.getpass('LIVE webhook signing secret (hidden): ')})
        elif args.activate:
            need(args.approved_release and re.fullmatch('unified-test-[0-9a-f]{12}', args.approved_release), 'Exact approved release is required.')
            result = activate(PRIVATE, args.approved_release, args.php)
        else:
            journal, _ = check_prepared(PRIVATE, args.php)
            result = {'result':'LIVE CONFIGURATION CHECK PASS; ACTIVATION NOT PERFORMED','realm':journal['realm'],'stripe_account':journal['stripe_account']}
        print(json.dumps(result))
    except Exception as error:
        print(json.dumps({'result':'STOPPED','reason':str(error) if isinstance(error,Stop) else type(error).__name__,
                          'preservation':'Keep TEST data, prepared files and journals. No unknown edits are overwritten.'}))
        raise SystemExit(1)
