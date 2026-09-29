#!/usr/bin/env python3
"""Single-file, offline TEST portal installation. No provider calls or database writes."""
import argparse, base64, fcntl, hashlib, json, os, pathlib, pwd, re, stat, subprocess, sys, tempfile, zlib
RELEASE = 'portal-20260929-r1'
ROOT = pathlib.Path('/home/sitesee/.sitesee-real-estate')
PUBLIC = pathlib.Path('/home/sitesee/public_html/re')
PAYLOAD = '__PAYLOAD__'
PAYLOAD_SHA = '__PAYLOAD_SHA__'
JOURNAL = 'portal-install.json'
FLAG = 'portal-test.json'
EVER = 'portal-ever-enabled.json'

class Stop(RuntimeError): pass

def need(ok, message):
    if not ok: raise Stop(message)

def sha(data): return hashlib.sha256(data).hexdigest()
def encode(value): return (json.dumps(value, sort_keys=True, indent=2)+'\n').encode()

def load():
    raw=zlib.decompress(base64.b64decode(PAYLOAD));need(sha(raw)==PAYLOAD_SHA,'Installer payload integrity differs.')
    obj=json.loads(raw)
    return obj,{k:base64.b64decode(v) for k,v in obj['files'].items()}

def safe(path):
    for p in [path]+list(path.parents):
        need(not p.is_symlink(),'Symbolic link preserved: '+str(p))

def read(path, uid, optional=False):
    safe(path)
    if not path.exists():
        need(optional,'Required file missing: '+str(path));return None
    info=path.stat();need(stat.S_ISREG(info.st_mode) and info.st_uid==uid and not info.st_mode&0o022,'Unsafe file preserved: '+str(path))
    need(info.st_size<=4000000,'Unexpected file size: '+str(path))
    return path.read_bytes()

def target(name,root,public):
    parts=pathlib.PurePosixPath(name).parts
    need(parts and parts[0] in ('private','public') and all(x not in ('.','..') for x in parts),'Invalid target.')
    return (root if parts[0]=='private' else public).joinpath(*parts[1:])

