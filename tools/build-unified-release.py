#!/usr/bin/env python3
"""Build the TEST application from one full Git commit, without changing the checkout."""
import argparse
import gzip
import hashlib
import io
import json
import pathlib
import re
import subprocess
import tarfile

BASELINE = 'ff5e625a8ff4c51af336e9203fa4be29b3611570'
ROOT = pathlib.Path(__file__).resolve().parents[1]
NEW_FILES = {'private/server/application.php', 'private/views/application-shell.php',
             'public/application-entry.php', 'public/portal-assets/application.css',
             'private/server/booking-review-ui.php', 'public/portal-assets/booking-review.js'}
CHANGED_PRIVATE = {'private/views/portal.php', 'private/server/vendor-app.php',
                   'private/server/booking-staff.php', 'private/server/booking-lifecycle.php',
                   'private/server/booking-lifecycle-store.php', 'private/server/booking-lifecycle-ui.php',
                   'private/server/booking-manage.php', 'private/server/booking-communication.php',
                   'private/server/portal-service.php', 'private/views/portal-service.php',
                   'private/server/booking-lifecycle-reconcile.php'}
PREDECESSOR = {'commit': '2de113503050b4ca5a63ad0aaa1b14705a3726e0',
               'manifest_sha256': 'f07088f5da66cf17565e0bde7860f590e7f99d40fcf8f8282de00c5f1412186c'}
RUNTIME_SCHEMA = 'Additive booking_change_requests table and unique pending-request index on first normal application use; existing tables and rows unchanged.'
ALIASES = {'account.php', 'vendor.php', 'staff-bookings.php', 'staff-production.php',
           'staff-vendors.php', 'manage-appointment.php', 'booking-pay.php',
           'booking-webhook.php', 'booking-availability.php', 'pricing.php',
           'pricing-request.php', 'pricing-approve.php', 'pricing-confirm.php',
           'pricing-logout.php', 'pricing-asset.php', 'quote-submit.php', 'contact-submit.php'}


def encode(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def tree(commit):
    result = {}
    for entry in git('ls-tree', '-r', '-z', commit).split(b'\0'):
        if not entry:
            continue
        meta, name = entry.split(b'\t', 1)
        mode, kind, oid = meta.decode().split()
        name = name.decode()
        if kind != 'blob' or mode not in ('100644', '100755'):
            raise RuntimeError('Unsupported source entry: ' + name)
        result[name] = git('cat-file', 'blob', oid)
    return result


def deployment_name(name):
    if '/_notes/' in name or name == '_private/README.md':
        return None
    if name.startswith('_private/'):
        return 'private/' + name[len('_private/'):]
    return name if name.startswith('public/') else None


def build(commit, output):
    if not re.fullmatch('[0-9a-f]{40}', commit):
        raise RuntimeError('A full, exact Git commit is required.')
    if git('rev-parse', '--verify', commit + '^{commit}').decode().strip() != commit:
        raise RuntimeError('Commit resolution differs.')
    subprocess.run(['git', '-C', str(ROOT), 'merge-base', '--is-ancestor', BASELINE, commit], check=True)
    current, original, previous = tree(commit), tree(BASELINE), tree(PREDECESSOR['commit'])
    runner = current['tools/install-unified.py']
    original_deploy = {deployment_name(k): v for k, v in original.items() if deployment_name(k)}
    previous_deploy = {deployment_name(k): v for k, v in previous.items() if deployment_name(k)}
    members = {'install-unified.py': runner}
    files = {}
    changed = set()
    for name, data in sorted(current.items()):
        target = deployment_name(name)
        if not target:
            continue
        before = original_deploy.get(target)
        if before != data:
            changed.add(target)
        # Bootstrap/shell plus the explicitly reviewed manager-approved reschedule correction.
        allowed = NEW_FILES | CHANGED_PRIVATE | {'public/' + a for a in ALIASES}
        if before != data and target not in allowed:
            raise RuntimeError('A new compatibility/migration review is required: ' + target)
        if before is None and target not in NEW_FILES:
            raise RuntimeError('Unexpected new deployment file: ' + target)
        variants = [data] if before is None else [before, data]
        if target in previous_deploy:
            variants.append(previous_deploy[target])
        if before is not None and name.endswith(('.php', '.css', '.js', '.html', '.txt', '.htaccess')):
            variants += [before.replace(b'\r\n', b'\n'), before.replace(b'\r\n', b'\n').replace(b'\n', b'\r\n')]
        files[target] = {'sha256': sha(data), 'bytes': len(data),
                         'accepted_before': sorted({sha(v) for v in variants}),
                         'allow_missing': before is None}
        members['files/' + target] = data
    if set(original_deploy) - set(files):
        raise RuntimeError('Existing deployment files were removed.')
    source = {'commit': commit, 'tree': git('rev-parse', commit + '^{tree}').decode().strip(),
              'files': {n: {'sha256': sha(b), 'bytes': len(b)} for n, b in sorted(current.items())}}
    members['source-test-manifest.json'] = encode(source)
    manifest = {'format': 1, 'release': 'unified-test-' + commit[:12], 'commit': commit,
                'baseline': BASELINE, 'stage': 'TEST', 'migration': 'none',
                'compatibility': 'reviewed bootstrap/shell, manager-approved reschedule requests and stepped Booking Review; original financial/provider identities retained',
                'runtime_schema': RUNTIME_SCHEMA,
                'predecessor': PREDECESSOR | {'files': {n: sha(b) for n, b in sorted(previous_deploy.items())}},
                'installer_sha256': sha(runner), 'source_manifest_sha256': sha(members['source-test-manifest.json']),
                'files': files, 'changed_from_baseline': sorted(changed),
                'active_manifests': ['calendar-confirmation-release.json', 'microsoft-scheduling-release.json',
                                     'booking-workflow-release.json', 'branded-checkout-release.json',
                                     'appointment-management-release.json']}
    members['manifest.json'] = encode(manifest)
    output = pathlib.Path(output)
    output.mkdir(mode=0o700, parents=True, exist_ok=True)
    archive = output / (manifest['release'] + '.tar.gz')
    if archive.exists():
        raise RuntimeError('Output already exists; use a new output directory.')
    with archive.open('xb') as handle:
        with gzip.GzipFile(fileobj=handle, mode='wb', filename='', mtime=0, compresslevel=9) as compressed:
            with tarfile.open(fileobj=compressed, mode='w', format=tarfile.PAX_FORMAT) as package:
                for name, data in sorted(members.items()):
                    info = tarfile.TarInfo(name)
                    info.size, info.mode, info.mtime = len(data), 0o600, 0
                    info.uid = info.gid = 0
                    package.addfile(info, io.BytesIO(data))
    digest = sha(archive.read_bytes())
    (output / 'install-unified.py').write_bytes(runner)
    (output / 'manifest.json').write_bytes(members['manifest.json'])
    (output / (archive.name + '.sha256')).write_text(digest + '  ' + archive.name + '\n')
    print(json.dumps({'package': str(archive), 'sha256': digest, 'commit': commit,
                      'deployment_files': len(files), 'source_test_files': len(current),
                      'changed_from_baseline': len(changed), 'stage': 'TEST'}))
    return archive


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    build(args.commit, args.output)
