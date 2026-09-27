#!/usr/bin/env python3
"""Install the reviewed SiteSee branded TEST checkout in one WHM session.

The publishable key is entered locally. Existing Stripe secrets, webhooks,
mail/calendar credentials, PHP-FPM and live-payment gates are not modified.
"""
import base64
import datetime
import fcntl
import getpass
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import sys
import tempfile
import zlib

ROOT = Path('/home/sitesee/.sitesee-real-estate')
PUBLIC = Path('/home/sitesee/public_html/re')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
OLD_PAYMENT_SHA = '845ba608002c03e761ed5296b2301f5f32dd4891705a84c718aadc14c1ede120'
PAYLOAD = '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'
PAYLOAD_SHA = '0d554f0f7a02062df29d02fdce1ffa424818994c8cedae4da8b4a92c7670e418'
PREVIOUS_BRANDED_FILES = {'payment-assets/booking-payment.css': '125e6763c5659c8835aef66d75299692d50b2c148f0ce2aef578bf5a67af4f7c', 'payment-assets/booking-payment.js': '88ea2aedc48062dbf3999d87144b3d68588def809d0ad3e43f99d70e08b26fb4', 'server/booking-checkout.php': '0121ff14a0ae12aff1d6ff3c54ee97447046ad4a4cf0a40f7555403dd032aa61', 'server/booking-pay.php': 'e2170aeb9e7976abed8e25046d64da046fdfe78831127c914e4ca13731a6899f', 'views/booking-payment.php': 'b9fc01773cb12a08f79f3e58acfcefa0e91b6b7034e256fe12181e21d3ce3b45'}
NAMES = (
    'server/booking-checkout.php',
    'views/booking-payment.php',
    'payment-assets/booking-payment.css',
    'payment-assets/booking-payment.js',
    'server/booking-pay.php',
)


def need(ok, message):
    if not ok:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()


def payload():
    raw = zlib.decompress(base64.b64decode(PAYLOAD))
    need(hashlib.sha256(raw).hexdigest() == PAYLOAD_SHA, 'Installer payload checksum failed.')
    files = json.loads(raw.decode('utf-8'))
    need(set(files) == set(NAMES), 'Unexpected installer payload paths.')
    return {name: content.encode('utf-8') for name, content in files.items()}


def safe_path(root, name):
    path = root / name
    need(not Path(name).is_absolute() and '..' not in Path(name).parts, 'Unsafe release path.')
    for item in [path] + list(path.parents):
        need(not item.is_symlink(), 'Symbolic link in deployment path: ' + str(path))
    return path


