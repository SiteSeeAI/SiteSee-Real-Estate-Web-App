#!/usr/bin/env python3
"""Reviewed root wrapper: exact TEST downloads, vhost pause, PHP drain, code update and runtime check."""
import argparse
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import re
import shutil
import socket
import stat
import struct
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

PRIVATE = pathlib.Path('/home/sitesee/.sitesee-real-estate')
PUBLIC = pathlib.Path('/home/sitesee/public_html/re')
DOMAIN = 're.sitesee.ai'
POOL = 're_sitesee_ai'
SERVICE = 'ea-php82-php-fpm'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
POOL_FILE = pathlib.Path('/opt/cpanel/ea-php82/root/etc/php-fpm.d/re.sitesee.ai.conf')
FUNCTIONS = ['site_application_bootstrap', 'booking_change_request_create', 'booking_change_request_approve',
             'booking_communication_unsent_draft', 'portal_appointment_change', 'portal_appointment_page', 'booking_lifecycle_html']


def need(value, message):
    if not value:
        raise RuntimeError(message)


def run(command):
    r = subprocess.run(command, capture_output=True, text=True, timeout=120)
    need(r.returncode == 0, 'Command failed: ' + pathlib.Path(command[0]).name + '; no further stage executed.')
    return r.stdout


def service(name):
    text = run(['systemctl', 'show', name, '-p', 'ActiveState', '-p', 'MainPID', '-p', 'CanReload'])
    return dict(line.split('=', 1) for line in text.splitlines() if '=' in line)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def php_string(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


def reflection_code(private):
    # Reflection only: never borrow, print or change deployed signing bindings.
    code = "putenv('SITESEE_REAL_ESTATE_SITE_URL=https://re.sitesee.ai');"
    code += "putenv('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='.bin2hex(random_bytes(32)));"
    code += ''.join('require_once ' + php_string(str(private / n)) + ';' for n in
                    ['server/application.php', 'server/portal-service.php', 'server/booking-lifecycle-ui.php', 'views/portal-service.php'])
    code += '$out["functions"]=[];foreach(json_decode(' + php_string(json.dumps(FUNCTIONS)) + ',true) as $n){$out["functions"][$n]=function_exists($n)?(new ReflectionFunction($n))->getEndLine():null;}'
    return code


def checked_file(path, uid, digest=None):
    need(path == path.resolve() and not path.is_symlink(), 'Noncanonical file preserved: ' + str(path))
    s = path.stat()
    need(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and s.st_uid == uid and not s.st_mode & 0o022,
         'Unsafe file preserved: ' + str(path))
    data = path.read_bytes()
    if digest:
        need(sha(data) == digest, 'Checksum differs; existing file preserved: ' + path.name)
    return data


def atomic(path, data, uid, gid, mode):
    fd, name = tempfile.mkstemp(prefix='.approval-', dir=path.parent)
    try:
        os.fchown(fd, uid, gid); os.fchmod(fd, mode)
        with os.fdopen(fd, 'wb') as f:
            f.write(data); f.flush(); os.fsync(f.fileno())
        os.replace(name, path)
        fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def stage_download(stage, name, digest, release, account):
    path = stage / name
    if path.exists() or path.is_symlink():
        checked_file(path, account.pw_uid, digest)
        return path
    url = 'https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/download/' + release + '/' + name
    print('Downloading and verifying ' + name, flush=True)
    with urllib.request.urlopen(url, timeout=120) as response:
        data = response.read(128 * 1024 * 1024 + 1)
    need(len(data) <= 128 * 1024 * 1024 and sha(data) == digest, 'Download checksum/size differs.')
    atomic(path, data, account.pw_uid, account.pw_gid, 0o600)
    return path


def processes():
    result = {}
    for p in pathlib.Path('/proc').iterdir():
        if not p.name.isdigit():
            continue
        try:
            fields = (p / 'stat').read_text().rsplit(')', 1)[1].split()
            argv = (p / 'cmdline').read_bytes().split(b'\0')
            result[int(p.name)] = {'parent': int(fields[1]), 'start': fields[19], 'argv': argv}
        except (FileNotFoundError, ProcessLookupError):
            continue
    return result


def wait_gone(saved, label, timeout=45):
    until = time.monotonic() + timeout
    while True:
        current = processes()
        alive = [pid for pid, value in saved.items() if pid in current and current[pid]['start'] == value['start']]
        if not alive:
            return
        need(time.monotonic() < until, label + ' did not drain. Maintenance preserved; code update not advanced.')
        time.sleep(0.25)


def fcgi_record(kind, data=b''):
    padding = (-len(data)) % 8
    return struct.pack('!BBHHBB', 1, kind, 1, len(data), padding, 0) + data + b'\0' * padding


def fcgi_length(length):
    return bytes([length]) if length < 128 else struct.pack('!I', length | 0x80000000)


def fcgi(sockpath, script):
    params = {'SCRIPT_FILENAME': str(script), 'SCRIPT_NAME': '/internal-approval-runtime.php',
              'DOCUMENT_ROOT': str(PUBLIC), 'REQUEST_METHOD': 'GET', 'SERVER_NAME': DOMAIN,
              'SERVER_PROTOCOL': 'HTTP/1.1', 'HTTPS': 'on', 'QUERY_STRING': '', 'CONTENT_LENGTH': '0'}
    encoded = b''
    for key, value in params.items():
        k, v = key.encode(), value.encode()
        encoded += fcgi_length(len(k)) + fcgi_length(len(v)) + k + v
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as s:
        s.settimeout(30); s.connect(sockpath)
        s.sendall(fcgi_record(1, struct.pack('!HB5x', 1, 0)) + fcgi_record(4, encoded) + fcgi_record(4) + fcgi_record(5))
        output = b''
        def exact(count):
            data = b''
            while len(data) < count:
                chunk = s.recv(count - len(data)); need(chunk, 'FPM response ended early.')
                data += chunk
            return data
        while True:
            version, kind, request_id, length, padding, _ = struct.unpack('!BBHHBB', exact(8))
            need(version == 1 and request_id == 1, 'FPM protocol identity differs.')
            data = exact(length); exact(padding)
            if kind == 6:
                output += data; need(len(output) <= 1024 * 1024, 'FPM response too large.')
            if kind == 3:
                need(len(data) == 8 and struct.unpack('!IB3x', data) == (0, 0), 'FPM request did not complete.')
                break
    parts = re.split(b'\r?\n\r?\n', output, maxsplit=1)
    need(len(parts) == 2, 'FPM response headers unavailable.')
    try:
        return json.loads(parts[1])
    except (ValueError, UnicodeDecodeError):
        raise RuntimeError('FPM runtime check did not return its expected JSON; no response details exposed.')


def runtime_probe(sockpath, account, action='read', files=()):
    code = "<?php header('Content-Type: application/json'); $c=function_exists('opcache_get_status')?opcache_get_status(false):false;"
    code += "$configured=filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN);"
    code += "$out=['sapi'=>PHP_SAPI,'version'=>PHP_VERSION,'opcache_enabled'=>$configured,'cache_observable'=>!$configured||is_array($c)];"
    if action == 'reset':
        code += "$out['cache_reset']=$c===false||(function_exists('opcache_reset')&&opcache_reset());"
    if action == 'verify':
        code += '$paths=json_decode(' + php_string(json.dumps(list(files))) + ',true);$out["cache_ready"]=true;'
        code += 'foreach($paths as $p){if($c!==false&&opcache_is_script_cached($p)&&!opcache_invalidate($p,true)){$out["cache_ready"]=false;}}'
        code += reflection_code(PRIVATE)
        code += 'site_application_bootstrap(' + php_string(str(PRIVATE)) + ');'
    code += 'echo json_encode($out);'
    fd, filename = tempfile.mkstemp(prefix='approval-runtime-', suffix='.php', dir=PRIVATE / 'data')
    path = pathlib.Path(filename)
    try:
        os.fchown(fd, account.pw_uid, account.pw_gid); os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'w') as f:
            f.write(code); f.flush(); os.fsync(f.fileno())
        result = fcgi(sockpath, path)
        need(result.get('sapi') == 'fpm-fcgi' and str(result.get('version', '')).startswith('8.2.'), 'Actual FPM PHP runtime differs.')
        need(result.get('cache_observable') is True, 'Enabled FPM compiled-code cache is not observable; no reset evidence established.')
        return result
    finally:
        path.unlink(missing_ok=True)


