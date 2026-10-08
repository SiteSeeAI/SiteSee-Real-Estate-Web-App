#!/usr/bin/env python3
"""Real isolated FPM: vhost doc_root, authentication, cache proof and guaranteed probe cleanup."""
import importlib.util
import json
import os
import pathlib
import pwd
import shutil
import subprocess
import tempfile
import time
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('host', ROOT / 'tools/install-appointment-approval-host.py')
host = importlib.util.module_from_spec(spec); spec.loader.exec_module(host)


class ActualFpm(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.binary = os.environ.get('SITESEE_TEST_FPM') or shutil.which('php-fpm8.2') or shutil.which('php-fpm8.3')
        if not cls.binary:
            raise RuntimeError('Actual FPM executable required: set SITESEE_TEST_FPM; this suite does not replace FPM with a mock.')

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sitesee-fpm-proof-')
        self.addCleanup(self.temporary.cleanup)
        self.root = pathlib.Path(self.temporary.name)
        self.public = self.root / 'public'; self.public.mkdir(mode=0o755)
        self.private = self.root / 'private'; shutil.copytree(ROOT / '_private', self.private)
        (self.private / 'data').mkdir(exist_ok=True, mode=0o700)
        self.sock = self.root / 'fpm.sock'
        self.account = pwd.getpwuid(os.geteuid())
        self.addCleanup(mock.patch.stopall)
        mock.patch.object(host, 'PRIVATE', self.private).start()
        mock.patch.object(host, 'PUBLIC', self.public).start()
        environment = dict(os.environ)
        for name in list(environment):
            if name.startswith('SITESEE_'):
                environment.pop(name)
        config = self.root / 'fpm.conf'
        lines = ['[global]', 'error_log=' + str(self.root / 'error.log'), 'pid=' + str(self.root / 'fpm.pid'),
                 '[synthetic]', 'user=' + self.account.pw_name, 'group=' + self.account.pw_name,
                 'listen=' + str(self.sock), 'pm=ondemand', 'pm.max_children=2',
                 'php_admin_value[doc_root]=' + str(self.public),
                 'php_admin_value[opcache.restrict_api]=' + str(self.public),
                 'php_admin_flag[allow_url_fopen]=off',
                 'php_admin_value[disable_functions]=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail']
        module = os.environ.get('SITESEE_TEST_OPCACHE')
        if module:
            lines.append('php_admin_value[opcache.enable]=1')
        config.write_text('\n'.join(lines) + '\n')
        command = [self.binary, '-F', '-y', str(config)]
        ini = os.environ.get('SITESEE_TEST_FPM_INI')
        if ini:
            command.extend(['-c', ini])
        if module:
            command.extend(['-d', 'zend_extension=' + module])
        self.process = subprocess.Popen(command, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(self.stop)
        for _ in range(100):
            if self.sock.exists():
                break
            if self.process.poll() is not None:
                raise RuntimeError('Isolated FPM failed startup; inspect its synthetic configuration locally.')
            time.sleep(.05)
        else:
            raise RuntimeError('Isolated FPM socket did not become ready.')

    def stop(self):
        if self.process.poll() is None:
            self.process.terminate(); self.process.wait(timeout=10)

    def test_private_filename_public_script_name_reproduces_prior_failure(self):
        # Execute the exact historical helper to demonstrate the former mismatch under actual doc_root.
        source = subprocess.check_output(['git', 'show', '2de113503050b4ca5a63ad0aaa1b14705a3726e0:tools/install-appointment-approval-host.py'], cwd=ROOT)
        old = type(host)('previous_host'); old.__file__ = 'historical-host-helper'; exec(compile(source, old.__file__, 'exec'), old.__dict__)
        old.PRIVATE = self.private; old.PUBLIC = self.public
        with self.assertRaisesRegex(RuntimeError, 'expected JSON'):
            old.runtime_probe(str(self.sock), self.account)
        self.assertFalse(list((self.private / 'data').glob('approval-runtime-*.php')))

    def test_matching_public_probe_reads_real_fpm_and_cleans_up(self):
        result = host.runtime_probe(str(self.sock), self.account)
        self.assertEqual(result['sapi'], 'fpm-fcgi')
        self.assertTrue(result['cache_observable'])
        self.assertNotIn('probe_id', result)
        self.assertFalse(list(self.public.iterdir()))

    def test_missing_or_wrong_token_is_denied_on_actual_fpm(self):
        original = host.fcgi
        def checked(sock, path, token):
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            for supplied in [None, 'wrong-token']:
                with self.assertRaisesRegex(RuntimeError, '"http_status": 404'):
                    original(sock, path, supplied)
            return original(sock, path, token)
        with mock.patch.object(host, 'fcgi', side_effect=checked):
            host.runtime_probe(str(self.sock), self.account)
        self.assertFalse(list(self.public.iterdir()))

    def test_read_reset_and_verify_use_actual_vhost_opcache(self):
        first = host.runtime_probe(str(self.sock), self.account)
        self.assertTrue(first['opcache_enabled'], 'This cache test requires actual OPCache enabled in the isolated FPM.')
        reset = host.runtime_probe(str(self.sock), self.account, 'reset')
        self.assertTrue(reset['cache_reset'])
        files = [str(p) for p in self.private.rglob('*.php')]
        result = host.runtime_probe(str(self.sock), self.account, 'verify', files)
        expected_code = '$out=[];' + host.reflection_code(self.private) + 'echo json_encode($out["functions"]);'
        expected = json.loads(subprocess.check_output(['php', '-d', 'opcache.enable_cli=0', '-r', expected_code]))
        self.assertTrue(result['cache_ready'])
        self.assertEqual(result['functions'], expected)
        self.assertTrue(all(type(n) is int and n > 0 for n in expected.values()))
        self.assertFalse(list(self.public.iterdir()))

    def test_bootstrap_failure_cleans_up_and_reports_only_categories(self):
        (self.private / '.unified-maintenance.json').write_text('{}')
        with self.assertRaisesRegex(RuntimeError, 'FPM probe refused') as caught:
            host.runtime_probe(str(self.sock), self.account, 'verify')
        self.assertNotIn(str(self.private), str(caught.exception))
        self.assertFalse(list(self.public.iterdir()))

    def test_response_identity_mismatch_is_refused_and_cleans_up(self):
        original = host.fcgi
        def wrong_identity(sock, path, token):
            result = original(sock, path, token); result['probe_id'] = 'other-probe'; return result
        with mock.patch.object(host, 'fcgi', side_effect=wrong_identity), self.assertRaisesRegex(RuntimeError, 'identity differs'):
            host.runtime_probe(str(self.sock), self.account)
        self.assertFalse(list(self.public.iterdir()))

    def test_public_user_ini_is_honored_by_actual_fpm(self):
        prepend = self.private / 'synthetic-prepend.php'
        prepend.write_text("<?php $_SERVER['SITESEE_SYNTHETIC_INI_SEEN']=true;")
        (self.public / '.user.ini').write_text('auto_prepend_file=' + str(prepend) + '\n')
        original = host.fcgi
        def record_setting(sock, path, token):
            code = path.read_text().replace('echo json_encode($out);', "$out['synthetic_ini_seen']=$_SERVER['SITESEE_SYNTHETIC_INI_SEEN']??false;echo json_encode($out);")
            path.write_text(code)
            return original(sock, path, token)
        with mock.patch.object(host, 'fcgi', side_effect=record_setting):
            result = host.runtime_probe(str(self.sock), self.account)
        self.assertTrue(result['synthetic_ini_seen'])
        self.assertEqual(list(self.public.iterdir()), [self.public / '.user.ini'])

    def test_operator_interrupt_removes_the_temporary_probe(self):
        with mock.patch.object(host, 'fcgi', side_effect=KeyboardInterrupt()), self.assertRaises(KeyboardInterrupt):
            host.runtime_probe(str(self.sock), self.account)
        self.assertFalse(list(self.public.iterdir()))


if __name__ == '__main__':
    unittest.main(verbosity=2)
