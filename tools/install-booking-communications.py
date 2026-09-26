#!/usr/bin/env python3
"""Checksum-guarded offline installer. Configurations, database and public files are never replaced."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile

ROOT=Path('/home/sitesee/.sitesee-real-estate')
PHP='/opt/cpanel/ea-php82/root/usr/bin/php'

def digest(path): return hashlib.sha256(path.read_bytes().replace(b'\r\n',b'\n')).hexdigest()

def checked_path(base,name):
    path=base/name
    if Path(name).is_absolute() or '..' in Path(name).parts or path.is_symlink() or os.path.commonpath([str(path.resolve()),str(base.resolve())]) != str(base.resolve()):
        raise RuntimeError('Unsafe release path.')
    return path

def preflight(package,root,manifest,php):
    for name,entry in manifest['files'].items():
        source=checked_path(package/'payload',name);target=checked_path(root,name)
        if not source.is_file() or digest(source)!=entry['sha256']: raise RuntimeError('Package checksum mismatch: '+name)
        if target.exists() and digest(target) not in [entry['sha256'],entry.get('previous_sha256')]: raise RuntimeError('Server file differs from the verified release: '+name)
        if not target.exists() and entry.get('previous_sha256'): raise RuntimeError('Required existing server file missing: '+name)
        if name.endswith('.php'):
            result=subprocess.run([php,'-l',str(source)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if result.returncode: raise RuntimeError('PHP syntax failed: '+name)
        if name.endswith('.py'): compile(source.read_text(),name,'exec')
    for name,expected in manifest['preserve'].items():
        target=checked_path(root,name)
        if not target.is_file() or digest(target)!=expected: raise RuntimeError('Calendar/pricing baseline differs: '+name)

def install(package,root,manifest,php,uid,gid):
    preflight(package,root,manifest,php)
    stamp=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup=root/'deployment-backups'/('booking-communications-'+stamp);backup.mkdir(parents=True,mode=0o700)
    # Dependencies first, invitation entry point and staff UI last; every file replacement is atomic.
    names=list(manifest['files'])
    names.sort(key=lambda n:(2 if n=='server/booking-staff.php' else 1 if n=='server/booking-invitation.php' else 0,n))
    installed=[]
    try:
        for name in names:
            target=checked_path(root,name);source=checked_path(package/'payload',name)
            if target.exists():
                old=backup/name;old.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(target,old)
            target.parent.mkdir(parents=True,exist_ok=True)
            fd,tmp=tempfile.mkstemp(prefix='.booking-release-',dir=target.parent)
            try:
                with os.fdopen(fd,'wb') as f: f.write(source.read_bytes());f.flush();os.fsync(f.fileno())
                os.chmod(tmp,0o600);os.chown(tmp,uid,gid);os.replace(tmp,target)
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
            installed.append(name)
        # Keep the prior calendar release checker usable for the unchanged calendar modules.
        release=root/'calendar-confirmation-release.json'
        if release.exists():
            shutil.copy2(release,backup/release.name)
            saved=json.loads(release.read_text())
            for name,entry in manifest['files'].items(): saved['files'][name]=entry['sha256']
            fd,tmp=tempfile.mkstemp(prefix='.calendar-manifest-',dir=root)
            try:
                with os.fdopen(fd,'w') as f:json.dump(saved,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
                os.chmod(tmp,0o600);os.chown(tmp,uid,gid);os.replace(tmp,release)
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
    except Exception:
        for name in reversed(installed):
            old=backup/name;target=root/name
            if old.exists():shutil.copy2(old,target);os.chown(target,uid,gid)
            else:target.unlink()
        raise
    return backup

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--install',action='store_true');args=parser.parse_args()
    if os.geteuid()!=0:raise RuntimeError('Run in WHM Terminal as root.')
    account=pwd.getpwnam('sitesee')
    if ROOT.is_symlink() or not ROOT.is_dir() or ROOT.stat().st_uid!=account.pw_uid or ROOT.stat().st_mode&0o022:raise RuntimeError('Private application directory is unsafe.')
    package=Path(__file__).resolve().parent
    manifest=json.loads((package/'manifest.json').read_text())
    os.umask(0o077)
    preflight(package,ROOT,manifest,PHP)
    print('Package, server baseline and PHP syntax: PASS')
    if not args.install:print('Check only. Use --install to install the reviewed package.');return
    backup=install(package,ROOT,manifest,PHP,account.pw_uid,account.pw_gid)
    print('Installed '+str(len(manifest['files']))+' private files. Backup: '+str(backup))
    print('Credentials, global bridge, PHP-FPM, DNS, routing, database, calendar events and payments were not changed.')
    print('Proceed to setup-booking-communications.py. No mail was sent.')

if __name__=='__main__':
    try:main()
    except Exception as error:
        import sys
        print('STOP: '+(str(error) if isinstance(error,RuntimeError) else 'Installation failed; existing files were preserved or restored.'),file=sys.stderr);sys.exit(1)
