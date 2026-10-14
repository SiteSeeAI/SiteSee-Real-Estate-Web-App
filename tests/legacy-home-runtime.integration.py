"""Required disposable-root CI: actual Linux schedules, units and processes."""
import contextlib
import importlib.util
import io
import os
import pathlib
import socket
import tempfile
from unittest import mock

spec = importlib.util.spec_from_file_location('home_archive', pathlib.Path(__file__).parents[1] / 'tools/archive-legacy-home-files.py')
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)
assert os.geteuid() == 0, 'Run only as root in the disposable CI VM.'

with tempfile.TemporaryDirectory(prefix='sitesee-runtime-ci-', dir='/var/lib') as temp:
    base = pathlib.Path(temp)
    home = base / 'account'
    home.mkdir()
    for name in tool.NAMES:
        (home / name).write_text('# synthetic historical installer ' + name + '\n')
    runtime = base / 'runtime'
    tags = runtime / 'udev/static_node-tags/uaccess'
    tags.mkdir(parents=True)
    (tags / r'snd\x2ftimer').symlink_to('/dev/zero')
    (runtime / 'stale-alias').symlink_to(runtime / 'unavailable')
    (runtime / 'loop').symlink_to(runtime, target_is_directory=True)
    os.mkfifo(runtime / 'pipe')
    (runtime / 'pipe-alias').symlink_to(runtime / 'pipe')
    listener = socket.socket(socket.AF_UNIX)
    try:
        listener.bind(str(runtime / 'socket'))
        (runtime / 'socket-alias').symlink_to(runtime / 'socket')
        with (runtime / 'unrelated.py').open('wb') as stream:
            stream.truncate(tool.MAX_FILE + 4096)
        (runtime / 'refresh.py').write_text('python3 ' + str(home / tool.NAMES[0]))
        alias = home / 'runtime-link'
        alias.symlink_to(runtime, target_is_directory=True)
        wrapper = home / 'service.conf'
        wrapper.write_text('StateDirectory=' + str(alias) + '\nExecStart=python3 ' + str(alias / 'refresh.py'))
        roots = []
        def inventory(*_):
            paths = [wrapper]
            for name in ['/etc/cron.d', '/etc/cron.hourly', '/etc/cron.daily', '/etc/cron.weekly',
                         '/etc/cron.monthly', '/etc/systemd/system', '/usr/lib/systemd/system',
                         '/run/systemd/system', '/run/systemd/transient', '/run/systemd/generator',
                         '/run/systemd/generator.early', '/run/systemd/generator.late']:
                directory = pathlib.Path(name)
                if directory.is_dir():
                    roots.append(name)
                    paths.extend(tool.sources(directory, all_files=True))
            for name in ['/etc/crontab', '/etc/anacrontab']:
                path = pathlib.Path(name)
                if path.exists():
                    paths.append(path)
            assert len(paths) > 20 and len(roots) >= 2, 'Real Linux source inventory unavailable.'
            return tool.scan(home, 0, paths, inspect_processes=True, root_home=pathlib.Path('/root'))
        # This executable harness constructs every decoy by name. Give its own
        # source the same self-inventory exclusion used by the standalone CLI;
        # otherwise its fixture constructors become fictitious dependencies.
        # Real OS sources, all other processes and the live wrapper stay checked.
        with mock.patch.object(tool, 'host_scan', side_effect=inventory), \
             mock.patch.object(tool, '__file__', __file__):
            report = tool.checked_host_scan(home, 0)
        assert report['sources_checked'] > 20
        assert len(report['eligible']) == 16 and tool.NAMES[0] in report['referenced']
        assert runtime.joinpath('unrelated.py').stat().st_size > tool.MAX_FILE
        with contextlib.redirect_stdout(io.StringIO()):
            archive = tool.archive(home, 0, report)
        assert (home / tool.NAMES[0]).exists()
        assert tool.restore(home, 0, archive) == 16
        assert all(tool.candidate(home / name, 0) == report['records'][name] for name in tool.NAMES)
        print('REAL LINUX RUNTIME PASS: roots=' + str(len(roots)) +
              '; sources=' + str(report['sources_checked']) +
              '; mixed IPC/device tree preserved; live wrapper kept;16 moved/restored.')
    finally:
        listener.close()
