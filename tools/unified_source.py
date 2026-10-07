"""Keep historical releases reproducible across the reviewed bootstrap change."""
import base64
import hashlib
import json


def before_unified_update(root, source):
    actual = (root / source).read_bytes()
    manifest = root / 'documents/unified/bootstrap-source.json'
    if not manifest.exists():
        return actual
    change = json.loads(manifest.read_text())['files'].get(source)
    if change is None:
        return actual
    before = base64.b64decode(change['before'], validate=True)
    after = base64.b64decode(change['after'], validate=True)
    if (hashlib.sha256(before).hexdigest() != change['before_sha256']
            or hashlib.sha256(after).hexdigest() != change['after_sha256']
            or actual != after):
        raise RuntimeError('Reviewed bootstrap source differs: ' + source)
    return before
