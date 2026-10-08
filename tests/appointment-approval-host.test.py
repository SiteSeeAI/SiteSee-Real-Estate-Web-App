#!/usr/bin/env python3
"""Exercise the host protocol and fail-closed traffic reopening without host services."""
import importlib.util
import json
import os
import pathlib
import socket
import struct
import subprocess
import tempfile
import threading
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('host_update', ROOT / 'tools/install-appointment-approval-host.py')
host = importlib.util.module_from_spec(spec)
spec.loader.exec_module(host)


class HostUpdate(unittest.TestCase):
    def test_reflection_works_without_apache_signing_environment(self):
        environment = dict(os.environ)
        for name in ['SITESEE_REAL_ESTATE_SITE_URL', 'SITESEE_REAL_ESTATE_PRICING_GATE_SECRET']:
            environment.pop(name, None)
        code = '$out=[];' + host.reflection_code(ROOT / '_private') + 'echo json_encode($out["functions"]);'
        result = subprocess.check_output(['php', '-r', code], env=environment)
        functions = json.loads(result)
        self.assertEqual(set(functions), set(host.FUNCTIONS))
        self.assertTrue(all(isinstance(line, int) and line > 0 for line in functions.values()))

    def test_php_literals_preserve_apostrophes_backslashes_and_dollar(self):
        value = "path/'quoted'\\$secret\nnext"
        result = subprocess.check_output(['php', '-r', 'echo ' + host.php_string(value) + ';'])
        self.assertEqual(result.decode(), value)

    def test_unknown_file_and_symlink_are_preserved(self):
        with tempfile.TemporaryDirectory() as name:
            path = pathlib.Path(name) / 'include.conf'
            path.write_bytes(b'unknown edit'); path.chmod(0o600)
            with self.assertRaisesRegex(RuntimeError, 'Checksum differs'):
                host.checked_file(path, os.geteuid(), '0' * 64)
            alias = path.parent / 'alias'; alias.symlink_to(path)
            with self.assertRaisesRegex(RuntimeError, 'Noncanonical'):
                host.checked_file(alias, os.geteuid())
            path.chmod(0o666)
            with self.assertRaisesRegex(RuntimeError, 'Unsafe file'):
                host.checked_file(path, os.geteuid())
            self.assertEqual(path.read_bytes(), b'unknown edit')

    def test_drain_does_not_mistake_reused_pid_for_old_process(self):
        old = {41: {'start': '100'}}
        with mock.patch.object(host, 'processes', side_effect=[{41: {'start': '100'}}, {41: {'start': '200'}}]), mock.patch.object(host.time, 'sleep') as sleep:
            host.wait_gone(old, 'Old PHP')
        self.assertEqual(sleep.call_count, 1)

    def test_timeout_refuses_to_advance(self):
        old = {41: {'start': '100'}}
        with mock.patch.object(host, 'processes', return_value=old), mock.patch.object(host.time, 'monotonic', side_effect=[1, 2]):
            with self.assertRaisesRegex(RuntimeError, 'did not drain'):
                host.wait_gone(old, 'Old PHP', timeout=0)

    def test_only_exact_target_pool_is_drained(self):
        values = {1: {'argv': [b'php-fpm: pool re_sitesee_ai']}, 2: {'argv': [b'php-fpm: pool other']}, 3: {'argv': [b'']}}
        self.assertEqual(set(host.pool_processes(values)), {1})

    def test_apache_drain_excludes_persistent_logger_and_keeps_late_worker(self):
        with tempfile.TemporaryDirectory() as directory:
            httpd = pathlib.Path(directory) / 'httpd'; httpd.write_bytes(b'synthetic executable')
            info = httpd.stat(); identity = (info.st_dev, info.st_ino)
            values = {10: {'parent': 1, 'exe': identity}, 11: {'parent': 10, 'exe': identity},
                      12: {'parent': 10, 'exe': (999, 999)}, 13: {'parent': 10, 'exe': identity}}
            self.assertEqual(set(host.apache_workers(values, 10, str(httpd))), {11, 13})
            values[10]['exe'] = (888, 888)
            with self.assertRaisesRegex(RuntimeError, 'master executable differs'):
                host.apache_workers(values, 10, str(httpd))

    def test_final_worker_drain_rechecks_processes_after_first_generation(self):
        path = str(host.PRIVATE / 'server/booking-lifecycle-reconcile.php').encode()
        first = {41: {'argv': [b'php', path], 'start': '100'}}
        second = {42: {'argv': [b'php', path], 'start': '200'}}
        with mock.patch.object(host, 'processes', side_effect=[first, second, {}]), mock.patch.object(host, 'wait_gone') as drain:
            host.drain_workers()
        self.assertEqual(drain.call_count, 2)
        self.assertEqual(drain.call_args_list[0].args[0], first)
        self.assertEqual(drain.call_args_list[1].args[0], second)

    def test_worker_drain_includes_relative_and_inline_php_invocations(self):
        values = {1: {'argv': [b'php', b'booking-lifecycle-reconcile.php']},
                  2: {'argv': [b'php', b'-r', b"require '/home/sitesee/.sitesee-real-estate/server/booking-lifecycle-reconcile.php';"]},
                  3: {'argv': [b'php', b'unrelated.php']}}
        self.assertEqual(set(host.worker_processes(values)), {1, 2})

    def test_unreadable_root_crontab_blocks_update_before_any_mutation(self):
        result = subprocess.CompletedProcess([], 1, stdout='', stderr='permission denied')
        with mock.patch.object(host.subprocess, 'run', return_value=result), mock.patch.object(host, 'atomic') as write:
            with self.assertRaisesRegex(RuntimeError, 'Cannot establish'):
                host.scheduler_inventory()
        write.assert_not_called()

    def test_only_whole_canonical_account_cron_is_allowed(self):
        command = '/usr/bin/flock -n ' + str(host.PRIVATE / 'lifecycle-worker.lock') + ' ' + host.PHP + ' ' + str(host.PRIVATE / 'server/booking-lifecycle-reconcile.php')
        self.assertTrue(host.canonical_schedule('*/5 * * * * ' + command, 'crontab:sitesee'))
        self.assertTrue(host.canonical_schedule('*/5 * * * * sitesee ' + command + ' >> /home/sitesee/worker.log 2>&1', '/etc/cron.d/sitesee-booking-reconcile'))
        for line in ['*/5 * * * * root ' + command, '*/5 * * * * sitesee ' + command + '; other-command']:
            self.assertFalse(host.canonical_schedule(line, '/etc/cron.d/unknown'))
        self.assertFalse(host.canonical_schedule('*/5 * * * * ' + command, 'crontab:root'))
        self.assertFalse(host.canonical_schedule('*/5 * * * * arbitrary; ' + command, 'crontab:sitesee'))

    def test_preserved_worker_cron_bytes_are_accepted_without_rewriting(self):
        spec = importlib.util.spec_from_file_location('historical_worker', ROOT / 'tools/repair-appointment-worker.py')
        worker = importlib.util.module_from_spec(spec); spec.loader.exec_module(worker)
        historical = worker.cron_bytes(host.PHP).decode()
        line = next(line for line in historical.splitlines() if not line.startswith('#'))
        self.assertTrue(host.canonical_schedule(line, '/etc/cron.d/sitesee-booking-reconcile'))
        self.assertFalse(host.canonical_schedule(line.replace('umask 077;', 'umask 000;'), '/etc/cron.d/sitesee-booking-reconcile'))

    def test_unobservable_enabled_fpm_cache_is_refused_and_probe_removed(self):
        import pwd
        with tempfile.TemporaryDirectory() as directory:
            private = pathlib.Path(directory)
            def response(socket, script, token):
                return {'probe_id': token, 'sapi': 'fpm-fcgi', 'version': '8.2.30', 'opcache_enabled': True, 'cache_observable': False}
            with mock.patch.object(host, 'PUBLIC', private), mock.patch.object(host, 'fcgi', side_effect=response):
                with self.assertRaisesRegex(RuntimeError, 'not observable'):
                    host.runtime_probe('/synthetic/socket', pwd.getpwuid(os.geteuid()))
            self.assertEqual(list(private.iterdir()), [])

    def exchange(self, body, app_status=0, request_id=1, status=200, stderr=b''):
        with tempfile.TemporaryDirectory() as name:
            socket_path = str(pathlib.Path(name) / 'fpm.sock')
            errors = []; received = []
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as listener:
                listener.bind(socket_path); listener.listen(1)
                def server():
                    try:
                        connection, _ = listener.accept()
                        with connection:
                            data = b''
                            while host.fcgi_record(5) not in data:
                                data += connection.recv(4096)
                            received.append(data)
                            headers = ('Status: ' + str(status) + '\r\nContent-Type: application/json\r\n\r\n').encode()
                            response = host.fcgi_record(6, headers + body)
                            for offset in range(0, len(stderr), 65535):
                                response += host.fcgi_record(7, stderr[offset:offset + 65535])
                            response += struct.pack('!BBHHBB', 1, 3, request_id, 8, 0, 0) + struct.pack('!IB3x', app_status, 0)
                            for offset in range(0, len(response), 3):
                                connection.sendall(response[offset:offset + 3])
                    except Exception as error:
                        errors.append(error)
                thread = threading.Thread(target=server); thread.start()
                try:
                    result = host.fcgi(socket_path, host.PUBLIC / 'probe.php', 'synthetic-probe-token')
                finally:
                    thread.join(timeout=3)
                self.assertFalse(errors)
                self.assertIn(b'SCRIPT_FILENAME' + str(host.PUBLIC / 'probe.php').encode(), received[0])
                self.assertIn(b'SCRIPT_NAME/probe.php', received[0])
                self.assertIn(b'HTTP_X_SITESEE_RUNTIME_TOKENsynthetic-probe-token', received[0])
                return result

    def test_fragmented_real_fastcgi_response(self):
        self.assertEqual(self.exchange(b'{"sapi":"fpm-fcgi"}'), {'sapi': 'fpm-fcgi'})

    def test_nonzero_fpm_result_refused(self):
        with self.assertRaisesRegex(RuntimeError, 'did not complete'):
            self.exchange(b'{}', app_status=1)

    def test_invalid_runtime_body_hides_private_error_details(self):
        with self.assertRaisesRegex(RuntimeError, 'invalid_json') as caught:
            self.exchange(b'private credentials should never appear')
        self.assertNotIn('credentials', str(caught.exception))

    def test_php_error_categories_are_reported_without_raw_details(self):
        for status, text, category in [(404, b'Primary script unknown private-secret', 'primary_script_unknown'),
                                       (403, b'Access denied private-secret', 'access_denied'),
                                       (500, b'PHP Fatal error private-secret', 'fatal_error')]:
            with self.subTest(status=status), self.assertRaisesRegex(RuntimeError, category) as caught:
                self.exchange(b'File not found', status=status, stderr=text)
            self.assertNotIn('private-secret', str(caught.exception))

    def test_json_with_error_http_status_is_refused(self):
        with self.assertRaisesRegex(RuntimeError, 'http_error'):
            self.exchange(b'{"sapi":"fpm-fcgi"}', status=500)

    def test_scalar_or_list_json_is_refused(self):
        for body in [b'[]', b'null', b'42']:
            with self.subTest(body=body), self.assertRaisesRegex(RuntimeError, 'json_object_required'):
                self.exchange(body)

    def test_json_with_fragmented_stderr_is_refused_and_redacted(self):
        with self.assertRaisesRegex(RuntimeError, 'open_basedir_restriction') as caught:
            self.exchange(b'{}', stderr=b'PHP Warning: open_basedir restriction private-secret')
        self.assertNotIn('private-secret', str(caught.exception))

    def test_large_stderr_is_bounded(self):
        with self.assertRaisesRegex(RuntimeError, 'diagnostic response too large'):
            self.exchange(b'{}', stderr=b'x' * 65537)

    def reopen_case(self, failure=None, account_status=200, interrupt=False):
        with tempfile.TemporaryDirectory() as name:
            includes = [pathlib.Path(name) / n for n in ['std.conf', 'ssl.conf']]
            gate = b'Redirect 503 /\n'
            for p in includes:
                p.write_bytes(gate); p.chmod(0o644)
            commands = []
            def run(command):
                commands.append(command)
                if failure and len(commands) == failure:
                    if interrupt:
                        raise KeyboardInterrupt()
                    raise RuntimeError('synthetic command failure')
                return ''
            def checked(path, uid):
                return path.read_bytes()
            def atomic(path, data, uid, gid, mode):
                path.write_bytes(data); path.chmod(mode)
            with mock.patch.object(host, 'run', side_effect=run), mock.patch.object(host, 'checked_file', side_effect=checked), mock.patch.object(host, 'atomic', side_effect=atomic), mock.patch.object(host, 'web_status', side_effect=lambda path: account_status if path == '/account.php' else 503):
                if failure or account_status != 200:
                    with self.assertRaisesRegex(RuntimeError, 'maintenance restored'):
                        host.reopen(includes, gate, 'rebuild', 'httpd', 'graceful')
                    self.assertTrue(all(p.read_bytes() == gate for p in includes))
                    self.assertEqual(commands[-3:], [['rebuild'], ['httpd', '-t'], ['graceful', '--graceful']])
                else:
                    host.reopen(includes, gate, 'rebuild', 'httpd', 'graceful')
                    self.assertTrue(all(not p.exists() for p in includes))

    def test_reopen_success(self):
        self.reopen_case()

    def test_every_unpause_command_failure_restores_maintenance(self):
        for stage in [1, 2, 3]:
            with self.subTest(stage=stage):
                self.reopen_case(failure=stage)

    def test_failed_account_request_restores_maintenance(self):
        self.reopen_case(account_status=500)

    def test_operator_interrupt_during_each_unpause_stage_restores_maintenance(self):
        for stage in [1, 2, 3]:
            with self.subTest(stage=stage):
                self.reopen_case(failure=stage, interrupt=True)


if __name__ == '__main__':
    unittest.main(verbosity=2)
