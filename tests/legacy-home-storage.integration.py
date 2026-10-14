"""Required root CI: exercise actual kernel loop mounts, never hosting storage."""
import contextlib
import importlib.util
import io
import os
import pathlib
import subprocess
import tempfile

spec = importlib.util.spec_from_file_location('home_archive', pathlib.Path(__file__).parents[1] / 'tools/archive-legacy-home-files.py')
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)
assert os.geteuid() == 0, 'This integration requires root in the disposable CI VM.'


def command(*args):
    return subprocess.run(args, check=True, capture_output=True, text=True).stdout.strip()


with tempfile.TemporaryDirectory(prefix='sitesee-storage-ci-', dir='/var/lib') as temp:
    base = pathlib.Path(temp)
    home = base / 'account'
    home.mkdir()
    for name in tool.NAMES:
        (home / name).write_text('# synthetic historical installer ' + name + '\n')
    image = base / 'filesystem-store'
    with image.open('wb') as stream:
        stream.truncate(64 * 1024 * 1024)
    image.chmod(0o600)
    command('mke2fs', '-q', '-F', '-t', 'ext4', str(image))
    mount = base / 'mounted'
    mount.mkdir()
    wrapper = home / 'storage-wrapper.sh'
    wrapper.write_text('mount ' + str(image) + '\n')
    loop = command('losetup', '--find', '--show', str(image))
    mounted = False
    try:
        command('mount', loop, str(mount))
        mounted = True
        # Actual filesystem write, followed by real kernel inode/mount checks.
        (mount / 'data.txt').write_text('mounted filesystem content\n')
        command('sync', '-f', str(mount / 'data.txt'))
        before = tool.fingerprint(image.stat())
        report = tool.scan(home, 0, [wrapper], root_home=home)
        assert report['preserved_storage'][str(image)]['ino'] == image.stat().st_ino
        assert len(report['eligible']) == 17
        with contextlib.redirect_stdout(io.StringIO()):
            archive = tool.archive(home, 0, report)
        assert tool.restore(home, 0, archive) == 17
        assert all(tool.candidate(home / name, 0) == report['records'][name] for name in tool.NAMES)
        assert (mount / 'data.txt').read_text() == 'mounted filesystem content\n'
        assert image.stat().st_ino == before['ino']
        # A real scheduler/source alias cannot use mounted storage classification.
        alias = home / 'active-job'
        alias.symlink_to(image)
        try:
            tool.scan(home, 0, [alias], root_home=home)
        except RuntimeError as error:
            assert 'Unreviewed type/size' in str(error), str(error)
        else:
            raise AssertionError('Declared source alias was incorrectly treated as storage.')
        command('umount', str(mount))
        mounted = False
        # Same valid image and attached loop, but no live mount: refuse.
        try:
            tool.scan(home, 0, [wrapper], root_home=home)
        except RuntimeError as error:
            assert 'Unreviewed type/size' in str(error), str(error)
        else:
            raise AssertionError('Unmounted image was incorrectly treated as active storage.')
        assert all((home / name).exists() for name in tool.NAMES)
    finally:
        if mounted:
            command('umount', str(mount))
        command('losetup', '--detach', loop)
print('REAL MOUNTED STORAGE PASS: kernel identity, ext4, writes, 17 moves/restore, source alias, unmounted refusal.')
