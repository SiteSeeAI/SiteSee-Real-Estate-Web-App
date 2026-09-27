#!/usr/bin/env python3
"""Install the staged Microsoft TEST connection and run one recoverable write test.

Default: install, then create/read/delete ONE private, free, no-attendee event.
--check: inspect local release only; no provider calls or writes.
Existing booking controllers, database, credentials and provider settings stay intact.
"""
import base64
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys
import tempfile
import zlib

ROOT = Path('/home/sitesee/.sitesee-real-estate')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
REVISION = '20260927-r1'
RELEASE = 'sitesee-microsoft-calendar-connection-test-v1'
NAMES = ('server/booking-microsoft-calendar.php', 'tools/verify-microsoft-calendar-connection.php')
PAYLOAD = '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'
PAYLOAD_SHA = 'cda420df462e1b0a86f46ce27ef2a064e5564fa9f6a4d053caeb5b5e25150d01'
CONFIG = {'schema': 1, 'stage': 'test', 'mailbox': 'sales@re.sitesee.ai',
          'application_id': '125a86f5-2a29-4b1e-8b8a-26026e3fa59c',
          'calendar_id': 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0AhxzFTH7h2UC_-_f2aEjbcQAAAAA0UAAA',
          'graph_credentials': '/home/sitesee/.sitesee-graph-mail.json',
          'scheduling_enabled': False, 'invitations_enabled': False}


class InstallError(Exception):
    pass


def need(ok, message):
    if not ok:
        raise InstallError(message)


def digest(data):
    return hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()


def encode(value):
    return (json.dumps(value, indent=2, sort_keys=True) + '\n').encode()


def payload():
    raw = zlib.decompress(base64.b64decode(PAYLOAD))
    need(hashlib.sha256(raw).hexdigest() == PAYLOAD_SHA, 'Installer payload checksum failed.')
    files = json.loads(raw)
    need(set(files) == set(NAMES), 'Installer payload paths differ.')
    return {name: content.encode() for name, content in files.items()}


def safe_path(root, name):
    need(not Path(name).is_absolute() and '..' not in Path(name).parts, 'Unsafe release path.')
    path = root / name
    for entry in [path] + list(path.parents):
        need(not entry.is_symlink(), 'Symbolic link in deployment path: ' + name)
    return path


def private_bytes(path, uid, limit=1048576, private=True):
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as handle:
        info = os.fstat(handle.fileno())
        forbidden = 0o077 if private else 0o022
        need(stat.S_ISREG(info.st_mode) and info.st_uid == uid and info.st_nlink == 1
             and not info.st_mode & forbidden and info.st_size <= limit,
             'Private file permissions, owner or size differ: ' + path.name)
        data = handle.read(limit + 1)
        need(len(data) <= limit, 'Private file exceeds its size limit: ' + path.name)
        return data


