#!/usr/bin/env python3
"""Offline mode-capable code release; configuration and activation remain separate."""
import argparse
import contextlib
import fcntl
import hashlib
import io
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

PREDECESSOR_COMMIT = 'e0bed2b3726c05b52c5d2c943ccd887cd3b376b8'
PREDECESSOR_MANIFEST = '1d9f8f6bc14dccabf3894501c1e9b2792389232a3c6684628851b6f6d2a29fb0'
PREDECESSOR_SCHEMA = 'Additive booking_finance_refund_dates evidence table plus existing financial/account/workflow schema on first normal application use; first verified refund dates retained immutably, original prices, references and payment rows retained. Financial activity requires repair forward; no operational database rollback.'
RUNTIME_SCHEMA = 'Separate TEST and LIVE ledgers; LIVE application_realm is prepared explicitly before activation. Existing financial/account/workflow schemas and immutable refund-date evidence retained; financial activity and LIVE activation require repair forward. No operational database rollback.'
MODE_ADDITIONS = {'private/server/application-mode.php', 'public/booking-live-pay.php',
                  'public/booking-live-webhook.php', 'public/manage-live-appointment.php',
                  'public/pricing-live-approve.php', 'public/pricing-live-confirm.php',
                  'public/staff-test-bookings.php', 'public/staff-test-production.php', 'public/staff-test-vendors.php'}
LIVE_CONFIGS = {'environment.json', 'booking-checkout.json', 'portal-test.json',
                'booking-lifecycle.json', 'portal-sms.json', 'graph-mail.json',
                'microsoft-calendar.json', 'microsoft-scheduling.json', 'booking-mail.json', 'zoho-crm.json'}


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
    need(path.is_absolute() and '..' not in path.parts and path == path.resolve(),
         'Paths must be canonical absolute paths without parent traversal.')
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
    need(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and s.st_uid == uid
         and 0 < stat.S_IMODE(s.st_mode) <= 0o777 and not s.st_mode & 0o022,
         'Unsafe file or ownership preserved: ' + str(path) +
         ' (uid=' + str(s.st_uid) + ', expected_uid=' + str(uid) +
         ', mode=' + format(stat.S_IMODE(s.st_mode), '04o') + ', links=' + str(s.st_nlink) + ')')
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
    payload = package.read_bytes()
    need(sha(payload) == expected, 'Package checksum differs.')
    values = {}
    # Parse the same bytes we verified, even if the upload path is replaced.
    with tarfile.open(fileobj=io.BytesIO(payload), mode='r:gz') as archive:
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
    if 'predecessor' in obj:
        previous = obj['predecessor']
        need(previous.get('commit') == PREDECESSOR_COMMIT
             and previous.get('manifest_sha256') == PREDECESSOR_MANIFEST
             and previous.get('runtime_schema') == PREDECESSOR_SCHEMA
             and set(previous.get('files', {})) == set(obj['files']) - MODE_ADDITIONS, 'Reviewed predecessor differs.')
        need(obj.get('runtime_schema') == RUNTIME_SCHEMA, 'Runtime schema review differs.')
    need(obj.get('runtime_modes') == ['TEST', 'LIVE'] and obj.get('default_runtime_mode') == 'TEST',
         'Reviewed runtime capabilities differ.')
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
    for path in (root, public, db):
        safe(path)
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


def private_configuration(path, uid, optional=False):
    """Read a private binding without following a substituted leaf descriptor."""
    safe(path)
    if not path.exists():
        need(optional, 'Required private configuration missing: ' + path.name)
        return None
    before = path.lstat()
    fields = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid,
                        s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
    need(stat.S_ISREG(before.st_mode) and before.st_uid == uid and before.st_nlink == 1
         and not before.st_mode & 0o077 and before.st_size <= 65536,
         'Unsafe private configuration preserved: ' + path.name)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(fields(os.fstat(fd)) == fields(before), 'Private configuration changed before reading.')
        with os.fdopen(fd, 'rb', closefd=False) as handle:
            raw = handle.read(65537)
        need(len(raw) == before.st_size and fields(os.fstat(fd)) == fields(before)
             and fields(path.lstat()) == fields(before), 'Private configuration changed during reading.')
        return raw
    finally:
        os.close(fd)


def private_object(path, uid, optional=False):
    raw = private_configuration(path, uid, optional)
    if raw is None:
        return None
    value = json.loads(raw)
    need(isinstance(value, dict), 'Private configuration object differs: ' + path.name)
    return value


def check_private_directory(path, uid):
    safe(path)
    value = path.stat()
    need(stat.S_ISDIR(value.st_mode) and value.st_uid == uid and stat.S_IMODE(value.st_mode) == 0o700,
         'Private runtime directory ownership/mode differs: ' + str(path))


