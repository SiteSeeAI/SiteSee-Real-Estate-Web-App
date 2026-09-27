#!/usr/bin/env python3
"""One-run local inventory, verified permission repair, install and TEST verification.

No additional downloads, packages, keys or input are needed on the server.
--check reports every independent local blocker/repair without changing anything.
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
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import types
import zlib

REVISION = '20260927-r3'
INSTALLER = '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'
INSTALLER_SHA = 'e5a8f78e4344069f64ad97cd279535a347e6ca63267df41cf3f98c9240c58f2d'
MAIL_CLIENT_SHA = '189e15f44c3c28856765527efdcf25b71f748add07d6e7d31c396d50d3ed5e5b'
MANIFESTS = {'calendar-confirmation-release.json': 'sitesee-calendar-confirmation-test-v1',
             'branded-checkout-release.json': 'sitesee-branded-checkout-test-v1'}


def embedded_installer():
    raw = zlib.decompress(base64.b64decode(INSTALLER))
    if hashlib.sha256(raw).hexdigest() != INSTALLER_SHA:
        raise RuntimeError('Embedded installer integrity check failed.')
    module = types.ModuleType('sitesee_embedded_calendar_installer')
    module.__file__ = __file__
    exec(compile(raw, 'reviewed-calendar-installer', 'exec'), module.__dict__)
    return module


def safe_error(error):
    if isinstance(error, OSError):
        return 'filesystem/process error errno=' + str(error.errno) + '; path=' + str(error.filename or '(not provided)')
    # Do not render exception values from JSON, credentials or provider bodies.
    return 'check could not complete (' + type(error).__name__ + ')'


class Inventory:
    def __init__(self, module, root, uid):
        self.m, self.root, self.uid = module, root, uid
        self.errors = []
        self.repairs = {}
        self.checked = 0
        self.readable = set()
        self.folders = {root}

    def issue(self, text):
        if text not in self.errors:
            self.errors.append(text)

    def guarded(self, label, action):
        try:
            return action()
        except self.m.InstallError as error:
            self.issue(label + ': ' + str(error))
        except Exception as error:
            self.issue(label + ': ' + safe_error(error))
        return None

    def path(self, value):
        # Check all existing ancestors without following a symbolic link.
        path = Path(value)
        for item in [path] + list(path.parents):
            if item.is_symlink():
                raise self.m.InstallError('Symbolic link is preserved for review: ' + str(item))
        return path

    def plan_mode(self, path, info, target, content=None):
        current = stat.S_IMODE(info.st_mode)
        if current == target:
            return
        # This runner only REMOVES permissions. It never adds access or changes ownership.
        self.m.need(target & ~current == 0, 'Repair attempted to add permissions.')
        self.repairs[str(path)] = {'path': str(path), 'old_mode': current, 'new_mode': target,
            'uid': info.st_uid, 'gid': info.st_gid, 'device': info.st_dev, 'inode': info.st_ino,
            'directory': stat.S_ISDIR(info.st_mode),
            'fingerprint': None if content is None else hashlib.sha256(content).hexdigest()}

    def directory(self, path, trust_root=False):
        def check():
            p = self.path(path)
            info = p.lstat()
            self.m.need(stat.S_ISDIR(info.st_mode), 'Not a real directory: ' + str(p))
            self.m.need(info.st_uid in ((self.uid,) if trust_root else (0, self.uid)),
                        'Unexpected owner: ' + str(p) + '; ' + self.m.metadata(info))
            self.m.need(not info.st_mode & 0o7000, 'Special directory mode needs review: ' + str(p))
            if trust_root:
                self.m.need(not info.st_mode & 0o022, 'Application root is writable by group/others; preserve for review: ' + str(p))
            else:
                self.plan_mode(p, info, stat.S_IMODE(info.st_mode) & ~0o022)
            self.checked += 1
            return True
        return self.guarded('Directory', check)

    def file(self, path, private=False, optional=False):
        def check():
            p = self.path(path)
            if optional and not p.exists():
                return None
            # NONBLOCK ensures a substituted FIFO cannot hang this root process.
            fd = os.open(str(p), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            with os.fdopen(fd, 'rb') as handle:
                info = os.fstat(handle.fileno())
                self.m.need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_size <= 1048576,
                            'File type, links or size need review: ' + str(p) + '; ' + self.m.metadata(info))
                self.m.need(info.st_uid in ((self.uid,) if private else (0, self.uid)),
                            'Unexpected owner: ' + str(p) + '; ' + self.m.metadata(info))
                self.m.need(not info.st_mode & 0o7000, 'Special file mode needs review: ' + str(p))
                data = handle.read(1048577)
                self.m.need(len(data) <= 1048576, 'File exceeds read limit: ' + str(p))
                self.plan_mode(p, info, stat.S_IMODE(info.st_mode) & ~(0o077 if private else 0o022), data)
                self.checked += 1
                return data
        return self.guarded(str(path), check)

    def json(self, path, private=False, optional=False):
        data = self.file(path, private, optional)
        if data is None:
            return None
        def parse():
            value = json.loads(data)
            self.m.need(isinstance(value, dict), 'JSON object is required.')
            return value
        return self.guarded(str(path), parse)


def desired_files(m, files):
    desired = dict(files)
    desired['microsoft-calendar.json'] = m.encode(m.CONFIG)
    desired['microsoft-calendar-connection-release.json'] = m.encode({'release': m.RELEASE,
        'revision': m.PAYLOAD_REVISION, 'files': {name: m.digest(data) for name, data in files.items()}})
    return desired


def valid_journal(j, config):
    if not isinstance(j, dict):
        return False
    if any(j.get(k) != config[k] for k in ('mailbox', 'calendar_id', 'application_id')):
        return False
    if (type(j.get('schema')) is not int or j['schema'] != 1 or not re.fullmatch(r'[a-f0-9]{32}', str(j.get('transaction_id', '')))
            or type(j.get('start')) is not int or j['start'] <= 0 or not isinstance(j.get('event_id'), str)
            or j.get('state') not in ('prepared', 'create_started', 'identified', 'delete_started', 'complete')):
        return False
    if j['state'] in ('identified', 'delete_started', 'complete') and not re.fullmatch(r'[\x21-\x7e]{1,2048}', j['event_id']):
        return False
    if j['state'] in ('delete_started', 'complete') and (type(j.get('verified_at')) is not int or j['verified_at'] <= 0):
        return False
    return j['state'] != 'complete' or type(j.get('completed_at')) is int


def inventory(m, root, files, php, uid, account=None, credentials=None):
    result = Inventory(m, root, uid)
    root_ok = result.directory(root, trust_root=True)
    for folder in ('server', 'tools'):
        result.folders.add(root / folder)
        result.directory(root / folder)
    seen = {}
    for name, release in MANIFESTS.items():
        record = result.json(root / name)
        if record is None:
            continue
        entries = record.get('files')
        if record.get('release') != release or not isinstance(entries, dict) or not entries:
            result.issue('Existing release manifest is invalid: ' + name)
            continue
        required = ('server/booking-store.php', 'server/booking-confirmation.php', 'server/booking-invitation.php',
                    'server/booking-mail-client.php', 'server/booking-calendar-client.php') if name.startswith('calendar') else ('server/booking-pay.php', 'server/booking-checkout.php')
        for key in required:
            if key not in entries:
                result.issue('Required release entry missing: ' + name + ': ' + key)
        for target, expected in entries.items():
            if not re.fullmatch(r'(?:server|tools|views|payment-assets)/[a-z0-9.-]+', target) or not isinstance(expected, str) or not re.fullmatch(r'[a-f0-9]{64}', expected):
                result.issue('Invalid release entry in ' + name)
                continue
            path = root / target
            if path.parent not in result.folders:
                result.folders.add(path.parent)
                result.directory(path.parent)
            if target in seen:
                data = seen[target]
            else:
                data = result.file(path)
                seen[target] = data
            if data is not None and m.digest(data) != expected:
                result.issue('Active release hash differs: ' + target + ' (' + name + ')')
            if not target.startswith('tools/'):
                result.readable.add(path)
    # The only preexisting PHP module loaded by the new connection is pinned
    # independently of writable filesystem metadata and local manifests.
    dependency = seen.get('server/booking-mail-client.php')
    if dependency is None or m.digest(dependency) != MAIL_CLIENT_SHA:
        result.issue('Existing mail transport differs from the reviewed connection dependency; preserve it for review.')
    mail = result.json(root / 'booking-mail.json', private=True)
    if mail is not None and not (mail.get('stage') == 'test' and mail.get('sender') == m.CONFIG['mailbox']
            and mail.get('graph_credentials') == m.CONFIG['graph_credentials'] and mail.get('test_recipient_email') == 'cro@sitesee.ai'):
        result.issue('Existing TEST mail identity/configuration differs; settings were not rewritten.')
    secret_path = credentials or Path(m.CONFIG['graph_credentials'])
    secret = result.json(secret_path, private=True)
    if secret is not None and not (str(secret.get('client_id', '')).lower() == m.CONFIG['application_id']
            and re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', str(secret.get('tenant_id', '')))
            and isinstance(secret.get('client_secret'), str) and secret['client_secret']):
        result.issue('Existing Microsoft application identity/credential structure differs; credentials were not rewritten.')
    result.readable.update([root / 'booking-mail.json', secret_path])
    for name, data in desired_files(m, files).items():
        current = result.file(root / name, private=True, optional=True)
        if current is not None:
            if current != data:
                result.issue('Existing connection file differs; preserved: ' + name)
            result.readable.add(root / name)
    journal = result.json(root / 'microsoft-calendar-probe.json', private=True, optional=True)
    if journal is not None:
        if not valid_journal(journal, m.CONFIG):
            result.issue('Existing temporary-event recovery journal is invalid; preserve it for review.')
        result.readable.add(root / 'microsoft-calendar-probe.json')
    backup = root / 'deployment-backups'
    if backup.exists() or backup.is_symlink():
        result.directory(backup)
    def capacity():
        need_bytes = max(8 * 1024 * 1024, sum(map(len, files.values())) * 4)
        m.need(shutil.disk_usage(root).free >= need_bytes, 'Insufficient free disk space for installation and recovery records.')
        m.need(os.statvfs(str(root)).f_favail >= 32, 'Insufficient free filesystem entries for installation/recovery.')
    result.guarded('Disk capacity', capacity)
    with tempfile.TemporaryDirectory(prefix='sitesee-ms-full-preflight-') as directory:
        for name, data in files.items():
            def lint(name=name, data=data):
                target = Path(directory) / Path(name).name
                target.write_bytes(data)
                r = subprocess.run([php, '-l', str(target)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
                m.need(r.returncode == 0, 'PHP syntax check failed: ' + name)
            result.guarded('PHP package syntax', lint)
    # Independent of mode/hash checks; this collects all unreadable paths in one
    # real application-user process. Do not execute any existing application code.
    result.guarded('Application-user PHP access', lambda: m.runtime_check(php, root,
        sorted(result.folders), result.readable, uid, account))
    if not root_ok:
        result.issue('Application root must be reviewed before any repair can be applied.')
    return result


def show_inventory(report, emit=print):
    emit('Local inventory: ' + str(report.checked) + ' file/directory checks; '
         + str(len(report.repairs)) + ' permission repairs; ' + str(len(report.errors)) + ' blocking findings.')
    for repair in sorted(report.repairs.values(), key=lambda r: r['path']):
        emit('REPAIR: ' + repair['path'] + ' {0:04o} -> {1:04o}'.format(repair['old_mode'], repair['new_mode']))
    for problem in report.errors:
        emit('REVIEW REQUIRED: ' + problem)


def apply_repairs(m, report, uid, gid):
    m.need(not report.errors, 'Blocking inventory findings must be resolved before repairs.')
    if not report.repairs:
        return None
    # The backup parent itself may have a proposed tightening. Use a new private
    # root-owned directory under the already checked application root first.
    folder = Path(tempfile.mkdtemp(prefix='microsoft-calendar-recovery-', dir=str(report.root)))
    folder.chmod(0o700)
    audit = folder / 'permission-repairs.json'
    changes = sorted(report.repairs.values(), key=lambda r: (not r['directory'], r['path']))
    records = [dict({k: v for k, v in item.items() if k != 'fingerprint'}, state='planned') for item in changes]
    def save():
        m.atomic_write(audit, m.encode({'revision': REVISION, 'repairs': records}), os.geteuid(), os.getegid())
    save()
    for index, repair in enumerate(changes):
        path = report.path(repair['path'])
        flags = os.O_RDONLY | os.O_NOFOLLOW | (os.O_DIRECTORY if repair['directory'] else os.O_NONBLOCK)
        fd = os.open(str(path), flags)
        try:
            current = os.fstat(fd)
            expected = (repair['device'], repair['inode'], repair['uid'], repair['gid'], repair['old_mode'])
            observed = (current.st_dev, current.st_ino, current.st_uid, current.st_gid, stat.S_IMODE(current.st_mode))
            m.need(observed == expected, 'A planned repair changed concurrently; rerun the same runner: ' + str(path))
            if not repair['directory']:
                m.need(stat.S_ISREG(current.st_mode) and current.st_nlink == 1, 'Repair target type/links changed: ' + str(path))
                with os.fdopen(os.dup(fd), 'rb') as handle:
                    digest = hashlib.sha256(handle.read(1048577)).hexdigest()
                m.need(digest == repair['fingerprint'], 'Repair target contents changed: ' + str(path))
            os.fchmod(fd, repair['new_mode'])
            os.fsync(fd)
            records[index]['state'] = 'applied'
            save()
        except Exception:
            records[index]['state'] = 'review_required'
            save()
            raise
        finally:
            os.close(fd)
    return audit


def retryable_probe(output):
    # Never use a retry to bypass identity, attendee, authentication or scope checks.
    deny = ('identity differs', 'identity or no-attendee', 'gates differ', 'safeguards differ', 'authentication failed', 'journal is invalid',
            'HTTP 400', 'HTTP 401', 'HTTP 403', 'HTTP 422', 'Another deployment')
    if any(word in output for word in deny):
        return False
    return any(word in output for word in ('HTTP 429', 'HTTP 500', 'HTTP 502', 'HTTP 503', 'HTTP 504',
        'Microsoft connection check could not finish', 'cleanup is pending', 'removal is not verified',
        'Earlier creation is unresolved', 'Temporary TEST event readback failed (HTTP 404)', 'Process time limit reached'))


def finish_probe(m, root, php, account, emit=print, pause=time.sleep):
    for attempt in (1, 2):
        emit('Calendar verification attempt ' + str(attempt) + '/2 (same durable operation).')
        try:
            run = subprocess.run([php, '-d', 'display_errors=0', str(root / m.NAMES[1]), '--test'],
                preexec_fn=m.account_switch(account), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=240)
            output = ((run.stdout or b'') + (run.stderr or b'')).decode('utf-8', 'replace')[:65536]
            ok = run.returncode == 0
        except subprocess.TimeoutExpired:
            output, ok = 'Process time limit reached; operation journal retained.', False
        if ok:
            journal = json.loads(m.private_bytes(root / 'microsoft-calendar-probe.json', account.pw_uid, 65536))
            m.need(valid_journal(journal, m.CONFIG) and journal['state'] == 'complete', 'Server test ended without a completed recovery record.')
            emit(output.rstrip())
            return
        if attempt == 1 and retryable_probe(output):
            path = root / 'microsoft-calendar-probe.json'
            if path.exists() or path.is_symlink():
                j = json.loads(m.private_bytes(path, account.pw_uid, 65536))
                m.need(valid_journal(j, m.CONFIG), 'Existing recovery journal differs; no retry was attempted.')
            emit('One bounded recovery retry follows; the existing event identity is preserved.')
            pause(3)
            continue
        emit(output.rstrip())
        raise m.InstallError('Microsoft verification needs review. Preserve the existing event journal; no new operation ID was substituted.')


def main():
    m = embedded_installer()
    m.need(sys.argv[1:] in ([], ['--check']), 'Use no arguments to finish, or --check for local inspection only.')
    m.need(os.geteuid() == 0, 'Run in WHM Terminal as root.')
    account = pwd.getpwnam('sitesee')
    os.umask(0o077)
    print('Microsoft calendar finish-and-repair | Revision: ' + REVISION, flush=True)
    print('Runner SHA256: ' + hashlib.sha256(Path(__file__).read_bytes()).hexdigest(), flush=True)
    root, php = m.ROOT, m.PHP
    files = m.payload()
    lock = os.open(str(root), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    audit = backup = None
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        print('Checking the entire local release, configuration, disk, PHP and account access...', flush=True)
        report = inventory(m, root, files, php, account.pw_uid, account)
        show_inventory(report, lambda text: print(text, flush=True))
        if sys.argv[1:] == ['--check']:
            print('FINAL RESULTS\nInspection only: no files, permissions or provider data changed.')
            m.need(not report.errors, 'Review the combined findings above.')
            return
        m.need(not report.errors, 'All currently detectable independent local blockers are listed above. No repairs or provider calls were performed.')
        audit = apply_repairs(m, report, account.pw_uid, account.pw_gid)
        if audit:
            print('Permission repairs applied; recovery record: ' + str(audit), flush=True)
        verified = inventory(m, root, files, php, account.pw_uid, account)
        if verified.errors or verified.repairs:
            show_inventory(verified, lambda text: print(text, flush=True))
            raise m.InstallError('Post-repair inventory changed; safe permission tightening is retained. No provider test was started.')
        desired = m.inspect(root, files, php, account.pw_uid, account=account)
        backup = m.install(root, desired, account.pw_uid, account.pw_gid)
        print('Local checks and connection installation: PASS', flush=True)
        if backup:
            print('Installation recovery record: ' + str(backup), flush=True)
    finally:
        os.close(lock)
    finish_probe(m, root, php, account, lambda text: print(text, flush=True))
    print('\nFINAL RESULTS\nMicrosoft TEST calendar connection: PASS')
    print('Local permission repairs: ' + str(len(report.repairs)))
    print('Temporary private TEST event: creation, readback and removal verified (completed prior test reused when present)')
    print('Scheduling migration: NOT ENABLED; payments remain in TEST mode.')
    print('No booking database, customer invitation, email, payment, credential content or Zoho setting was changed.')
    if audit:
        print('Permission recovery record: ' + str(audit))
    print('Next: send this FINAL RESULTS block for review.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('\nFINAL RESULTS\nMicrosoft TEST calendar connection: STOPPED', file=sys.stderr)
        # Only installer-owned messages are printable; other values stay redacted.
        print(str(error) if type(error).__name__ == 'InstallError' else safe_error(error), file=sys.stderr)
        print('Scheduling migration: NOT ENABLED. Preserve existing recovery records and the event journal.', file=sys.stderr)
        sys.exit(1)
