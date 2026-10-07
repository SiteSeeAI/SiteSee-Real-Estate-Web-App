#!/usr/bin/env python3
"""Offline TEST release: preflight, file-only install/resume, verify and code recovery."""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import re
import shutil
import sqlite3
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.parse


class Stop(RuntimeError):
    pass


def need(ok, message):
    if not ok:
        raise Stop(message)


def sha(data):
    return None if data is None else hashlib.sha256(data).hexdigest()


def encode(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()


def safe(path):
    need(path.is_absolute(), 'Paths must be absolute.')
    need(all(not p.is_symlink() for p in [path, *path.parents]), 'Symbolic link preserved: ' + str(path))


def metadata(path):
    s = path.stat()
    return {'uid': s.st_uid, 'gid': s.st_gid, 'mode': stat.S_IMODE(s.st_mode)}


def read(path, uid, optional=False):
    safe(path)
    if not path.exists():
        need(optional, 'Required file missing: ' + str(path))
        return None
    s = path.stat()
    need(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and s.st_uid == uid and not s.st_mode & 0o022,
         'Unsafe file or ownership preserved: ' + str(path))
    need(s.st_size <= 128 * 1024 * 1024, 'Unexpected file size: ' + str(path))
    return path.read_bytes()


def sync_dir(path):
    fd = os.open(str(path), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def atomic(path, data, meta):
    safe(path)
    fd, temporary = tempfile.mkstemp(prefix='.unified-', dir=str(path.parent))
    try:
        os.fchmod(fd, meta['mode'])
        os.fchown(fd, meta['uid'], meta['gid'])
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
        sync_dir(path.parent)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def target(name, root, public):
    parts = name.split('/')
    need(len(parts) > 1 and parts[0] in ('private', 'public') and all(p not in ('', '.', '..') for p in parts),
         'Invalid deployment path.')
    return (root if parts[0] == 'private' else public).joinpath(*parts[1:])


def load(package, expected):
    need(re.fullmatch('[0-9a-f]{64}', expected), 'Exact package SHA256 required.')
    need(sha(package.read_bytes()) == expected, 'Package checksum differs.')
    values = {}
    with tarfile.open(package, 'r:gz') as archive:
        total = 0
        for member in archive:
            parts = pathlib.PurePosixPath(member.name).parts
            need(member.isfile() and not member.name.startswith('/') and '..' not in parts,
                 'Unsafe package member.')
            need(member.name not in values and 0 <= member.size <= 128 * 1024 * 1024, 'Duplicate/oversized package member.')
            total += member.size
            need(total <= 256 * 1024 * 1024, 'Oversized release.')
            values[member.name] = archive.extractfile(member).read()
    obj = json.loads(values['manifest.json'])
    need(obj['format'] == 1 and obj['stage'] == 'TEST' and obj['migration'] == 'none', 'Release mode/migration differs.')
    need(re.fullmatch('[0-9a-f]{40}', obj['commit']) and obj['release'] == 'unified-test-' + obj['commit'][:12], 'Release identity differs.')
    need(sha(values['install-unified.py']) == obj['installer_sha256'] == sha(pathlib.Path(__file__).read_bytes()),
         'Use the installer belonging to this exact package.')
    need(sha(values['source-test-manifest.json']) == obj['source_manifest_sha256'], 'Source/test manifest differs.')
    source = json.loads(values['source-test-manifest.json'])
    need(source['commit'] == obj['commit'], 'Source/test commit differs.')
    need(obj['active_manifests'] == ['calendar-confirmation-release.json', 'microsoft-scheduling-release.json',
          'booking-workflow-release.json', 'branded-checkout-release.json', 'appointment-management-release.json'],
         'Active manifest paths differ.')
    wanted = {'manifest.json', 'source-test-manifest.json', 'install-unified.py'} | {'files/' + n for n in obj['files']}
    need(set(values) == wanted, 'Package files differ from manifest.')
    files = {}
    for name, item in obj['files'].items():
        target(name, pathlib.Path('/private'), pathlib.Path('/public'))
        data = values['files/' + name]
        need(sha(data) == item['sha256'] and len(data) == item['bytes'], 'Source checksum differs: ' + name)
        source_name = '_private/' + name[8:] if name.startswith('private/') else name
        need(source['files'][source_name]['sha256'] == sha(data), 'Source/test mapping differs: ' + name)
        need(isinstance(item['allow_missing'], bool) and item['accepted_before'] and
             all(re.fullmatch('[0-9a-f]{64}', h) for h in item['accepted_before']), 'Baseline hashes differ.')
        files[name] = data
    return obj, files, sha(values['manifest.json'])


def check_dirs(root, public, db, uid, files):
    need(root != public and root not in public.parents and public not in root.parents, 'Public/private roots must be separate.')
    need(db != root and public not in db.parents and db != public, 'Database must stay private.')
    for path in {root, public, db.parent, *(target(n, root, public).parent for n in files)}:
        safe(path)
        s = path.stat()
        need(stat.S_ISDIR(s.st_mode) and s.st_uid == uid and not s.st_mode & 0o022 and s.st_mode & 0o500 == 0o500,
             'Directory ownership/mode differs: ' + str(path))
    need(not root.stat().st_mode & 0o077 and not db.parent.stat().st_mode & 0o077, 'Private root/data must be 0700.')
    s = db.stat()
    safe(db)
    need(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and s.st_uid == uid and not s.st_mode & 0o077, 'Database ownership/mode differs.')
    for suffix in ('-wal', '-shm'):
        companion = pathlib.Path(str(db) + suffix)
        safe(companion)
        if companion.exists():
            s = companion.stat()
            need(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and s.st_uid == uid and not s.st_mode & 0o077,
                 'SQLite companion ownership/mode differs.')


def test_settings(root, uid, own_journal):
    for name in ('booking-checkout.json', 'portal-test.json'):
        path = root / name
        raw = read(path, uid)
        need(not path.stat().st_mode & 0o077 and len(raw) <= 16384, 'Private TEST setting permissions/size differ.')
        obj = json.loads(raw)
        need(obj.get('stage') == 'TEST' and isinstance(obj.get('enabled'), bool), 'Existing TEST setting differs: ' + name)
        if name == 'booking-checkout.json':
            need(re.fullmatch('pk_test_[A-Za-z0-9]{12,512}', str(obj.get('publishable_key', ''))), 'Checkout must remain TEST.')
    need(os.getenv('SITESEE_APPLICATION_STAGE', 'TEST') in ('', 'TEST'), 'Application stage must remain TEST.')
    for name in ('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET', 'SITESEE_REAL_ESTATE_STRIPE_PUBLISHABLE_KEY'):
        need(not os.getenv(name, '').startswith(('sk_live_', 'pk_live_', 'rk_live_')), 'LIVE Stripe binding rejected.')
    for path in root.glob('*install.json'):
        if path.name == own_journal:
            continue
        obj = json.loads(read(path, uid))
        need(isinstance(obj, dict) and obj.get('state') not in ('prepared', 'installing', 'failed'), 'Earlier interrupted update requires recovery: ' + path.name)


def lint(files, php):
    probe = subprocess.run([php, '-r', "exit(PHP_SAPI==='cli' && PHP_VERSION_ID>=80200 && PHP_VERSION_ID<80400 && extension_loaded('pdo_sqlite') && extension_loaded('curl') && extension_loaded('openssl') ? 0 : 1);"], capture_output=True, timeout=30)
    need(probe.returncode == 0, 'PHP CLI 8.2/8.3 with SQLite, cURL and OpenSSL required.')
    with tempfile.TemporaryDirectory(prefix='unified-lint-') as folder:
        for i, (name, data) in enumerate(files.items()):
            if not name.endswith('.php'):
                continue
            path = pathlib.Path(folder) / (str(i) + '.php')
            path.write_bytes(data)
            result = subprocess.run([php, '-l', str(path)], capture_output=True, timeout=30)
            need(result.returncode == 0, 'PHP syntax differs: ' + name)


def inspect(obj, files, root, public, uid, manifest_sha):
    before, after, metas = {}, dict(files), {}
    for name, item in obj['files'].items():
        path = target(name, root, public)
        data = read(path, uid, item['allow_missing'])
        need((data is None and item['allow_missing']) or sha(data) in item['accepted_before'], 'Unknown deployed edit preserved: ' + name)
        before[name] = data
        metas[name] = metadata(path) if data is not None else {'uid': uid, 'gid': pwd.getpwuid(uid).pw_gid, 'mode': 0o644 if name.startswith('public/') else 0o600}
    for filename in obj['active_manifests']:
        name = 'private/' + filename
        raw = read(root / filename, uid)
        record = json.loads(raw)
        need(isinstance(record, dict) and isinstance(record.get('files'), dict), 'Active release manifest differs: ' + filename)
        changed = False
        for key, digest in record['files'].items():
            lookup = key if key.startswith(('private/', 'public/')) else 'private/' + key
            target(lookup, root, public)
            if lookup in before and before[lookup] != after[lookup]:
                need(digest == sha(before[lookup]), 'Established manifest hash differs: ' + filename + ' / ' + key)
                record['files'][key] = sha(after[lookup])
                changed = True
        before[name], after[name], metas[name] = raw, encode(record) if changed else raw, metadata(root / filename)
    name = 'private/unified-release.json'
    data = encode({'release': obj['release'], 'commit': obj['commit'], 'stage': 'TEST', 'manifest_sha256': manifest_sha,
                   'migration': 'none', 'files': {n: sha(b) for n, b in files.items()}})
    old = read(root / 'unified-release.json', uid, True)
    need(old in (None, data), 'Different unified release record preserved.')
    before[name], after[name] = old, data
    metas[name] = metadata(root / 'unified-release.json') if old is not None else {'uid': uid, 'gid': pwd.getpwuid(uid).pw_gid, 'mode': 0o600}
    return before, after, metas


def readonly_db(path):
    return sqlite3.connect('file:' + urllib.parse.quote(str(path), safe='/') + '?mode=ro', uri=True, timeout=5)


def db_fingerprint(connection):
    schema = connection.execute("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name").fetchall()
    tables = {}
    for name, in connection.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"):
        quoted = '"' + name.replace('"', '""') + '"'
        rows = []
        for row in connection.execute('SELECT * FROM ' + quoted):
            values = [({'blob': bytes(v).hex()} if isinstance(v, bytes) else v) for v in row]
            rows.append(sha(encode(values)))
        tables[name] = {'rows': len(rows), 'content_sha256': sha(encode(sorted(rows)))}
    return {'schema_sha256': sha(encode(schema)), 'tables': tables}


def snapshot(db, destination, uid, gid):
    safe(destination)
    need(not destination.exists(), 'Snapshot destination already exists.')
    fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    os.fchown(fd, uid, gid)
    os.close(fd)
    started = time.monotonic()
    def progress(status, remaining, total):
        need(time.monotonic() - started < 60, 'SQLite backup could not complete within 60 seconds.')
    with contextlib.closing(readonly_db(db)) as source, contextlib.closing(sqlite3.connect(destination)) as backup:
        source.backup(backup, pages=256, progress=progress, sleep=0.05)
        need(backup.execute('PRAGMA integrity_check').fetchall() == [('ok',)], 'SQLite snapshot integrity differs.')
        result = db_fingerprint(backup)
    os.chmod(destination, 0o600)
    os.chown(destination, uid, gid)
    with destination.open('rb') as handle:
        os.fsync(handle.fileno())
    sync_dir(destination.parent)
    return result


def space(root, public, db, before, after):
    wal = pathlib.Path(str(db) + '-wal')
    private_need = sum(len(v) for v in before.values() if v is not None) + db.stat().st_size + (wal.stat().st_size if wal.exists() else 0) + 64 * 1024 * 1024
    public_need = max(len(v) for n, v in after.items() if n.startswith('public/')) + 16 * 1024 * 1024
    need(shutil.disk_usage(root).free >= private_need + public_need and shutil.disk_usage(public).free >= public_need,
         'Insufficient capacity for durable backups and atomic replacement.')


def prepare(obj, package_sha, root, public, db, uid, before, after, metas):
    state = root / 'unified-deployments'
    safe(state)
    if not state.exists():
        state.mkdir(mode=0o700)
        os.chown(state, uid, pwd.getpwuid(uid).pw_gid)
        sync_dir(root)
    s = state.stat()
    need(stat.S_ISDIR(s.st_mode) and s.st_uid == uid and not s.st_mode & 0o077, 'Deployment backup directory differs.')
    folder = pathlib.Path(tempfile.mkdtemp(prefix=obj['release'] + '-', dir=state))
    os.chown(folder, uid, pwd.getpwuid(uid).pw_gid)
    entries = {}
    for name, data in before.items():
        path = target(name, root, public)
        need(read(path, uid, True) == data and (data is None or metadata(path) == metas[name]), 'Concurrent file edit preserved before backup: ' + name)
        if data is not None:
            atomic(folder / (sha(name.encode()) + '.bin'), data, {'uid': uid, 'gid': pwd.getpwuid(uid).pw_gid, 'mode': 0o600})
        entries[name] = {'before': sha(data), 'after': sha(after[name]), 'metadata': metas[name]}
    database = snapshot(db, folder / 'bookings.sqlite', uid, pwd.getpwuid(uid).pw_gid)
    configs = {}
    for path in sorted(root.glob('*.json')):
        if path.name == 'unified-release.json':
            continue
        raw = read(path, uid)
        configs[path.name] = {'sha256': sha(raw), 'metadata': metadata(path)}
    atomic(folder / 'configuration-metadata.json', encode(configs), {'uid': uid, 'gid': pwd.getpwuid(uid).pw_gid, 'mode': 0o600})
    sync_dir(state)
    journal = {'release': obj['release'], 'package_sha256': package_sha, 'state': 'prepared', 'backup': folder.name,
               'private_root': str(root), 'public_root': str(public), 'database_path': str(db),
               'entries': entries, 'database': database, 'database_sha256': sha((folder / 'bookings.sqlite').read_bytes())}
    return journal


def recover_plan(obj, files, journal, package_sha, root, public, db, uid, manifest_sha):
    need(journal.get('release') == obj['release'] and journal.get('package_sha256') == package_sha and
         journal.get('state') in ('prepared', 'installed', 'restoring', 'restored') and
         journal.get('private_root') == str(root) and journal.get('public_root') == str(public) and
         journal.get('database_path') == str(db), 'Deployment journal identity differs.')
    name = journal.get('backup', '')
    need(isinstance(name, str) and name.startswith(obj['release'] + '-') and pathlib.Path(name).name == name, 'Backup path differs.')
    folder = root / 'unified-deployments' / name
    safe(folder)
    before, metas = {}, {}
    expected = set(files) | {'private/' + n for n in obj['active_manifests']} | {'private/unified-release.json'}
    need(set(journal['entries']) == expected, 'Journal targets differ.')
    for name, item in journal['entries'].items():
        data = None if item['before'] is None else read(folder / (sha(name.encode()) + '.bin'), uid)
        need(sha(data) == item['before'], 'Backup checksum differs: ' + name)
        meta = item['metadata']
        need(set(meta) == {'uid', 'gid', 'mode'} and meta['uid'] == uid and isinstance(meta['gid'], int) and
             isinstance(meta['mode'], int) and 0 < meta['mode'] <= 0o777 and not meta['mode'] & 0o022, 'Journal metadata differs.')
        before[name], metas[name] = data, meta
    # Recompute active manifest updates from the immutable original backups.
    after = dict(files)
    for filename in obj['active_manifests']:
        name = 'private/' + filename
        record = json.loads(before[name]); changed = False
        for key, digest in record['files'].items():
            lookup = key if key.startswith(('private/', 'public/')) else 'private/' + key
            target(lookup, root, public)
            if lookup in files and before[lookup] != files[lookup]:
                need(digest == sha(before[lookup]), 'Backup release metadata differs.')
                record['files'][key] = sha(files[lookup]); changed = True
        after[name] = encode(record) if changed else before[name]
    after['private/unified-release.json'] = encode({'release': obj['release'], 'commit': obj['commit'], 'stage': 'TEST',
        'manifest_sha256': manifest_sha, 'migration': 'none', 'files': {n: sha(b) for n, b in files.items()}})
    for name, value in after.items():
        item = journal['entries'][name]
        need(sha(value) == item['after'], 'Journal result differs: ' + name)
        path = target(name, root, public)
        current = read(path, uid, True)
        choices = [value] if journal['state'] == 'installed' else ([before[name]] if journal['state'] == 'restored' else [before[name], value])
        need(current in choices and (current is None or metadata(path) == metas[name]), 'Concurrent/unknown edit preserved: ' + name)
    need(sha(read(folder / 'bookings.sqlite', uid)) == journal['database_sha256'], 'SQLite backup checksum differs.')
    return before, after, metas


def installation_order(name):
    if name in ('private/server/application.php', 'private/views/application-shell.php', 'public/portal-assets/application.css'):
        return 0, name
    if name == 'public/application-entry.php':
        return 2, name
    if name.startswith('public/') and name.endswith('.php'):
        return 3, name
    if name == 'private/unified-release.json':
        return 4, name
    return 1, name


def apply(root, public, uid, before, after, metas, rollback=False, writer=atomic):
    order = sorted(after, key=installation_order, reverse=rollback)
    for name in order:
        path = target(name, root, public)
        current = read(path, uid, True)
        need(current in (before[name], after[name]) and (current is None or metadata(path) == metas[name]), 'Concurrent edit preserved during deployment: ' + name)
        desired = before[name] if rollback else after[name]
        if current == desired:
            continue
        if desired is None:
            path.unlink(); sync_dir(path.parent)
        else:
            writer(path, desired, metas[name])
    for name in after:
        current = read(target(name, root, public), uid, True)
        desired = before[name] if rollback else after[name]
        need(current == desired and (current is None or metadata(target(name, root, public)) == metas[name]), 'Final file verification differs: ' + name)


def restore_rehearsal(root, public, db, journal, destination, uid):
    safe(destination)
    need(destination != db and root not in destination.parents and public not in destination.parents and not destination.exists(),
         'Rehearsal destination must be new and outside application/public storage.')
    safe(destination.parent)
    s = destination.parent.stat()
    need(s.st_uid == uid and stat.S_ISDIR(s.st_mode) and not s.st_mode & 0o077, 'Rehearsal parent must be private and account-owned.')
    folder = root / 'unified-deployments' / journal['backup']
    need(sha(read(folder / 'bookings.sqlite', uid)) == journal['database_sha256'], 'Snapshot checksum differs.')
    observed = snapshot(folder / 'bookings.sqlite', destination, uid, pwd.getpwuid(uid).pw_gid)
    need(observed == journal['database'], 'Restored SQLite schema/row/content fingerprints differ.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', type=pathlib.Path, required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--private-root', type=pathlib.Path, default=pathlib.Path('/home/sitesee/.sitesee-real-estate'))
    parser.add_argument('--public-root', type=pathlib.Path, default=pathlib.Path('/home/sitesee/public_html/re'))
    parser.add_argument('--database', type=pathlib.Path)
    parser.add_argument('--account', default='sitesee')
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php-cli')
    actions = parser.add_mutually_exclusive_group(required=True)
    for action in ('preflight', 'install', 'resume', 'verify', 'rollback-code'):
        actions.add_argument('--' + action, action='store_true')
    actions.add_argument('--restore-rehearsal', type=pathlib.Path)
    args = parser.parse_args()
    os.umask(0o077)
    account = pwd.getpwnam(args.account)
    uid, gid = account.pw_uid, account.pw_gid
    need(os.geteuid() in (0, uid), 'Run as the cPanel account or root.')
    root, public = args.private_root, args.public_root
    db = args.database or pathlib.Path(os.getenv('SITESEE_REAL_ESTATE_BOOKING_DB', str(root / 'data/bookings.sqlite')))
    obj, files, manifest_sha = load(args.package, args.sha256)
    check_dirs(root, public, db, uid, files)
    journal_name = 'unified-' + obj['commit'][:12] + '-install.json'
    test_settings(root, uid, journal_name)
    lint(files, args.php)
    lock = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('Another deployment is running.')
        journal_path = root / journal_name
        raw = read(journal_path, uid, True)
        journal = json.loads(raw) if raw is not None else None
        if journal is None:
            need(args.preflight or args.install, 'No deployment journal; run preflight/install first.')
            before, after, metas = inspect(obj, files, root, public, uid, manifest_sha)
        else:
            before, after, metas = recover_plan(obj, files, journal, args.sha256, root, public, db, uid, manifest_sha)
        space(root, public, db, before, after)
        owner = {'uid': uid, 'gid': gid, 'mode': 0o600}
        if args.preflight:
            with contextlib.closing(readonly_db(db)) as connection:
                need(connection.execute('PRAGMA quick_check').fetchall() == [('ok',)], 'SQLite integrity check differs.')
            print(json.dumps({'result': 'PREFLIGHT PASS', 'release': obj['release'], 'commit': obj['commit'],
                  'stage': 'TEST', 'deployment_files': len(files), 'changed_files': sum(before[n] != after[n] for n in after),
                  'journal': None if journal is None else journal['state'], 'database': 'read-only integrity checked',
                  'fpm_and_connected_acceptance': 'pending; CLI is not FPM evidence'}))
            return
        if args.restore_rehearsal:
            need(journal is not None, 'Backup journal required.')
            restore_rehearsal(root, public, db, journal, args.restore_rehearsal, uid)
            print('RESTORE REHEARSAL PASS: isolated SQLite copy matches backup schema, rows and content. Operational data unchanged.')
            return
        if args.verify:
            need(journal is not None and journal['state'] == 'installed', 'Installation is not complete.')
            print('VERIFY PASS: exact release files, ownership/modes, backup integrity and TEST gates. Connected acceptance pending.')
            return
        if args.rollback_code:
            need(journal is not None, 'Backup journal required.')
            journal['state'] = 'restoring'; atomic(journal_path, encode(journal), owner)
            apply(root, public, uid, before, after, metas, rollback=True)
            journal['state'] = 'restored'; atomic(journal_path, encode(journal), owner)
            print('CODE RESTORED: original file bytes and modes. Database, configurations, provider state and sessions were not restored.')
            return
        need(journal is None or journal['state'] in ('prepared', 'installed'), 'Code was restored; preserve journal and review before another installation.')
        if journal is None:
            journal = prepare(obj, args.sha256, root, public, db, uid, before, after, metas)
            need(read(journal_path, uid, True) is None, 'Another journal appeared; preserved.')
            atomic(journal_path, encode(journal), owner)
        apply(root, public, uid, before, after, metas)
        journal['state'] = 'installed'; atomic(journal_path, encode(journal), owner)
        print('INSTALLED AND VERIFIED: ' + obj['release'] + '; Stripe TEST. Backup: ' + str(root / 'unified-deployments' / journal['backup']))
        print('No database migration, provider operation, configuration/gate change, session reset, new worker or callback change.')
    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        print('STOPPED: ' + (str(error) if isinstance(error, Stop) else 'Local check failed (' + type(error).__name__ + ').'))
        print('Preserve files and journal. Use the same package with --resume after reviewing the cause; unknown edits are never overwritten.')
        sys.exit(1)