def check_database(path, uid):
    safe(path)
    check_private_directory(path.parent, uid)
    for item in [path, pathlib.Path(str(path) + '-wal'), pathlib.Path(str(path) + '-shm')]:
        safe(item)
        if item == path or item.exists():
            s = item.stat()
            need(stat.S_ISREG(s.st_mode) and s.st_uid == uid and s.st_nlink == 1
                 and stat.S_IMODE(s.st_mode) == 0o600, 'SQLite runtime ownership/mode differs: ' + str(item))


def database_binding(path, stage, control=None):
    with contextlib.closing(readonly_db(path)) as connection:
        connection.execute('PRAGMA query_only=ON')
        exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='application_realm'").fetchone()
        if not exists:
            need(stage == 'TEST', 'LIVE database realm is missing.')
            return
        rows = connection.execute('SELECT stage,realm,stripe_account FROM application_realm').fetchall()
        need(len(rows) == 1 and rows[0][0] == stage, 'Database runtime mode differs.')
        if stage == 'LIVE':
            need(tuple(rows[0]) == ('LIVE', control['realm'], control['stripe_account']), 'LIVE database realm differs.')


def configuration_inventory(root, uid):
    # Operational configuration only. Source manifests, installer journals and the
    # installer's own maintenance marker have separate integrity/recovery rules.
    excluded = {'unified-release.json', 'microsoft-calendar-probe.json', '.unified-maintenance.json'}
    excluded.update({'calendar-confirmation-release.json', 'microsoft-scheduling-release.json',
                     'booking-workflow-release.json', 'branded-checkout-release.json', 'appointment-management-release.json'})
    paths = [p for p in root.glob('*.json') if p.name not in excluded
             and not p.name.endswith(('install.json', 'repair.json'))]
    live = root / 'runtime/live'
    if live.exists() or live.is_symlink():
        check_private_directory(root / 'runtime', uid)
        check_private_directory(live, uid)
        paths.extend(live.glob('*.json'))
        plan = live / '.preparation-plan.json'
        if plan.exists() or plan.is_symlink():
            paths.append(plan)
    return {str(p.relative_to(root)): {'sha256': sha(private_configuration(p, uid)), 'metadata': metadata(p),
            'identity': {key: getattr(p.stat(), 'st_' + key) for key in ('dev', 'ino', 'size', 'mtime_ns', 'ctime_ns')}}
            for p in sorted(set(paths))}


def runtime_state(root, db, uid):
    """Validate both ledgers and private bindings without bootstrapping PHP or providers."""
    check_database(db, uid)
    database_binding(db, 'TEST')
    result = {'stage': 'TEST', 'realm': None, 'stripe_account': None,
              'databases': {'TEST': str(db)}, 'live_state': 'absent'}
    marker = private_object(root / 'application-live.json', uid, True)
    ready = private_object(root / 'application-live-ready.json', uid, True)
    journal = private_object(root / 'live-activation-journal.json', uid, True)
    live = root / 'runtime/live'
    if marker is not None or ready is not None or journal is not None or live.exists() or live.is_symlink():
        for directory in (root / 'runtime', live, live / 'data'):
            check_private_directory(directory, uid)
        plan = private_object(live / '.preparation-plan.json', uid)
        control = plan.get('control')
        need(plan.get('format') == 1 and isinstance(control, dict) and control.get('format') == 1
             and control.get('stage') == 'LIVE' and control.get('enabled') is True
             and re.fullmatch('[0-9a-f]{32}', str(control.get('realm', '')))
             and re.fullmatch('acct_[A-Za-z0-9]{8,64}', str(control.get('stripe_account', ''))), 'Prepared LIVE identity differs.')
        need(ready == control and isinstance(journal, dict) and journal.get('format') == 1
             and journal.get('state') in ('prepared', 'active') and journal.get('realm') == control['realm']
             and journal.get('stripe_account') == control['stripe_account']
             and journal.get('plan_sha256') == sha(encode(plan)), 'LIVE activation history differs; repair forward.')
        planned = plan.get('files')
        need(isinstance(planned, dict) and set(planned) == LIVE_CONFIGS
             and all(isinstance(value, dict) for value in planned.values())
             and isinstance(journal.get('configuration'), dict) and set(journal['configuration']) == LIVE_CONFIGS,
             'Prepared LIVE configuration inventory differs.')
        for name in sorted(LIVE_CONFIGS):
            raw = private_configuration(live / name, uid)
            need(sha(raw) == journal['configuration'][name] == sha(encode(planned[name])), 'LIVE configuration changed: ' + name)
        environment = planned['environment.json']
        need(isinstance(environment, dict) and environment.get('stage') == 'LIVE'
             and environment.get('realm') == control['realm'] and isinstance(environment.get('values'), dict),
             'LIVE environment realm differs.')
        values = environment['values']
        need(re.fullmatch('sk_live_[A-Za-z0-9_]{12,}', str(values.get('SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET', '')))
             and re.fullmatch('whsec_[A-Za-z0-9_]{12,}', str(values.get('SITESEE_REAL_ESTATE_STRIPE_LIVE_WEBHOOK_SECRET', '')))
             and len(str(values.get('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET', ''))) >= 32,
             'LIVE credential modes differ; no credential values exposed.')
        checkout = planned['booking-checkout.json']
        need(checkout.get('stage') == 'LIVE' and checkout.get('enabled') is True
             and re.fullmatch('pk_live_[A-Za-z0-9]{12,512}', str(checkout.get('publishable_key', ''))), 'LIVE checkout binding differs.')
        live_db = live / 'data/bookings.sqlite'
        need(db != live_db, 'TEST and LIVE ledgers must remain separate.')
        check_database(live_db, uid)
        database_binding(live_db, 'LIVE', control)
        result['databases']['LIVE'] = str(live_db)
        result['live_state'] = journal['state']
        if marker is not None:
            initial = journal.get('initial_code_release')
            need(journal['state'] == 'active' and isinstance(initial, str)
                 and re.fullmatch('unified-test-[0-9a-f]{12}', initial)
                 and marker == control | {'initial_code_release': initial},
                 'LIVE activation is incomplete or changed; repair forward.')
            result.update(stage='LIVE', realm=control['realm'], stripe_account=control['stripe_account'])
        else:
            need(journal['state'] == 'prepared', 'LIVE history prevents fallback to TEST; repair forward.')
    result['configuration'] = configuration_inventory(root, uid)
    return result


