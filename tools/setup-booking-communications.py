#!/usr/bin/env python3
"""Private CRM authorization; read-only provider checks, no mail, no calendar edits."""
import datetime as dt
import getpass
import json
import os
from pathlib import Path
import pwd
import re
import tempfile
import urllib.request
import urllib.error
import urllib.parse
import warnings

ROOT=Path('/home/sitesee/.sitesee-real-estate')
SCOPES='ZohoCRM.org.READ,ZohoCRM.users.READ,ZohoCRM.modules.contacts.ALL,ZohoCRM.modules.emails.ALL,ZohoSearch.securesearch.READ'

class SetupError(Exception): pass
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): raise SetupError('Provider redirect blocked.')

def secret(prompt):
    with warnings.catch_warnings():
        warnings.simplefilter('error',getpass.GetPassWarning)
        value=getpass.getpass(prompt).strip()
    if not value: raise SetupError('Required private value missing.')
    return value

def request(url,form=None,token=None):
    headers={'Accept':'application/json'}
    if token: headers['Authorization']='Zoho-oauthtoken '+token
    data=None if form is None else urllib.parse.urlencode(form).encode()
    try:
        with urllib.request.build_opener(NoRedirect).open(urllib.request.Request(url,data=data,headers=headers),timeout=25) as reply:
            raw=reply.read(2097153)
            if len(raw)>2097152: raise SetupError('Provider response too large.')
            return json.loads(raw)
    except urllib.error.HTTPError as e: raise SetupError('Provider request failed (HTTP %d); secrets were not printed.'%e.code) from None
    except (OSError,ValueError): raise SetupError('Provider response unavailable or invalid.') from None

def load_private(path,uid):
    if path.is_symlink() or path.stat().st_uid!=uid or path.stat().st_mode & 0o077: raise SetupError('Unsafe private file ownership or permissions: '+path.name)
    return json.loads(path.read_text())

def save_new(path,config,account):
    if path.exists(): raise SetupError(path.name+' already exists; it will not be overwritten.')
    fd,name=tempfile.mkstemp(prefix='.booking-setup-',dir=ROOT)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(config,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(name,0o600);os.chown(name,account.pw_uid,account.pw_gid)
        os.link(name,path)  # atomic no-clobber
    finally: os.unlink(name)

def main():
    if os.geteuid()!=0: raise SetupError('Run setup in WHM Terminal as root.')
    account=pwd.getpwnam('sitesee')
    if ROOT.is_symlink() or not ROOT.is_dir() or ROOT.stat().st_uid!=account.pw_uid or ROOT.stat().st_mode & 0o022:
        raise SetupError('Private application directory is unsafe or unavailable.')
    os.umask(0o077)
    graph=load_private(Path('/home/sitesee/.sitesee-graph-mail.json'),account.pw_uid)
    for k in ['tenant_id','client_id','client_secret']:
        if not graph.get(k): raise SetupError('Existing Graph credential missing; global bridge left unchanged.')
    print('Uses existing Graph application; its credentials and global sender stay unchanged.')
    crm_path=ROOT/'zoho-crm.json'
    pending_path=ROOT/'zoho-crm-pending.json'
    if crm_path.exists():
        cfg=load_private(crm_path,account.pw_uid)
        print('Existing CRM configuration found; no credential replacement.')
    else:
        if pending_path.exists():
            cfg=load_private(pending_path,account.pw_uid)
            print('Resuming saved CRM authorization.')
        else:
            print('Use a separate US-region Zoho API Self Client, authorized by the intended CRM administrator.')
            print('Do not edit or revoke the Calendar client. Enter secrets here only.')
            client=secret('CRM Self Client ID (hidden): ');client_secret=secret('CRM Self Client secret (hidden): ')
            print('Generate Code scope:\n'+SCOPES+'\nDuration: 10 minutes. Select the intended CRM organization.')
            grant=secret('CRM grant code (hidden): ')
            token=request('https://accounts.zoho.com/oauth/v2/token',form={'grant_type':'authorization_code','client_id':client,'client_secret':client_secret,'code':grant})
            if not token.get('refresh_token') or token.get('api_domain')!='https://www.zohoapis.com': raise SetupError('US-region CRM refresh authorization not returned.')
            cfg={'client_id':client,'client_secret':client_secret,'refresh_token':token['refresh_token'],
                 'api_domain':'https://www.zohoapis.com','accounts_domain':'https://accounts.zoho.com','requested_scopes':SCOPES.split(',')}
            save_new(pending_path,cfg,account)
        token=request('https://accounts.zoho.com/oauth/v2/token',form={'grant_type':'refresh_token',**{k:cfg[k] for k in ['client_id','client_secret','refresh_token']}})
        access=token.get('access_token')
        if not access or token.get('api_domain',cfg['api_domain'])!=cfg['api_domain']: raise SetupError('CRM token validation failed.')
        org=request(cfg['api_domain']+'/crm/v8/org',token=access).get('org',[])
        users=request(cfg['api_domain']+'/crm/v8/users?type=CurrentUser',token=access).get('users',[])
        if len(org)!=1 or len(users)!=1: raise SetupError('Exactly one CRM organization and current user are required.')
        o,u=org[0],users[0]
        print('CRM organization:',o.get('company_name'),'| API ID:',o.get('id'),'| environment:',o.get('type'))
        print('Authorized email-history owner:',u.get('full_name'),'|',u.get('email'),'| ID:',u.get('id'))
        if input('Type the displayed organization API ID to confirm the intended organization: ').strip()!=o.get('id'): raise SetupError('Organization not confirmed.')
        if input('Type the displayed user ID to confirm the intended email-history owner: ').strip()!=u.get('id'): raise SetupError('Email owner not confirmed.')
        print('Inspect CRM Setup > Channels > Email and any organization mailbox synchronization.')
        print('If sales@re.sitesee.ai is already synchronized, choose native. If verified NOT synchronized, choose api.')
        mode=input('Verified synchronization mode (native/api; Enter stops): ').strip()
        if mode not in ['native','api']: raise SetupError('Synchronization must be inspected before history writes.')
        print('Also inspect synchronization for the ORIGINAL sender cro@sitesee.ai, whose delivered invitation will be imported.')
        original_mode=input('Original mailbox verified mode (native/api; Enter stops): ').strip()
        if original_mode not in ['native','api']: raise SetupError('Original sender synchronization must also be inspected.')
        now=dt.datetime.now(dt.timezone.utc).isoformat()
        cfg.update(org_id=o['id'],user_id=u['id'],sync_mode=mode,original_sync_mode=original_mode,sync_reviewed_at=now,owner_verified_at=now)
        save_new(crm_path,cfg,account)
        pending_path.unlink()
    if not (ROOT/'booking-mail.json').exists():
        save_new(ROOT/'booking-mail.json',{'stage':'test','enabled':False,'sender':'sales@re.sitesee.ai',
            'test_recipient_email':'cro@sitesee.ai','graph_credentials':'/home/sitesee/.sitesee-graph-mail.json'},account)
    print('CRM authorization saved. Dedicated invitations remain subject to their existing activation state.')
    print('Next: lookup and link the correct contact, import the existing message, then perform the one-time probe.')
    print('No message, CRM email, contact, calendar event or payment was created by setup.')

if __name__=='__main__':
    try: main()
    except (SetupError,EOFError,KeyboardInterrupt,getpass.GetPassWarning,OSError,ValueError) as error:
        import sys
        print('STOP: '+(str(error) if isinstance(error,SetupError) else 'Setup incomplete; inspect the last displayed step.')+' Do not paste secrets into chat.',file=sys.stderr)
        sys.exit(1)