def atomic(path,data,uid,gid,mode=0o600):
    safe(path)
    if not path.parent.exists():
        path.parent.mkdir(mode=0o755 if mode==0o644 else 0o700);os.chown(str(path.parent),uid,gid);os.chmod(str(path.parent),0o755 if mode==0o644 else 0o700)
    fd,tmp=tempfile.mkstemp(prefix='.portal-',dir=str(path.parent))
    try:
        os.fchmod(fd,mode);os.fchown(fd,uid,gid)
        with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(tmp,str(path))
        d=os.open(str(path.parent),os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(d)
        finally:os.close(d)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def unlink_durable(path):
    safe(path)
    if path.exists():
        path.unlink();fd=os.open(str(path.parent),os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(fd)
        finally:os.close(fd)

def write_target(name,data,root,public,uid,gid):
    p=target(name,root,public)
    if data is None:
        safe(p)
        unlink_durable(p)
    else:atomic(p,data,uid,gid,0o644 if name.startswith('public/') else 0o600)

def journal(root,uid):
    raw=read(root/JOURNAL,uid,True)
    if raw is None:return None
    j=json.loads(raw);need(j.get('release')==RELEASE and j.get('state') in ('prepared','installed','restored'),'Different installer journal preserved.')
    return j

def verify_directories(root,public,uid):
    for path,mode in ((root/'server',0o700),(root/'views',0o700),(public/'portal-assets',0o755)):
        safe(path)
        if path.exists():
            info=path.stat();need(stat.S_ISDIR(info.st_mode) and info.st_uid==uid and not info.st_mode&0o022,'Unsafe application directory: '+str(path))
            if mode==0o755:need(info.st_mode&0o555==0o555,'Public assets directory must be readable and traversable.')

def verify_dependencies(obj,root,public,uid):
    verify_directories(root,public,uid)
    for name,accepted in obj['dependencies'].items():
        data=read(target(name,root,public),uid)
        need(sha(data) in accepted,'Unreviewed dependency preserved: '+name)
    for name,expected in obj['fonts'].items():
        data=read(public/name,uid)
        need(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()==expected,'Brand font differs: '+name)
    record=json.loads(read(root/'appointment-management-release.json',uid))
    need(record.get('revision')=='20260928-r4','The verified r4 appointment release is required.')
    config_path=root/'booking-checkout.json'
    config_raw=read(config_path,uid)
    need(not config_path.stat().st_mode&0o077 and len(config_raw)<=4096,'Private checkout configuration permissions or size differ.')
    config=json.loads(config_raw)
    need(config.get('stage')=='TEST' and isinstance(config.get('enabled'),bool) and re.fullmatch(r'pk_test_[A-Za-z0-9]{12,512}',str(config.get('publishable_key',''))),'Existing checkout must be TEST.')
    need((root/'data').is_dir() and not (root/'data').is_symlink(),'Private data directory missing.')
    info=(root/'data').stat();need(info.st_uid==uid and not info.st_mode&0o077,'Private data directory permissions differ.')

def desired(obj,files,root,uid):
    values=dict(files)
    # Keep every historical manifest entry; update only the intentional webhook hash.
    for name in obj['manifests']:
        raw=read(root/name,uid);record=json.loads(raw)
        need(isinstance(record.get('files'),dict),'Release metadata differs: '+name)
        for key,digest in record['files'].items():
            need(isinstance(key,str) and not key.startswith('/') and '..' not in pathlib.PurePosixPath(key).parts,'Invalid release path.')
            data=read(root/key,uid)
            allowed={sha(data)}
            if key=='server/booking-webhook.php':allowed.update(obj['webhook_before']);allowed.add(sha(files['private/server/booking-webhook.php']))
            need(digest in allowed,'Established release hash differs: '+key)
        if 'server/booking-webhook.php' in record['files']:
            record['files']['server/booking-webhook.php']=sha(files['private/server/booking-webhook.php'])
            values['private/'+name]=encode(record)
    return values

def recover(root,public,uid,gid,values,rollback=False):
    j=journal(root,uid)
    if not j or j['state']=='restored':return
    if j['state']=='installed' and not rollback:return
    need(read(root/EVER,uid,True) is None,'Portal has been activated. Use --disable; retain webhook and ledger, then fix forward.')
    need(read(root/FLAG,uid,True) is None,'Disable account access before rollback.')
    need(set(j['entries'])==set(values),'Journal targets differ.')
    backup=root/'deployment-backups'/j['backup'];safe(backup)
    need(backup.parent==root/'deployment-backups' and backup.name.startswith('portal-'),'Backup location differs.')
    restore={}
    for name,item in j['entries'].items():
        need(item['after']==sha(values[name]),'Journal payload differs.')
        before=None
        if item['before'] is not None:
            before=read(backup/(hashlib.sha256(name.encode()).hexdigest()+'.bin'),uid)
            need(sha(before)==item['before'],'Backup checksum differs.')
        current=read(target(name,root,public),uid,True)
        need(current in (before,values[name]),'Unknown edit preserved during recovery: '+name)
        restore[name]=before
    # Remove public account entry first. No database or payment records are restored.
    order=sorted(restore,key=lambda n:0 if n=='public/account.php' else (1 if n=='private/server/booking-webhook.php' else 2))
    for name in order:
        write_target(name,restore[name],root,public,uid,gid)
        if restore[name] is not None:os.chmod(str(target(name,root,public)),j['entries'][name]['mode'])
    j['state']='restored';atomic(root/JOURNAL,encode(j),uid,gid)

def install(obj,files,root,public,uid,gid,writer=None):
    verify_directories(root,public,uid)
    values=desired(obj,files,root,uid)
    j=journal(root,uid)
    if j and j['state']=='prepared':
        recover(root,public,uid,gid,values);values=desired(obj,files,root,uid)
    j=journal(root,uid)
    if j and j['state']=='installed':
        need(set(j['entries'])==set(values),'Installed manifest targets differ.')
        for name,data in values.items():need(read(target(name,root,public),uid)==data,'Installed file differs: '+name)
        return 'Already installed; unchanged.'
    need(read(root/EVER,uid,True) is None and read(root/FLAG,uid,True) is None,'Existing portal activation preserved.')
    for name,data in values.items():
        current=read(target(name,root,public),uid,True)
        if name in files:
            if name=='private/server/booking-webhook.php':need(current is not None and sha(current) in obj['webhook_before'],'Original webhook differs; preserved.')
            else:need(current is None,'Existing portal file preserved: '+name)
    base=root/'deployment-backups';safe(base)
    if not base.exists():base.mkdir(mode=0o700);os.chown(str(base),uid,gid)
    folder=pathlib.Path(tempfile.mkdtemp(prefix='portal-',dir=str(base)));os.chown(str(folder),uid,gid)
    entries={}
    for name,data in values.items():
        p=target(name,root,public);before=read(p,uid,True)
        entries[name]={'before':None if before is None else sha(before),'after':sha(data),'mode':stat.S_IMODE(p.stat().st_mode) if before is not None else None}
        if before is not None:atomic(folder/(hashlib.sha256(name.encode()).hexdigest()+'.bin'),before,uid,gid)
    backup_parent=os.open(str(base),os.O_RDONLY|os.O_DIRECTORY)
    try:os.fsync(backup_parent)
    finally:os.close(backup_parent)
    j={'release':RELEASE,'state':'prepared','backup':folder.name,'entries':entries};atomic(root/JOURNAL,encode(j),uid,gid)
    write=writer or write_target
    # Dependencies first, webhook next, account route last. Activation is a separate operation.
    order=sorted(values,key=lambda n:2 if n=='public/account.php' else (1 if n=='private/server/booking-webhook.php' else 0))
    for name in order:write(name,values[name],root,public,uid,gid)
    for name,data in values.items():need(read(target(name,root,public),uid)==data,'Post-install checksum differs: '+name)
    j['state']='installed';atomic(root/JOURNAL,encode(j),uid,gid)
    return 'Installed, disabled. Backup: '+str(folder)

def activate(root,public,uid,gid,values):
    j=journal(root,uid);need(j and j['state']=='installed','Install and verify first.')
    for name,data in values.items():need(read(target(name,root,public),uid)==data,'Installed checksum differs: '+name)
    # Durable marker precedes activation, so interruption cannot permit unsafe rollback.
    marker=encode({'release':RELEASE,'ever_enabled':True})
    old=read(root/EVER,uid,True);need(old in (None,marker),'Activation history differs.')
    atomic(root/EVER,marker,uid,gid)
    flag=b'{"release":"portal-20260929-r1","stage":"TEST","enabled":true}\n'
    old=read(root/FLAG,uid,True);need(old in (None,flag),'Activation flag differs.')
    atomic(root/FLAG,flag,uid,gid)

def lint(files,php):
    with tempfile.TemporaryDirectory(prefix='portal-lint-') as folder:
        for name,data in files.items():
            if name.endswith('.php'):
                p=pathlib.Path(folder)/pathlib.Path(name).name;p.write_bytes(data)
                result=subprocess.run([php,'-l',str(p)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
                need(result.returncode==0,'PHP syntax failed: '+name)
        result=subprocess.run([php,'-r',"exit(PHP_VERSION_ID >= 80200 && PHP_SAPI === 'cli' && extension_loaded('pdo_sqlite') && extension_loaded('curl') ? 0 : 1);"],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
        need(result.returncode==0,'PHP CLI 8.2+, SQLite and cURL required.')

def main():
    parser=argparse.ArgumentParser(description=__doc__);group=parser.add_mutually_exclusive_group()
    for flag in ('check','install','enable-test','disable','rollback'):group.add_argument('--'+flag,action='store_true')
    args=parser.parse_args();need(os.geteuid()==0,'Run in WHM Terminal as root.');os.umask(0o077)
    a=pwd.getpwnam('sitesee');uid,gid=a.pw_uid,a.pw_gid
    for path in (ROOT,PUBLIC):
        safe(path);info=path.stat();need(stat.S_ISDIR(info.st_mode) and info.st_uid==uid and not info.st_mode&0o022,'Application directory permissions differ.')
    lock=os.open(str(ROOT),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if args.disable:
            read(ROOT/FLAG,uid,True)
            unlink_durable(ROOT/FLAG)
            print('Account access disabled. Webhook, order records and payment recovery retained.');return
        obj,files=load();values=desired(obj,files,ROOT,uid)
        if args.rollback:
            recover(ROOT,PUBLIC,uid,gid,values,True);print('Pre-activation rollback complete. No database or provider records changed.');return
        j=journal(ROOT,uid)
        if j and j['state']=='prepared':
            need(args.install,'Interrupted install: rerun --install to recover known files before proceeding.')
            recover(ROOT,PUBLIC,uid,gid,values)
        verify_dependencies(obj,ROOT,PUBLIC,uid)
        php='/opt/cpanel/ea-php82/root/usr/bin/php-cli'
        if not pathlib.Path(php).is_file():php='/opt/cpanel/ea-php82/root/usr/bin/php'
        lint(files,php)
        if args.install:print(install(obj,files,ROOT,PUBLIC,uid,gid))
        elif args.enable_test:
            activate(ROOT,PUBLIC,uid,gid,desired(obj,files,ROOT,uid));print('Portal enabled for TEST at https://re.sitesee.ai/account.php. Existing booking TEST flag still required.')
        else:
            if j and j['state']=='installed':
                for name,data in values.items():need(read(target(name,ROOT,PUBLIC),uid)==data,'Installed checksum differs: '+name)
            else:
                for name in files:
                    p=target(name,ROOT,PUBLIC);current=read(p,uid,True)
                    need((name=='private/server/booking-webhook.php' and current is not None and sha(current) in obj['webhook_before']) or (name!='private/server/booking-webhook.php' and current is None),'Unknown target preserved: '+name)
            print('Local preflight: PASS. No installed files, configuration, database or provider records changed.')
        print('Stripe remains TEST. No email, charge, calendar or CRM operation performed. Actual browser/provider checks remain required.')
    finally:os.close(lock)

if __name__=='__main__':
    try:main()
    except Exception as error:
        print('STOPPED: '+(str(error) if isinstance(error,Stop) else 'A local check could not finish ('+type(error).__name__+').'))
        print('Preserve backups and journals. Unknown files are never overwritten.');sys.exit(1)