def web_status(path):
    request = urllib.request.Request('https://' + DOMAIN + path, headers={'Cache-Control': 'no-cache'})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def include_parent(path):
    missing = []
    p = path.parent
    while not p.exists():
        missing.append(p); p = p.parent
    for parent in [p, *reversed(missing)]:
        if not parent.exists():
            parent.mkdir(mode=0o755)
        info = parent.stat()
        need(parent == parent.resolve() and info.st_uid == 0 and not info.st_mode & 0o022,
             'Unsafe Apache include directory preserved: ' + str(parent))
    for parent in path.parents:
        info = parent.stat()
        need(parent == parent.resolve() and stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
             'Unsafe Apache include ancestor preserved: ' + str(parent))


def pool_processes(current):
    return {pid: v for pid, v in current.items()
            if v['argv'] and v['argv'][0].rstrip() == ('php-fpm: pool ' + POOL).encode()}


def worker_processes(current):
    worker = str(PRIVATE / 'server/booking-lifecycle-reconcile.php').encode()
    return {pid: v for pid, v in current.items() if worker in v['argv']}


def drain_workers(timeout=45):
    until = time.monotonic() + timeout
    while True:
        saved = worker_processes(processes())
        if not saved:
            return
        remaining = until - time.monotonic()
        need(remaining > 0, 'Reconciliation execution drain timed out; maintenance preserved.')
        wait_gone(saved, 'Reconciliation executions', timeout=remaining)


