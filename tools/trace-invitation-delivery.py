#!/usr/bin/env python3
"""Read-only delivery trace for the current authorized SiteSee test invitation."""
import datetime as dt
import glob
import json
import re
import shutil
import sqlite3
import subprocess
from pathlib import Path

ROOT = Path('/home/sitesee/.sitesee-real-estate')
REF, EMAIL = 'E6E183EF8E', 'cro@sitesee.ai'
MID = re.compile(r'\b[A-Za-z0-9]{6}-[A-Za-z0-9]{6,11}-[A-Za-z0-9]{2,4}\b')

def clean(value):
    value = re.sub(r'(?i)(password|secret|authorization|access_token|refresh_token)\s*[=:]\s*\S+', r'\1=[REDACTED]', value)
    value = re.sub(r'\bA=\S+', 'A=[REDACTED]', value)
    return value[:1800]

def run(args):
    try:
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=12, universal_newlines=True)
        return result.stdout if result.returncode == 0 else 'UNAVAILABLE (exit ' + str(result.returncode) + '): ' + result.stdout
    except (OSError, subprocess.TimeoutExpired) as error:
        return 'UNAVAILABLE: ' + type(error).__name__

def main():
    db = sqlite3.connect('file:' + str(ROOT / 'data/bookings.sqlite') + '?mode=ro', uri=True)
    db.row_factory = sqlite3.Row
    row = db.execute('SELECT state,invitation_state,invitation_recipient,invitation_attempted_at,invitation_sent_at FROM booking_confirmations WHERE reference=?', (REF,)).fetchone()
    if not row or row['invitation_recipient'] != EMAIL:
        raise SystemExit('STOP: Saved invitation recipient does not match this test.')
    print('INVITATION RECORD:', json.dumps(dict(row)), flush=True)
    timestamp = re.sub(r'([+-]\d{2}):(\d{2})$', r'\1\2', row['invitation_attempted_at'])
    when = dt.datetime.strptime(timestamp, '%Y-%m-%dT%H:%M:%S%z')
    clocks = [when.astimezone().replace(tzinfo=None), when.astimezone(dt.timezone.utc).replace(tzinfo=None)]
    print('SERVER CLOCK:', dt.datetime.now().astimezone().isoformat())
    for file in ['/etc/localdomains', '/etc/remotedomains']:
        p = Path(file)
        print(file + ' includes sitesee.ai:', 'sitesee.ai' in p.read_text().splitlines() if p.exists() else 'UNAVAILABLE')
    pools = glob.glob('/opt/cpanel/ea-php82/root/etc/php-fpm.d/*re.sitesee.ai*.conf')
    for file in pools:
        text = Path(file).read_text(errors='replace')
        host = re.search(r'^\s*env\[SITESEE_SMTP_HOST\]\s*=\s*(.*?)\s*$', text, re.M)
        sender = re.search(r'^\s*env\[SITESEE_FROM_EMAIL\]\s*=\s*(.*?)\s*$', text, re.M)
        print('FPM SMTP host configured:', bool(host and host.group(1).strip("\"' ")))
        print('FPM sender:', clean(sender.group(1)) if sender else 'Application default')
    if not pools:
        print('FPM mail settings: matching pool file not found; route not inferred.')
    exim = shutil.which('exim') or '/usr/sbin/exim'
    print('EXIM LOG TIMEZONE:', clean(run([exim, '-bP', 'timezone']).strip()))
    print('CURRENT RECIPIENT ROUTE:\n' + clean(run([exim, '-bt', EMAIL]).strip()))
    dig = shutil.which('dig')
    if dig:
        print('PUBLIC MX:\n' + clean(run([dig, '+time=2', '+tries=1', '+short', 'MX', 'sitesee.ai']).strip()))
    lines = []
    files = []
    for pattern in ['/var/log/exim_mainlog*', '/var/log/exim_rejectlog*']:
        candidates = [Path(p) for p in glob.glob(pattern) if Path(p).is_file() and not p.endswith(('.gz', '.bz2', '.xz'))]
        files.extend(sorted(candidates, key=lambda p: p.stat().st_mtime, reverse=True)[:2])
    for p in files:
        with p.open('rb') as handle:
            size = p.stat().st_size
            handle.seek(max(0, size - 32 * 1024 * 1024))
            if handle.tell():
                handle.readline()
            chunk = handle.read().decode('utf-8', errors='replace').splitlines()
        lines.extend(chunk)
        dates = [line[:19] for line in chunk if re.match(r'^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d', line)]
        print('LOG COVERAGE:', p.name, dates[0] if dates else 'no timestamps', 'through', dates[-1] if dates else 'none')
    exact, candidates = set(), set()
    for line in lines:
        match = MID.search(line)
        if not match:
            continue
        if REF in line and 'Photography Arrival Window' in line:
            exact.add(match.group())
        if EMAIL.lower() in line.lower():
            try:
                stamp = dt.datetime.strptime(line[:19], '%Y-%m-%d %H:%M:%S')
                if any(abs((stamp - clock).total_seconds()) <= 300 for clock in clocks):
                    candidates.add(match.group())
            except ValueError:
                pass
    ids = exact or candidates
    print('CORRELATION:', 'booking reference' if exact else 'recipient/time candidates; not yet exact')
    print('MESSAGE IDS:', ', '.join(sorted(ids)) or 'NONE IN SCANNED LOGS')
    related = set(ids)
    for line in lines:
        if any('R=' + mid in line for mid in ids):
            match = MID.search(line)
            if match:
                related.add(match.group())
    selected = list(dict.fromkeys(line for line in lines if set(MID.findall(line)) & related))
    print('DELIVERY / DEFERRAL / REJECTION TRACE:')
    for line in selected[-70:]:
        print(clean(line))
    if not selected:
        print('No correlated log entries. This alone does not establish whether delivery occurred.')
    if len(selected) > 70:
        print('Earlier matching entries omitted:', len(selected) - 70)
    queue = run([exim, '-bp'])
    if queue.startswith('UNAVAILABLE'):
        print('QUEUE:', queue)
    else:
        queued = set(MID.findall(queue)) & related
        print('CORRELATED IDS STILL QUEUED:', ', '.join(sorted(queued)) or 'NONE')
        for mid in sorted(queued)[:4]:
            print('QUEUED MESSAGE LOG:', mid)
            print(clean(run([exim, '-Mvl', mid])))
    print('READ-ONLY TRACE COMPLETE. No resend, queue action, configuration change or booking change.')
    db.close()

if __name__ == '__main__':
    main()
