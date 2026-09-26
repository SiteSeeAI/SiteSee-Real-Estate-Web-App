#!/usr/bin/env python3
"""Install the CRM history correction and recover already-delivered messages only."""
import argparse
import base64
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import sys
import tempfile
import zlib

ROOT = Path('/home/sitesee/.sitesee-real-estate')
RUNNER = Path('/home/sitesee/finish-booking-communications.py')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
REFERENCE = 'E6E183EF8E'
KEYS = ('original:' + REFERENCE, 'probe:' + REFERENCE)
CRM_OLD = '42eb3369d40e1fc37712413cde685f76cdf72dac5329b199ac22c6ef42877c39'
RUNNER_OLD = 'bd9de9f30ea41a11bc52bb7c611374cd5bce4a35caa95fb2a1e8052c4bb37f46'
CLI_HASH = 'bf8173d38bfa7f980cf8a6d7e8e09ee07f0efabbdc3a466c87f08c2e1877ad5b'
CLIENT_HASH = '189e15f44c3c28856765527efdcf25b71f748add07d6e7d31c396d50d3ed5e5b'
# Compressed source payloads; no credentials or account configuration are embedded.
PAYLOADS = {'crm': {'sha256': 'acf250ec05945007b7940b09cd57280b9a8baea6ecc7bec81962345f9421bb97', 'data': 'eNq9Wvtz28YR/l1/xWmGExAORFFOJ5NagVRFYmy2epWSkmk4LAYCjuTZeDAHQBLj6H/v7j2AAwhSlN3Wmdgk7rW3++3utwv+eLyYL3ZCGkQ+p90s5yzIvXy5oJl7YB/ucPp7wTj10iSgxPPOhiPPIz1i7d+n6SeWzPaCNI6LhAV+ztKkB3tZhzs70yIJ8DtRs7yAx94D5Wy69FI+6/qc+0vSCdJkymYOCfwo8u8jCk94bL8jDykLdz7vEPjT4cQVj7vW+8Gt5Vj7sN4CwXCQTUm3w8dWlvt5kVkTsuu65G2/T/78kwRpkeRi9D4Nl9ZkbOHCCTk+JuOJLWYeiE3wD8wXV09m9sqKcR8+sVDtrmQWYx4+tUk+5+kjSegjGcGRLKaDp4Au8Ppd63R0QWCmn7A/hIIICynMyZckmPvJjIY9fZdO0bxokVGeHaMp3NOCc1h3B09qdy823r2obiL22ub2q2vW3B9Ht1SAX+TzlLM/aEhwVe3qzzs7+2/ekMGTH+RkwVns8yWhsc8iuEW88DnL0sQh9GnuF1nOHihZ+DOWSGX6SQgji4gFLCegiOmUZDSiAno9cpnCFkmOGwecSnySN/vt4AxgLxb6Oc2UKkhHSLEKToFdhU40wu6URTno4sHnXbXm5+H57WDk/XJyPjw7uR14g4uT4bltKmqYPPgRC0/4rIjBsIbCfsHnWjSlCeWEBlimYOEQADOeyAfTlAMcQDXUPTgU//7oHvQPv/1WfLbJ59LYqw51KpWU7WfU58H82OrN83zh3RcsCr3fC8qX3bElBLHcI3VDC7fFr/gvfIP7qycAwYljwb7fWM71h2vvn3eD0b+80c+n3/31h+9tJX+r87oCwH+x4bp5wROpaFBrVIBR5I03LTfwv8uyjNadH0zrI1blqCf2bpvwMpo1pqS2yBTUYVhGGYP6MNSyP/Ez4UK4g2kVfSPAXuBnNIgXXUBh3NVzx9ZAGEB4sGXZjrSDLZTWtxUgxiaiWdgtvbrcRTjshLilEJXUzzXV1mRnyTTFf2O4mMdpkPJQBZOkiJQQUz/K6Cts96oD0LY5L+jL1lFWMYIEywhLMJZENKelneRtt9wNtsjTlECGnFH4RGQmI5k/pdFSh7FNOU8puwwtLHTIK8LMBp+1eg2bw972F6VHBdCXMkTLmipDSHDKRAEp3Fi+Auz2XdagfFeivPKXbZ10eEbClGYkSXMS+znYMp9TyBk4VouxDWAoGLdKWSatUzAZ5LQ0iZbEn0IOIL7KQjGN7+Erpw+MPmYiT8nElInzQ5YtIn8Ja1FWiff1qSliyafu9dkV6YT3FWw4nVLgBAGtHiGqtmdWa6iZXioWqGyDunbLBTMMrSBKJYKBt12cvF2m+0npH20jIkSV3qpsbJzbSNN4kM5LE1NcIQZLdIwHrchoFPuLLroCC8g0gXva7lEVICWAHeNk2xERZ7u73Ajrgv2FJhkNJcb2FJPRm5Y33BAjUOC1dwO97x3RJxqA+gbvh5dkeHExOBsCydA758CfjGyfuWLJgtMF8nvrZnA+OL0lb8jPo6uLSgx5toBaRn79MBgNSGld9xj27mTy3CKn3XFl+QmMpFHo4vCUgn91mykaRsk338gPY0sfhMoGp4a7YnCQY5pR4/Mmy26EELmg1I+pLRuW9+1mbt3Ghifgl1Nxr1z4JSqD+BHk8hAo6RPL8uxQuTQBvnlPMc9LMoswnsOElC9rTKCeUzc4UCm+jJ21C20Mdj/ViKJi1iQsRESQYFSlmSlXHRPDy5vB6JZcjcjw/eUVmH54eXu1BhvdKu5I2ziVSR2ZyrQLeD5QHODAd4Mb0j121H+2Zdcs0worZwUAzqpbzGJ0qa4VWPZk7d3urpGB68tk5GZwS0QgKaV2j9sAb4olTjYQXz9KeuPp1cXF8LZMIuDymGy6t2g6GYCRhZsrRlfn5z+dnP4D1igLd+ghIGYdlUD903CrRKAoRS0XNCnF/yEw4KLVyCCzhHAudH388DrflytK55cUQXh9K0HYUGpJGofZWYiDqRlSMcdIbpII5ekZhSiezNYwBdyhZAbDJFvgLkhBoWTNlkkA8pRNACjW/KSsXIE9SHIgsgZEkYqUrOUEKtiUhFKJ+gKNhDEXKsYOS0L65CK1BvNRmrgtZWRflZEH/fYycuHnc3cjEdVlTs/aF7Qus3pdebQoGPB4gjSPvCPWsRTJ6nH/seARICgNqZptFo0d7ho8WAixoSaEY8yCEu+/fvIuTu6vLxDVHaqq5OViRAZlZSuk3JALke3cUyhJBEIalaOwkGIrFIqNLj5w2qT4imoKtFIv1up6Ueho2zChTxAxcbja7svlAIUjw9Ial1DWNhexQUriuoAR+KqKegTsWA5NNuT5TRZ5sTqs5+7akULm19SQ2vwUHtNQFgBYOWoIoBQRi1m+sZBUu1QEWPdQ67Eeyfc7XBhpn89Tt5X+PgD9BZXnaZQ+Ug7fKwpSK77GVp6WZaEioXhLVyzGT91yInyRU/UTCJm5Vz22tH4V8MqVWXH/EVRRzgKby1xfDQgOaWwr50oguxJJpcFgrpkw1KopT2OEY+2ailNgbKcc3Aq26uP6sn4wdSSmApDZggkJQEd5KssE82yhn13lZfjAv4dyBR/uGUrT5+bAk7Dx9KP7fb9MIJcpec/9xVzEc1lAJqnIQOTketgjI7qgfq4KSD/PabyA2pLTAg6UAIsppBmGOI8glmQZxG+IymsyitnDR7itsoxPdLm5uHTIsZGMpozHA53LZKxv9vXTR7f9/JIew5nNylKEBY2MGCICLkBUUxHApT7T+4zyBxqKqLErp7ME6vOE5oYu6tT6RZqAaoV0VvgR2iGHcL5YamrgZ1kaMF/c1vD5Xv1FBcqBvqzkxcCmF4KwOhwfvrY6FzSrhSnKEkNCVrGyslgyQKy59haVqWReZg3Xul2tEjfuXRI69JGVp0j96mRQJAFjvXlwfY9mWbkqqL1t9+gWvQeMp3wrpIhhmSuar2yeFZMGdncBdIXowk0HEzRwwNO/ZSynkEJ6PrOA8HQNgqv8E3fwYthCRSVJGwER76r3LcYcefr+PoaCPXxE5IkZgShFTV5ZdiN82QKriGhVJEJSLRa9cksNyBW8X0jH2cOWGkOOHC3fkd/SeQq4/ShaW8BpVbm8V+Fab9wSjMgjy+fk7O76fHiKr0mgUjvpkbuE00jENulFEBYigiH7Pn2CCBKBe+o94yLLxb2ginlAGX1hVFRL+pggnRbpHkbAF8llRcbBwgzYJ0TLzEjDvcpXGyEMLCnoqpkYVvzZgdSi5ldp2pMNA8uxFjx9YGAlLyzQPnKJajGZnt9RwcPtlshCIClMiADYkG5XSmcDuNpqhFa3RScFeGnib/S93FrvXn6Rr7i6WjTH4BGQfY82E5UOFVHCNtvSqv1sdNuOauUbGPcsDUQklr5XdnFnhc99COat6Y3ATKgMerBYTYYhLLdKnfcqVic6dJtuW0nXvDD8bbivmVKaBKY975iUX9d+rtKJEAs3OCDHSshxfwK2WmXba4FQJ8Ud0fnebNkX7rrmio3zzXs1LK1EQNJ/8JqWqgrD0pGZhIAvW/miTNYcY7XFuqpgLYTo4be36HbL2U0ltnOVYiEhruiKYwYE92hjPMCJlPOU48SRbCuW9yrvfIjRDGMU5ZKyRWnwCXPQpHFNM4TU7/VFkhvExDHTtHvUSNJOMwGXM2oBpyarWGJACpZotdehJjevZEGq7B4Zjb+6GhH8pl5MnTzvvOg126DyYsVC5FEl1/o7DHFSSwA3cfVllnn0mcCU3FCQB6tVDy33f/n9QSdk/ixJQTOBK3+9obktoC8RFSyW8sAsgN62vnII5I9Vtmf3lWHkys0s+XNr21XfteHPL24JAlqqXtSFWW2Jqq0k9Cb2kZhi7x287W/xuxdDcxC69KsElgBfSWcccF4LVl/lp3gPZ1XiurOsOuFXevVG7/ufdMdlPnM/ZqCakMouoUi0OmzgiCJWzndvnb/fXF16tx9GV7968GEwGl2NNNCF97qqL+AeqZ80JVA5o2JvgLTfAMsYUaCfA6lvp/oZTI3oG+ENuyWwV32z1SKpsZExBJuVXY/yHN0FMc5BO+ACmBOPrXkeYzPcwl09KEljYXv52LFE4hFtGENy2XdwZDPFPRIKq7ZvIzd6cSuncSyk3FwoUs5d+aXYZNIaL3RL9/rqpvnbhhbM9ax9X7D1bL8MC57UplO2R0EIad5JDZBg0tht66YiwRIdrnpAMrh+1Sd2xm/7fedt/wD+f6sYvOxO4fYobEhNkmTd3J2eDm5uRJjZRddc6qkhzdX5Vp0e/je4x0oGr1tyowT1SFFGiy9OxM+ERhmV7YCmlkx7rOiuXhxaTcUYZQIQRV1hVWwfskJWRLnIDj7EXZaLmjMtcojCWDFiJveNXD4863295lsqvQbdu9aScrpIOZTOJY81csahLk3rLaVVvTbpPmrR3VbPd5cQZS+vfj0fnL0fnFmrBN7szeM6+Rs6qLhnnqCrXWv/3+OTvd+8yecD54f+c2f/zHLkTFvJsvmQjYxD/ryOdD/c3l4TqwcumdumNwIHIXgGjImzIKP2Gvu/Jk1rLucHn5L0EU6eNd7QPK/JYF9N6wChUDUv5DvGBl4MDfUscoFALVsf9wLkAOU6MjZd+kL+phQAn2Sit9ND7dXOGKE08pdN2AdqqMnogD3v/AcEXd5G'}, 'runner': {'sha256': '78b5aa3bb4f334a31db63755b19a2d60ece5d5b059ca7cb4318dd0e2b286a5ca', 'data': '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'}}


