#!/usr/bin/env python3
"""RE TEST saved draft repair update. No database or provider operations."""
import argparse, base64, fcntl, hashlib, json, os, pathlib, pwd, stat, subprocess, sys, tempfile, zlib
ROOT=pathlib.Path('/home/sitesee/.sitesee-real-estate')
REVISION='re-draft-recovery-20261001-r1'
COMPATIBLE_REVISIONS=(REVISION,)
UPDATE_STARTED=False
RESUMING=False
JOURNAL='re-draft-recovery-install.json'
PAYLOAD='__PAYLOAD__'
PAYLOAD_SHA='__PAYLOAD_SHA__'
CONFIGS={'booking-mail.json':'test_recipient_email','microsoft-scheduling.json':'test_recipient_email','booking-lifecycle.json':'recipient'}
FLAG='portal-test.json'
class Stop(RuntimeError): pass
def need(ok,message):
    if not ok: raise Stop(message)
def sha(b): return hashlib.sha256(b).hexdigest()
def encode(x): return (json.dumps(x,sort_keys=True,indent=2)+'\n').encode()
def load():
    b=zlib.decompress(base64.b64decode(PAYLOAD));need(sha(b)==PAYLOAD_SHA,'Package integrity check failed.');return json.loads(b)
def safe(p):
    for x in [p]+list(p.parents):need(not x.is_symlink(),'Symbolic link preserved: '+str(x))
def read(p,uid,optional=False):
    safe(p)
    if not p.exists():
        need(optional,'Required file missing: '+str(p));return None
    s=p.stat();need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_uid==uid and not s.st_mode&0o022 and s.st_size<4000000,'Unsafe file preserved: '+str(p))
    return p.read_bytes()
def atomic(p,b,uid,gid):
    safe(p);fd,tmp=tempfile.mkstemp(prefix='.re-draft-',dir=str(p.parent))
    try:
        os.fchmod(fd,0o600);os.fchown(fd,uid,gid)
        with os.fdopen(fd,'wb') as f:f.write(b);f.flush();os.fsync(f.fileno())
        os.replace(tmp,p)
        fd=os.open(str(p.parent),os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(fd)
        finally:os.close(fd)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def derived_plan(obj,before):
    """Re-derive every write from reviewed code or backed-up configuration."""
    targets=set(obj['files'])|set(obj['manifests'])|set(CONFIGS)|{FLAG}
    need(set(before)==targets,'Deployment plan paths differ.')
    after={};errors=[]
    for n,item in obj['files'].items():
        old=base64.b64decode(item['before']);new=base64.b64decode(item['after'])
        accepted={old,old.replace(b'\n',b'\r\n'),new}
        if before[n] not in accepted:errors.append('Unrecognized code preserved: '+n)
        after[n]=new
    for n,key in CONFIGS.items():
        try:
            c=json.loads(before[n]);need(isinstance(c,dict) and c.get('stage')=='test' and c.get('enabled') is True and c.get(key)=='sales@re.sitesee.ai','TEST configuration requires review: '+n)
            if n=='booking-lifecycle.json':need(set(c)=={'schema','stage','enabled','recipient'} and c['schema']==1,'Lifecycle configuration differs.')
            if n=='booking-mail.json':need(c.get('sender')=='sales@re.sitesee.ai' and c.get('graph_credentials')=='/home/sitesee/.sitesee-graph-mail.json','Dedicated RE mail configuration differs.')
            if n=='microsoft-scheduling.json':
                expected={'schema':1,'stage':'test','provider':'microsoft','enabled':True,'confirmation_stage':'test','confirmation_enabled':True,'invitations_enabled':True,'test_recipient_email':c.get(key),'calendar_uid':obj['calendar_uid']}
                need(c==expected,'Microsoft TEST scheduling configuration differs.')
            c[key]='sales@re.sitesee.ai';after[n]=encode(c)
        except (ValueError,Stop) as e:errors.append(str(e) if isinstance(e,Stop) else 'Invalid configuration: '+n)
    for n in obj['manifests']:
        try:
            record=json.loads(before[n]);need(isinstance(record,dict) and isinstance(record.get('files'),dict),'Release manifest differs: '+n)
            for key,digest in record['files'].items():
                if key in obj['files']:
                    need(digest==sha(before[key]),'Release hash mismatch: '+key+' in '+n)
                    record['files'][key]=sha(after[key])
            after[n]=encode(record)
        except (ValueError,Stop) as e:errors.append(str(e) if isinstance(e,Stop) else 'Invalid manifest: '+n)
    try:
        c=json.loads(before[FLAG]);need(c=={'release':'portal-20260929-r2','stage':'TEST','enabled':True},'The installed TEST portal must be enabled before this update.')
        after[FLAG]=before[FLAG]
    except (ValueError,Stop) as e:errors.append(str(e) if isinstance(e,Stop) else 'Invalid portal flag.')
    need(not errors,'\n'.join(errors));return after

def gate_bytes(before):
    gates={}
    for n in CONFIGS:
        c=json.loads(before[n]);c['enabled']=False;gates[n]=encode(c)
    c=json.loads(before[FLAG]);c['enabled']=False;gates[FLAG]=encode(c)
    return gates

def inspect(root,uid,obj):
    before={};errors=[]
    for n in sorted(set(obj['files'])|set(obj['manifests'])|set(CONFIGS)|{FLAG}):
        try:before[n]=read(root/n,uid)
        except (Stop,OSError) as e:errors.append(str(e))
    need(not errors,'\n'.join(errors));return before,derived_plan(obj,before)

def backup_parent(root,uid,required=False):
    # Historical installers create this shared parent as root:root 0700.
    # It lives beneath the private application root. Never chown/chmod it or
    # any historical backup to make a new installer fit its own assumptions.
    safe(root);r=root.stat()
    need(stat.S_ISDIR(r.st_mode) and r.st_uid==uid and not r.st_mode&0o077,'Private application root differs.')
    base=root/'deployment-backups';safe(base)
    if not base.exists():
        need(not required,'Existing update backup directory is missing.');return base
    info=base.stat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid in (0,uid) and not info.st_mode&0o022 and info.st_mode&0o700==0o700,
         'Backup parent requires a root- or sitesee-owned directory without group/other write access; existing backups are preserved.')
    return base

