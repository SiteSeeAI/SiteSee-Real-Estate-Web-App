import importlib.util
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch

PROJECT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('checkout_install', PROJECT / 'tools/install-branded-checkout.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
PHP = os.environ.get('SITESEE_TEST_PHP') or shutil.which('php') or '/workspace/scratch/0620b17ff5df/php-bin/php'


class InstallTests(unittest.TestCase):
    def setUp(self):
        if not Path(PHP).is_file():
            self.skipTest('PHP is required for installer rehearsal.')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'private'
        self.public = Path(self.temp.name) / 'public'
        self.root.mkdir(mode=0o700)
        self.public.mkdir()
        self.files = installer.payload()
        # Rehearse the actual upgrade from the server's previous payment entry point.
        manifest = {'release': 'sitesee-calendar-confirmation-test-v1', 'files': {}}
        for name in ('server/booking-pay.php', 'server/booking-store.php', 'server/booking-crm.php', 'tools/booking-communications.php'):
            source = PROJECT / ('_private/' + name if name.startswith('server/') else name)
            if name == 'server/booking-pay.php':
                source = PROJECT / 'tests/fixtures/booking-pay-before-branded.php.txt'
                self.assertEqual(installer.digest(source.read_bytes()), installer.OLD_PAYMENT_SHA)
            dest = self.root / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(source.read_bytes())
            dest.chmod(0o600)
            manifest['files'][name] = installer.digest(dest.read_bytes())
        self.manifest = self.root / 'calendar-confirmation-release.json'
        self.manifest.write_text(json.dumps(manifest))
        for name in ('assets/images/sitesee-logo.png', 'assets/fonts/Inter-Regular.ttf', 'assets/fonts/Poppins-SemiBold.ttf', 'booking-pay.php'):
            path = self.public / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'public-fixture-must-not-change')
        self.config = {'stage': 'TEST', 'enabled': True, 'publishable_key': 'pk_test_' + 'p' * 24}

    def install(self):
        return installer.install(self.root, self.public, self.files, PHP, self.config, os.getuid(), os.getgid())

    def test_embedded_payload_matches_reviewed_sources(self):
        for name, data in self.files.items():
            self.assertEqual(data, (PROJECT / '_private' / name).read_bytes())

    def test_install_repeat_and_live_file_preservation(self):
        protected = {}
        for name in ('data/bookings.sqlite', 'zoho-calendar.json', 'zoho-crm.json', 'booking-mail.json'):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(('protected-' + name).encode())
            protected[path] = path.read_bytes()
        backup = self.install()
        self.assertTrue(backup.is_dir())
        for name, data in self.files.items():
            self.assertEqual((self.root / name).read_bytes(), data)
            self.assertEqual((self.root / name).stat().st_mode & 0o777, 0o600)
        self.assertEqual(installer.existing_config(self.root), self.config)
        self.assertIsNone(self.install())
        for path, data in protected.items():
            self.assertEqual(path.read_bytes(), data)

    def test_changed_release_or_new_file_blocks_install(self):
        existing = self.root / 'server/booking-store.php'
        existing.write_bytes(existing.read_bytes() + b'changed')
        with self.assertRaises(RuntimeError):
            self.install()
        self.assertFalse((self.root / 'booking-checkout.json').exists())

    def test_unrecognized_checkout_helper_is_not_overwritten(self):
        helper = self.root / 'server/booking-checkout.php'
        helper.write_bytes(b'unrecognized-content')
        with self.assertRaises(RuntimeError):
            self.install()
        self.assertEqual(helper.read_bytes(), b'unrecognized-content')

    def test_failure_rolls_back_every_completed_write(self):
        previous = self.manifest.read_bytes()
        previous_pay = (self.root / 'server/booking-pay.php').read_bytes()
        original = installer.atomic_write
        counter = [0]
        def failing(*args, **kwargs):
            counter[0] += 1
            if counter[0] == 6:
                raise OSError('simulated interrupted manifest write')
            return original(*args, **kwargs)
        with patch.object(installer, 'atomic_write', side_effect=failing):
            with self.assertRaises(OSError):
                self.install()
        self.assertEqual(self.manifest.read_bytes(), previous)
        self.assertEqual((self.root / 'server/booking-pay.php').read_bytes(), previous_pay)
        self.assertFalse((self.root / 'booking-checkout.json').exists())
        self.assertFalse((self.root / 'server/booking-checkout.php').exists())

    def test_live_publishable_key_is_rejected(self):
        config = self.root / 'booking-checkout.json'
        config.write_text(json.dumps({'stage': 'TEST', 'enabled': True, 'publishable_key': 'pk_live_forbidden'}))
        config.chmod(0o600)
        with self.assertRaises(RuntimeError):
            installer.existing_config(self.root)


if __name__ == '__main__':
    unittest.main()
