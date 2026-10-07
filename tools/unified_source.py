"""Keep historical releases reproducible across the reviewed bootstrap change."""
import base64
import hashlib
import json


def before_unified_update(root, source):
    actual = (root / source).read_bytes()
    # Reverse newest reviewed presentation changes before the route bootstrap.
    for name in ['shell-source.json', 'bootstrap-source.json']:
        manifest = root / 'documents/unified' / name
        if not manifest.exists():
            continue
        change = json.loads(manifest.read_text())['files'].get(source)
        if change is None:
            continue
        before = base64.b64decode(change['before'], validate=True)
        after = base64.b64decode(change['after'], validate=True)
        if (hashlib.sha256(before).hexdigest() != change['before_sha256']
                or hashlib.sha256(after).hexdigest() != change['after_sha256']
                or actual != after):
            raise RuntimeError('Reviewed unified source differs: ' + source)
        actual = before
    return actual