def preflight(root,uid,obj,j=None):
    errors=[];plan=None
    try:backup_parent(root,uid,required=j is not None)
    except (Stop,OSError) as e:errors.append(str(e))
    for p in sorted({(root/n).parent for n in obj['files']}):
        try:
            safe(p);s=p.stat()
            need(stat.S_ISDIR(s.st_mode) and s.st_uid==uid and not s.st_mode&0o022 and s.st_mode&0o700==0o700,'Application directory requires review: '+str(p))
        except (Stop,OSError) as e:errors.append(str(e))
    try:
        checkout=json.loads(read(root/'booking-checkout.json',uid))
        need(isinstance(checkout,dict) and checkout.get('stage')=='TEST','Stripe must remain TEST.')
    except (Stop,OSError,ValueError) as e:errors.append(str(e) if not isinstance(e,ValueError) else 'Invalid checkout configuration.')
    try:plan=resume_plan(root,uid,obj,j) if j else inspect(root,uid,obj)
    except (Stop,OSError) as e:errors.append(str(e))
    need(not errors,'\n'.join(dict.fromkeys(errors)));return plan

def prepare(root,uid,gid,obj,before,after):
    base=backup_parent(root,uid)
    if not base.exists():
        base.mkdir(mode=0o700)
        fd=os.open(str(root),os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(fd)
        finally:os.close(fd)
    backup_parent(root,uid,required=True)
    folder=pathlib.Path(tempfile.mkdtemp(prefix='re-draft-',dir=str(base)));os.chown(folder,uid,gid);os.chmod(folder,0o700)
    for n,b in before.items():
        need(read(root/n,uid)==b,'Concurrent edit preserved before backup: '+n)
        atomic(folder/(hashlib.sha256(n.encode()).hexdigest()+'.bin'),b,uid,gid)
    fd=os.open(str(base),os.O_RDONLY|os.O_DIRECTORY)
    try:os.fsync(fd)
    finally:os.close(fd)
    record={'revision':REVISION,'payload_sha':PAYLOAD_SHA,'state':'prepared','backup':folder.name,'entries':{n:{'before':sha(b),'after':sha(after[n])} for n,b in before.items()}}
    need(read(root/JOURNAL,uid,True) is None,'Another update journal appeared; preserved.')
    atomic(root/JOURNAL,encode(record),uid,gid);return record

def resume_plan(root,uid,obj,j):
    need(j.get('revision') in COMPATIBLE_REVISIONS and j.get('payload_sha')==PAYLOAD_SHA and j.get('state') in ('prepared','installed'),'Different update journal preserved.')
    name=j.get('backup','');need(isinstance(name,str) and name.startswith('re-draft-') and pathlib.Path(name).name==name,'Backup path differs.')
    folder=root/'deployment-backups'/name;safe(folder)
    targets=set(obj['files'])|set(obj['manifests'])|set(CONFIGS)|{FLAG}
    need(set(j.get('entries',{}))==targets,'Journal paths differ.')
    before={}
    for n in targets:
        b=read(folder/(hashlib.sha256(n.encode()).hexdigest()+'.bin'),uid)
        need(sha(b)==j['entries'][n]['before'],'Backup checksum differs: '+n);before[n]=b
    after=derived_plan(obj,before)
    need(all(sha(b)==j['entries'][n]['after'] for n,b in after.items()),'Journal result differs from reviewed package.')
    gates=gate_bytes(before)
    for n in targets:
        current=read(root/n,uid)
        allowed=[after[n]] if j['state']=='installed' else [before[n],after[n]]+([gates[n]] if n in gates else [])
        need(current in allowed,'Concurrent or unknown edit preserved: '+n)
    return before,after

def apply(root,uid,gid,obj,j,before,after,writer=atomic):
    global UPDATE_STARTED
    if j['state']=='installed':return
    # Disable portal, lifecycle, mail and calendar writes before changing code.
    gates=gate_bytes(before);observed={n:read(root/n,uid) for n in after}
    for n,b in observed.items():
        need(b in [before[n],after[n]]+([gates[n]] if n in gates else []),'Concurrent edit preserved before update: '+n)
    def write(n,b):
        need(read(root/n,uid)==observed[n],'Concurrent edit preserved during update: '+n)
        writer(root/n,b,uid,gid);observed[n]=b
    UPDATE_STARTED=True
    for n in [FLAG,'booking-lifecycle.json','booking-mail.json','microsoft-scheduling.json']:write(n,gates[n])
    for n in sorted(set(after)-set(gates)):write(n,after[n])
    # Verify all code/manifests while all provider gates remain disabled.
    for n in set(after)-set(gates):need(read(root/n,uid)==after[n],'Installed checksum differs: '+n)
    for n in ['microsoft-scheduling.json','booking-mail.json','booking-lifecycle.json',FLAG]:write(n,after[n])
    for n,b in after.items():need(read(root/n,uid)==b,'Final checksum differs: '+n)
    j['state']='installed';atomic(root/JOURNAL,encode(j),uid,gid)

def lint(obj,php):
    with tempfile.TemporaryDirectory(prefix='re-draft-lint-') as folder:
        for n,item in obj['files'].items():
            p=pathlib.Path(folder)/pathlib.Path(n).name;p.write_bytes(base64.b64decode(item['after']))
            r=subprocess.run([php,'-l',str(p)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
            need(r.returncode==0,'PHP syntax check failed: '+n)

def appointment_lock(root,uid,gid):
    p=root/'booking-confirmation.lock';safe(p)
    try:
        fd=os.open(str(p),os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        os.fchown(fd,uid,gid)
    except FileExistsError:fd=os.open(str(p),os.O_RDWR|os.O_NOFOLLOW)
    try:
        s=os.fstat(fd);need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_uid==uid and not s.st_mode&0o077,'Appointment lock permissions differ.')
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise Stop('An appointment operation is running. Rerun this same deployment command when it finishes.')
        return fd
    except BaseException:os.close(fd);raise

def main():
    global RESUMING
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--deploy',action='store_true',required=True);parser.parse_args()
    need(os.geteuid()==0,'Run in WHM Terminal as root.');os.umask(0o077)
    account=pwd.getpwnam('sitesee');uid,gid=account.pw_uid,account.pw_gid
    safe(ROOT);s=ROOT.stat();need(stat.S_ISDIR(s.st_mode) and s.st_uid==uid and not s.st_mode&0o077,'Private application root differs.')
    fd=os.open(str(ROOT),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);booking_lock=None
    try:
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        booking_lock=appointment_lock(ROOT,uid,gid)
        print('INSTALLER REVISION: '+REVISION,flush=True);obj=load()
        raw=read(ROOT/JOURNAL,uid,True);j=json.loads(raw) if raw else None
        RESUMING=isinstance(j,dict) and j.get('state')=='prepared'
        before,after=preflight(ROOT,uid,obj,j)
        php='/opt/cpanel/ea-php82/root/usr/bin/php-cli'
        if not pathlib.Path(php).is_file():php='/opt/cpanel/ea-php82/root/usr/bin/php'
        lint(obj,php)
        if j is None:j=prepare(ROOT,uid,gid,obj,before,after)
        apply(ROOT,uid,gid,obj,j,before,after)
        print('INSTALLED: RE invitation draft repair and guarded staff continuation.')
        print('Existing orders, deposits, phone login and credentials retained. No database changes or provider calls made.')
        print('Backup: '+str(ROOT/'deployment-backups'/j['backup']))
        print('NEXT: Refresh the existing booking in Staff Bookings. Verify sales@re.sitesee.ai and use Repair & Send Saved Invitation.')
        print('The installer sends nothing. The staff action verifies and submits the same saved unsent draft, then checks sent-copy, recipient and CRM evidence.')
    finally:
        if booking_lock is not None:os.close(booking_lock)
        os.close(fd)
if __name__=='__main__':
    try:main()
    except (KeyboardInterrupt,EOFError):print('Interrupted. Rerun this same --deploy command to resume.');sys.exit(1)
    except Exception as e:
        print('STOPPED: '+(str(e) if isinstance(e,Stop) else 'Local operation failed ('+type(e).__name__+').'))
        if UPDATE_STARTED or RESUMING:print('Update incomplete; TEST gates may remain disabled. Unknown edits are preserved. Rerun this same command to resume.')
        else:print('Preflight stopped before application files or TEST gates were changed. All detected blockers are listed above.')
        sys.exit(1)
