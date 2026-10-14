"""Required disposable-root CI: actual Linux schedules, units and processes."""
import contextlib
import hashlib
import importlib.util
import io
import os
import pathlib
import shutil
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
        media_dir = home / 'ctc-publishing/server/media-bin'
        media_dir.mkdir(parents=True)
        media_before = {}
        for name in ('ffprobe', 'ffmpeg'):
            native = shutil.which(name)
            assert native, 'Required actual FFmpeg tools missing from CI image.'
            target = media_dir / name
            shutil.copyfile(native, target)
            target.chmod(0o755)
            media_before[str(target)] = (hashlib.sha256(target.read_bytes()).hexdigest(), tool.fingerprint(target.stat()))
        media_source = home / 'media-launcher.js'
        media_source.write_text('const tools = ' + repr(list(media_before)))
        roots = []
        def inventory(*_):
            paths = [wrapper, media_source]
            for name in ['/etc/cron.d', '/etc/cron.hourly', '/etc/cron.daily', '/etc/cron.weekly',
                         '/etc/cron.monthly', *map(str, tool.unit_directories(home, 0))]:
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
        with mock.patch.object(tool, 'host_scan', side_effect=inventory):
            try:
                report = tool.checked_host_scan(home, 0)
            except Exception as error:
                # Narrow public-package diagnostics only: no source contents,
                # process arguments, credentials or arbitrary binary data.
                frame = error.__traceback__
                while frame:
                    state = frame.tb_frame.f_locals
                    target = state.get('path')
                    if frame.tb_frame.f_code.co_name == 'scan' and str(target) == '/usr/share/java/selenium-server.jar':
                        aliases = []
                        for entry in state.get('seen', ()):
                            candidate = pathlib.Path(entry[0])
                            if candidate.is_symlink() and candidate.resolve() == target:
                                aliases.append(str(candidate))
                        print('PUBLIC PACKAGE DIAGNOSTIC: required=' + str(target in state.get('required_sources', set())) +
                              '; all_files=' + str(state.get('all_files')) +
                              '; magic=' + state.get('head', b'').hex() + '; aliases=' + repr(aliases), flush=True)
                    frame = frame.tb_next
                raise
        assert report['sources_checked'] > 20
        assert len(report['eligible']) == 16 and tool.NAMES[0] in report['referenced']
        assert set(report['preserved_media']) == set(media_before)
        assert runtime.joinpath('unrelated.py').stat().st_size > tool.MAX_FILE
        with contextlib.redirect_stdout(io.StringIO()):
            archive = tool.archive(home, 0, report)
        assert (home / tool.NAMES[0]).exists()
        assert tool.restore(home, 0, archive) == 16
        assert all(tool.candidate(home / name, 0) == report['records'][name] for name in tool.NAMES)
        for name, (digest, metadata) in media_before.items():
            target = pathlib.Path(name)
            assert report['preserved_media'][name]['sha256'] == digest
            assert hashlib.sha256(target.read_bytes()).hexdigest() == digest and tool.fingerprint(target.stat()) == metadata
        print('REAL CTC MEDIA PASS: actual ffprobe and ffmpeg unchanged; native tools never executed.')
        print('REAL LINUX RUNTIME PASS: roots=' + str(len(roots)) +
              '; sources=' + str(report['sources_checked']) +
              '; mixed IPC/device tree preserved; live wrapper kept;16 moved/restored.')
    finally:
        listener.close()
