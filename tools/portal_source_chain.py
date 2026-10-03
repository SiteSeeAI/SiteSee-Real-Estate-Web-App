"""Validate reviewed upgrades while reproducing older immutable packages."""
import base64, json

def before_job_update(root, source):
    actual=(root/source).read_bytes()
    manifest=root/'documents/portal/job-closeout-source.json'
    if not manifest.exists(): return actual
    change=json.loads(manifest.read_text())['files'].get(source.replace('_private/','private/',1))
    if change is None:return actual
    if actual!=base64.b64decode(change['after']):raise RuntimeError('Reviewed closeout upgrade source differs: '+source)
    if change['before'] is None:raise RuntimeError('No historical baseline: '+source)
    return base64.b64decode(change['before'])

def before_recipient_update(root, source):
    actual=before_job_update(root, source)
    manifest=root/'documents/portal/test-recipient-source.json'
    if not manifest.exists(): return actual
    key=source.replace('_private/','private/',1)
    change=json.loads(manifest.read_text())['files'].get(key)
    if change is None: return actual
    if actual!=base64.b64decode(change['after']):
        raise RuntimeError('Reviewed recipient upgrade source differs: '+source)
    if change['before'] is None: raise RuntimeError('No historical baseline: '+source)
    return base64.b64decode(change['before'])

def before_email_update(root, source):
    actual=before_recipient_update(root, source)
    manifest=root/'documents/portal/email-change-source.json'
    if not manifest.exists(): return actual
    key=source.replace('_private/','private/',1)
    change=json.loads(manifest.read_text())['files'].get(key)
    if change is None: return actual
    if actual!=base64.b64decode(change['after']):
        raise RuntimeError('Reviewed email upgrade source differs: '+source)
    if change['before'] is None: raise RuntimeError('No historical baseline: '+source)
    return base64.b64decode(change['before'])