class Stop(Exception):
    pass


def digest(data):
    return hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()


def content(name):
    entry = PAYLOADS[name]
    data = zlib.decompress(base64.b64decode(entry['data'], validate=True))
    if digest(data) != entry['sha256']:
        raise Stop('Recovery payload checksum failed.')
    return data


def clean(value):
    return ''.join(c if c.isprintable() else ' ' for c in str(value))[:800]


def run(*args):
    if not args or args[0] not in ('report', 'crm', 'enable'):
        raise Stop('This recovery cannot send email or operate on appointments.')
    result = subprocess.run(['runuser', '-u', 'sitesee', '--', PHP,
                             str(ROOT / 'tools/booking-communications.php'), *args],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            universal_newlines=True, check=False)
    return result.returncode, result.stdout, result.stderr


def report(call=run):
    code, output, error = call('report', REFERENCE)
    if code:
        raise Stop('Cannot read saved communication state: ' + clean(error))
    try:
        records = json.loads(output)
        if not isinstance(records, list):
            raise ValueError()
        rows = {row['communication_key']: row for row in records}
        if len(rows) != len(records):
            raise ValueError()
        for key, sender in zip(KEYS, ('cro@sitesee.ai', 'sales@re.sitesee.ai')):
            row = rows[key]
            if (row['sender'] != sender or row['recipient'] != 'cro@sitesee.ai'
                    or row['submission_state'] != 'sent_observed'
                    or row['delivery_state'] != 'recipient_copy_observed'
                    or not row['internet_message_id']):
                raise ValueError()
        return rows
    except (ValueError, KeyError, TypeError):
        raise Stop('Both designated messages must already have verified sent and received evidence. No sending is attempted.')