def runtime_identity(state):
    return {key: state[key] for key in ('stage', 'realm', 'stripe_account')}


def test_settings(root, uid, own_journal, runtime=None):
    for name in ('booking-checkout.json', 'portal-test.json'):
        path = root / name
        raw = read(path, uid)
        need(not path.stat().st_mode & 0o077 and len(raw) <= 16384, 'Private TEST setting permissions/size differ.')
        obj = json.loads(raw)
        need(obj.get('stage') == 'TEST' and isinstance(obj.get('enabled'), bool), 'Existing TEST setting differs: ' + name)
        if name == 'booking-checkout.json':
            need(re.fullmatch('pk_test_[A-Za-z0-9]{12,512}', str(obj.get('publishable_key', ''))), 'Checkout must remain TEST.')
    need(os.getenv('SITESEE_APPLICATION_STAGE', '') in ('', (runtime or {'stage': 'TEST'})['stage']),
         'Application stage differs from its private activation.')
    for name in ('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET', 'SITESEE_REAL_ESTATE_STRIPE_PUBLISHABLE_KEY'):
        need(not os.getenv(name, '').startswith(('sk_live_', 'pk_live_', 'rk_live_')), 'LIVE Stripe binding rejected.')
    journals = set(root.glob('*install.json')) | set(root.glob('*repair.json'))
    probe = root / 'microsoft-calendar-probe.json'
    if probe.exists() or probe.is_symlink():
        journals.add(probe)
    for path in sorted(journals):
        if path.name == own_journal:
            continue
        obj = json.loads(read(path, uid))
        terminal = ('complete',) if path.name == 'microsoft-calendar-probe.json' else ('installed', 'restored')
        need(isinstance(obj, dict) and obj.get('state') in terminal,
             'Earlier interrupted or unknown update requires recovery: ' + path.name)


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


def preserved_apache(source, current, accepted):
    """Retain exact host bytes only for reviewed application rules and PHP 8.2."""
    need(current is not None, 'Required file missing: public/.htaccess')
    if sha(current) in accepted:
        return current
    normalized = current.replace(b'\r\n', b'\n')
    need(b'Options -IndexesDirectoryIndex index.html' not in normalized,
         'Joined Apache directives in public/.htaccess; preserve the cPanel handler and repair only the first line before preflight.')
    try:
        lines = [line.strip(' \t') for line in normalized.decode('utf-8').split('\n')]
        base = [line.strip(' \t') for line in source.decode('utf-8').split('\n')]
    except UnicodeDecodeError:
        raise Stop('Unknown deployed edit preserved: public/.htaccess')
    # Only outer line whitespace and blank separators are presentation changes.
    # Directive text, quoted values, order and section boundaries must match.
    while base and not base[-1]:
        base.pop()
    need(lines[:len(base)] == base, 'Unknown deployed edit preserved: public/.htaccess')
    suffix = lines[len(base):]
    while suffix and not suffix[0]:
        suffix.pop(0)
    while suffix and not suffix[-1]:
        suffix.pop()
    comments = (
        '# Set the “ea-php82” package as the default “PHP” programming language.',
        '# Set the "ea-php82" package as the default "PHP" programming language.',
    )
    need(len(suffix) == 6 and suffix[0] == '# php -- BEGIN cPanel-generated handler, do not edit'
         and suffix[1] in comments and suffix[2:] == [
             '<IfModule mime_module>',
             'AddHandler application/x-httpd-ea-php82 .php .php8 .phtml',
             '</IfModule>',
             '# php -- END cPanel-generated handler, do not edit'],
         'Unknown deployed edit preserved: public/.htaccess')
    return current


