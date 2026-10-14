import contextlib
import importlib.util
import io
import json
import os
import pathlib
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

spec = importlib.util.spec_from_file_location('home_archive', pathlib.Path(__file__).parents[1] / 'tools/archive-legacy-home-files.py')
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)


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
