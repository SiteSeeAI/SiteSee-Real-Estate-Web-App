#!/usr/bin/env python3
"""Rebuild the self-contained installer payload from reviewed repository files."""
import base64
import hashlib
import json
from pathlib import Path
import re
import zlib

root = Path(__file__).resolve().parents[1]
paths = {'server/booking-microsoft-calendar.php': root / '_private/server/booking-microsoft-calendar.php',
         'tools/verify-microsoft-calendar-connection.php': root / 'tools/verify-microsoft-calendar-connection.php'}
raw = json.dumps({name: path.read_text() for name, path in paths.items()}, sort_keys=True).encode()
installer = root / 'tools/install-microsoft-calendar-connection.py'
text = installer.read_text()
text = re.sub(r"^PAYLOAD = .*?$", 'PAYLOAD = ' + repr(base64.b64encode(zlib.compress(raw, 9)).decode()), text, flags=re.M)
text = re.sub(r"^PAYLOAD_SHA = .*?$", 'PAYLOAD_SHA = ' + repr(hashlib.sha256(raw).hexdigest()), text, flags=re.M)
installer.write_text(text)
