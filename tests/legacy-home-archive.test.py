import contextlib
import errno
import importlib.util
import io
import json
import os
import pathlib
import signal
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

spec = importlib.util.spec_from_file_location('home_archive', pathlib.Path(__file__).parents[1] / 'tools/archive-legacy-home-files.py')
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)
IMAGE_FRAGMENT = '/eer+NxztxMYuEWQG3ECKwwUF58ycAKixOAGAyAKILAAiCyCyACILgMgCiCyAyAIgsgAiCyCyAIgsgMgCiCwAIgsgsgAiC4DIAogsACIL0CWuZ3UGgLrIhjMA1EV2OAOAJQtgyQLwjOzmDAAiCyCyAIgsQFtkd2cAEFkAkQVAZAHaIns4A4AlC2DJAiCyACILILIAiCzAV5H1dQGAJQsgsgCILIDIAvwisl58AViyAJYsACILILIAIgvAe2T9EhxAZAFEFgCRBeiL7HAGgLrIhjMAWLIAliwAt1OAAQDwygTBulLIlQAAAABJRU5ErkJggg'


class ArchiveSafety(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sitesee-archive-test-')
        self.addCleanup(self.temp.cleanup)
        self.home = pathlib.Path(self.temp.name)
        self.uid = os.geteuid()
        for name in tool.NAMES:
            (self.home / name).write_text('# historical installer: ' + name + '\n')
        self.source = self.home / 'active'
        self.source.mkdir()

    def report(self, files=(), blobs=()):
        return tool.scan(self.home, self.uid, files, blobs, root_home=self.home)

    def archive(self, report):
        with contextlib.redirect_stdout(io.StringIO()):
            return tool.archive(self.home, self.uid, report)

    def script(self, name, body):
        path = self.source / name
        path.write_text(body)
        return path

    def test_exact_archive_restore_preserves_all_files_and_metadata(self):
        before = self.report()
        folder = self.archive(before)
        self.assertEqual(set(p.name for p in folder.iterdir()), {*tool.NAMES, 'manifest.json'})
        for name in tool.NAMES:
            self.assertFalse((self.home / name).exists())
            self.assertEqual(tool.candidate(folder / name, self.uid), before['records'][name])
        self.assertEqual(tool.restore(self.home, self.uid, folder), 17)
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), before['records'][name])

    def test_reported_embedded_png_fragment_allows_exact_archive_and_restore(self):
        source = self.script('image.php', 'image="data:image/png;base64,iVBORw0KGgo' + IMAGE_FRAGMENT + '";')
        original = source.read_bytes()
        report = self.report([source])
        self.assertEqual(len(report['eligible']), 17)
        folder = self.archive(report)
        self.assertEqual(tool.restore(self.home, self.uid, folder), 17)
        self.assertEqual(source.read_bytes(), original)
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), report['records'][name])

    def test_overlong_absolute_heuristic_path_is_not_a_dependency(self):
        source = self.script('image.php', 'url="/' + 'segment/' * 700 + 'image";')
        self.assertEqual(len(self.report([source])['eligible']), 17)

    def test_overlong_relative_match_does_not_hide_live_relative_wrapper(self):
        wrapper = self.script('nightly', 'python3 ' + str(self.home / tool.NAMES[0]))
        source = self.script('image.php', 'image="active/' + 'A' * 300 + '"; python3 active/nightly')
        report = self.report([source])
        self.assertNotIn(tool.NAMES[0], report['eligible'])
        self.assertIn(str(wrapper), report['referenced'][tool.NAMES[0]])

    def test_overlong_run_parts_match_does_not_hide_live_periodic_job(self):
        directory = self.source / 'periodic'
        directory.mkdir()
        wrapper = directory / 'nightly'
        wrapper.write_text('python3 ' + str(self.home / tool.NAMES[0]))
        source = self.script('periodic.sh', 'run-parts /' + 'A' * 300 + '\nrun-parts ' + str(directory))
        report = self.report([source])
        self.assertNotIn(tool.NAMES[0], report['eligible'])
        self.assertIn(str(wrapper), report['referenced'][tool.NAMES[0]])

    def test_overlong_token_still_preserves_raw_candidate_name_and_glob_references(self):
        source = self.script('image.php', 'image="/' + 'A' * 300 + '/' + tool.NAMES[0] + '"; command="/' + 'B' * 300 + '/install-re-*.py";')
        report = self.report([source])
        expected = {tool.NAMES[0], *(name for name in tool.NAMES if name.startswith('install-re-'))}
        for name in expected:
            self.assertNotIn(name, report['eligible'])
        folder = self.archive(report)
        for name in expected:
            self.assertTrue((self.home / name).exists())
        self.assertEqual(tool.restore(self.home, self.uid, folder), len(tool.NAMES) - len(expected))

    def test_heuristic_probe_does_not_hide_permission_io_or_alias_errors(self):
        path = self.source / 'wrapper'
        for kind in ['is_file', 'is_symlink', 'is_dir']:
            for code in [errno.EACCES, errno.EIO, errno.ELOOP]:
                error = OSError(code, 'fixture probe failure', str(path))
                with self.subTest(kind=kind, errno=code), \
                     mock.patch.object(pathlib.Path, kind, side_effect=error), \
                     self.assertRaises(OSError) as caught:
                    tool.literal_probe(path, kind)
                self.assertIs(caught.exception, error)

    def test_enumerated_source_and_source_read_enametoolong_still_stop(self):
        with self.assertRaises(OSError) as caught:
            self.report([pathlib.Path('/' + 'A' * 300)])
        self.assertEqual(caught.exception.errno, errno.ENAMETOOLONG)
        source = self.script('live.php', 'echo "safe";')
        original_read = tool.read_regular
        def failed_read(path):
            if path == source:
                raise OSError(errno.ENAMETOOLONG, 'fixture actual-source failure', str(path))
            return original_read(path)
        with mock.patch.object(tool, 'read_regular', side_effect=failed_read), \
             self.assertRaises(OSError) as caught:
            self.report([source])
        self.assertEqual(caught.exception.errno, errno.ENAMETOOLONG)
        self.assertFalse((self.home / 'private-archives').exists())
        self.assertTrue(all((self.home / name).exists() for name in tool.NAMES))

    def test_long_representable_wrapper_paths_and_maximum_component_still_followed(self):
        max_name = self.script('w' * 252 + '.py', 'python3 ' + str(self.home / tool.NAMES[0]))
        nested = self.source.joinpath(*['directory' * 10 for _ in range(5)])
        nested.mkdir(parents=True)
        deep_wrapper = nested / 'nightly.py'
        deep_wrapper.write_text('python3 ' + str(self.home / tool.NAMES[0]))
        for wrapper in [max_name, deep_wrapper]:
            with self.subTest(wrapper=str(wrapper)):
                source = self.script('caller.sh', 'python3 ' + str(wrapper))
                report = self.report([source])
                self.assertNotIn(tool.NAMES[0], report['eligible'])
                self.assertIn(str(wrapper), report['referenced'][tool.NAMES[0]])

    def test_relative_self_cycle_reads_one_source_and_preserves_real_archive_restore(self):
        source = self.script('self.py', 'python3 ../active/self.py\n' + 'const a="icon";function f(){return a};' * 6000)
        with mock.patch.object(tool, 'read_regular', wraps=tool.read_regular) as read:
            report = self.report([source])
        self.assertEqual(report['sources_checked'], 1)
        self.assertEqual(sum(call.args[0] == source for call in read.call_args_list), 1)
        self.assertEqual(len(report['eligible']), 17)
        folder = self.archive(report)
        self.assertEqual(tool.restore(self.home, self.uid, folder), 17)
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), report['records'][name])

    def test_relative_two_file_cycle_retains_live_dependency_and_archives_only_unused(self):
        a = self.script('a.py', 'python3 ../active/b.py\n')
        b = self.script('b.py', 'python3 ../active/a.py\npython3 ' + str(self.home / tool.NAMES[0]))
        with mock.patch.object(tool, 'read_regular', wraps=tool.read_regular) as read:
            report = self.report([a])
        self.assertEqual(report['sources_checked'], 3)  # Two wrappers and their live installer.
        for path in [a, b]:
            self.assertEqual(sum(call.args[0] == path for call in read.call_args_list), 1)
        self.assertNotIn(tool.NAMES[0], report['eligible'])
        folder = self.archive(report)
        self.assertTrue((self.home / tool.NAMES[0]).exists())
        self.assertEqual(tool.restore(self.home, self.uid, folder), 16)

    def test_directory_alias_spellings_converge_without_resolving_leaf_aliases(self):
        source = self.script('wrapper.py', '# no installer reference\n')
        alias_parent = self.home / 'active-alias'
        alias_parent.symlink_to(self.source, target_is_directory=True)
        report = self.report([source, alias_parent / source.name, self.source / '..' / 'active' / source.name])
        self.assertEqual(report['sources_checked'], 1)
        leaf_alias = self.source / 'required.php'
        leaf_alias.symlink_to(self.home / tool.NAMES[0])
        report = self.report([alias_parent / leaf_alias.name])
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_hardlinked_wrappers_in_distinct_directories_keep_distinct_relative_contexts(self):
        first, second = self.source / 'first', self.source / 'second'
        first.mkdir()
        second.mkdir()
        (first / 'caller.py').write_text('python3 helper\n')
        os.link(first / 'caller.py', second / 'caller.py')
        for directory, name in zip([first, second], tool.NAMES[:2]):
            (directory / 'helper').write_text('python3 ' + str(self.home / name))
        report = self.report([first / 'caller.py', second / 'caller.py'])
        for name in tool.NAMES[:2]:
            self.assertNotIn(name, report['eligible'])

    def test_checked_scan_disarms_timer_and_restores_handler_before_real_archive(self):
        previous = signal.getsignal(signal.SIGALRM)
        def inventory(home, uid):
            self.assertGreater(signal.getitimer(signal.ITIMER_REAL)[0], 0)
            return self.report()
        with mock.patch.object(tool, 'host_scan', side_effect=inventory):
            report = tool.checked_host_scan(self.home, self.uid)
        self.assertEqual(signal.getitimer(signal.ITIMER_REAL), (0.0, 0.0))
        self.assertEqual(signal.getsignal(signal.SIGALRM), previous)
        rename = tool.rename_new
        def no_timer_during_move(a, b):
            self.assertEqual(signal.getitimer(signal.ITIMER_REAL), (0.0, 0.0))
            rename(a, b)
        with mock.patch.object(tool, 'rename_new', side_effect=no_timer_during_move):
            folder = self.archive(report)
        self.assertEqual(tool.restore(self.home, self.uid, folder), 17)

    def test_checked_scan_restores_timer_state_after_error(self):
        previous = signal.getsignal(signal.SIGALRM)
        with mock.patch.object(tool, 'host_scan', side_effect=RuntimeError('fixture inventory failure')), \
             self.assertRaisesRegex(RuntimeError, 'fixture inventory failure'):
            tool.checked_host_scan(self.home, self.uid)
        self.assertEqual(signal.getitimer(signal.ITIMER_REAL), (0.0, 0.0))
        self.assertEqual(signal.getsignal(signal.SIGALRM), previous)
        self.assertFalse((self.home / 'private-archives').exists())

    def test_checked_scan_restores_timer_state_after_keyboard_interrupt(self):
        previous = signal.getsignal(signal.SIGALRM)
        with mock.patch.object(tool, 'host_scan', side_effect=KeyboardInterrupt()), \
             self.assertRaises(KeyboardInterrupt):
            tool.checked_host_scan(self.home, self.uid)
        self.assertEqual(signal.getitimer(signal.ITIMER_REAL), (0.0, 0.0))
        self.assertEqual(signal.getsignal(signal.SIGALRM), previous)
        self.assertFalse((self.home / 'private-archives').exists())

    def scan_child(self, body):
        preamble = ('import importlib.util, os, pathlib, signal, sys, time\n'
                    'spec=importlib.util.spec_from_file_location("isolated_scan", ' + repr(tool.__file__) + ')\n'
                    'tool=importlib.util.module_from_spec(spec);spec.loader.exec_module(tool)\n'
                    'home=pathlib.Path(sys.argv[1])\n')
        return subprocess.run([sys.executable, '-c', preamble + body, str(self.home)],
                              capture_output=True, text=True, timeout=5)

    def test_real_scan_deadline_and_heartbeat_fail_before_archive(self):
        result = self.scan_child('''
tool.SCAN_SECONDS=0.15
tool.SCAN_HEARTBEAT_SECONDS=0.04
previous=signal.getsignal(signal.SIGALRM)
def slow_inventory(home, uid):
    time.sleep(2)
    raise AssertionError("Deadline did not interrupt inventory")
tool.host_scan=slow_inventory
started=time.monotonic()
try:
    report=tool.checked_host_scan(home, os.geteuid())
    tool.archive(home, os.geteuid(), report)
except RuntimeError as error:
    assert "exceeded" in str(error)
else:
    raise AssertionError("Deadline did not stop the scan")
assert time.monotonic()-started < 1.5
assert signal.getitimer(signal.ITIMER_REAL)==(0.0, 0.0)
assert signal.getsignal(signal.SIGALRM)==previous
assert not (home/"private-archives").exists()
assert all((home/name).exists() for name in tool.NAMES)
print("REAL DEADLINE AND PRESERVATION PASS")
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('REAL DEADLINE AND PRESERVATION PASS', result.stdout)
        self.assertIn('Checking local files and dependencies', result.stderr)
        self.assertIn('Dependency check:', result.stderr)
        self.assertIn('stage checked_host_scan:', result.stderr)
        self.assertNotIn(str(self.home), result.stderr)

    def test_preexisting_process_alarm_is_preserved_and_inventory_not_called(self):
        result = self.scan_child('''
def existing(signum, frame):
    raise AssertionError("Existing alarm was changed")
signal.signal(signal.SIGALRM,existing)
signal.setitimer(signal.ITIMER_REAL,10)
def forbidden_inventory(home, uid):
    raise AssertionError("Inventory must not run with an existing timer")
tool.host_scan=forbidden_inventory
try:
    tool.checked_host_scan(home,os.geteuid())
except RuntimeError as error:
    assert "Existing process timer preserved" in str(error)
else:
    raise AssertionError("Existing alarm was not guarded")
assert signal.getsignal(signal.SIGALRM)==existing
assert 8 < signal.getitimer(signal.ITIMER_REAL)[0] <= 10
signal.setitimer(signal.ITIMER_REAL,0)
assert not (home/"private-archives").exists()
print("EXISTING ALARM PRESERVED")
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('EXISTING ALARM PRESERVED', result.stdout)

    def test_explicit_live_reference_is_preserved(self):
        source = self.script('live.php', 'require "' + str(self.home / tool.NAMES[0]) + '";')
        report = self.report([source])
        self.assertNotIn(tool.NAMES[0], report['eligible'])
        self.archive(report)
        self.assertTrue((self.home / tool.NAMES[0]).exists())

    def test_dormant_installer_chain_can_archive_but_live_chain_is_preserved(self):
        a, b = tool.NAMES[:2]
        (self.home / a).write_text('python3 ' + str(self.home / b))
        dormant = self.report()
        self.assertIn(b, dormant['eligible'])
        live = self.report(blobs=[('crontab:root', ('python3 ' + str(self.home / a)).encode())])
        self.assertNotIn(a, live['eligible'])
        self.assertNotIn(b, live['eligible'])

    def test_extensionless_absolute_wrapper_is_followed(self):
        source = self.script('nightly', 'python3 ' + str(self.home / tool.NAMES[0]))
        report = self.report(blobs=[('crontab:root', str(source).encode())])
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_relative_extensionless_wrapper_is_followed(self):
        self.script('nightly', 'python3 ' + str(self.home / tool.NAMES[0]))
        report = self.report(blobs=[('crontab:root', ('cd ' + str(self.source) + '; ./nightly').encode())])
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_relative_nested_wrapper_is_followed(self):
        report = self.report(blobs=[('crontab:root', ('cd ' + str(self.home) + '; python3 active/nested.py').encode())])
        self.assertIn(tool.NAMES[0], report['eligible'])
        self.script('nested.py', 'python3 ' + str(self.home / tool.NAMES[0]))
        report = self.report(blobs=[('crontab:root', ('cd ' + str(self.home) + '; python3 active/nested.py').encode())])
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_run_parts_extensionless_jobs_are_followed(self):
        self.script('nightly', 'python3 ' + str(self.home / tool.NAMES[0]))
        report = self.report(blobs=[('crontab:root', ('run-parts ' + str(self.source)).encode())])
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_running_relative_wrapper_uses_process_cwd_and_is_followed(self):
        wrapper = self.script('nightly.py', 'python3 ' + str(self.home / tool.NAMES[0]))
        proc = self.home / 'fake-proc'
        process = proc / '123'
        process.mkdir(parents=True)
        (process / 'cmdline').write_bytes(b'python3\x00nightly.py\x00')
        (process / 'cwd').symlink_to(wrapper.parent, target_is_directory=True)
        (process / 'fd').mkdir()
        report = tool.scan(self.home, self.uid, [], inspect_processes=True,
                           root_home=self.home, proc_root=proc)
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_running_process_with_deleted_cwd_cannot_be_silently_skipped(self):
        proc = self.home / 'fake-proc'
        process = proc / '123'
        process.mkdir(parents=True)
        (process / 'cmdline').write_bytes(b'python3\x00' + os.fsencode(self.home / tool.NAMES[0]) + b'\x00')
        (process / 'cwd').symlink_to(self.home / 'missing-dir', target_is_directory=True)
        (process / 'fd').mkdir()
        with self.assertRaisesRegex(RuntimeError, 'unavailable working directory'):
            tool.scan(self.home, self.uid, [], inspect_processes=True,
                      root_home=self.home, proc_root=proc)

    def test_referenced_perl_wrapper_is_followed(self):
        self.script('nightly.pl', 'system("python3 ' + str(self.home / tool.NAMES[0]) + '");')
        report = self.report(blobs=[('crontab:root', ('cd ' + str(self.source) + '; perl nightly.pl').encode())])
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_installer_glob_is_preserved(self):
        source = self.script('nightly', 'for f in install-re-*.py; do python3 "$f"; done')
        report = self.report([source])
        for name in tool.NAMES:
            if name.startswith('install-re-'):
                self.assertNotIn(name, report['eligible'])

    def test_periodic_directory_alias_retains_extensionless_scan(self):
        self.script('nightly', 'python3 ' + str(self.home / tool.NAMES[0]))
        directory = self.home / 'cron'
        directory.mkdir()
        (directory / 'jobs').symlink_to(self.source, target_is_directory=True)
        report = self.report(list(tool.sources(directory, all_files=True)))
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_alias_with_mixed_contexts_keeps_stronger_periodic_scan(self):
        self.script('nightly', 'python3 ' + str(self.home / tool.NAMES[0]))
        directory = self.home / 'cron'
        directory.mkdir()
        alias = directory / 'jobs'
        alias.symlink_to(self.source, target_is_directory=True)
        report = self.report(list(tool.sources(directory, all_files=True)),
                             [('unit', ('WorkingDirectory=' + str(alias)).encode())])
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_extensionless_public_file_alias_preserves_candidate(self):
        alias = self.source / 'legacy'
        alias.symlink_to(self.home / tool.NAMES[0])
        report = self.report(list(tool.sources(self.source)))
        self.assertNotIn(tool.NAMES[0], report['eligible'])

    def test_masked_unit_is_not_a_failure(self):
        alias = self.source / 'masked.service'
        alias.symlink_to('/dev/null')
        self.assertEqual(len(self.report([alias])['eligible']), 17)

    def compiler_namespace(self):
        compiler = self.home / 'system' / 'usr' / 'lib' / 'gcc'
        compiler.mkdir(parents=True)
        return compiler

    def system_commands(self):
        system = self.home / 'package-system'
        command = system / 'usr' / 'sbin'
        command.mkdir(parents=True)
        return system, command

    def test_dangling_package_commands_preserved_in_real_archive_and_restore(self):
        system, command = self.system_commands()
        targets = [system / 'etc/rc.d/init.d/mysql',
                   system / 'etc/init.d/postgresql',
                   system / 'etc/alternatives/mta',
                   system / 'usr/libexec/platform-python',
                   system / 'opt/cpanel/optional/bin/worker']
        aliases = []
        for name, target in zip(['rcmysql', 'rcpostgresql', 'sendmail', 'python', 'package-worker'], targets):
            alias = command / name
            alias.symlink_to(target)
            aliases.append(alias)
        with mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', tuple(system / p for p in ['etc/rc.d/init.d', 'etc/init.d', 'etc/alternatives', 'usr', 'opt/cpanel'])):
            before = {str(p): p.lstat() for p in aliases}
            report = self.report(blobs=[('unit', (' '.join(map(str, aliases))).encode())])
        expected = {str(p): str(t) for p, t in zip(aliases, targets)}
        self.assertEqual(report['preserved_system_links'], expected)
        self.assertEqual(len(report['eligible']), 17)
        folder = self.archive(report)
        journal = json.loads((folder / 'manifest.json').read_text())
        self.assertEqual(journal['scan']['preserved_system_links'], expected)
        self.assertEqual(tool.restore(self.home, self.uid, folder), 17)
        for path, target in zip(aliases, targets):
            self.assertTrue(path.is_symlink())
            self.assertEqual(path.readlink(), target)
            self.assertEqual(tool.fingerprint(path.lstat()), tool.fingerprint(before[str(path)]))
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), report['records'][name])

    def test_present_system_command_alias_to_live_wrapper_is_followed(self):
        system, command = self.system_commands()
        wrapper = self.script('nightly', 'python3 ' + str(self.home / tool.NAMES[0]))
        alias = command / 'nightly'
        alias.symlink_to(wrapper)
        report = self.report([alias])
        self.assertNotIn(tool.NAMES[0], report['eligible'])
        self.assertEqual(report['preserved_system_links'], {})
        folder = self.archive(report)
        self.assertTrue((self.home / tool.NAMES[0]).exists())
        self.assertEqual(tool.restore(self.home, self.uid, folder), 16)

    def test_present_system_command_alias_to_installer_is_preserved(self):
        system, command = self.system_commands()
        alias = command / 'nightly'
        alias.symlink_to(self.home / tool.NAMES[0])
        report = self.report([alias])
        self.assertNotIn(tool.NAMES[0], report['eligible'])
        self.assertEqual(report['preserved_system_links'], {})

    def test_missing_application_and_scheduler_aliases_to_package_targets_still_stop(self):
        system, command = self.system_commands()
        for name in ['app', 'cron', 'systemd']:
            directory = self.source / name
            directory.mkdir()
            alias = directory / 'rcmysql'
            alias.symlink_to(system / 'etc/rc.d/init.d/mysql')
            with self.subTest(role=name), mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
                 mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
                 mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', (system,)), \
                 self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
                self.report(list(tool.sources(directory, all_files=True)))
        self.assertFalse((self.home / 'private-archives').exists())

    def test_system_command_exception_rejects_private_scheduler_and_installer_targets(self):
        system, command = self.system_commands()
        alias = command / 'rcmysql'
        targets = [self.home / 'missing.php', self.source / 'missing.py',
                   system / 'etc/apache2/missing.conf', system / 'opt/custom/missing',
                   system / 'usr/lib/systemd/system/missing.service',
                   system / 'usr/local/lib/systemd/user/missing.service',
                   system / 'usr/local/share/systemd/user/missing.service',
                   system / 'usr/libexec' / tool.NAMES[0],
                   system / 'usr/libexec' / tool.NAMES[0] / 'worker',
                   system / 'usr-lookalike/missing']
        for target in targets:
            with self.subTest(target=str(target)):
                alias.symlink_to(target)
                with mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
                     mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
                     mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', tuple(system / p for p in ['usr', 'etc/init.d', 'etc/rc.d/init.d', 'etc/alternatives', 'opt/cpanel'])), \
                     self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
                    self.report([alias])
                alias.unlink()
        self.assertFalse((self.home / 'private-archives').exists())

    def test_system_command_exception_rejects_source_directory_lookalikes_and_nested_dirs(self):
        system, command = self.system_commands()
        for directory in [command.parent / 'sbin-backup', command / 'nested', self.source]:
            directory.mkdir(parents=True, exist_ok=True)
            alias = directory / 'rcmysql'
            alias.symlink_to(system / 'etc/rc.d/init.d/mysql')
            with self.subTest(directory=str(directory)), mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
                 mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
                 mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', (system,)), \
                 self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
                self.report([alias])
        self.assertFalse((self.home / 'private-archives').exists())

    def test_system_command_exception_rejects_unexpected_ownership_and_writable_directory(self):
        system, command = self.system_commands()
        alias = command / 'rcmysql'
        alias.symlink_to(system / 'etc/rc.d/init.d/mysql')
        with mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', (system,)):
            with mock.patch.object(tool, 'SYSTEM_UID', self.uid + 1), \
                 self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
                self.report([alias])
            command.chmod(0o777)
            with mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
                 self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
                self.report([alias])
        self.assertFalse((self.home / 'private-archives').exists())

    def test_restored_system_command_is_not_classified_as_missing(self):
        system, command = self.system_commands()
        target = system / 'usr/worker'
        target.write_text('python3 ' + str(self.home / tool.NAMES[0]))
        alias = command / 'worker'
        alias.symlink_to(target)
        with mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', (system,)):
            self.assertFalse(tool.missing_system_command(alias, target))
            self.assertNotIn(tool.NAMES[0], self.report([alias])['eligible'])

    def test_archive_cli_reports_preserved_package_aliases_and_real_archive_result(self):
        system, command = self.system_commands()
        alias, target = command / 'rcmysql', system / 'etc/rc.d/init.d/mysql'
        alias.symlink_to(target)
        with mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', (system,)):
            report = self.report([alias])
        base = self.home / 'private-archives'
        base.mkdir(mode=0o700)
        lock = base / '.legacy-installers.lock'
        lock.touch(mode=0o600)
        lock_id = (lock.stat().st_dev, lock.stat().st_ino)
        original_fstat = os.fstat
        def lock_owner(fd):
            s = original_fstat(fd)
            # Only supply root lock ownership on unprivileged local runs. All
            # candidate/archive metadata and filesystem moves remain real.
            if (s.st_dev, s.st_ino) == lock_id:
                return SimpleNamespace(st_uid=0, st_mode=s.st_mode, st_nlink=s.st_nlink)
            return s
        output = io.StringIO()
        with mock.patch.object(tool, 'HOME', self.home), \
             mock.patch.object(tool.os, 'geteuid', return_value=0), \
             mock.patch.object(tool.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=self.uid)), \
             mock.patch.object(tool, 'host_scan', return_value=report), \
             mock.patch.object(tool.os, 'fstat', side_effect=lock_owner), \
             mock.patch('sys.argv', ['archive-legacy-home-files.py', '--archive']), \
             contextlib.redirect_stdout(output):
            tool.main()
        result = json.loads(next(line for line in output.getvalue().splitlines() if line.startswith('{')))
        self.assertEqual(result['result'], 'ARCHIVE PASS')
        self.assertEqual(result['archived'], 17)
        self.assertEqual(result['preserved_system_links'], {str(alias): str(target)})
        self.assertTrue(alias.is_symlink())
        self.assertEqual(tool.restore(self.home, self.uid, pathlib.Path(result['archive'])), 17)

    def test_system_command_restored_during_missing_target_recheck_is_not_skipped(self):
        system, command = self.system_commands()
        alias, target = command / 'worker', system / 'usr' / 'worker'
        alias.symlink_to(target)
        original_resolve = pathlib.Path.resolve
        strict_checks = []
        restored = []
        def restored_during_resolve(path, strict=False):
            if path == alias and strict:
                strict_checks.append(path)
            if path == alias and not strict and len(strict_checks) == 1 and not restored:
                target.write_text('python3 ' + str(self.home / tool.NAMES[0]))
                restored.append(target)
            return original_resolve(path, strict=strict)
        with mock.patch.object(tool, 'SYSTEM_UID', self.uid), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_DIRS', (command,)), \
             mock.patch.object(tool, 'SYSTEM_COMMAND_TARGETS', (system,)), \
             mock.patch.object(pathlib.Path, 'resolve', new=restored_during_resolve):
            self.assertFalse(tool.missing_system_command(alias, target))
            self.assertEqual(len(strict_checks), 2)
            self.assertEqual(restored, [target])
            self.assertNotIn(tool.NAMES[0], self.report([alias])['eligible'])
        self.assertTrue((self.home / tool.NAMES[0]).exists())

    def test_missing_system_compiler_alias_is_recorded_preserved_and_does_not_block_archive(self):
        compiler = self.compiler_namespace()
        alias, target = compiler / 'i386-redhat-linux', compiler / 'i686-redhat-linux'
        alias.symlink_to(target, target_is_directory=True)
        with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)):
            report = self.report([alias])
        self.assertEqual(report['preserved_compiler_links'], {str(alias): str(target)})
        folder = self.archive(report)
        self.assertTrue(alias.is_symlink())
        self.assertEqual(alias.readlink(), target)
        self.assertFalse(target.exists())
        journal = json.loads((folder / 'manifest.json').read_text())
        self.assertEqual(journal['scan']['preserved_compiler_links'], report['preserved_compiler_links'])
        self.assertEqual(tool.restore(self.home, self.uid, folder), 17)
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), report['records'][name])

    def test_missing_app_alias_into_compiler_namespace_still_stops(self):
        compiler = self.compiler_namespace()
        alias = self.source / 'application.php'
        alias.symlink_to(compiler / 'i686-redhat-linux')
        with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
             self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
            self.report([alias])
        self.assertFalse((self.home / 'private-archives').exists())
        self.assertTrue(all((self.home / name).exists() for name in tool.NAMES))

    def test_missing_scheduler_alias_into_compiler_namespace_still_stops(self):
        compiler = self.compiler_namespace()
        cron = self.home / 'cron.d'
        cron.mkdir()
        alias = cron / 'nightly'
        alias.symlink_to(compiler / 'i686-redhat-linux')
        with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
             self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
            self.report(list(tool.sources(cron, all_files=True)))
        self.assertTrue(alias.is_symlink())
        self.assertFalse((self.home / 'private-archives').exists())

    def test_compiler_alias_to_missing_app_target_still_stops(self):
        compiler = self.compiler_namespace()
        alias = compiler / 'i386-redhat-linux'
        alias.symlink_to(self.source / 'missing.php')
        with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
             self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
            self.report([alias])
        self.assertFalse((self.home / 'private-archives').exists())

    def test_compiler_namespace_lookalike_cannot_bypass_missing_dependency_guard(self):
        compiler = self.compiler_namespace()
        lookalike = compiler.parent / 'gcc-backup'
        lookalike.mkdir()
        alias = lookalike / 'i386-redhat-linux'
        alias.symlink_to(compiler / 'i686-redhat-linux')
        with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
             self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
            self.report([alias])
        self.assertFalse((self.home / 'private-archives').exists())

    def test_optional_multilib_runtime_aliases_are_preserved_during_archive_restore(self):
        compiler = self.compiler_namespace()
        libraries = (compiler.parent, compiler.parent.parent / 'lib64')
        libraries[1].mkdir()
        aliases = {}
        families = [('libstdc++.so', 'libstdc++.so.6.0.29'),
                    ('libgcc_s.so', 'libgcc_s.so.1'),
                    ('libasan.so', 'libasan.so.6.0.0'),
                    ('libgomp.so', 'libgomp.so.1.0.0'),
                    ('libatomic.so.1', 'libatomic.so.1.2.0'),
                    ('libquadmath.so', 'libquadmath-11-20230605.so.0'),
                    ('libstdc++fs.a', 'libstdc++fs.a')]
        for index, library in enumerate(libraries):
            directory = compiler / 'x86_64-redhat-linux' / '11' / ('32' if index == 0 else '64')
            directory.mkdir(parents=True)
            for name, target_name in families:
                alias, target = directory / name, library / target_name
                alias.symlink_to(target)
                aliases[alias] = target
        with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
             mock.patch.object(tool, 'LIBRARY_ROOTS', libraries):
            report = self.report(list(aliases))
        self.assertEqual(report['preserved_compiler_links'], {str(a): str(t) for a, t in aliases.items()})
        folder = self.archive(report)
        self.assertEqual(tool.restore(self.home, self.uid, folder), 17)
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), report['records'][name])
        for alias, target in aliases.items():
            self.assertTrue(alias.is_symlink())
            self.assertEqual(alias.readlink(), target)
            self.assertFalse(target.exists())

    def test_app_and_scheduler_library_aliases_still_stop(self):
        compiler = self.compiler_namespace()
        library = compiler.parent
        for directory_name in ['application', 'cron.d']:
            with self.subTest(directory=directory_name):
                directory = self.source / directory_name
                directory.mkdir()
                alias = directory / 'libstdc++.so'
                alias.symlink_to(library / 'libstdc++.so.6.0.29')
                with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
                     mock.patch.object(tool, 'LIBRARY_ROOTS', (library,)), \
                     self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
                    self.report([alias])
        self.assertFalse((self.home / 'private-archives').exists())
        self.assertTrue(all((self.home / name).exists() for name in tool.NAMES))

    def test_compiler_library_exception_rejects_other_families_and_library_dirs(self):
        compiler = self.compiler_namespace()
        library = compiler.parent
        alias = compiler / 'libstdc++.so'
        targets = [library / 'libdifferent.so.6',
                   library / 'libstdc++.so.6.php',
                   library / 'libstdc++.so.development',
                   library / 'libstdc++-debug.so.6',
                   library / 'libstdc++-11-evil.so.6',
                   library / 'nested' / 'libstdc++.so.6.0.29',
                   library.parent / 'lib-backup' / 'libstdc++.so.6.0.29',
                   self.source / 'libstdc++.so.6.0.29']
        for target in targets:
            with self.subTest(target=target):
                alias.symlink_to(target)
                with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
                     mock.patch.object(tool, 'LIBRARY_ROOTS', (library,)), \
                     self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
                    self.report([alias])
                alias.unlink()
        self.assertFalse((self.home / 'private-archives').exists())

    def test_compiler_script_alias_cannot_use_library_exception(self):
        compiler = self.compiler_namespace()
        library = compiler.parent
        alias = compiler / 'worker.py'
        alias.symlink_to(library / 'worker.py')
        with mock.patch.object(tool, 'COMPILER_ROOTS', (compiler,)), \
             mock.patch.object(tool, 'LIBRARY_ROOTS', (library,)), \
             self.assertRaisesRegex(RuntimeError, 'Missing dependency alias preserved'):
            self.report([alias])
        self.assertFalse((self.home / 'private-archives').exists())

    def test_symlink_candidate_is_never_archived(self):
        path = self.home / tool.NAMES[0]
        path.unlink()
        path.symlink_to(self.home / tool.NAMES[1])
        with self.assertRaises(RuntimeError):
            self.report()
        self.assertTrue(path.is_symlink())

    def test_hardlinked_candidate_is_never_archived(self):
        path = self.home / tool.NAMES[0]
        os.link(path, self.home / 'second-name')
        with self.assertRaises(RuntimeError):
            self.report()
        self.assertEqual(path.stat().st_nlink, 2)

    def test_candidate_change_after_scan_stops_before_archive_creation(self):
        report = self.report()
        (self.home / tool.NAMES[0]).write_text('unknown edit')
        with self.assertRaises(RuntimeError):
            self.archive(report)
        self.assertFalse((self.home / 'private-archives').exists())
        self.assertTrue(all((self.home / name).exists() for name in tool.NAMES))

    def test_restore_refuses_any_existing_original_before_moving_any_file(self):
        folder = self.archive(self.report())
        original = self.home / tool.NAMES[-1]
        original.write_text('new file')
        with self.assertRaises(RuntimeError):
            tool.restore(self.home, self.uid, folder)
        self.assertEqual(original.read_text(), 'new file')
        self.assertTrue(all((folder / name).exists() for name in tool.NAMES))

    def test_restore_refuses_modified_archive(self):
        folder = self.archive(self.report())
        (folder / tool.NAMES[0]).write_text('unknown edit')
        with self.assertRaises(RuntimeError):
            tool.restore(self.home, self.uid, folder)
        self.assertFalse(any((self.home / name).exists() for name in tool.NAMES))

    def test_interrupted_restore_resumes_without_overwriting(self):
        report = self.report()
        folder = self.archive(report)
        tool.rename_new(folder / tool.NAMES[0], self.home / tool.NAMES[0])
        self.assertEqual(tool.restore(self.home, self.uid, folder), 16)
        self.assertEqual(tool.restore(self.home, self.uid, folder), 0)
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), report['records'][name])

    def test_interrupted_archive_can_restore_before_move_is_journaled(self):
        report = self.report()
        rename = tool.rename_new
        def interrupted(a, b):
            rename(a, b)
            raise RuntimeError('fixture interruption after first move')
        with mock.patch.object(tool, 'rename_new', side_effect=interrupted), \
             self.assertRaisesRegex(RuntimeError, 'fixture interruption'):
            self.archive(report)
        folder = next((self.home / 'private-archives').glob('sitesee-legacy-installers-*'))
        self.assertEqual(tool.restore(self.home, self.uid, folder), 1)
        for name in tool.NAMES:
            self.assertEqual(tool.candidate(self.home / name, self.uid), report['records'][name])

    def test_atomic_move_never_overwrites_destination(self):
        a, b = self.home / tool.NAMES[0], self.home / tool.NAMES[1]
        original_a, original_b = a.read_bytes(), b.read_bytes()
        with self.assertRaises(OSError):
            tool.rename_new(a, b)
        self.assertEqual(a.read_bytes(), original_a)
        self.assertEqual(b.read_bytes(), original_b)

    def test_retry_reports_missing_files_and_does_not_create_second_archive(self):
        self.archive(self.report())
        report = self.report(blobs=[('cron', ('python3 ' + str(self.home / tool.NAMES[0])).encode())])
        self.assertEqual(report['eligible'], [])
        self.assertEqual(set(report['missing']), set(tool.NAMES))
        self.assertIsNone(self.archive(report))

    def test_check_creates_no_lock_archive_or_other_file(self):
        before = sorted(str(p) for p in self.home.rglob('*'))
        with mock.patch.object(tool, 'HOME', self.home), mock.patch.object(tool.os, 'geteuid', return_value=0), \
             mock.patch.object(tool.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=self.uid)), \
             mock.patch.object(tool, 'host_scan', return_value=self.report()), \
             mock.patch('sys.argv', ['archive-legacy-home-files.py', '--check']), \
             contextlib.redirect_stdout(io.StringIO()):
            tool.main()
        self.assertEqual(sorted(str(p) for p in self.home.rglob('*')), before)

    def test_archive_parent_is_synced_before_first_move(self):
        events = []
        sync, rename = tool.sync_directory, tool.rename_new
        def synced(path):
            events.append(('sync', path))
            sync(path)
        def renamed(a, b):
            events.append(('rename', a))
            rename(a, b)
        with mock.patch.object(tool, 'sync_directory', side_effect=synced), \
             mock.patch.object(tool, 'rename_new', side_effect=renamed):
            self.archive(self.report())
        first = next(i for i, event in enumerate(events) if event[0] == 'rename')
        self.assertIn(('sync', self.home), events[:first])
        self.assertIn(('sync', self.home / 'private-archives'), events[:first])


if __name__ == '__main__':
    unittest.main(verbosity=2)