PRESERVED_PUBLIC = {'public/.htaccess', 'public/assets/js/turnstile.js',
                    'public/assets/images/platform/notes-collaboration.svg'}


def preserved_public(name, source, current, accepted):
    if name == 'public/.htaccess':
        return preserved_apache(source, current, accepted)
    need(name in PRESERVED_PUBLIC and current is not None, 'Required preserved file missing: ' + name)
    if sha(current) in accepted:
        return current
    normalized = current.replace(b'\r\n', b'\n')
    canonical = source.replace(b'\r\n', b'\n')
    if name.endswith('.svg'):
        need(normalized == canonical, 'Unknown deployed edit preserved: ' + name)
    else:
        marker = b'__SITESEE_AUDIT_SITEKEY__'
        need(canonical.count(marker) == 1, 'Turnstile template differs.')
        match = re.search(rb"(?m)^  const SITESEE_SITEKEY = '([A-Za-z0-9_-]{10,200})';$", normalized)
        need(match is not None and not re.match(rb'^[123]x0{8,}', match.group(1)) and
             canonical.replace(marker, match.group(1)) == normalized,
             'Unknown deployed edit preserved: ' + name)
    return current


def release_record(obj, files, deployed, manifest_sha):
    record = {'release': obj['release'], 'commit': obj['commit'], 'stage': 'TEST',
        'runtime_modes': obj['runtime_modes'], 'default_runtime_mode': obj['default_runtime_mode'],
        'manifest_sha256': manifest_sha, 'migration': 'none',
        'files': {n: sha(deployed[n]) for n in files},
        'preserved_host_files': {n: sha(deployed[n]) for n in sorted(PRESERVED_PUBLIC)}}
    if 'runtime_schema' in obj:
        record['runtime_schema'] = obj['runtime_schema']
    return encode(record)


def previous_record(obj, before, old):
    previous = obj.get('predecessor')
    need(previous is not None, 'Different unified release record preserved.')
    for name, digest in previous['files'].items():
        if name not in PRESERVED_PUBLIC:
            need(sha(before[name]) == digest, 'Installed predecessor file differs: ' + name)
    expected = {'release': 'unified-test-' + previous['commit'][:12], 'commit': previous['commit'],
                'stage': 'TEST', 'manifest_sha256': previous['manifest_sha256'], 'migration': 'none',
                'files': {n: sha(before[n]) for n in previous['files']},
                'preserved_host_files': {n: sha(before[n]) for n in sorted(PRESERVED_PUBLIC)}}
    expected['runtime_schema'] = previous['runtime_schema']
    need(old == encode(expected), 'Different unified release record preserved.')


def inspect(obj, files, root, public, uid, manifest_sha):
    before, after, metas = {}, dict(files), {}
    for name, item in obj['files'].items():
        path = target(name, root, public)
        data = read(path, uid, item['allow_missing'])
        if name in PRESERVED_PUBLIC:
            after[name] = preserved_public(name, files[name], data, item['accepted_before'])
        else:
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
            if lookup in before:
                need(digest == sha(before[lookup]), 'Established manifest hash differs: ' + filename + ' / ' + key)
                if before[lookup] != after[lookup]:
                    record['files'][key] = sha(after[lookup])
                    changed = True
        before[name], after[name], metas[name] = raw, encode(record) if changed else raw, metadata(root / filename)
    name = 'private/unified-release.json'
    data = release_record(obj, files, after, manifest_sha)
    old = read(root / 'unified-release.json', uid, True)
    if old not in (None, data):
        previous_record(obj, before, old)
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


def space(root, public, db, before, after, runtime=None):
    databases = [pathlib.Path(p) for p in runtime['databases'].values()] if runtime else [db]
    database_bytes = sum(p.stat().st_size + (pathlib.Path(str(p) + '-wal').stat().st_size
                         if pathlib.Path(str(p) + '-wal').exists() else 0) for p in databases)
    config_bytes = sum((root / p).stat().st_size for p in runtime['configuration']) if runtime else 0
    private_need = sum(len(v) for v in before.values() if v is not None) + database_bytes + config_bytes + 64 * 1024 * 1024
    public_need = max(len(v) for n, v in after.items() if n.startswith('public/')) + 16 * 1024 * 1024
    need(shutil.disk_usage(root).free >= private_need + public_need and shutil.disk_usage(public).free >= public_need,
         'Insufficient capacity for durable backups and atomic replacement.')


