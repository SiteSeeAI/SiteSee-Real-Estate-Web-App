#!/usr/bin/env python3
"""Install onsite final billing and Production while preserving existing bookings. File-only update; no database or provider operations."""
import argparse, base64, fcntl, hashlib, json, os, pathlib, pwd, stat, subprocess, sys, tempfile, zlib
ROOT = pathlib.Path('/home/sitesee/.sitesee-real-estate')
PUBLIC = pathlib.Path('/home/sitesee/public_html/re')
REVISION = 'job-closeout-20261002-r1'
JOURNAL = 'job-closeout-20261002-r1-install.json'
PAYLOAD = '__PAYLOAD__'
PAYLOAD_SHA = '__PAYLOAD_SHA__'
UPDATE_STARTED = False
RESUMING = False
class Stop(RuntimeError): pass
def need(ok, message):
    if not ok: raise Stop(message)
def sha(data): return hashlib.sha256(data).hexdigest()
def digest(data): return None if data is None else sha(data)
def encode(value): return (json.dumps(value, sort_keys=True, indent=2)+'\n').encode()
def load():
    raw=zlib.decompress(base64.b64decode(PAYLOAD))
    need(sha(raw)==PAYLOAD_SHA,'Package integrity check failed.')
    return json.loads(raw)
def safe(path):
    for p in [path]+list(path.parents): need(not p.is_symlink(),'Symbolic link preserved: '+str(p))
def target(name,root,public):
    parts=name.split('/')
    need(len(parts)>1 and parts[0] in ('private','public') and all(p not in ('','.','..') for p in parts),'Invalid target path.')
    return (root if parts[0]=='private' else public).joinpath(*parts[1:])
def read(path,uid,optional=False):
    safe(path)
    if not path.exists():
        need(optional,'Required file missing: '+str(path)); return None
    s=path.stat()
    need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_uid==uid and not s.st_mode&0o022 and s.st_size<4000000,'Unsafe file preserved: '+str(path))
    return path.read_bytes()
def atomic(path,data,uid,gid,mode=0o600):
    safe(path); fd,tmp=tempfile.mkstemp(prefix='.job-closeout-',dir=str(path.parent))
    try:
        os.fchmod(fd,mode);os.fchown(fd,uid,gid)
        with os.fdopen(fd,'wb') as f: f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
        fd=os.open(str(path.parent),os.O_RDONLY|os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)
def backup_parent(root,uid,required=False):
    # Historical root:root 0700 backups are valid. Never change their ownership/mode.
    safe(root);s=root.stat()
    need(stat.S_ISDIR(s.st_mode) and s.st_uid==uid and not s.st_mode&0o077,'Private application root differs.')
    base=root/'deployment-backups';safe(base)
    if not base.exists():
        need(not required,'Existing update backup directory is missing.');return base
    s=base.stat()
    need(stat.S_ISDIR(s.st_mode) and s.st_uid in (0,uid) and not s.st_mode&0o022 and s.st_mode&0o700==0o700,'Backup parent requires a root- or sitesee-owned directory without group/other write access; historical backups are preserved.')
    return base
