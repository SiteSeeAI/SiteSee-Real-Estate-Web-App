import os, pathlib, tempfile, hashlib, json, fcntl, subprocess, stat

INSTALLER_SHA = 'ef9442311d50a44f4b3b7352550fee0fe9c81b68cf2c9d1dd44642cdda7a2503'
PACKAGE_SHA = '3a33d161bcd2ed96485bb23b6560eef63df6a5c844a33313dfb1b475c4fd83ae'


def repair(stage, private, public):
    owner = os.geteuid()
    info = stage.stat()
    if stage != stage.resolve() or not stat.S_ISDIR(info.st_mode) or info.st_uid != owner or info.st_mode & 0o077:
        raise SystemExit('Staging must be canonical, account-owned and private; no files changed.')
    script = stage / 'install-unified.py'
    with os.fdopen(os.open(script, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != owner or info.st_nlink != 1 or info.st_mode & 0o022:
            raise SystemExit('Unsafe staged installer; no files changed.')
        source = stream.read()
    if hashlib.sha256(source).hexdigest() != INSTALLER_SHA:
        raise SystemExit('Installer checksum mismatch; no files changed.')
    tool = {'__name__':'verified_unified_installer', '__file__':str(script)}
    exec(compile(source, str(script), 'exec'), tool)
    obj, files, manifest_sha = tool['load'](stage / 'unified-test-d380d41a0903.tar.gz', PACKAGE_SHA)
    tool['safe'](private)
    journal = 'unified-' + obj['commit'][:12] + '-install.json'
    lock = os.open(private, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        database = pathlib.Path(os.getenv('SITESEE_REAL_ESTATE_BOOKING_DB', str(private / 'data/bookings.sqlite')))
        tool['check_dirs'](private, public, database, owner, files)
        if tool['read'](private / journal, owner, True) is not None:
            raise SystemExit('An installation journal exists; no files changed.')
        tool['test_settings'](private, owner, journal)
        fixes, problems = [], []
        for name, item in obj['files'].items():
            path = tool['target'](name, private, public)
            try:
                data = tool['read'](path, owner, item['allow_missing'])
                if name == 'public/.htaccess':
                    tool['preserved_apache'](files[name], data, item['accepted_before'])
                elif data is None and item['allow_missing']:
                    continue
                elif tool['sha'](data) not in item['accepted_before']:
                    if name.startswith('public/') and name.endswith('.svg') and data.replace(b'\r\n', b'\n') == files[name]:
                        fixes.append((name, path, data, tool['metadata'](path)))
                    else:
                        problems.append(name + ' SHA256=' + str(tool['sha'](data)))
            except tool['Stop'] as error:
                problems.append(name + ': ' + str(error))
        if problems:
            print('ALL remaining differences; no files changed:', flush=True)
            for problem in problems:
                print(problem, flush=True)
            raise SystemExit(1)
        # A read-only plan permits only already-proven equivalent SVG bytes.
        # The authenticated archive and every host manifest remain untouched.
        preview = json.loads(json.dumps(obj))
        for name, _, data, _ in fixes:
            preview['files'][name]['accepted_before'].append(tool['sha'](data))
        before, _, _ = tool['inspect'](preview, files, private, public, owner, manifest_sha)
        records = [before['private/' + name] for name in obj['active_manifests']]
        unified = tool['read'](private / 'unified-release.json', owner, True)
        if unified is not None:
            records.append(unified)
        for raw in records:
            record = json.loads(raw)
            tool['need'](isinstance(record, dict) and isinstance(record.get('files'), dict), 'Release record differs; files preserved.')
            for key, digest in record['files'].items():
                name = key if key.startswith(('private/', 'public/')) else 'private/' + key
                tool['target'](name, private, public)
                if name in before:
                    tool['need'](digest == tool['sha'](before[name]), 'Established release hash differs: ' + name)
                if any(name == fix[0] for fix in fixes):
                    tool['need'](digest == tool['sha'](files[name]), 'SVG is referenced by a release record; files preserved for review.')
        if fixes:
            backup = pathlib.Path(tempfile.mkdtemp(prefix='svg-originals-', dir=stage))
            tool['sync_dir'](stage)
            backup_meta = {'uid':owner, 'gid':os.getegid(), 'mode':0o600}
            for name, path, data, meta in fixes:
                target = backup / path.name
                tool['atomic'](target, data, backup_meta)
                if target.read_bytes() != data:
                    raise SystemExit('Backup verification failed; original unchanged.')
                tool['atomic'](backup / (path.name + '.metadata.json'), tool['encode']({'path':str(path), 'sha256':tool['sha'](data), **meta}), backup_meta)
                if tool['read'](path, owner) != data or tool['metadata'](path) != meta:
                    raise SystemExit('File changed during review; original preserved.')
                tool['atomic'](path, files[name], meta)
                print('FIXED line endings:', name, flush=True)
            print('Original files and metadata preserved in:', backup, flush=True)
        print('All', len(files), 'deployment paths checked.', flush=True)
    finally:
        os.close(lock)


if __name__ == '__main__':
    stage = pathlib.Path('/home/sitesee/staging')
    try:
        repair(stage, pathlib.Path('/home/sitesee/.sitesee-real-estate'), pathlib.Path('/home/sitesee/public_html/re'))
    except Exception as error:
        raise SystemExit('STOPPED before preflight: ' + str(error))
    raise SystemExit(subprocess.run(['python3', str(stage / 'install-unified.py'), '--package', str(stage / 'unified-test-d380d41a0903.tar.gz'), '--sha256', PACKAGE_SHA, '--php', '/opt/cpanel/ea-php82/root/usr/bin/php', '--preflight']).returncode)