def prepare(obj, package_sha, root, public, db, uid, before, after, metas, runtime=None):
    runtime = runtime or runtime_state(root, db, uid)
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
    databases = {}
    for stage, path in runtime['databases'].items():
        filename = 'bookings.sqlite' if stage == 'TEST' else 'live-bookings.sqlite'
        fingerprint = snapshot(pathlib.Path(path), folder / filename, uid, pwd.getpwuid(uid).pw_gid)
        databases[stage] = {'path': path, 'backup': filename, 'fingerprint': fingerprint,
                            'sha256': sha((folder / filename).read_bytes())}
    need(runtime_state(root, db, uid) == runtime, 'Runtime binding/configuration changed before backup completion.')
    configs = runtime['configuration']
    for name, item in configs.items():
        raw = private_configuration(root / name, uid)
        need(sha(raw) == item['sha256'], 'Configuration changed before backup: ' + name)
        atomic(folder / ('config-' + sha(name.encode()) + '.bin'), raw,
               {'uid': uid, 'gid': pwd.getpwuid(uid).pw_gid, 'mode': 0o600})
    config_bytes = encode(configs)
    atomic(folder / 'configuration-metadata.json', config_bytes, {'uid': uid, 'gid': pwd.getpwuid(uid).pw_gid, 'mode': 0o600})
    need(runtime_state(root, db, uid) == runtime, 'Runtime binding/configuration changed during backup.')
    sync_dir(state)
    journal = {'release': obj['release'], 'package_sha256': package_sha, 'state': 'prepared', 'backup': folder.name,
               'private_root': str(root), 'public_root': str(public), 'database_path': str(db),
               'entries': entries, 'database': databases['TEST']['fingerprint'], 'database_sha256': databases['TEST']['sha256'],
               'databases': databases, 'runtime': runtime, 'configuration_sha256': sha(config_bytes)}
    return journal


def recover_plan(obj, files, journal, package_sha, root, public, db, uid, manifest_sha, runtime=None):
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
    for name in sorted(PRESERVED_PUBLIC):
        after[name] = preserved_public(name, files[name], before[name], obj['files'][name]['accepted_before'])
    for filename in obj['active_manifests']:
        name = 'private/' + filename
        record = json.loads(before[name]); changed = False
        for key, digest in record['files'].items():
            lookup = key if key.startswith(('private/', 'public/')) else 'private/' + key
            target(lookup, root, public)
            if lookup in files:
                need(digest == sha(before[lookup]), 'Backup release metadata differs.')
                if before[lookup] != after[lookup]:
                    record['files'][key] = sha(after[lookup]); changed = True
        after[name] = encode(record) if changed else before[name]
    after['private/unified-release.json'] = release_record(obj, files, after, manifest_sha)
    prior = before['private/unified-release.json']
    if prior not in (None, after['private/unified-release.json']):
        previous_record(obj, before, prior)
    for name, value in after.items():
        item = journal['entries'][name]
        need(sha(value) == item['after'], 'Journal result differs: ' + name)
        path = target(name, root, public)
        current = read(path, uid, True)
        choices = [value] if journal['state'] == 'installed' else ([before[name]] if journal['state'] == 'restored' else [before[name], value])
        need(current in choices and (current is None or metadata(path) == metas[name]), 'Concurrent/unknown edit preserved: ' + name)
    need(sha(read(folder / 'bookings.sqlite', uid)) == journal['database_sha256'], 'SQLite backup checksum differs.')
    saved_runtime = journal.get('runtime')
    need(isinstance(saved_runtime, dict) and isinstance(journal.get('databases'), dict)
         and set(journal['databases']) == set(saved_runtime['databases']) and 'TEST' in journal['databases'],
         'Runtime backup inventory differs.')
    expected_paths = {'TEST': str(db)}
    if 'LIVE' in saved_runtime['databases']:
        expected_paths['LIVE'] = str(root / 'runtime/live/data/bookings.sqlite')
    need(saved_runtime['databases'] == expected_paths, 'Runtime backup paths differ.')
    need(journal['database'] == journal['databases']['TEST']['fingerprint']
         and journal['database_sha256'] == journal['databases']['TEST']['sha256'], 'TEST backup descriptor differs.')
    for stage, item in journal['databases'].items():
        filename = 'bookings.sqlite' if stage == 'TEST' else 'live-bookings.sqlite'
        need(stage in ('TEST', 'LIVE') and item['path'] == saved_runtime['databases'][stage]
             and item['backup'] == filename and sha(read(folder / filename, uid)) == item['sha256'],
             'SQLite realm backup checksum/identity differs.')
        with contextlib.closing(readonly_db(folder / filename)) as connection:
            need(db_fingerprint(connection) == item['fingerprint'], 'SQLite realm backup fingerprint differs.')
    config_bytes = read(folder / 'configuration-metadata.json', uid)
    need(sha(config_bytes) == journal.get('configuration_sha256')
         and json.loads(config_bytes) == saved_runtime['configuration'], 'Configuration backup inventory differs.')
    for name, item in saved_runtime['configuration'].items():
        need(sha(read(folder / ('config-' + sha(name.encode()) + '.bin'), uid)) == item['sha256'],
             'Configuration backup checksum differs: ' + name)
    # An installed release may subsequently receive an authorized activation or
    # credential rotation. Its historical backup stays immutable; current binding
    # validation is independent. A partial update cannot change its runtime scope.
    if journal['state'] in ('prepared', 'restoring'):
        need((runtime or runtime_state(root, db, uid)) == saved_runtime,
             'Runtime binding/configuration changed during interrupted update; preserved.')
    return before, after, metas


