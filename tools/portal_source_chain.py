"""Validate the exact email upgrade while reproducing older immutable packages."""
import base64, json

def before_email_update(root, source):
    actual=(root/source).read_bytes()
    manifest=root/'documents/portal/email-change-source.json'
    if not manifest.exists(): return actual
    key=source.replace('_private/','private/',1)
    change=json.loads(manifest.read_text())['files'].get(key)
    if change is None: return actual
    if actual!=base64.b64decode(change['after']):
        raise RuntimeError('Reviewed email upgrade source differs: '+source)
    if change['before'] is None: raise RuntimeError('No historical baseline: '+source)
    return base64.b64decode(change['before'])