def targets(obj): return set(obj['files'])|{'private/'+n for n in obj['manifests']}
def derived_plan(obj,before):
    need(set(before)==targets(obj),'Deployment plan paths differ.')
    after={};errors=[]
    for n,item in obj['files'].items():
        old=None if item['before'] is None else base64.b64decode(item['before'])
        new=base64.b64decode(item['after'])
        accepted=[old,new]+([] if old is None else [old.replace(b'\r\n',b'\n'),old.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')])
        if before[n] not in accepted: errors.append('Unrecognized code preserved: '+n)
        after[n]=new
    for name in obj['manifests']:
        n='private/'+name
        try:
            record=json.loads(before[n]);need(isinstance(record,dict) and isinstance(record.get('files'),dict),'Release manifest differs: '+name)
            changed=False
            for key,h in record['files'].items():
                lookup=key if key.startswith(('public/','private/')) else 'private/'+key
                if lookup in obj['files']:
                    need(before[lookup] is not None and h==sha(before[lookup]),'Release hash mismatch: '+key+' in '+name)
                    record['files'][key]=sha(after[lookup]);changed=changed or h!=record['files'][key]
            after[n]=encode(record) if changed else before[n]
        except (ValueError,TypeError,AttributeError,Stop) as e:
            errors.append(str(e) if isinstance(e,Stop) else 'Invalid release manifest: '+name)
    need(not errors,'\n'.join(errors));return after
def inspect(root,public,uid,obj):
    before={};errors=[]
    for n in sorted(targets(obj)):
        try:before[n]=read(target(n,root,public),uid,n in obj['files'] and obj['files'][n]['before'] is None)
        except (Stop,OSError) as e:errors.append(str(e))
    need(not errors,'\n'.join(errors));return before,derived_plan(obj,before)
def preflight(root,public,uid,obj,j=None):
    errors=[];plan=None
    try:backup_parent(root,uid,required=j is not None)
    except (Stop,OSError) as e:errors.append(str(e))
    for p in sorted({public}|{target(n,root,public).parent for n in obj['files']}):
        try:
            safe(p);s=p.stat()
            need(stat.S_ISDIR(s.st_mode) and s.st_uid==uid and not s.st_mode&0o022 and s.st_mode&0o500==0o500,'Application directory requires review: '+str(p))
            if p==public/'portal-assets':need(s.st_mode&0o555==0o555,'Public assets directory must remain readable.')
        except (Stop,OSError) as e:errors.append(str(e))
    try:
        checkout=json.loads(read(root/'booking-checkout.json',uid))
        need(isinstance(checkout,dict) and checkout.get('stage')=='TEST','Stripe must remain TEST.')
    except (ValueError,Stop,OSError) as e:errors.append(str(e) if not isinstance(e,ValueError) else 'Invalid checkout configuration.')
    for n,accepted in obj['dependencies'].items():
        try:need(sha(read(target(n,root,public),uid)) in accepted,'Unreviewed closeout dependency preserved: '+n)
        except (Stop,OSError) as e:errors.append(str(e))
    for name in ['test-recipient-20261002-r1-install.json','email-change-20261002-r1-install.json','staff-review-20261002-r1-install.json','portal-polish-20261002-r1-install.json','calendar-notice-20261002-r1-install.json','re-draft-recovery-install.json','re-business-workflow-install.json','portal-complete-install.json']:
        try:
            raw=read(root/name,uid,True)
            if raw is not None:need(json.loads(raw).get('state')!='prepared','An earlier update is incomplete: '+name)
        except (ValueError,AttributeError,Stop,OSError) as e:errors.append(str(e) if isinstance(e,(Stop,OSError)) else 'Invalid prior update journal: '+name)
    try:plan=resume_plan(root,public,uid,obj,j) if j is not None else inspect(root,public,uid,obj)
    except (Stop,OSError) as e:errors.append(str(e))
    need(not errors,'\n'.join(dict.fromkeys(errors)));return plan
def metadata(path):
    if not path.exists():return None
    s=path.stat();return {'uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode)}
def prepare(root,public,uid,gid,obj,before,after):
    base=backup_parent(root,uid)
    if not base.exists():
        base.mkdir(mode=0o700)
        fd=os.open(str(root),os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(fd)
        finally:os.close(fd)
    backup_parent(root,uid,True)
    folder=pathlib.Path(tempfile.mkdtemp(prefix='job-closeout-',dir=str(base)));os.chown(folder,uid,gid);os.chmod(folder,0o700)
    entries={}
    for n,b in before.items():
        p=target(n,root,public)
        need(read(p,uid,True)==b,'Concurrent edit preserved before backup: '+n)
        meta=metadata(p)
        if b is not None:atomic(folder/(sha(n.encode())+'.bin'),b,uid,gid)
        entries[n]={'before':digest(b),'after':sha(after[n]),'metadata':meta or {'uid':uid,'gid':gid,'mode':0o644 if n.startswith('public/') else 0o600}}
    fd=os.open(str(base),os.O_RDONLY|os.O_DIRECTORY)
    try:os.fsync(fd)
    finally:os.close(fd)
    j={'revision':REVISION,'payload_sha':PAYLOAD_SHA,'state':'prepared','backup':folder.name,'entries':entries}
    need(read(root/JOURNAL,uid,True) is None,'Another update journal appeared; preserved.')
    atomic(root/JOURNAL,encode(j),uid,gid);return j
def resume_plan(root,public,uid,obj,j):
    need(isinstance(j,dict) and j.get('revision')==REVISION and j.get('payload_sha')==PAYLOAD_SHA and j.get('state') in ('prepared','installed'),'Different update journal preserved.')
    name=j.get('backup','');need(isinstance(name,str) and name.startswith('job-closeout-') and pathlib.Path(name).name==name,'Backup path differs.')
    folder=root/'deployment-backups'/name;safe(folder)
    need(set(j.get('entries',{}))==targets(obj),'Journal paths differ.')
    before={}
    for n,item in j['entries'].items():
        b=None if item['before'] is None else read(folder/(sha(n.encode())+'.bin'),uid)
        need(digest(b)==item['before'],'Backup checksum differs: '+n);before[n]=b
        meta=item['metadata']
        need(set(meta)=={'uid','gid','mode'} and meta['uid']==uid and isinstance(meta['gid'],int) and isinstance(meta['mode'],int) and 0< meta['mode'] <=0o777 and not meta['mode']&0o022,'Unsafe journal metadata preserved: '+n)
    after=derived_plan(obj,before)
    need(all(sha(b)==j['entries'][n]['after'] for n,b in after.items()),'Journal result differs from reviewed package.')
    for n in targets(obj):
        p=target(n,root,public);current=read(p,uid,True)
        need(current in ([after[n]] if j['state']=='installed' else [before[n],after[n]]),'Concurrent or unknown edit preserved: '+n)
        need(current is None or metadata(p)==j['entries'][n]['metadata'],'Concurrent permissions change preserved: '+n)
    return before,after
def apply(root,public,uid,gid,obj,j,before,after,writer=atomic):
    global UPDATE_STARTED
    if j['state']=='installed':return
    observed={n:read(target(n,root,public),uid,True) for n in after}
    for n,b in observed.items():
        need(b in [before[n],after[n]],'Concurrent edit preserved before update: '+n)
        need(b is None or metadata(target(n,root,public))==j['entries'][n]['metadata'],'Concurrent permissions change preserved: '+n)
    # Install payment and appointment guards before exposing Job Complete.
    order=list(obj['order'])+sorted(set(after)-set(obj['order']))
    UPDATE_STARTED=True
    for n in order:
        p=target(n,root,public);current=read(p,uid,True);meta=j['entries'][n]['metadata']
        need(current==observed[n],'Concurrent edit preserved during update: '+n)
        need(current is None or metadata(p)==meta,'Concurrent permissions change preserved: '+n)
        if current!=after[n]:writer(p,after[n],meta['uid'],meta['gid'],meta['mode'])
    for n,b in after.items():
        p=target(n,root,public)
        need(read(p,uid)==b and metadata(p)==j['entries'][n]['metadata'],'Installed checksum or permissions differ: '+n)
    j['state']='installed';atomic(root/JOURNAL,encode(j),uid,gid)
def lint(obj,php):
    with tempfile.TemporaryDirectory(prefix='job-closeout-lint-') as folder:
        for n,item in obj['files'].items():
            if not n.endswith('.php'):continue
            p=pathlib.Path(folder)/pathlib.Path(n).name;p.write_bytes(base64.b64decode(item['after']))
            r=subprocess.run([php,'-l',str(p)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
            need(r.returncode==0,'PHP syntax check failed: '+n)
def appointment_lock(root,uid,gid):
    p=root/'booking-confirmation.lock';safe(p)
    try:
        fd=os.open(str(p),os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);os.fchown(fd,uid,gid)
    except FileExistsError:fd=os.open(str(p),os.O_RDWR|os.O_NOFOLLOW)
    try:
        s=os.fstat(fd);need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_uid==uid and not s.st_mode&0o077,'Appointment lock permissions differ.')
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise Stop('An appointment operation is running. Rerun this same command when it finishes.')
        return fd
    except BaseException:os.close(fd);raise
def main():
    global RESUMING
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--deploy',action='store_true',required=True);parser.parse_args()
    need(os.geteuid()==0,'Run in WHM Terminal as root.');os.umask(0o077)
    account=pwd.getpwnam('sitesee');uid,gid=account.pw_uid,account.pw_gid
    backup_parent(ROOT,uid)
    fd=os.open(str(ROOT),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);booking_lock=None
    try:
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise Stop('Another deployment is running. Rerun this same command when it finishes.')
        booking_lock=appointment_lock(ROOT,uid,gid)
        print('INSTALLER REVISION: '+REVISION,flush=True);obj=load()
        raw=read(ROOT/JOURNAL,uid,True);j=json.loads(raw) if raw else None
        RESUMING=isinstance(j,dict) and j.get('state')=='prepared'
        before,after=preflight(ROOT,PUBLIC,uid,obj,j)
        php='/opt/cpanel/ea-php82/root/usr/bin/php-cli'
        if not pathlib.Path(php).is_file():php='/opt/cpanel/ea-php82/root/usr/bin/php'
        lint(obj,php)
        if j is None:j=prepare(ROOT,PUBLIC,uid,gid,obj,before,after)
        apply(ROOT,PUBLIC,uid,gid,obj,j,before,after)
        print('INSTALLED: Onsite Job Complete, final TEST collection and Production delivery.')
        print('Original bookings, deposits, phone login, calendar and CRM history are preserved.')
        print('No database changes, provider calls, messages or configuration changes made. Stripe remains TEST.')
        print('Backup: '+str(ROOT/'deployment-backups'/j['backup']))
        print('NEXT: Open a fresh completed TEST job in Staff Bookings, then review staff-production.php.')
    finally:
        if booking_lock is not None:os.close(booking_lock)
        os.close(fd)
if __name__=='__main__':
    try:main()
    except (KeyboardInterrupt,EOFError):print('Interrupted. Rerun this same --deploy command to resume.');sys.exit(1)
    except Exception as e:
        print('STOPPED: '+(str(e) if isinstance(e,Stop) else 'Local operation failed ('+type(e).__name__+').'))
        if UPDATE_STARTED or RESUMING:print('Update incomplete; unknown edits are preserved. Rerun this same command to resume.')
        else:print('Preflight stopped before application files were changed.')
        sys.exit(1)