def installation_order(name):
    if name == 'private/server/application-mode.php':
        return -1, name
    if name in ('private/server/application.php', 'private/views/application-shell.php', 'public/portal-assets/application.css'):
        return 0, name
    if name == 'public/application-entry.php':
        return 2, name
    if name.startswith('public/') and name.endswith('.php'):
        return 3, name
    if name == 'private/unified-release.json':
        return 4, name
    return 1, name


def maintenance_begin(root, uid, gid, obj, package_sha):
    path = root / '.unified-maintenance.json'
    value = encode({'format': 1, 'release': obj['release'], 'commit': obj['commit'], 'package_sha256': package_sha})
    existing = read(path, uid, True)
    need(existing in (None, value), 'Another maintenance boundary is preserved; finish its exact release first.')
    if existing is None:
        atomic(path, value, {'uid': uid, 'gid': gid, 'mode': 0o600})
    return path, value


def maintenance_finish(path, value, uid):
    need(read(path, uid) == value, 'Maintenance boundary changed; preserved.')
    path.unlink(); sync_dir(path.parent)


def bootstrap_boundary(root, uid, before, after, metas):
    for name in ('private/server/application-mode.php', 'private/server/application.php'):
        path = target(name, root, root)  # Both are private source files.
        current = read(path, uid, True)
        need(current in (before[name], after[name]) and (current is None or metadata(path) == metas[name]), 'Bootstrap changed before maintenance; preserved.')
        if current != after[name]:
            atomic(path, after[name], metas[name])


@contextlib.contextmanager
def calendar_boundary(root, uid, gid):
    path = root / 'booking-confirmation.lock'
    safe(path)
    existing = read(path, uid, True)
    if existing is not None:
        need(metadata(path)['mode'] == 0o600, 'Calendar lock metadata differs.')
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        if existing is None:
            os.fchown(fd, uid, gid); os.fsync(fd); sync_dir(root)
        info = os.fstat(fd)
        need(stat.S_ISREG(info.st_mode) and info.st_uid == uid and info.st_nlink == 1
             and stat.S_IMODE(info.st_mode) == 0o600, 'Calendar lock ownership differs.')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('An existing calendar operation must finish before this update can resume.')
        yield
    finally:
        os.close(fd)


def rollback_request_guard(db):
    with contextlib.closing(readonly_db(db)) as connection:
        for table in ('booking_finance_cancellations', 'booking_finance_refunds', 'booking_finance_credits', 'booking_finance_allocations', 'booking_finance_funding','booking_finance_refund_dates'):
            exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone()
            if exists:
                need(connection.execute('SELECT count(*) FROM ' + table).fetchone()[0] == 0, 'Financial ledger preserved: repair forward; previous code cannot safely account for refunds or credits.')
        exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='booking_change_requests'").fetchone()
        if exists:
            count = connection.execute("SELECT count(*) FROM booking_change_requests WHERE state IN ('pending','applying')").fetchone()[0]
            need(count == 0, 'Pending manager requests preserved: repair forward; restoring the previous code would bypass their approval flow.')
        exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='vendor_review_grants'").fetchone()
        if exists:
            count = connection.execute("""SELECT count(*) FROM vendor_review_grants r
                JOIN vendor_assignments v ON v.reference=r.reference AND v.revision=r.assignment_revision
                LEFT JOIN booking_confirmations c ON c.reference=r.reference
                WHERE c.state IS NULL OR c.state<>'confirmed'""").fetchone()[0]
            need(count == 0, 'Pending Vendor review grants preserved: repair forward; previous code cannot withhold access until confirmation.')
        exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='booking_scheduling'").fetchone()
        if exists:
            for (raw,) in connection.execute('SELECT appointment_json FROM booking_scheduling WHERE appointment_json IS NOT NULL'):
                appointment = json.loads(raw)
                need(appointment.get('time') not in ('13:30','15:30','17:30'), 'Half-hour appointment preserved: repair forward; previous code cannot preserve Sunday minutes.')
        exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='bookings'").fetchone()
        if exists:
            for (raw,) in connection.execute('SELECT request_json FROM bookings'):
                request = json.loads(raw)
                need(request.get('appointment', {}).get('time') not in ('13:30','15:30','17:30'), 'Half-hour appointment preserved: repair forward; previous code cannot preserve Sunday minutes.')
        exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='booking_communications'").fetchone()
        if exists:
            count = connection.execute("""SELECT count(*) FROM booking_communications
                WHERE kind LIKE 'change-declined-%' AND (submission_state<>'sent_observed'
                OR crm_state<>'associated' OR (recipient='sales@re.sitesee.ai' AND delivery_state<>'recipient_copy_observed'))""").fetchone()[0]
            need(count == 0, 'Unresolved customer decline notices preserved: repair forward; restoring the previous code would remove their recovery controls.')
            drafts = connection.execute("""SELECT message_json FROM booking_communications
                WHERE kind LIKE 'lifecycle-%' AND submission_state IN ('draft','draft_blocked')
                AND provider_message_id IS NOT NULL AND provider_message_id<>''
                AND submission_attempted_at IS NULL AND provider_accepted_at IS NULL
                AND sent_observed_at IS NULL AND sent_at IS NULL""")
            for (raw,) in drafts:
                message = json.loads(raw)
                need('METHOD:CANCEL' not in message.get('ical', ''), 'Unsent cancellation draft preserved: repair forward; previous code cannot resume its exact saved provider draft.')