def atomic_write(path, data, uid, gid):
    fd, temporary = tempfile.mkstemp(prefix='.crm-recovery-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, 0o600)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def install(root, runner, php, uid, gid):
    if root.is_symlink() or not root.is_dir() or root.stat().st_uid != uid or root.stat().st_mode & 0o022:
        raise Stop('Private application directory is unsafe.')
    for name, expected in (('tools/booking-communications.php', CLI_HASH), ('server/booking-mail-client.php', CLIENT_HASH)):
        target = root / name
        if target.is_symlink() or not target.is_file() or digest(target.read_bytes()) != expected:
            raise Stop('Installed command differs from the verified release: ' + name)
    targets = [(root / 'server/booking-crm.php', content('crm'), CRM_OLD, 'booking-crm.php')]
    if runner.exists() or runner.is_symlink():
        targets.append((runner, content('runner'), RUNNER_OLD, 'finish-booking-communications.py'))
    for path, data, previous, name in targets:
        if path.is_symlink() or not path.is_file() or digest(path.read_bytes()) not in (previous, digest(data)):
            raise Stop('Existing file differs from the verified release: ' + name)
    release = root / 'calendar-confirmation-release.json'
    if release.exists() or release.is_symlink():
        if release.is_symlink() or not release.is_file():
            raise Stop('Release manifest path is unsafe.')
        saved = json.loads(release.read_text())
        if saved.get('files', {}).get('server/booking-crm.php') not in (CRM_OLD, digest(content('crm'))):
            raise Stop('Release manifest differs from the verified CRM version.')
        saved['files']['server/booking-crm.php'] = digest(content('crm'))
        targets.append((release, (json.dumps(saved, indent=2) + '\n').encode(), digest(release.read_bytes()), release.name))
    compile(content('runner'), 'finish-booking-communications.py', 'exec')
    with tempfile.NamedTemporaryFile(suffix='.php', dir=root) as staged:
        staged.write(content('crm'))
        staged.flush()
        result = subprocess.run([php, '-l', staged.name], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            raise Stop('PHP syntax check failed; no installed file was replaced.')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backups = root / 'deployment-backups'
    if backups.is_symlink():
        raise Stop('Backup path is unsafe.')
    backup = backups / ('crm-history-' + stamp)
    backup.mkdir(parents=True, mode=0o700)
    for path, data, previous, name in targets:
        shutil.copy2(path, backup / name)
    changed = []
    try:
        for path, data, previous, name in targets:
            atomic_write(path, data, uid, gid)
            changed.append((path, name))
    except Exception:
        for path, name in reversed(changed):
            atomic_write(path, (backup / name).read_bytes(), uid, gid)
        raise
    return backup


def recover(call=run, emit=print):
    rows = report(call)
    problems = []
    for key in KEYS:
        if rows[key]['crm_state'] == 'associated':
            continue
        emit('Recovering CRM history for ' + key + '...', flush=True)
        code, output, error = call('crm', key)
        if code:
            problems.append(key + ': ' + clean(error))
    rows = report(call)
    ready = all(rows[key]['crm_state'] == 'associated' for key in KEYS)
    activated = False
    if ready:
        code, output, error = call('enable', KEYS[1])
        activated = code == 0
        if code:
            problems.append('Activation: ' + clean(error))
    emit('\nFINAL RESULTS', flush=True)
    for key in KEYS:
        emit('{}: sent=sent_observed | delivery=recipient_copy_observed | CRM={}'.format(key, rows[key]['crm_state']), flush=True)
        if rows[key].get('crm_error'):
            emit('  ' + clean(rows[key]['crm_error']), flush=True)
    emit('Activation: ' + ('verified and enabled' if activated else 'not performed by this run'), flush=True)
    for problem in problems:
        emit(problem, flush=True)
    emit('No email was sent. No calendar event or payment was changed.', flush=True)
    return 0 if activated else 2


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true', help='Install the correction, recover both CRM entries and enable only after success.')
    args = parser.parse_args()
    if not args.apply:
        print('Use --apply to install the correction and recover the two already-delivered messages. No email is sent.')
        return 0
    if os.geteuid() != 0:
        raise Stop('Run from the root WHM Terminal.')
    os.umask(0o077)
    flags = os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0)
    with os.fdopen(os.open('/run/lock/sitesee-booking-comms-finish.lock', flags, 0o600), 'w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('The previous runner is still active. Let it finish first.')
        report()
        account = pwd.getpwnam('sitesee')
        backup = install(ROOT, RUNNER, PHP, account.pw_uid, account.pw_gid)
        print('CRM correction installed. Backup: ' + str(backup), flush=True)
        return recover()


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Stop as error:
        print('STOP: ' + clean(error), file=sys.stderr)
        sys.exit(1)
    except KeyboardInterrupt:
        print('\nStopped. This command can resume CRM recovery without sending email.', file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('STOP: Recovery could not finish. Preserve saved progress; do not resend email.', file=sys.stderr)
        sys.exit(1)