def canonical_schedule(line, source):
    parts = line.split(maxsplit=5)
    if len(parts) != 6 or not all(re.fullmatch(r'[A-Za-z0-9*/,\-]+', p) for p in parts[:5]):
        return False
    command = parts[5]
    if source.startswith('/etc/'):
        if not command.startswith('sitesee '):
            return False
        command = command[len('sitesee '):]
    elif source != 'crontab:sitesee':
        return False
    if command.startswith('umask 077; '):
        command = command[len('umask 077; '):]
    return re.fullmatch(r'(?:/usr/bin/)?flock\s+-n\s+' + re.escape(str(PRIVATE / 'lifecycle-worker.lock'))
                        + r'\s+' + re.escape(PHP) + r'\s+' + re.escape(str(PRIVATE / 'server/booking-lifecycle-reconcile.php'))
                        + r'(?:\s*>>?\s*/home/sitesee/[A-Za-z0-9_./-]+\s*2>&1)?\s*', command) is not None


def scheduler_inventory():
    sources = []
    for user in ['root', 'sitesee']:
        r = subprocess.run(['crontab', '-u', user, '-l'], capture_output=True, text=True, timeout=30)
        need(r.returncode == 0 or (r.returncode == 1 and 'no crontab for' in r.stderr.lower()),
             'Cannot establish read-only crontab inventory for ' + user)
        sources.append(('crontab:' + user, r.stdout if r.returncode == 0 else ''))
    for p in [pathlib.Path('/etc/crontab'), pathlib.Path('/etc/anacrontab')]:
        if p.exists() or p.is_symlink():
            sources.append((str(p), checked_file(p.resolve(), 0).decode()))
    for directory in ['/etc/cron.d', '/etc/cron.hourly', '/etc/cron.daily', '/etc/cron.weekly', '/etc/cron.monthly']:
        root = pathlib.Path(directory)
        if root.exists():
            for current, directories, files in os.walk(root, followlinks=False):
                need(not any((pathlib.Path(current) / n).is_symlink() for n in directories),
                     'Unreviewed periodic cron directory alias; no host update started.')
                for name in files:
                    p = pathlib.Path(current) / name
                    sources.append((str(p), checked_file(p.resolve(), 0).decode()))
    timers = run(['systemctl', 'list-units', '--type=timer', '--all', '--plain', '--no-legend', '--no-pager'])
    for line in timers.splitlines():
        timer = line.split()[0]
        need(timer.endswith('.timer'), 'Unexpected timer inventory output.')
        triggers = run(['systemctl', 'show', timer, '-p', 'Triggers', '--value']).split()
        for unit in triggers:
            sources.append(('systemd:' + unit, run(['systemctl', 'show', unit, '-p', 'ExecStart', '--value'])))
    matches = []
    for name, text in sources:
        for line in text.splitlines():
            if line.lstrip().startswith('#'):
                continue
            if 'booking-lifecycle-reconcile' in line or str(PRIVATE) in line:
                need(canonical_schedule(line, name), 'Unreviewed matching local scheduler: ' + name + '; no schedules changed.')
                matches.append({'source': name, 'command_sha256': sha(line.encode()), 'lock': 'canonical'})
    return {'sources_checked': len(sources), 'matching_local_jobs': matches,
            'absence_scope': 'local cron and systemd timers only; external automation is not established'}