def preflight(root, public, files, php):
    need(root.is_dir() and not root.is_symlink(), 'Private application directory not found.')
    release = safe_path(root, 'calendar-confirmation-release.json')
    need(release.is_file(), 'Current release manifest is missing.')
    manifest = json.loads(release.read_text())
    need(manifest.get('release') == 'sitesee-calendar-confirmation-test-v1', 'Unexpected active release.')
    recorded = manifest.get('files', {})
    need(all(name in recorded for name in ('server/booking-pay.php', 'server/booking-store.php',
         'server/booking-crm.php', 'tools/booking-communications.php')), 'Booking release is incomplete.')
    for name, expected in recorded.items():
        target = safe_path(root, name)
        need(target.is_file() and digest(target.read_bytes()) == expected,
             'Active release check failed: ' + name)
    for name, content in files.items():
        target = safe_path(root, name)
        previous = OLD_PAYMENT_SHA if name == 'server/booking-pay.php' else None
        known = (previous, PREVIOUS_BRANDED_FILES.get(name), digest(content))
        if target.exists():
            need(target.is_file() and digest(target.read_bytes()) in known,
                 'Existing server file differs from the reviewed versions: ' + name)
        else:
            need(previous is None, 'Existing payment entry point is missing.')
    branded = safe_path(root, 'branded-checkout-release.json')
    if branded.exists():
        expected = {'release': 'sitesee-branded-checkout-test-v1',
                    'files': {name: digest(data) for name, data in files.items()}}
        prior = {'release': 'sitesee-branded-checkout-test-v1', 'files': PREVIOUS_BRANDED_FILES}
        need(branded.is_file() and json.loads(branded.read_text()) in (prior, expected),
             'Existing branded checkout manifest differs from this release.')
    for name in ('assets/images/sitesee-logo.png', 'assets/fonts/Inter-Regular.ttf',
                 'assets/fonts/Poppins-SemiBold.ttf', 'booking-pay.php'):
        need((public / name).is_file(), 'Existing public asset is missing: ' + name)
    with tempfile.TemporaryDirectory(prefix='sitesee-payment-lint-') as directory:
        for name, content in files.items():
            if name.endswith('.php'):
                file = Path(directory) / Path(name).name
                file.write_bytes(content)
                result = subprocess.run([php, '-l', str(file)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                need(result.returncode == 0, 'PHP syntax check failed: ' + name)
    return manifest


def existing_config(root):
    path = safe_path(root, 'booking-checkout.json')
    if not path.exists():
        return None
    need(path.is_file() and path.stat().st_size <= 4096 and path.stat().st_mode & 0o077 == 0,
         'Existing checkout configuration is not private.')
    config = json.loads(path.read_text())
    need(config.get('stage') == 'TEST' and isinstance(config.get('enabled'), bool)
         and re.fullmatch(r'pk_test_[A-Za-z0-9]{12,512}', config.get('publishable_key', '')),
         'Existing checkout configuration differs; nothing replaced.')
    return config


def atomic_write(path, content, uid, gid, mode=0o600):
    fd, temp = tempfile.mkstemp(prefix='.payment-install-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp, mode)
        os.chown(temp, uid, gid)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def install(root, public, files, php, config, uid, gid):
    manifest = preflight(root, public, files, php)
    # Server state is validated again immediately before mutation.
    prior_config = existing_config(root)
    need(prior_config is None or prior_config == config, 'Checkout settings changed during preparation.')
    updated = dict(manifest)
    updated['files'] = dict(manifest['files'])
    # Legacy calendar checkers accept only server/tools PHP/Python entries.
    # The separate checkout manifest also covers the view, CSS and JavaScript.
    updated['files'].update({name: digest(content) for name, content in files.items() if name.startswith('server/')})
    branded = {'release': 'sitesee-branded-checkout-test-v1',
               'files': {name: digest(content) for name, content in files.items()}}
    config_bytes = (json.dumps(config, indent=2) + '\n').encode()
    release_bytes = (json.dumps(updated, indent=2) + '\n').encode()
    changes = [(name, files[name]) for name in NAMES if name != 'server/booking-pay.php']
    if prior_config is None:
        changes.append(('booking-checkout.json', config_bytes))
    changes += [('branded-checkout-release.json', (json.dumps(branded, indent=2) + '\n').encode()),
                ('calendar-confirmation-release.json', release_bytes),
                ('server/booking-pay.php', files['server/booking-pay.php'])]
    changes = [(n, b) for n, b in changes if not (root / n).is_file() or (root / n).read_bytes() != b]
    if not changes:
        return None
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup = root / 'deployment-backups' / ('branded-checkout-' + stamp)
    safe_path(root, 'deployment-backups')
    backup.mkdir(parents=True, mode=0o700)
    original = {}
    for name, _ in changes:
        target = safe_path(root, name)
        if target.exists():
            original[name] = target.stat()
            saved = backup / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(str(target), str(saved))
        else:
            original[name] = None
    (backup / 'restore-paths.json').write_text(json.dumps({n: original[n] is not None for n, _ in changes}, indent=2))
    written = []
    try:
        for name, content in changes:
            target = safe_path(root, name)
            if not target.parent.exists():
                target.parent.mkdir(mode=0o700)
                os.chown(str(target.parent), uid, gid)
            atomic_write(target, content, uid, gid)
            written.append(name)
        preflight(root, public, files, php)
        need(existing_config(root) == config, 'Installed checkout configuration verification failed.')
    except Exception:
        for name in reversed(written):
            target = root / name
            previous = original[name]
            if previous is None:
                target.unlink()
            else:
                atomic_write(target, (backup / name).read_bytes(), previous.st_uid, previous.st_gid,
                             previous.st_mode & 0o777)
        raise
    return backup


def main():
    need(sys.argv[1:] in ([], ['--check']), 'Run without arguments to install, or --check to inspect only.')
    need(os.geteuid() == 0, 'Run in WHM Terminal as root.')
    account = pwd.getpwnam('sitesee')
    need(ROOT.is_dir() and ROOT.stat().st_uid == account.pw_uid and ROOT.stat().st_mode & 0o022 == 0,
         'Private application ownership or permissions differ.')
    os.umask(0o077)
    lock = os.open(str(ROOT), os.O_RDONLY | os.O_DIRECTORY)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        files = payload()
        preflight(ROOT, PUBLIC, files, PHP)
        print('Package, existing release, public brand assets and PHP syntax: PASS', flush=True)
        if sys.argv[1:] == ['--check']:
            print('Inspection only. No server files or settings changed.')
            return
        config = existing_config(ROOT)
        if config is None:
            print('Use the TEST publishable key from the same Stripe account as the existing test payments.', flush=True)
            key = getpass.getpass('Stripe TEST publishable key (pk_test_..., hidden): ').strip()
            need(re.fullmatch(r'pk_test_[A-Za-z0-9]{12,512}', key), 'A pk_test_ publishable key is required. No files installed.')
            config = {'stage': 'TEST', 'enabled': True, 'publishable_key': key}
        backup = install(ROOT, PUBLIC, files, PHP, config, account.pw_uid, account.pw_gid)
        print('\nFINAL RESULTS')
        print('Branded payment page: installed' if backup else 'Branded payment page: already installed')
        print('Embedded checkout: ' + ('enabled in TEST mode' if config['enabled'] else 'existing disabled state preserved'))
        print('Checkout client-secret handling: opaque string validation installed')
        print('Active release hashes: PASS')
        if backup:
            print('Backup: ' + str(backup))
        print('Existing Stripe secret, webhook, mail, calendar, PHP-FPM and live-payment settings unchanged.')
        print('No payment session, charge, email, calendar event or booking was created by installation.')
        print('Next: retry the existing unpaid test booking; create a fresh booking only if none exists.')
    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('STOP: ' + (str(error) if isinstance(error, RuntimeError) else
              'Installation could not complete. Changed application files were restored where possible.'), file=sys.stderr)
        sys.exit(1)
