import getpass, glob, shutil, time

REPORT = 'portal-complete-report.json'
METADATA = 'portal-complete-metadata.json'

def file_snapshot(path):
    safe(path)
    if not path.exists(): return None
    s=path.stat()
    need(stat.S_ISREG(s.st_mode) and s.st_nlink==1, 'Not a single regular file: '+str(path))
    need(s.st_size<=4000000, 'Unexpected file size: '+str(path))
    b=path.read_bytes()
    return {'sha256':sha(b),'uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode),'bytes':b}

def scan(obj,files,root,public,uid,gid):
    """Collect every independent issue before making any application change."""
    errors=[]; repairs={}; restores={}; checked={}; records={}
    def issue(message):
        if message not in errors: errors.append(message)
    def directory(path,mode,repair=True):
        try:
            safe(path)
            if not path.exists(): return
            s=path.stat()
            need(stat.S_ISDIR(s.st_mode),'Expected directory: '+str(path))
            need(s.st_uid in (0,uid),'Unexpected directory owner: '+str(path))
            if s.st_uid!=uid or s.st_gid!=gid or stat.S_IMODE(s.st_mode)!=mode:
                need(repair,'Directory requires review: '+str(path))
                repairs[str(path)]={'kind':'directory','uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode),'after_mode':mode}
        except (Stop,OSError) as e: issue(str(e))
    for path,mode in [(root,0o700),(root/'server',0o700),(root/'views',0o700),(root/'tools',0o700),(root/'pricing-assets',0o700),(root/'payment-assets',0o700),(root/'data',0o700),(public/'portal-assets',0o755)]: directory(path,mode)
    try:
        safe(public);s=public.stat()
        need(stat.S_ISDIR(s.st_mode) and s.st_uid==uid and not s.st_mode&0o022 and s.st_mode&0o555==0o555,'Website root ownership/permissions require review: '+str(public))
    except (Stop,OSError) as e: issue(str(e))
    def inspect(name,accepted=None,expected_blob=None,optional=False,restore=None,private=False):
        path=target(name,root,public)
        try:
            snap=file_snapshot(path)
            if snap is None:
                if restore is not None:
                    restores[name]=restore;return restore
                need(optional,'Missing: '+str(path));return None
            b=snap['bytes'];h=snap['sha256'];checked[name]=h
            if accepted is not None: need(h in accepted,'Content requires review (preserved): '+name)
            if expected_blob is not None: need(hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()==expected_blob,'Brand font differs: '+name)
            need(snap['uid'] in (0,uid),'Unexpected owner (preserved): '+name)
            mode=0o644 if name.startswith('public/') else 0o600
            if snap['uid']!=uid or snap['gid']!=gid or snap['mode']!=mode:
                trusted=accepted is not None or expected_blob is not None or private
                need(trusted,'Metadata of unrecognized file requires review: '+name)
                repairs[str(path)]={k:v for k,v in snap.items() if k!='bytes'}
                repairs[str(path)].update(kind='file',after_mode=mode)
            return b
        except (Stop,OSError) as e: issue(str(e));return None
    # All installed release records are read before planning restoration.
    for name in obj['manifests']:
        raw=inspect('private/'+name,private=True)
        if raw is None:continue
        try:
            record=json.loads(raw);need(isinstance(record,dict) and isinstance(record.get('files'),dict),'Invalid release record: '+name)
            if name=='appointment-management-release.json':need(record.get('revision')=='20260928-r4','Verified r4 appointment release required.')
            records[name]=record
        except (ValueError,Stop) as e: issue(str(e))
    expected={}
    for name,record in records.items():
        for key,digest in record['files'].items():
            try:
                need(isinstance(key,str) and key and not key.startswith('/') and all(p not in ('.','..') for p in key.split('/')),'Invalid release path in '+name)
                need(isinstance(digest,str) and re.fullmatch('[a-f0-9]{64}',digest),'Invalid release hash in '+name)
                k=key if key.startswith('public/') else 'private/'+key
                expected.setdefault(k,set()).add(digest)
            except Stop as e:issue(str(e))
    # Bundle retained dependencies for missing-file recovery only. Never replace changed code.
    for name,item in obj['retained'].items():
        b=base64.b64decode(item['content']);choices=[b,b.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')]
        allowed=expected.get(name,set());replacement=next((x for x in choices if not allowed or all(h==sha(x) for h in allowed)),None)
        inspect(name,accepted=item['accepted'],restore=replacement)
    for name,digests in expected.items():
        if name in restores:
            b=restores[name]
        else:
            allowed=obj['known'].get(name)
            if name=='private/server/booking-webhook.php':allowed=list(set((allowed or [])+obj['webhook_before']+[sha(files[name])]))
            b=inspect(name,accepted=allowed)
        if b is not None:
            for h in digests:
                acceptable={sha(b)}
                if name=='private/server/booking-webhook.php':acceptable.update(obj['webhook_before']);acceptable.add(sha(files[name]))
                if h not in acceptable:issue('Release hash mismatch: '+name)
    for name,blob in obj['fonts'].items():inspect('public/'+name,expected_blob=blob)
    for name in ('booking-checkout.json','portal-sms.json'):
        raw=inspect('private/'+name,private=True,optional=name=='portal-sms.json')
        if raw is None:continue
        try:
            c=json.loads(raw)
            if name=='booking-checkout.json':need(isinstance(c,dict) and c.get('stage')=='TEST' and isinstance(c.get('enabled'),bool) and re.fullmatch(r'pk_test_[A-Za-z0-9]{12,512}',str(c.get('publishable_key',''))),'Existing checkout must remain TEST.')
            else:validate_sms(c)
        except (ValueError,Stop) as e:issue(str(e))
    # Previous email releases must not be silently activated or overwritten.
    old=inspect('private/portal-install.json',private=True,optional=True)
    if old is not None:
        try:need(json.loads(old).get('state')=='restored','An earlier portal installation journal is present; preserve it for migration review.')
        except (ValueError,Stop) as e:issue(str(e))
    raw=inspect('private/'+JOURNAL,private=True,optional=True)
    j=None
    if raw is not None:
        try:
            j=json.loads(raw);need(j.get('release')==RELEASE and j.get('state') in ('prepared','installed','restored'),'Different consolidated journal preserved.')
            if j['state']!='restored':
                for n in j['entries']:
                    if n in obj['retained'] and n not in files:
                        item=obj['retained'][n];b=base64.b64decode(item['content'])
                        variant=next((v for v in [b,b.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')] if sha(v)==j['entries'][n]['after']),None)
                        need(variant is not None,'Restored dependency journal differs: '+n);restores[n]=variant
        except (ValueError,KeyError,TypeError,Stop) as e:issue('Consolidated journal: '+str(e))
    for name,data in files.items():
        accepted=[sha(data)]
        if name=='private/server/booking-webhook.php':accepted+=obj['webhook_before']
        b=inspect(name,accepted=accepted,optional=name!='private/server/booking-webhook.php')
        if b is not None and name!='private/server/booking-webhook.php' and (not j or j.get('state')=='restored'):
            issue('Existing portal file without this release journal: '+name)
    obj['restore_files']=restores
    return {'release':RELEASE,'errors':errors,'metadata_repairs':repairs,'missing_files':list(restores),'checked_files':checked,'application_file_count':len(files)}

def validate_sms(c):
    need(isinstance(c,dict) and c.get('provider')=='twilio-verify' and c.get('stage')=='TEST' and c.get('enabled') is True,'SMS configuration requires review.')
    for key,pattern in [('account_sid',r'AC[0-9a-fA-F]{32}'),('service_sid',r'VA[0-9a-fA-F]{32}'),('auth_token',r'[0-9a-fA-F]{32}')]:need(re.fullmatch(pattern,str(c.get(key,''))) is not None,'SMS '+key+' is invalid.')
    numbers=c.get('allowed_numbers');need(isinstance(numbers,list) and 1<=len(numbers)<=10 and all(isinstance(n,str) and re.fullmatch(r'\+[1-9][0-9]{7,14}',n) for n in numbers),'SMS TEST allowlist requires 1–10 international-format numbers.')

def repair_metadata(report,root,uid,gid):
    """Journal and revalidate complete content before narrow metadata-only repairs."""
    path=root/METADATA
    prior=read(path,uid,True)
    history=json.loads(prior) if prior else {'release':RELEASE,'entries':{}}
    need(history.get('release')==RELEASE and isinstance(history.get('entries'),dict),'Metadata journal differs.')
    changes=report['metadata_repairs']
    for name,item in changes.items():
        p=pathlib.Path(name);safe(p);s=p.stat()
        need((s.st_uid,s.st_gid,stat.S_IMODE(s.st_mode))==(item['uid'],item['gid'],item['mode']),'Metadata changed concurrently: '+name)
        if item['kind']=='file':need(file_snapshot(p)['sha256']==item['sha256'],'Content changed concurrently: '+name)
        history['entries'].setdefault(name,item)
    # Root may need a known owner correction before the journal is readable by the app.
    atomic(path,encode(history),uid,gid)
    for name,item in sorted(changes.items(),key=lambda x:len(pathlib.Path(x[0]).parts)):
        p=pathlib.Path(name);safe(p);s=p.stat()
        need((s.st_uid,s.st_gid,stat.S_IMODE(s.st_mode))==(item['uid'],item['gid'],item['mode']),'Metadata changed during repair: '+name)
        if item['kind']=='file':need(file_snapshot(p)['sha256']==item['sha256'],'Content changed during repair: '+name)
        os.chown(str(p),uid,gid);os.chmod(str(p),item['after_mode'])
    for name,item in changes.items():
        p=pathlib.Path(name);s=p.stat();need((s.st_uid,s.st_gid,stat.S_IMODE(s.st_mode))==(uid,gid,item['after_mode']),'Metadata repair did not persist: '+name)
        if item['kind']=='file':need(sha(p.read_bytes())==item['sha256'],'Metadata repair changed contents: '+name)

def runtime_settings(root,uid):
    """Inspect only this site's pool. Never log values or alter FPM settings."""
    candidates=glob.glob('/opt/cpanel/ea-php82/root/etc/php-fpm.d/*re.sitesee.ai*.conf')
    need(len(candidates)==1,'Expected one PHP-FPM pool for re.sitesee.ai; active settings need review.')
    p=pathlib.Path(candidates[0]);safe(p)
    content=p.read_text();values={};issues=[]
    def check(ok,message):
        if not ok:issues.append(message)
    for key,value in re.findall(r'^\s*env\[([A-Z0-9_]+)\]\s*=\s*(.*?)\s*$',content,re.M):values[key]=value.strip('"\'')
    check(values.get('SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED')=='1','Existing PHP-FPM booking TEST flag is not enabled.')
    check(values.get('SITESEE_REAL_ESTATE_SITE_URL')=='https://re.sitesee.ai','Existing PHP-FPM site URL requires review.')
    check(len(values.get('SITESEE_REAL_ESTATE_PRICING_GATE_SECRET',''))>=32,'Existing PHP-FPM pricing gate secret is unavailable.')
    check(values.get('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET','').startswith('sk_test_'),'Existing PHP-FPM Stripe TEST secret is unavailable.')
    db=pathlib.Path(values.get('SITESEE_REAL_ESTATE_BOOKING_DB',str(root/'data/bookings.sqlite')))
    safe(db);need(db.is_file() and db.resolve()==db and db.parent==root/'data','Existing booking ledger path requires review.')
    info=db.stat()
    check(stat.S_ISREG(info.st_mode) and info.st_nlink==1 and info.st_uid==uid and info.st_mode&0o077==0,'Booking ledger ownership or permissions require review; data is preserved.')
    need(not issues,'; '.join(issues))
    return db

def setup_phone(root,uid,gid,php,db):
    config=root/'portal-sms.json';raw=read(config,uid,True)
    if raw is None:
        if not sys.stdin.isatty():return ['SMS credentials and verified test cell number have not been configured.']
        print('Phone sign-in uses Twilio Verify. No text is sent by this installer.')
        account=getpass.getpass('Twilio Account SID (Enter to finish installation disabled): ').strip()
        if not account:return ['SMS credentials and verified test cell number have not been configured.']
        service=getpass.getpass('Twilio Verify Service SID: ').strip();token=getpass.getpass('Twilio Auth Token (hidden): ').strip()
        phone=getpass.getpass('Approved test cell number, e.g. +13125550100 (hidden): ').strip()
        c={'provider':'twilio-verify','stage':'TEST','enabled':True,'account_sid':account,'service_sid':service,'auth_token':token,'allowed_numbers':[phone]};validate_sms(c)
        need(not config.exists() and not config.is_symlink(),'SMS configuration appeared concurrently; preserved.')
        atomic(config,encode(c),uid,gid)
    else:c=json.loads(raw);validate_sms(c)
    # Read-only identity check does not initialize or alter the booking ledger.
    import sqlite3
    connection=sqlite3.connect(db.as_uri()+'?mode=ro',uri=True)
    try:
        tables={r[0] for r in connection.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        enrolled=[]
        if {'portal_accounts','portal_phone_identities'}<=tables:
            enrolled=[r[0] for r in connection.execute('SELECT p.phone FROM portal_phone_identities p JOIN portal_accounts a ON a.id=p.account_id WHERE a.disabled=0') if r[0] in c['allowed_numbers']]
    finally:connection.close()
    if enrolled:return []
    if not sys.stdin.isatty():return ['Staff verification and phone enrollment are required.']
    print('Link one approved test customer to their cell number. Email identifies the existing account; customers sign in only by cell number.')
    email=getpass.getpass('Approved account email (Enter to leave disabled): ').strip()
    if not email:return ['Staff verification and phone enrollment are required.']
    phone=getpass.getpass('Verified login cell number (international format): ').strip()
    need(phone in c['allowed_numbers'],'Enrollment number must match the saved SMS TEST allowlist.')
    confirmed=input('Have you verified that this customer owns this cell number? Type YES: ').strip()
    if confirmed!='YES':return ['Staff phone ownership verification remains required.']
    evidence=input('Brief staff verification evidence (12–500 characters): ').strip()
    request={'email':email,'phone':phone,'evidence':evidence,'ownership_verified':True,'database_path_verified':True,'database_path':str(db)}
    runuser=shutil.which('runuser');need(runuser is not None,'runuser is required for owner-scoped phone enrollment.')
    result=subprocess.run([runuser,'-u','sitesee','--',php,str(root/'tools/portal-enroll-phone.php')],input=json.dumps(request).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
    need(result.returncode==0,'Phone enrollment requires review; credentials and installed files are retained. No SMS sent.')
    return []

def show(report):
    print('COMPLETE LOCAL INSPECTION: '+str(len(report['checked_files']))+' files; '+str(report['application_file_count'])+' packaged application files.')
    print('Verified metadata repairs: '+str(len(report['metadata_repairs']))+'. Missing known files to restore: '+str(len(report['missing_files'])))
    for message in report['errors']:print('BLOCKER: '+message)

def main():
    parser=argparse.ArgumentParser(description='Consolidated SiteSee phone-login TEST deployment. No provider calls.');group=parser.add_mutually_exclusive_group(required=True)
    for flag in ('deploy','check','disable','rollback'):group.add_argument('--'+flag,action='store_true')
    args=parser.parse_args();need(os.geteuid()==0,'Run in WHM Terminal as root.');os.umask(0o077)
    a=pwd.getpwnam('sitesee');uid,gid=a.pw_uid,a.pw_gid
    safe(ROOT);need(ROOT.is_dir(),'Existing private application root is missing.')
    lock=os.open(str(ROOT),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if args.disable:
            read(ROOT/FLAG,uid,True);unlink_durable(ROOT/FLAG);print('Portal access disabled. Files, payment recovery and data retained.');return
        obj,files=load();report=scan(obj,files,ROOT,PUBLIC,uid,gid)
        php='/opt/cpanel/ea-php82/root/usr/bin/php-cli'
        if not pathlib.Path(php).is_file():php='/opt/cpanel/ea-php82/root/usr/bin/php'
        try:lint(dict(files,**obj.get('restore_files',{})),php)
        except (Stop,OSError,subprocess.TimeoutExpired) as e:report['errors'].append('PHP preflight: '+str(e))
        # Runtime issues are reported together, but do not block installation of a disabled portal.
        runtime=[];db=None
        try:db=runtime_settings(ROOT,uid)
        except (Stop,OSError) as e:runtime.append(str(e))
        report['runtime_setup_required']=runtime
        show(report)
        for message in runtime:print('ACTIVATION REQUIREMENT: '+message)
        if args.check:
            need(not report['errors'],'All detected blockers are listed above. No changes made.')
            print('Inspection complete. No changes made. Run --deploy to apply the verified plan.');return
        need(not report['errors'],'All detected blockers are listed above. Unknown files and application state are preserved.')
        repair_metadata(report,ROOT,uid,gid)
        recheck=scan(obj,files,ROOT,PUBLIC,uid,gid)
        need(not recheck['errors'],'Files changed after inspection: '+'; '.join(recheck['errors']))
        values=desired(obj,files,ROOT,PUBLIC,uid)
        if args.rollback:
            recover(ROOT,PUBLIC,uid,gid,values,True);print('Application files restored. Verified metadata tightening retained; original metadata is recorded in '+str(ROOT/METADATA)+'. No database restored.');return
        print(install(obj,files,ROOT,PUBLIC,uid,gid))
        report['installed']=True
        if db is not None:
            try:runtime.extend(setup_phone(ROOT,uid,gid,php,db))
            except (Stop,OSError,ValueError,subprocess.TimeoutExpired) as e:runtime.append(str(e))
        if not runtime:
            activate(ROOT,PUBLIC,uid,gid,desired(obj,files,ROOT,PUBLIC,uid));report['enabled']=True
            print('PHONE-LOGIN PORTAL ENABLED FOR TEST: https://re.sitesee.ai/account.php')
        else:
            # Never leave this candidate active when required setup is missing.
            read(ROOT/FLAG,uid,True);unlink_durable(ROOT/FLAG);report['enabled']=False
            print('PHONE-LOGIN PACKAGE INSTALLED; ACCESS DISABLED PENDING SETUP:')
            for message in runtime:print('- '+message)
        report['runtime_setup_required']=runtime;atomic(ROOT/REPORT,encode(report),uid,gid)
        print('Report: '+str(ROOT/REPORT))
        print('Stripe remains TEST. No SMS, email, payment, calendar or CRM operation was sent. Browser SMS/provider verification remains required.')
    finally:os.close(lock)

if __name__=='__main__':
    try:main()
    except (KeyboardInterrupt,EOFError):print('Stopped. Saved installation and private setup are retained; rerun this same --deploy command.');sys.exit(1)
    except Exception as error:
        print('STOPPED: '+(str(error) if isinstance(error,Stop) else 'Local operation could not finish ('+type(error).__name__+').'))
        print('Preserve backups and journals. No unknown file is overwritten.');sys.exit(1)