def reopen(includes, gate, rebuild, httpd, graceful):
    try:
        for p in includes:
            need(checked_file(p, 0) == gate, 'Apache maintenance include changed; preserved.')
        for p in includes:
            p.unlink()
        run([rebuild]); run([httpd, '-t']); run([graceful, '--graceful'])
        need(web_status('/account.php') == 200, 'Anonymous account check failed.')
    except Exception:
        for p in includes:
            if not p.exists() and not p.is_symlink():
                atomic(p, gate, 0, 0, 0o644)
            else:
                need(checked_file(p, 0) == gate, 'Changed maintenance include preserved; root review required.')
        run([rebuild]); run([httpd, '-t']); run([graceful, '--graceful'])
        need(web_status('/portal-assets/application.css') == 503, 'Maintenance restoration needs root review.')
        raise RuntimeError('Traffic reopening failed; maintenance restored. Resume the same package after review.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release', required=True)
    parser.add_argument('--package-sha256', required=True)
    parser.add_argument('--installer-sha256', required=True)
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--preflight', action='store_true')
    actions.add_argument('--install', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0, 'Use WHM Terminal as root.')
    need(re.fullmatch('unified-test-[0-9a-f]{12}', args.release), 'Exact TEST release name required.')
    need(all(re.fullmatch('[0-9a-f]{64}', v) for v in [args.package_sha256, args.installer_sha256]), 'Exact SHA256 values required.')
    account = pwd.getpwnam('sitesee')
    config = checked_file(POOL_FILE, 0).decode()
    need(re.findall(r'^\[([^]]+)\]', config, re.M) == [POOL], 'FPM pool identity differs.')
    users = re.findall(r'^user\s*=\s*(\S+)', config, re.M)
    need([u.strip('"\'') for u in users] == ['sitesee'], 'FPM user differs.')
    listen = re.findall(r'^listen\s*=\s*(\S+)', config, re.M)
    need(len(listen) == 1 and listen[0].startswith('/opt/cpanel/ea-php82/root/usr/var/run/php-fpm/')
         and stat.S_ISSOCK(os.stat(listen[0]).st_mode), 'Expected private FPM socket unavailable.')
    fpm = service(SERVICE); apache = service('httpd')
    need(fpm.get('ActiveState') == 'active' and fpm.get('CanReload') == 'yes' and apache.get('ActiveState') == 'active', 'Required host services unavailable.')
    rebuild = '/usr/local/cpanel/scripts/rebuildhttpdconf'
    graceful = '/usr/local/cpanel/scripts/restartsrv_httpd'
    httpd = shutil.which('httpd') or '/usr/local/apache/bin/httpd'
    need(all(os.path.isfile(p) and os.access(p, os.X_OK) for p in [rebuild, graceful, httpd, PHP]), 'Required cPanel/Apache/PHP executable unavailable.')
    need('alias_module' in run([httpd, '-M']), 'Apache per-vhost maintenance module unavailable.')
    base = pathlib.Path('/etc/apache2/conf.d/userdata').resolve()
    need(base.is_dir(), 'Apache userdata directory unavailable.')
    includes = [base / mode / '2_4/sitesee/re.sitesee.ai/zz-sitesee-unified-approval.conf' for mode in ['std', 'ssl']]
    gate = ('# SiteSee exact TEST update: ' + args.release + '\nRedirect 503 /\n').encode()
    for p in includes:
        if p.exists() or p.is_symlink():
            need(checked_file(p, 0) == gate, 'Unknown Apache maintenance include preserved.')
    stage = pathlib.Path('/home/sitesee/staging') / ('approval-' + args.release)
    need(stage.parent == stage.parent.resolve() and stage.parent.is_dir(), 'Private staging folder unavailable.')
    if not stage.exists():
        stage.mkdir(mode=0o700); os.chown(stage, account.pw_uid, account.pw_gid)
    need(stage == stage.resolve() and stage.stat().st_uid == account.pw_uid and stat.S_IMODE(stage.stat().st_mode) == 0o700, 'Unsafe staging folder preserved.')
    package = stage_download(stage, args.release + '.tar.gz', args.package_sha256, args.release, account)
    installer = stage_download(stage, 'install-unified.py', args.installer_sha256, args.release, account)
    command = [shutil.which('runuser') or '/usr/sbin/runuser', '-u', 'sitesee', '--', shutil.which('python3'), str(installer),
               '--package', str(package), '--sha256', args.package_sha256, '--php', PHP]
    print(run(command + ['--preflight']), end='', flush=True)
    inventory = scheduler_inventory()
    probe = runtime_probe(listen[0], account)
    print(json.dumps({'result': 'HOST PREFLIGHT PASS', 'pool': POOL, 'fpm': probe, 'maintenance_scope': DOMAIN,
                      'shared_service_graceful_reload': SERVICE, 'schedule_changes': 'none',
                      'scheduler_inventory': inventory}), flush=True)
    if args.preflight:
        return
    worker = PRIVATE / 'lifecycle-worker.lock'
    if worker.exists() or worker.is_symlink():
        checked_file(worker, account.pw_uid)
    fd = os.open(worker, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if info.st_uid == 0 and info.st_size == 0:
            os.fchown(fd, account.pw_uid, account.pw_gid); os.fchmod(fd, 0o600)
        need(os.fstat(fd).st_uid == account.pw_uid and os.fstat(fd).st_nlink == 1, 'Worker lock ownership differs.')
        until = time.monotonic() + 45
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                need(time.monotonic() < until, 'Existing reconciliation lock did not drain; no traffic pause started.')
                time.sleep(0.25)
        current = processes()
        apache_old = {pid: v for pid, v in current.items() if v['parent'] == int(apache['MainPID'])}
        worker_old = worker_processes(current)
        for p in includes:
            include_parent(p)
            if not p.exists():
                atomic(p, gate, 0, 0, 0o644)
        print('Pausing re.sitesee.ai and draining old requests.', flush=True)
        run([rebuild]); run([httpd, '-t']); run([graceful, '--graceful'])
        need(web_status('/portal-assets/application.css') == 503, 'Host traffic pause is not proven; code was not changed.')
        wait_gone(apache_old, 'Old Apache executions'); wait_gone(worker_old, 'Existing reconciliation executions')
        php_old = pool_processes(processes())
        print('Reloading PHP 8.2 and clearing old compiled code.', flush=True)
        run(['systemctl', 'reload', SERVICE]); wait_gone(php_old, 'Old target PHP executions')
        need(service(SERVICE).get('ActiveState') == 'active', 'FPM reload is not active.')
        reset = runtime_probe(listen[0], account, 'reset')
        need(reset.get('cache_reset') is True, 'FPM compiled-code reset was not verified.')
        journal = PRIVATE / ('unified-' + args.release.removeprefix('unified-test-') + '-install.json')
        action = '--resume' if journal.exists() else '--install'
        print('Installing the exact approved TEST code with existing records preserved.', flush=True)
        print(run(command + [action, '--first-upgrade-drained']), end='', flush=True)
        print(run(command + ['--verify']), end='', flush=True)
        record = json.loads(checked_file(PRIVATE / 'unified-release.json', account.pw_uid))
        files = [str((PRIVATE if n.startswith('private/') else PUBLIC) / n.split('/', 1)[1])
                 for n in record['files'] if n.endswith('.php')]
        php_code = '$out=[];' + reflection_code(PRIVATE) + 'echo json_encode($out["functions"]);'
        expected = json.loads(run([PHP, '-d', 'opcache.enable_cli=0', '-r', php_code]))
        need(set(expected) == set(FUNCTIONS) and all(type(line) is int and line > 0 for line in expected.values()),
             'Installed CLI approval declarations incomplete; maintenance preserved.')
        verified = runtime_probe(listen[0], account, 'verify', files)
        need(verified.get('cache_ready') is True and verified.get('functions') == expected,
             'Actual FPM approval implementation differs; maintenance preserved.')
        print('Actual FPM approval functions and compiled-code refresh verified.', flush=True)
        drain_workers()
        reopen(includes, gate, rebuild, httpd, graceful)
        print('HOST UPDATE PASS: app serving, exact files verified, FPM approval code verified, Stripe TEST. Existing schedules unchanged.', flush=True)
    finally:
        os.close(fd)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('STOPPED: ' + str(error) + '\nPreserve staged files, deployment journal and any Apache maintenance includes. Review before the same package resumes.', flush=True)
        raise SystemExit(1)
