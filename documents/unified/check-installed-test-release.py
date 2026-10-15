import fcntl, hashlib, json, os, pathlib, pwd, stat

CORE = 'fa841de7bb2607d7b8dae6428f6f5a75248d4837ba672e8dac1fe856a59f13e2'
MANIFEST = '1d9f8f6bc14dccabf3894501c1e9b2792389232a3c6684628851b6f6d2a29fb0'
COMMIT = 'e0bed2b3726c05b52c5d2c943ccd887cd3b376b8'
PACKAGE = '82be15dbd74b6f0e22627c2992d0db0fc743adf752a47dfb9799e8a7702ad0b6'
HOST = {'public/.htaccess':'e640befd468cd72a53a925ae633490e09fae922c7bf7299e9d73b523178c7ee2','public/assets/images/platform/notes-collaboration.svg':'0fd47ceb96ace2afc73878e1be338d037140472c4b90e12471c75730049a9f4e','public/assets/js/turnstile.js':'106d79ade29b3139805004ed44cc6c185709622fe32d1df70d667c92f4840c73'}
D = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
# cPanel may allow searching / and /home without allowing directory listing.
# O_PATH obtains a traversal handle without requesting directory read access.
SEARCH = os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW

def root_identity(fd):
    s = os.fstat(fd)
    return (s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode)

def opened(base, name, directory=False):
    parts = pathlib.PurePosixPath(name).parts
    if not parts or any(p in ('..', '.', '/') for p in parts):
        raise RuntimeError('Unexpected relative path')
    fd = os.dup(base)
    try:
        for part in parts[:-1]:
            nxt = os.open(part, SEARCH, dir_fd=fd); os.close(fd); fd = nxt
        return os.open(parts[-1], D if directory else os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | getattr(os,'O_NOATIME',0), dir_fd=fd)
    finally:
        os.close(fd)

def read(base, name, uid, limit=128*1024*1024):
    fd = opened(base, name)
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or before.st_uid != uid or before.st_nlink != 1 or before.st_mode & 0o022 or before.st_size > limit:
            raise RuntimeError('Unexpected file metadata: ' + name)
        chunks = []; count = 0
        while True:
            b = os.read(fd, 65536)
            if not b: break
            chunks.append(b); count += len(b)
            if count > limit: raise RuntimeError('File grew: ' + name)
        after = os.fstat(fd)
        fields = lambda s:(s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        if count != before.st_size or fields(before) != fields(after): raise RuntimeError('File changed: ' + name)
        current = opened(base, name)
        try:
            if fields(before) != fields(os.fstat(current)): raise RuntimeError('File replaced: ' + name)
        finally: os.close(current)
        return b''.join(chunks)
    finally:
        os.close(fd)

def verify(private, public, uid):
    top = os.open('/', SEARCH); private_fd = public_fd = None
    try:
        private_fd = opened(top, private.lstrip('/'), True)
        public_fd = opened(top, public.lstrip('/'), True)
        fcntl.flock(private_fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
        roots_before = (root_identity(private_fd), root_identity(public_fd))
        raw = read(private_fd, 'unified-release.json', uid, 1024*1024); record = json.loads(raw)
        if (record.get('release') != 'unified-test-'+COMMIT[:12] or record.get('commit') != COMMIT or record.get('stage') != 'TEST' or record.get('manifest_sha256') != MANIFEST):
            raise RuntimeError('Installed release identity differs')
        files = record.get('files', {}); core = {k:v for k,v in files.items() if k not in HOST}
        if len(files) != 276 or hashlib.sha256(json.dumps(core,sort_keys=True,separators=(',',':')).encode()).hexdigest() != CORE or any(files.get(k) != v for k,v in HOST.items()):
            raise RuntimeError('Release file map differs from approved source')
        for name, digest in files.items():
            role, relative = name.split('/',1)
            data = read(private_fd if role == 'private' else public_fd, relative, uid)
            if hashlib.sha256(data).hexdigest() != digest: raise RuntimeError('File differs: '+name)
        try:
            os.stat('.unified-maintenance.json',dir_fd=private_fd,follow_symlinks=False)
        except FileNotFoundError: pass
        else: raise RuntimeError('Application maintenance marker remains')
        journal = json.loads(read(private_fd,'unified-'+COMMIT[:12]+'-install.json',uid,2*1024*1024))
        backup = journal.get('backup','')
        if journal.get('state') != 'installed' or journal.get('release') != record['release'] or journal.get('package_sha256') != PACKAGE or not backup.startswith(record['release']+'-') or '/' in backup or '..' in backup:
            raise RuntimeError('Installation journal differs')
        backup_fd = opened(private_fd,'unified-deployments/'+backup,True); os.close(backup_fd)
        if read(private_fd,'unified-release.json',uid,1024*1024) != raw: raise RuntimeError('Release changed during check')
        for path, expected in zip((private,public),roots_before):
            current_root = opened(top,path.lstrip('/'),True)
            try:
                if root_identity(current_root) != expected: raise RuntimeError('Application root changed during check')
            finally: os.close(current_root)
        return {'result':'SITESEE RELEASE FILE CHECK PASS','release':record['release'],'commit':COMMIT,'stage':'TEST','files_checked':len(files),'journal':'installed','backup':private+'/unified-deployments/'+backup,'backup_directory_present':True,'scope':'SiteSee code and release metadata only; no FPM probe or backup-content check'}
    finally:
        for fd in (private_fd, public_fd, top):
            if fd is not None: os.close(fd)

if __name__ == '__main__':
    try:
        print(json.dumps(verify('/home/sitesee/.sitesee-real-estate','/home/sitesee/public_html/re',pwd.getpwnam('sitesee').pw_uid)))
    except Exception as error:
        print(json.dumps({'result':'SITESEE RELEASE FILE CHECK STOPPED','reason':str(error)})); raise SystemExit(1)