def inspect(root, files, php, uid, credentials=None):
    info = root.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == uid and not info.st_mode & 0o022,
         'Existing private application directory is unsafe.')
    for folder in ('server', 'tools'):
        path = safe_path(root, folder)
        s = path.stat()
        need(path.is_dir() and s.st_uid == uid and not s.st_mode & 0o022, 'Existing private application subdirectory is unsafe: ' + folder)
    for name, release in [('calendar-confirmation-release.json', 'sitesee-calendar-confirmation-test-v1'),
                          ('branded-checkout-release.json', 'sitesee-branded-checkout-test-v1')]:
        record = json.loads(private_bytes(safe_path(root, name), uid, private=False))
        need(record.get('release') == release and isinstance(record.get('files'), dict) and record['files'],
             'Existing release manifest differs: ' + name)
        required = ('server/booking-store.php', 'server/booking-confirmation.php', 'server/booking-invitation.php',
                    'server/booking-mail-client.php', 'server/booking-calendar-client.php') if name.startswith('calendar') else ('server/booking-pay.php', 'server/booking-checkout.php')
        need(all(key in record['files'] for key in required), 'Existing release manifest is incomplete: ' + name)
        for target, expected in record['files'].items():
            need(re.fullmatch(r'(?:server|tools|views|payment-assets)/[a-z0-9.-]+', target)
                 and isinstance(expected, str) and re.fullmatch(r'[a-f0-9]{64}', expected), 'Existing release entry is invalid.')
            need(digest(private_bytes(safe_path(root, target), uid, private=False)) == expected, 'Active release check failed: ' + target)
    mail = json.loads(private_bytes(safe_path(root, 'booking-mail.json'), uid, 65536))
    need(mail.get('stage') == 'test' and mail.get('sender') == CONFIG['mailbox']
         and mail.get('graph_credentials') == CONFIG['graph_credentials']
         and mail.get('test_recipient_email') == 'cro@sitesee.ai', 'Existing TEST mail configuration differs.')
    secret = json.loads(private_bytes(credentials or Path(CONFIG['graph_credentials']), uid, 65536))
    need(str(secret.get('client_id', '')).lower() == CONFIG['application_id']
         and re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', str(secret.get('tenant_id', '')))
         and isinstance(secret.get('client_secret'), str) and secret['client_secret'], 'Existing Microsoft application identity differs.')
    runtime = subprocess.run([php, '-r', 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("curl") && function_exists("fsync") ? 0 : 1);'],
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    need(runtime.returncode == 0, 'PHP 8.2+ with cURL and fsync is required.')
    with tempfile.TemporaryDirectory(prefix='sitesee-ms-lint-') as directory:
        for name, content in files.items():
            target = Path(directory) / Path(name).name
            target.write_bytes(content)
            run = subprocess.run([php, '-l', str(target)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            need(run.returncode == 0, 'PHP syntax check failed: ' + name)
    desired = dict(files)
    desired['microsoft-calendar.json'] = encode(CONFIG)
    desired['microsoft-calendar-connection-release.json'] = encode({'release': RELEASE, 'revision': REVISION,
        'files': {name: digest(data) for name, data in files.items()}})
    for name, content in desired.items():
        target = safe_path(root, name)
        if target.exists():
            need(private_bytes(target, uid) == content, 'An existing Microsoft connection file differs; preserved for review: ' + name)
    journal = safe_path(root, 'microsoft-calendar-probe.json')
    if journal.exists():
        private_bytes(journal, uid, 65536)
    return desired


def atomic_write(path, data, uid, gid):
    fd, temp = tempfile.mkstemp(prefix='.ms-connection-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fchmod(handle.fileno(), 0o600)
            os.fchown(handle.fileno(), uid, gid)
            os.fsync(handle.fileno())
        os.replace(temp, str(path))
        folder = os.open(str(path.parent), os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(folder)
        finally:
            os.close(folder)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def install(root, desired, uid, gid):
    # Only additive files are accepted in this release; different files are never overwritten.
    missing = [(name, data) for name, data in desired.items() if not safe_path(root, name).exists()]
    if not missing:
        return None
    base = safe_path(root, 'deployment-backups')
    if not base.exists():
        base.mkdir(mode=0o700)
    need(base.is_dir() and not base.stat().st_mode & 0o022, 'Backup directory is unsafe.')
    stamp = dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup = base / ('microsoft-calendar-connection-' + stamp)
    backup.mkdir(mode=0o700)
    atomic_write(backup / 'restore-paths.json', encode({name: 'absent before installation' for name, _ in missing}), 0 if os.geteuid() == 0 else uid, gid)
    written = []
    try:
        for name, data in missing:
            path = safe_path(root, name)
            written.append(path)
            atomic_write(path, data, uid, gid)
        for name, data in desired.items():
            need(private_bytes(root / name, uid) == data, 'Installed connection verification failed: ' + name)
    except Exception:
        failures = []
        for path in reversed(written):
            try:
                if path.exists():
                    path.unlink()
            except OSError:
                failures.append(path.name)
        if failures:
            raise InstallError('Local rollback needs review: ' + ', '.join(failures) + '. Recovery record: ' + str(backup))
        raise
    return backup


def run_probe(root, php, account):
    def as_account():
        os.initgroups(account.pw_name, account.pw_gid)
        os.setgid(account.pw_gid)
        os.setuid(account.pw_uid)
        os.umask(0o077)
    # Inherit stdout so each stage appears immediately. No secrets are command-line arguments.
    result = subprocess.run([php, '-d', 'display_errors=0', str(root / NAMES[1]), '--test'], preexec_fn=as_account)
    need(result.returncode == 0, 'Connection test stopped. Installed files and the recovery journal are retained; migration remains disabled.')


def main():
    need(sys.argv[1:] in ([], ['--check']), 'Run without arguments to install/test, or --check for local inspection only.')
    need(os.geteuid() == 0, 'Run in WHM Terminal as root.')
    account = pwd.getpwnam('sitesee')
    os.umask(0o077)
    print('Microsoft TEST calendar connection | Revision: ' + REVISION, flush=True)
    print('Installer SHA256: ' + hashlib.sha256(Path(__file__).read_bytes()).hexdigest(), flush=True)
    files = payload()
    lock = os.open(str(ROOT), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        desired = inspect(ROOT, files, PHP, account.pw_uid)
        print('Existing releases, TEST identity, payload and PHP: PASS', flush=True)
        if sys.argv[1:] == ['--check']:
            print('Local inspection only. No files or provider data changed. No Microsoft API calls.')
            return
        backup = install(ROOT, desired, account.pw_uid, account.pw_gid)
        print('Connection files: ' + ('installed' if backup else 'already installed'), flush=True)
        if backup:
            print('Recovery record: ' + str(backup), flush=True)
    finally:
        os.close(lock)
    print('Testing one private, free, no-attendee event; an unchanged rerun reuses the saved test.', flush=True)
    run_probe(ROOT, PHP, account)
    print('\nFINAL RESULTS')
    print('Microsoft TEST calendar connection: PASS')
    print('Mailbox: ' + CONFIG['mailbox'])
    print('Private test event: created, read back and removal verified (or previous completed test reused)')
    print('Scheduling migration: NOT ENABLED')
    print('Existing bookings, Zoho events, invitations, CRM, credentials and Stripe settings: unchanged')
    print('No customer event, invitation, email or payment was created; the booking database was not opened.')
    print('Next: send this FINAL RESULTS block for review before scheduling activation.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('\nFINAL RESULTS\nMicrosoft TEST calendar connection: STOPPED', file=sys.stderr)
        print(str(error) if isinstance(error, InstallError) else 'A local check or operation failed; preserve this output and any recovery journal.', file=sys.stderr)
        print('Scheduling migration: NOT ENABLED. Do not delete the probe journal or change credentials.', file=sys.stderr)
        sys.exit(1)