def rollback_runtime_guard(runtime):
    need(runtime['stage'] == 'TEST' and runtime['live_state'] != 'active',
         'LIVE activation history preserved: repair forward; previous code cannot serve separate LIVE state.')
    if 'LIVE' in runtime['databases']:
        with contextlib.closing(readonly_db(pathlib.Path(runtime['databases']['LIVE']))) as connection:
            identities = {'application_realm', 'portal_accounts', 'portal_profiles', 'portal_phone_identities', 'vendor_accounts'}
            for (name,) in connection.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"):
                if name not in identities:
                    quoted = '"' + name.replace('"', '""') + '"'
                    need(connection.execute('SELECT count(*) FROM ' + quoted).fetchone()[0] == 0,
                         'LIVE operational records preserved: repair forward; no code rollback.')


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


def restore_rehearsal(root, public, db, journal, destination, uid, stage='TEST'):
    for path in (root, public, db, destination):
        safe(path)
    need(destination != db and root not in destination.parents and public not in destination.parents and not destination.exists(),
         'Rehearsal destination must be new and outside application/public storage.')
    safe(destination.parent)
    s = destination.parent.stat()
    need(s.st_uid == uid and stat.S_ISDIR(s.st_mode) and not s.st_mode & 0o077, 'Rehearsal parent must be private and account-owned.')
    folder = root / 'unified-deployments' / journal['backup']
    need(stage in ('TEST', 'LIVE') and stage in journal['databases'], 'No snapshot for the requested runtime mode.')
    descriptor = journal['databases'][stage]
    filename = 'bookings.sqlite' if stage == 'TEST' else 'live-bookings.sqlite'
    need(descriptor['backup'] == filename and sha(read(folder / filename, uid)) == descriptor['sha256'], 'Snapshot checksum differs.')
    observed = snapshot(folder / filename, destination, uid, pwd.getpwuid(uid).pw_gid)
    need(observed == descriptor['fingerprint'], 'Restored SQLite schema/row/content fingerprints differ.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', type=pathlib.Path, required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--private-root', type=pathlib.Path, default=pathlib.Path('/home/sitesee/.sitesee-real-estate'))
    parser.add_argument('--public-root', type=pathlib.Path, default=pathlib.Path('/home/sitesee/public_html/re'))
    parser.add_argument('--database', type=pathlib.Path)
    parser.add_argument('--account', default='sitesee')
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--expect-runtime-binding', help='Expected nonsecret runtime identity SHA256 from preflight.')
    parser.add_argument('--restore-realm', choices=('TEST', 'LIVE'), default='TEST')
    parser.add_argument('--first-upgrade-drained', action='store_true',
                        help='Attest the reviewed host traffic/worker pause and old PHP/OPCache drain completed before a first predecessor upgrade or code rollback.')
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
    lint(files, args.php)
    lock = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('Another deployment is running.')
        # Activation and configuration writers take this same exclusive lock.
        # Revalidate beneath it, rather than trusting an earlier unlocked check.
        check_dirs(root, public, db, uid, files)
        runtime = runtime_state(root, db, uid)
        test_settings(root, uid, journal_name, runtime)
        binding_sha = sha(encode(runtime_identity(runtime)))
        if args.expect_runtime_binding:
            need(re.fullmatch('[0-9a-f]{64}', args.expect_runtime_binding)
                 and args.expect_runtime_binding == binding_sha, 'Runtime activation changed since approved preflight.')
        journal_path = root / journal_name
        raw = read(journal_path, uid, True)
        journal = json.loads(raw) if raw is not None else None
        if journal is None:
            need(args.preflight or args.install, 'No deployment journal; run preflight/install first.')
            before, after, metas = inspect(obj, files, root, public, uid, manifest_sha)
        else:
            before, after, metas = recover_plan(obj, files, journal, args.sha256, root, public, db, uid, manifest_sha, runtime)
        prior = before['private/unified-release.json']
        if prior is not None and json.loads(prior).get('commit') == PREDECESSOR_COMMIT:
            need(runtime['stage'] == 'TEST' or (journal is not None and journal['state'] == 'installed'),
                 'The first mode-capable code installation must leave TEST active.')
        space(root, public, db, before, after, runtime)
        owner = {'uid': uid, 'gid': gid, 'mode': 0o600}
        if args.preflight:
            for path in runtime['databases'].values():
                with contextlib.closing(readonly_db(pathlib.Path(path))) as connection:
                    need(connection.execute('PRAGMA quick_check').fetchall() == [('ok',)], 'SQLite integrity check differs.')
            print(json.dumps({'result': 'PREFLIGHT PASS', 'release': obj['release'], 'commit': obj['commit'],
                  'stage': 'TEST', 'deployment_files': len(files), 'changed_files': sum(before[n] != after[n] for n in after),
                  'runtime_modes': obj['runtime_modes'], 'default_runtime_mode': obj['default_runtime_mode'],
                  'runtime_stage': runtime['stage'], 'runtime_binding': runtime_identity(runtime),
                  'runtime_binding_sha256': binding_sha, 'database_modes': sorted(runtime['databases']),
                  'journal': None if journal is None else journal['state'], 'database': 'each present ledger read-only integrity checked',
                  'preserved_host_files': {n: sha(after[n]) for n in sorted(PRESERVED_PUBLIC)},
                  'fpm_and_connected_acceptance': 'pending; CLI is not FPM evidence'}))
            return
        if args.restore_rehearsal:
            need(journal is not None, 'Backup journal required.')
            restore_rehearsal(root, public, db, journal, args.restore_rehearsal, uid, args.restore_realm)
            print('RESTORE REHEARSAL PASS: isolated ' + args.restore_realm + ' SQLite copy matches backup schema, rows and content. Operational data unchanged.')
            return
        if args.verify:
            need(journal is not None and journal['state'] == 'installed', 'Installation is not complete.')
            need(read(root / '.unified-maintenance.json', uid, True) is None, 'Maintenance is still active; finish the same package with --resume.')
            print('VERIFY PASS: exact release files, ownership/modes, both present ledger/configuration backups and current ' + runtime['stage'] + ' binding. Connected acceptance pending.')
            return
        if args.rollback_code:
            need(journal is not None, 'Backup journal required.')
            need(args.first_upgrade_drained, 'Code rollback requires the reviewed host traffic/worker pause and PHP drain; older restored routes do not enforce the new maintenance boundary.')
            rollback_runtime_guard(runtime)
            rollback_request_guard(db)
            marker, value = maintenance_begin(root, uid, gid, obj, args.sha256)
            with calendar_boundary(root, uid, gid):
                journal['state'] = 'restoring'; atomic(journal_path, encode(journal), owner)
                apply(root, public, uid, before, after, metas, rollback=True)
                need(runtime_state(root, db, uid) == runtime, 'Runtime configuration changed during code recovery; maintenance preserved.')
                journal['state'] = 'restored'; atomic(journal_path, encode(journal), owner)
                maintenance_finish(marker, value, uid)
            print('CODE RESTORED: original file bytes and modes. Database, configurations, provider state and sessions were not restored.')
            return
        need(journal is None or journal['state'] in ('prepared', 'installed'), 'Code was restored; preserve journal and review before another installation.')
        if before['private/unified-release.json'] is not None and before['private/unified-release.json'] != after['private/unified-release.json'] and (journal is None or journal['state'] == 'prepared'):
            need(args.first_upgrade_drained, 'First predecessor upgrade requires the reviewed host traffic/worker pause and old PHP/OPCache drain; do not add --first-upgrade-drained until that procedure is complete.')
        if journal is None:
            journal = prepare(obj, args.sha256, root, public, db, uid, before, after, metas, runtime)
            need(read(journal_path, uid, True) is None, 'Another journal appeared; preserved.')
            atomic(journal_path, encode(journal), owner)
        marker, value = maintenance_begin(root, uid, gid, obj, args.sha256)
        bootstrap_boundary(root, uid, before, after, metas)
        with calendar_boundary(root, uid, gid):
            need(runtime_state(root, db, uid) == runtime, 'Runtime configuration changed before code update; maintenance preserved.')
            apply(root, public, uid, before, after, metas)
            need(runtime_state(root, db, uid) == runtime, 'Runtime configuration changed during code update; maintenance preserved.')
            journal['state'] = 'installed'; atomic(journal_path, encode(journal), owner)
            maintenance_finish(marker, value, uid)
        print('INSTALLED AND VERIFIED: ' + obj['release'] + '; active runtime ' + runtime['stage'] + '. Backup: ' + str(root / 'unified-deployments' / journal['backup']))
        print('No activation, database migration, provider operation, configuration/gate change, session reset or schedule change. Existing callback URLs retain TEST ownership; LIVE aliases require separate activation.')
        if obj.get('runtime_schema'):
            print('On next normal application use: ' + obj['runtime_schema'])
    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        detail = str(error) if isinstance(error, Stop) else (
            'Required path missing: ' + str(error.filename) if isinstance(error, FileNotFoundError)
            else 'Local check failed (' + type(error).__name__ + ').')
        print('STOPPED: ' + detail)
        if '--preflight' in sys.argv:
            print('Read-only preflight stopped. Review the cause and repeat --preflight; do not use --resume.')
        else:
            print('Preserve files and journal. Review the cause before resuming; unknown edits are never overwritten.')
        sys.exit(1)
