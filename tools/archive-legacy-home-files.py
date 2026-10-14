#!/usr/bin/env python3
"""Check local dependencies and reversibly archive seventeen historical installers."""
import argparse
import ctypes
import datetime
import errno
import fcntl
import fnmatch
import hashlib
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import struct
import subprocess
import tempfile
import time
import zipfile

NAMES = tuple('''install-customer-portal-pathfix.py
install-customer-portal.py
install-microsoft-calendar-connection.py
install-microsoft-calendar-connection-r2.py
install-portal-complete.py
install-re-business-workflow.py
install-re-business-workflow-r2.py
install-re-calendar-notice-20261002-r1.py
install-re-draft-recovery-r1.py
install-re-email-change-20261002-r1.py
install-re-job-closeout-20261002-r1.py
install-re-job-closeout-20261003-r1_1.py
install-re-onsite-services-20261005-r1.py
install-re-portal-polish-20261002-r1.py
install-re-staff-review-20261002-r1.py
install-re-test-recipient-20261002-r1.py
install-re-vendor-accounts-20261005-r1.py'''.splitlines())
HOME = pathlib.Path('/home/sitesee')
MAX_FILE = 16 * 1024 * 1024
SCAN_SECONDS = 120
SCAN_HEARTBEAT_SECONDS = 10
SUFFIXES = {'.php', '.phtml', '.py', '.js', '.mjs', '.cjs', '.sh', '.conf',
            '.ini', '.json', '.yaml', '.yml', '.html', '.service', '.timer',
            '.pl', '.rb', '.cgi', '.bash', '.zsh', '.ksh', '.lua', '.tcl', '.awk'}
NON_CODE = {'.sqlite', '.sqlite3', '.db', '.log', '.gz', '.zip', '.tar', '.pdf',
            '.jpg', '.jpeg', '.png', '.webp', '.gif', '.woff', '.woff2', '.ttf', '.mp4'}
PACKAGED_HEADERS = {
    **{suffix: (b'PK\x03\x04', b'PK\x05\x06') for suffix in ('.jar', '.war', '.ear')},
    '.rpm': (b'\xed\xab\xee\xdb',), '.deb': (b'!<arch>\n',),
    '.xz': (b'\xfd7zXZ\x00',), '.bz2': tuple(b'BZh' + bytes([n]) for n in range(49, 58)),
    '.zst': (b'\x28\xb5\x2f\xfd',), '.7z': (b'7z\xbc\xaf\x27\x1c',),
}
META = ('dev', 'ino', 'uid', 'gid', 'mode', 'size', 'mtime_ns')
COMPILER_ROOTS = (pathlib.Path('/usr/lib/gcc'), pathlib.Path('/usr/lib64/gcc'))
LIBRARY_ROOTS = (pathlib.Path('/usr/lib'), pathlib.Path('/usr/lib64'),
                 pathlib.Path('/lib'), pathlib.Path('/lib64'))
SYSTEM_UID = 0
SYSTEM_COMMAND_DIRS = tuple(map(pathlib.Path, ('/usr/bin', '/usr/sbin', '/bin', '/sbin')))
SYSTEM_COMMAND_TARGETS = tuple(map(pathlib.Path, (
    '/usr', '/lib', '/lib64', '/bin', '/sbin', '/opt/cpanel',
    '/etc/alternatives', '/etc/init.d', '/etc/rc.d/init.d')))
SYSTEMD_UNITS = pathlib.Path('/run/systemd/units')
SYSTEMD_USER_RUNTIME = pathlib.Path('/run/user')
STORAGE_ROOTS = (pathlib.Path('/usr'), pathlib.Path('/var'))
MOUNTINFO = pathlib.Path('/proc/self/mountinfo')
SWAPS = pathlib.Path('/proc/swaps')
VIRTUAL_ROOTS = tuple(map(pathlib.Path, ('/dev', '/proc', '/sys')))
DEVICE_ROOT = pathlib.Path('/dev')
BLOCK_SYSFS = pathlib.Path('/sys/dev/block')
LOOP_STATUS = struct.Struct('=5Q4I64s64s32s2Q')  # Linux loop_info64: 232 bytes.
UNIT_NAME = r'[A-Za-z0-9_:@.\\-]+\.(?:service|socket|target|device|mount|automount|swap|timer|path|slice|scope)'
SYSTEMD_IPC_LINKS = {
    'invocation': r'[0-9a-f]{32}',
    'log-level-max': r'[0-7]',
    'log-rate-limit-interval': r'[1-9][0-9]*',
    'log-rate-limit-burst': r'[1-9][0-9]*',
}


def need(ok, message):
    if not ok:
        raise RuntimeError(message)


def canonical(path):
    need(path.is_absolute() and path == path.resolve() and
         not any(p.is_symlink() for p in [path, *path.parents]),
         'Noncanonical path preserved: ' + str(path))


def fingerprint(s):
    return dict(zip(META, (s.st_dev, s.st_ino, s.st_uid, s.st_gid,
                          stat.S_IMODE(s.st_mode), s.st_size, s.st_mtime_ns)))


def compiler_link(source, target):
    # Canonicalize the parent, not the leaf alias: an app/scheduler alias pointing
    # into GCC is still an unreviewed application/scheduler dependency.
    source = source.parent.resolve(strict=False) / source.name
    if not any(source != root and root in source.parents for root in COMPILER_ROOTS):
        return False
    if any(target == root or root in target.parents for root in COMPILER_ROOTS):
        return True
    # Multilib GCC stubs also point to optional 32/64-bit system runtimes.
    # Accept only the same compiled-library family in an exact system library
    # directory; never follow this exception for app aliases or arbitrary scripts.
    if target.parent not in LIBRARY_ROOTS:
        return False
    shared = re.fullmatch(r'(lib[A-Za-z0-9_+.-]+)\.so(?:\.[0-9]+)*', source.name)
    if shared:
        # Red Hat also stamps compiler build versions before the .so suffix.
        return re.fullmatch(re.escape(shared.group(1)) +
                            r'(?:-[0-9]+(?:[.-][0-9]+)*)?\.so(?:\.[0-9]+)*', target.name) is not None
    return re.fullmatch(r'lib[A-Za-z0-9_+.-]+\.a', source.name) is not None and target.name == source.name


def missing_system_command(source, target):
    # Optional package commands can retain a dangling alias after a package is
    # removed. Such a command has no current executable to inspect. This applies
    # only to root-owned aliases directly in protected system command directories,
    # never to application/scheduler sources or aliases to historical installers.
    source = source.parent.resolve(strict=True) / source.name
    if source.parent not in {p.resolve(strict=False) for p in SYSTEM_COMMAND_DIRS}:
        return False
    before, parent = source.lstat(), source.parent.stat()
    if (not stat.S_ISLNK(before.st_mode) or before.st_uid != SYSTEM_UID or
            parent.st_uid != SYSTEM_UID or parent.st_mode & 0o022):
        return False
    if source.name in NAMES or any(part in NAMES for part in target.parts):
        return False
    if not any(root in target.parents for root in SYSTEM_COMMAND_TARGETS):
        return False
    if 'systemd' in target.parts:
        return False
    try:
        source.resolve(strict=True)
    except FileNotFoundError:
        if source.resolve(strict=False) != target:
            return False
        after = source.lstat()
        if (fingerprint(before) != fingerprint(after) or
                before.st_ctime_ns != after.st_ctime_ns):
            return False
        try:
            source.resolve(strict=True)
        except FileNotFoundError:
            return True
    return False  # A newly restored command must be inspected, never skipped.


def read_regular(path):
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK |
                 getattr(os, 'O_NOATIME', 0))
    try:
        s = os.fstat(fd)
        need(stat.S_ISREG(s.st_mode) and s.st_size <= MAX_FILE,
             'Unreviewed type/size preserved: ' + str(path))
        chunks = []
        count = 0
        while True:
            chunk = os.read(fd, 65536)
            if not chunk:
                break
            count += len(chunk)
            need(count <= MAX_FILE, 'Growing file preserved: ' + str(path))
            chunks.append(chunk)
        after = os.fstat(fd)
        need(fingerprint(s) == fingerprint(after) and s.st_ctime_ns == after.st_ctime_ns,
             'Changing file preserved: ' + str(path))
        return b''.join(chunks), s
    finally:
        os.close(fd)


def candidate(path, uid):
    canonical(path)
    data, s = read_regular(path)
    need(s.st_uid == uid and s.st_nlink == 1 and not s.st_mode & 0o022,
         'Unexpected ownership, links or writable mode preserved: ' + path.name)
    record = fingerprint(s)
    record['sha256'] = hashlib.sha256(data).hexdigest()
    return record


def sources(directory, all_files=False, skip=()):
    if not directory.exists():
        return
    need(not directory.is_symlink(), 'Unreviewed source directory alias: ' + str(directory))
    def failed(error):
        raise RuntimeError('Unreadable source directory: ' + str(error.filename))
    for current, directories, files in os.walk(directory, followlinks=False, onerror=failed):
        # Each symlink target is checked separately, never followed recursively.
        for name in list(directories):
            path = pathlib.Path(current) / name
            if path.is_symlink():
                yield (path, all_files)
                directories.remove(name)
            elif name in skip:
                directories.remove(name)
        for name in files:
            path = pathlib.Path(current) / name
            if path.is_symlink():
                yield (path, all_files)
            elif all_files or path.suffix.lower() in SUFFIXES or name in {'.htaccess', '.user.ini'}:
                yield path


def literal_probe(path, kind):
    # Source text can contain base64/URLs that resemble paths but cannot be
    # represented by the filesystem. Treat only an overlong heuristic match as
    # absent. Real enumerated sources, candidates and other OS errors retain
    # their existing failure behavior; candidate-name/glob checks run first.
    try:
        return getattr(path, kind)()
    except OSError as error:
        if error.errno == errno.ENAMETOOLONG:
            return False
        raise


def packaged_archive(path):
    headers = PACKAGED_HEADERS.get(path.suffix.lower())
    if (not headers or literal_probe(path, 'is_symlink') or
            not literal_probe(path, 'is_file')):
        return False
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK |
                 getattr(os, 'O_NOATIME', 0))
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode), 'Unreviewed package reference type: ' + str(path))
        head = os.read(fd, 8)
        after = os.fstat(fd)
        current = path.lstat()
        need(fingerprint(before) == fingerprint(after) == fingerprint(current) and
             before.st_ctime_ns == after.st_ctime_ns == current.st_ctime_ns,
             'Changing package reference preserved: ' + str(path))
        return any(head.startswith(header) for header in headers)
    finally:
        os.close(fd)


def package_launcher(path, fd, before):
    if path.suffix.lower() not in {'.jar', '.war', '.ear'}:
        return None
    prefix = os.pread(fd, 65536, 0)
    if not prefix.startswith(b'#!'):
        return None
    # Bound the central directory before stdlib parsing; never decompress data.
    # Its actual member offsets distinguish a real ZIP start from magic bytes
    # merely mentioned in shell comments. Inspect the entire bounded prefix too.
    with os.fdopen(os.dup(fd), 'rb') as stream:
        class MetadataReader:
            count = 0
            def seek(self, *args):
                return stream.seek(*args)
            def tell(self):
                return stream.tell()
            def read(self, size=-1):
                if size < 0:  # Python 3.9 uses no-argument reads for ZIP comments.
                    size = max(0, before.st_size - stream.tell())
                need(size <= MAX_FILE and self.count + size <= 2 * MAX_FILE,
                     'Archive metadata read limit; source preserved: ' + str(path))
                data = stream.read(size)
                self.count += len(data)
                return data
        bounded = MetadataReader()
        end = zipfile._EndRecData(bounded)
        if not end or not 0 < end[zipfile._ECD_ENTRIES_TOTAL] <= 50000 or not 0 < end[zipfile._ECD_SIZE] <= MAX_FILE:
            return None
        try:
            with zipfile.ZipFile(bounded) as package:
                members = package.infolist()
                if not 0 < len(members) <= 50000:
                    return None
                boundary = min(info.header_offset for info in members)
        except zipfile.BadZipFile:
            return None
    if not 0 < boundary <= len(prefix) - 4 or prefix[boundary:boundary + 4] != b'PK\x03\x04' or b'\0' in prefix[:boundary]:
        return None
    try:
        prefix[:boundary].decode('utf-8')
    except UnicodeDecodeError:
        return None
    after, current = os.fstat(fd), path.lstat()
    need(fingerprint(before) == fingerprint(after) == fingerprint(current) and
         before.st_ctime_ns == after.st_ctime_ns == current.st_ctime_ns,
         'Changing packaged launcher preserved: ' + str(path))
    return prefix


def systemd_metadata(path):
    # systemd unit.c exports these properties as journald IPC. A bare ID/number
    # symlink value is data, not a pathname. Real units/drop-ins live elsewhere.
    kind, separator, unit = path.name.partition(':')
    if not separator or not re.fullmatch(UNIT_NAME, unit):
        return None
    if kind not in {*SYSTEMD_IPC_LINKS, 'log-extra-fields'}:
        return None
    if path.parent == SYSTEMD_UNITS:
        owner = SYSTEM_UID
    else:
        if kind != 'invocation':
            return None
        try:
            parts = path.parent.relative_to(SYSTEMD_USER_RUNTIME).parts
        except ValueError:
            return None
        if (len(parts) != 3 or parts[1:] != ('systemd', 'units') or
                not re.fullmatch(r'0|[1-9][0-9]*', parts[0])):
            return None
        owner = int(parts[0])
    parent = path.parent.stat()
    if parent.st_uid != owner or parent.st_mode & 0o022:
        return None
    for attempt in range(3):
        try:
            before = path.lstat()
            if before.st_uid != owner:
                return None
            if kind in SYSTEMD_IPC_LINKS:
                if not stat.S_ISLNK(before.st_mode):
                    return None
                value = os.readlink(path)
                if not re.fullmatch(SYSTEMD_IPC_LINKS[kind], value):
                    return None
                if kind == 'invocation' and value == '0' * 32:
                    return None
                if kind.startswith('log-rate-limit-'):
                    maximum = (1 << (32 if kind.endswith('burst') else 64)) - 1
                    if len(value) > len(str(maximum)) or int(value) > maximum:
                        return None
                target = path.parent / value
                try:
                    target.lstat()
                except FileNotFoundError:
                    pass
                else:
                    return None  # A real target/alias must still be inspected.
                after = path.lstat()
                if (fingerprint(before) != fingerprint(after) or
                        before.st_ctime_ns != after.st_ctime_ns or os.readlink(path) != value):
                    continue
                try:
                    target.lstat()
                except FileNotFoundError:
                    return {'kind': kind, 'state': 'ipc-value', 'value': value}
                return None
            if (not stat.S_ISREG(before.st_mode) or before.st_mode & 0o133 or
                    before.st_nlink != 1):
                return None
            data, current = read_regular(path)
            after = path.lstat()
            if (fingerprint(before) != fingerprint(current) or
                    before.st_ctime_ns != current.st_ctime_ns or
                    fingerprint(current) != fingerprint(after) or
                    current.st_ctime_ns != after.st_ctime_ns):
                continue
            # log-extra-fields is a sequence of little-endian uint64 lengths
            # and field bytes. Plain scripts, executable files and bad records
            # are not classified as metadata and retain normal source checks.
            offset, fields = 0, 0
            while offset < len(data):
                if len(data) - offset < 8:
                    return None
                length = int.from_bytes(data[offset:offset + 8], 'little')
                offset += 8
                if length < 2 or length > len(data) - offset:
                    return None
                field = data[offset:offset + length]
                name, separator, value = field.partition(b'=')
                if not separator or not re.fullmatch(rb'[A-Z][A-Z0-9_]{0,63}', name):
                    return None
                offset += length
                fields += 1
            if not fields:
                return None
            return {'kind': kind, 'state': 'ipc-fields', 'fields': fields,
                    'size': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
        except FileNotFoundError:
            try:
                path.lstat()
            except FileNotFoundError:
                current_parent = path.parent.stat()
                if current_parent.st_uid == owner and not current_parent.st_mode & 0o022:
                    return {'kind': kind, 'state': 'vanished'}
                return None
            # A replacement might now be executable source rather than metadata.
            continue
    raise RuntimeError('Changing systemd metadata preserved; no files archived: ' + str(path))


def mounted_loop_binding(s):
    # GET_STATUS64 is read-only. Match kernel inode/device identity, never the
    # truncated filename in loop_info64 or a mount's human-readable source name.
    data, _ = read_regular(MOUNTINFO)
    bindings = []
    for line in data.splitlines():
        fields = line.split()
        separator = fields.index(b'-') if b'-' in fields else -1
        need(separator >= 6 and len(fields) >= separator + 4,
             'Invalid mount inventory; no files archived.')
        device = re.fullmatch(rb'7:([0-9]+)', fields[2])
        if not device or fields[separator + 1] not in {b'ext2', b'ext3', b'ext4'}:
            continue
        minor = int(device.group(1))
        uevent, _ = read_regular(BLOCK_SYSFS / ('7:' + str(minor)) / 'uevent')
        names = re.findall(rb'^DEVNAME=(loop([0-9]+))$', uevent, re.M)
        if len(names) != 1 or b'DEVTYPE=disk' not in uevent.splitlines():
            continue
        name, index = names[0]
        path = DEVICE_ROOT / os.fsdecode(name)
        canonical(path)
        parent = path.parent.stat()
        need(parent.st_uid == SYSTEM_UID and not parent.st_mode & 0o022,
             'Unprotected loop device directory preserved.')
        fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            block = os.fstat(fd)
            need(stat.S_ISBLK(block.st_mode) and block.st_uid == SYSTEM_UID and
                 block.st_rdev == os.makedev(7, minor), 'Unexpected loop device preserved.')
            status = fcntl.ioctl(fd, 0x4C05, bytes(LOOP_STATUS.size))
            dev, ino, _, offset, limit, number, encryption, key_size, *_ = LOOP_STATUS.unpack(status)
            if (dev, ino) != (s.st_dev, s.st_ino):
                continue
            need(offset == 0 and limit in {0, s.st_size} and number == int(index) and
                 encryption == 0 and key_size == 0,
                 'Partial or encrypted storage binding preserved; no files archived.')
            bindings.append((fields[0].decode('ascii'), minor, fields[separator + 1].decode('ascii')))
        finally:
            os.close(fd)
    return tuple(sorted(bindings))


def storage_namespace(path):
    if (not any(root in path.parents for root in STORAGE_ROOTS) or
            path.suffix.lower() in SUFFIXES or path.name in NAMES or
            any(p in {'bin', 'sbin', 'etc', 'systemd', 'init.d'} or p.endswith('.d')
                for p in path.parts)):
        return False
    canonical(path)
    for parent in path.parents:
        s = parent.stat()
        if s.st_uid != SYSTEM_UID or s.st_mode & 0o022:
            return False
    return True


def mounted_storage(path, fd, before):
    # Filesystems can change while mounted. Verify stable identity/permissions
    # and live kernel binding, rather than hashing gigabytes of mutable data.
    if (not stat.S_ISREG(before.st_mode) or before.st_size <= MAX_FILE or
            not storage_namespace(path) or before.st_uid != SYSTEM_UID or
            before.st_nlink != 1 or before.st_mode & 0o133):
        return None
    header = os.pread(fd, 2048, 0)
    if len(header) != 2048 or any(header[:1024]) or header[1080:1082] != b'\x53\xef':
        return None
    sb = header[1024:]
    word = lambda offset: struct.unpack_from('<I', sb, offset)[0]
    blocks = word(4) | ((word(0x150) << 32) if word(96) & 0x80 else 0)
    log_size = word(24)
    if log_size > 6:
        return None
    block_size = 1024 << log_size
    inode_size = struct.unpack_from('<H', sb, 88)[0] if word(76) else 128
    if (not word(0) or blocks < 16 or word(20) != (1 if log_size == 0 else 0) or
            not 0 < word(32) <= 8 * block_size or not 0 < word(40) <= 8 * block_size or
            inode_size < 128 or inode_size > block_size or inode_size & (inode_size - 1) or
            not 0 <= before.st_size - blocks * block_size < block_size):
        return None
    binding = mounted_loop_binding(before)
    if not binding:
        return None
    repeated = mounted_loop_binding(before)
    after = os.fstat(fd)
    current = path.lstat()
    stable = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink, s.st_size)
    need(stable(before) == stable(after) == stable(current) and binding == repeated,
         'Changing storage identity or mount binding preserved; no files archived.')
    return {'type': 'mounted-ext-filesystem', 'dev': before.st_dev, 'ino': before.st_ino,
            'size': before.st_size, 'bindings': binding}


def swap_namespace(path):
    if (path.suffix.lower() in SUFFIXES or path.name in NAMES or
            any(p in {'home', 'root', 'etc', 'bin', 'sbin', 'systemd', 'init.d'} or p.endswith('.d')
                for p in path.parts)):
        return False
    canonical(path)
    return all(p.stat().st_uid == SYSTEM_UID and not p.stat().st_mode & 0o022 for p in path.parents)


def swap_binding(path, expected_kib):
    data, _ = read_regular(SWAPS)
    lines = data.splitlines()
    need(lines and lines[0].split() == [b'Filename', b'Type', b'Size', b'Used', b'Priority'],
         'Invalid active swap inventory; no files archived.')
    matches = []
    for line in lines[1:]:
        fields = line.split()
        need(len(fields) == 5, 'Invalid active swap entry; no files archived.')
        filename = re.sub(rb'\\([0-7]{3})', lambda m: bytes([int(m.group(1), 8)]), fields[0])
        if filename != os.fsencode(path):
            continue
        need(fields[1] == b'file' and fields[2].isdigit() and int(fields[2]) == expected_kib and
             fields[3].isdigit() and re.fullmatch(rb'-?[0-9]+', fields[4]) is not None,
             'Unreviewed active swap binding; no files archived.')
        matches.append((int(fields[2]), int(fields[4])))
    need(len(matches) <= 1, 'Ambiguous active swap binding; no files archived.')
    return tuple(matches)


def active_swap(path, fd, before):
    if (not stat.S_ISREG(before.st_mode) or before.st_size <= MAX_FILE or
            before.st_uid != SYSTEM_UID or before.st_nlink != 1 or before.st_mode & 0o133 or
            not swap_namespace(path)):
        return None
    page_size = os.sysconf('SC_PAGE_SIZE')
    header = os.pread(fd, page_size, 0)
    if (len(header) != page_size or any(header[:1024]) or header[-10:] != b'SWAPSPACE2'):
        return None
    order = '<' if struct.unpack_from('<I', header, 1024)[0] == 1 else '>'
    version, last_page, bad_pages = struct.unpack_from(order + 'III', header, 1024)
    if version != 1 or bad_pages != 0 or not 0 < last_page < before.st_size // page_size:
        return None
    binding = swap_binding(path, last_page * page_size // 1024)
    if not binding:
        return None
    repeated = swap_binding(path, last_page * page_size // 1024)
    after, current = os.fstat(fd), path.lstat()
    stable = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink, s.st_size)
    need(stable(before) == stable(after) == stable(current) and binding == repeated,
         'Changing active swap identity or binding preserved; no files archived.')
    return {'type': 'active-swap-file', 'dev': before.st_dev, 'ino': before.st_ino,
            'size': before.st_size, 'binding': binding}


def scan(home, uid, source_paths, blobs=(), inspect_processes=False,
         root_home=pathlib.Path('/root'), proc_root=pathlib.Path('/proc')):
    records, missing, references = {}, [], {name: set() for name in NAMES}
    for name in NAMES:
        path = home / name
        if not path.exists() and not path.is_symlink():
            missing.append(name)
        else:
            records[name] = candidate(path, uid)
    identities = {(r['dev'], r['ino']): n for n, r in records.items()}
    seen, seen_directories, checked, budget = set(), set(), 0, 0
    preserved_compiler_links = {}
    preserved_system_links = {}
    preserved_runtime_metadata = {}
    preserved_storage = {}
    inspected_package_launchers = {}
    unresolved_aliases = {}
    queue = list(source_paths)
    required_sources = {p.parent.resolve(strict=True) / p.name
                        for item in queue for p in [item[0] if isinstance(item, tuple) else item]}
    helper_path = pathlib.Path(__file__).resolve()
    home_entries = {p.name: p for p in home.iterdir()
                    if p.name not in NAMES and not p.name.startswith('.')
                    and (p.is_file() or p.is_symlink())
                    and (p.suffix in SUFFIXES or not p.suffix)}
    context_entries = {}
    def enqueue_sources(directory, all_files, skip=()):
        for item in sources(directory, all_files=all_files, skip=skip):
            path = item[0] if isinstance(item, tuple) else item
            required_sources.add(path.parent.resolve(strict=True) / path.name)
            queue.append(item)
    def mark(name, label):
        if name not in records:
            return
        references[name].add(label)
        queue.append(home / name)  # A live installer can invoke another installer.
    def followable(path):
        return (path.suffix.lower() not in NON_CODE and
                not virtual(path) and
                not packaged_archive(path))
    def virtual(path):
        return any(path == root or root in path.parents for root in VIRTUAL_ROOTS)
    def inspect(label, data, extra_contexts=()):
        nonlocal budget
        budget += len(data)
        need(budget <= 512 * 1024 * 1024, 'Source scan limit reached; no files archived.')
        for name in records:
            if name.encode() in data:
                mark(name, label)
            for pattern in re.findall(rb'install-[A-Za-z0-9_.?*+-]+', data):
                if (b'*' in pattern or b'?' in pattern) and fnmatch.fnmatchcase(name, os.fsdecode(pattern)):
                    mark(name, label)
        # Follow literal local script/config paths in scheduler and service wrappers.
        contexts = {p.resolve(strict=True) for p in [home, *extra_contexts] if not virtual(p)}
        if label.startswith('/'):
            contexts.add(pathlib.Path(label).parent.resolve(strict=True))
        elif label == 'crontab:root':
            contexts.add(root_home.resolve(strict=True))
        contexts = {p for p in contexts if not virtual(p)}
        def reference(path):
            # A directory is a resolution context, whether spelled directly or
            # through an alias. Only declared source roots and run-parts calls
            # authorize recursive source discovery; a pathname mention does not.
            if virtual(path):
                return
            if literal_probe(path, 'is_dir'):
                resolved = path.resolve(strict=True)
                if not virtual(resolved):
                    contexts.add(resolved)
            elif followable(path) and (literal_probe(path, 'is_file') or literal_probe(path, 'is_symlink')):
                queue.append(path)
        for name, path in home_entries.items():
            if re.search(rb'(?<![A-Za-z0-9_.-])' + re.escape(os.fsencode(name)) + rb'(?![A-Za-z0-9_.-])', data):
                reference(path)
        for raw in re.findall(rb'/[A-Za-z0-9_./+@-]+', data):
            path = pathlib.Path(os.fsdecode(raw))
            if path.name in NAMES and path.parent == home:
                mark(path.name, label)
            else:
                reference(path)
        for raw in re.findall(rb'run-parts\s+(?:--[A-Za-z0-9_=+-]+\s+)*[\'"]?(/[A-Za-z0-9_./+-]+)', data):
            directory = pathlib.Path(os.fsdecode(raw))
            if directory == home or directory in home.parents:
                for name in records:
                    mark(name, label)
            elif literal_probe(directory, 'is_dir'):
                enqueue_sources(directory, all_files=True)
        tokens = re.findall(rb'(?<![A-Za-z0-9_./-])([A-Za-z0-9_.+-]+(?:/[A-Za-z0-9_.+-]+)*)(?![A-Za-z0-9_./-])', data)
        pending_contexts, inspected_contexts = list(contexts), set()
        while pending_contexts:
            context = pending_contexts.pop()
            if context in inspected_contexts:
                continue
            inspected_contexts.add(context)
            for token in tokens:
                if token in {b'.', b'..'}:
                    continue
                key = str(context)
                if key not in context_entries:
                    context_entries[key] = {p.name for p in context.iterdir()}
                first = os.fsdecode(token).split('/', 1)[0]
                if first not in context_entries[key] and first not in {'.', '..'}:
                    continue
                path = context / os.fsdecode(token)
                if path.name in NAMES and context == home:
                    mark(path.name, label)
                else:
                    reference(path)
            pending_contexts.extend(contexts - inspected_contexts)
    for label, data in blobs:
        inspect(label, data)
        checked += 1
    if inspect_processes:
        for process in proc_root.iterdir():
            if not process.name.isdigit():
                continue
            try:
                argv = (process / 'cmdline').read_bytes()
                try:
                    contexts = [(process / 'cwd').resolve(strict=True)]
                except FileNotFoundError:
                    contexts = []
                    need(not argv or not process.exists(),
                         'Running process has an unavailable working directory; no files archived.')
                inspect('process:' + process.name, argv, contexts)
                checked += 1
                for fd in (process / 'fd').iterdir():
                    try:
                        s = fd.stat()
                        name = identities.get((s.st_dev, s.st_ino))
                        if name:
                            mark(name, 'open-file:process:' + process.name)
                    except FileNotFoundError:
                        pass
            except (FileNotFoundError, ProcessLookupError):
                pass
    while queue:
        item = queue.pop()
        path, all_files = item if isinstance(item, tuple) else (item, False)
        # Relative imports can spell the same source as a/../a/../a/file forever.
        # Normalize its parent, retaining the leaf alias and stronger scan flag:
        # application aliases must still be checked in their own source role.
        path = path.parent.resolve(strict=True) / path.name
        is_candidate = path.parent == home and path.name in NAMES
        if is_candidate and not references[path.name]:
            continue
        visit = (str(path), all_files, path in required_sources)
        if visit in seen:
            continue
        seen.add(visit)
        metadata = systemd_metadata(path)
        if metadata is not None:
            preserved_runtime_metadata[str(path)] = metadata
            checked += 1
            continue
        if path.resolve() == helper_path:
            continue  # The verified cleanup helper's own inventory is not an execution dependency.
        if path.is_symlink():
            try:
                resolved = path.resolve(strict=True)
            except FileNotFoundError:
                target = path.resolve(strict=False)
                if compiler_link(path, target):
                    preserved_compiler_links[str(path)] = str(target)
                    checked += 1
                    continue
                if missing_system_command(path, target):
                    preserved_system_links[str(path)] = str(target)
                    checked += 1
                    continue
                unresolved_aliases[str(path)] = str(target)
                checked += 1
                continue  # Finish checking other reachable sources before refusing.
            if resolved.parent == home and resolved.name in NAMES:
                mark(resolved.name, str(path))
            elif resolved == pathlib.Path('/dev/null'):
                pass  # A masked systemd unit cannot execute a historical installer.
            elif resolved.is_file():
                required_sources.add(resolved)  # A source alias cannot become opaque data.
                queue.append(resolved)
            elif resolved.is_dir():
                if resolved == home or resolved in home.parents:
                    for name in records:
                        mark(name, str(path))
                elif (str(resolved), all_files) not in seen_directories:
                    seen_directories.add((str(resolved), all_files))
                    enqueue_sources(resolved, all_files, skip=('.git', 'node_modules'))
            else:
                raise RuntimeError('Unreviewed source alias: ' + str(path))
            continue
        # Do not load system binaries as source. Unknown custom binaries are not proof of non-use.
        fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK |
                     getattr(os, 'O_NOATIME', 0))
        try:
            s = os.fstat(fd)
            need(stat.S_ISREG(s.st_mode), 'Unreviewed source type: ' + str(path))
            head = os.read(fd, 4)
            storage = (mounted_storage(path, fd, s)
                       if s.st_size > MAX_FILE and not all_files and path not in required_sources
                       else None)
            if storage is None and s.st_size > MAX_FILE and not all_files and path not in required_sources:
                storage = active_swap(path, fd, s)
            launcher = (package_launcher(path, fd, s)
                        if not all_files and path not in required_sources else None)
        finally:
            os.close(fd)
        if storage is not None:
            preserved_storage[str(path)] = storage
            checked += 1
            continue
        if head == b'\x7fELF':
            need(not (path == home or home in path.parents or str(path).startswith('/root/')),
                 'Custom binary wrapper requires review: ' + str(path))
            continue
        if launcher is not None:
            data = launcher
            inspected_package_launchers[str(path)] = {
                'source_bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
        else:
            data, s = read_regular(path)
        if is_candidate:
            record = dict(fingerprint(s), sha256=hashlib.sha256(data).hexdigest())
            need(record == records[path.name], 'Live installer changed during scan; no files archived.')
        inspect(str(path), data)
        checked += 1
    if unresolved_aliases:
        details = {'unresolved_count': len(unresolved_aliases),
                   'aliases': dict(sorted(unresolved_aliases.items())[:50]),
                   'truncated': len(unresolved_aliases) > 50}
        raise RuntimeError('Missing dependency alias preserved; dependency check incomplete: ' +
                           json.dumps(details) + '; no files archived.')
    return {'records': records, 'missing': missing, 'sources_checked': checked,
            'preserved_compiler_links': dict(sorted(preserved_compiler_links.items())),
            'preserved_system_links': dict(sorted(preserved_system_links.items())),
            'preserved_runtime_metadata': dict(sorted(preserved_runtime_metadata.items())),
            'preserved_storage': dict(sorted(preserved_storage.items())),
            'inspected_package_launchers': dict(sorted(inspected_package_launchers.items())),
            'referenced': {n: sorted(v) for n, v in references.items() if v},
            'eligible': sorted(n for n in records if not references[n]),
            'scope': 'Inspected local literal/wildcard source dependencies and observed processes; compiled programs/archive contents, computed launch paths and external automation are not established.'}


def host_scan(home, uid):
    private = home / '.sitesee-real-estate'
    need((private / 'server').is_dir() and (home / 'public_html/re').is_dir(),
         'Expected active application roots missing; no files archived.')
    paths = list(sources(home / 'public_html', skip=('.git', 'node_modules')))
    paths += list(sources(private, skip=('unified-deployments', 'deployment-backups',
                  'data', 'sessions', 'customer-sessions', 'vendor-sessions', 'pricing-sessions')))
    paths += list(sources(home / 'ctc-publishing', skip=('.git', 'node_modules', 'data', 'backups')))
    for directory in ['/etc/cron.d', '/etc/cron.hourly', '/etc/cron.daily', '/etc/cron.weekly',
                      '/etc/cron.monthly', '/var/spool/cron', '/etc/apache2/conf',
                      '/etc/apache2/conf.d', '/var/cpanel/userdata/sitesee',
                      '/etc/systemd/system', '/usr/lib/systemd/system', '/run/systemd/system']:
        # Unit enablement aliases point to files scanned in their unit directory.
        paths += list(sources(pathlib.Path(directory), all_files=True))
    for directory in ['/usr/local/lib/systemd/system', '/usr/local/lib/systemd/user',
                      '/run/systemd/transient', '/run/systemd/generator',
                      '/run/systemd/generator.early', '/run/systemd/generator.late',
                      '/run/systemd/user', '/etc/systemd/user', '/usr/lib/systemd/user',
                      '/var/spool/at', '/var/spool/atjobs',
                      str(home / '.config/systemd/user'), '/root/.config/systemd/user']:
        paths += list(sources(pathlib.Path(directory), all_files=True))
    for user_id in {0, uid}:
        for name in ['user', 'transient', 'generator', 'generator.early', 'generator.late']:
            paths += list(sources(pathlib.Path('/run/user') / str(user_id) / 'systemd' / name, all_files=True))
    for filename in ['/etc/crontab', '/etc/anacrontab']:
        path = pathlib.Path(filename)
        if path.exists():
            paths.append(path)
    blobs = []
    for user in ['root', 'sitesee']:
        result = subprocess.run(['crontab', '-u', user, '-l'], capture_output=True, timeout=30)
        need(result.returncode == 0 or (result.returncode == 1 and b'no crontab for' in result.stderr.lower()),
             'Cannot read crontab for ' + user + '; no files archived.')
        blobs.append(('crontab:' + user, result.stdout if result.returncode == 0 else b''))
    return scan(home, uid, paths, blobs, inspect_processes=True)


def checked_host_scan(home, uid):
    # Arm before eager inventory as well as graph traversal; disarm before any
    # archive or restore operation. Progress contains no source/argument values.
    need(signal.getitimer(signal.ITIMER_REAL) == (0.0, 0.0),
         'Existing process timer preserved; no files archived.')
    need(SCAN_SECONDS > 0 and SCAN_HEARTBEAT_SECONDS > 0, 'Invalid scan time limit.')
    started = time.monotonic()
    def progress(signum, frame):
        elapsed = time.monotonic() - started
        stage = 'dependency scan'
        while frame:
            if frame.f_code.co_filename == __file__:
                stage = frame.f_code.co_name + ':' + str(frame.f_lineno)
                break
            frame = frame.f_back
        if elapsed >= SCAN_SECONDS:
            raise RuntimeError('Dependency check incomplete: exceeded ' + str(SCAN_SECONDS) +
                               ' seconds at ' + stage + '; no files archived.')
        os.write(2, ('Dependency check: ' + str(int(elapsed)) +
                     ' seconds; stage ' + stage + '; no files moved.\n').encode())
    previous = signal.signal(signal.SIGALRM, progress)
    try:
        os.write(2, b'Checking local files and dependencies; no files moved.\n')
        interval = min(SCAN_SECONDS, SCAN_HEARTBEAT_SECONDS)
        signal.setitimer(signal.ITIMER_REAL, interval, interval)
        return host_scan(home, uid)
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, previous)


def sync_directory(path):
    fd = os.open(str(path), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_journal(folder, journal):
    fd, filename = tempfile.mkstemp(prefix='.journal-', dir=str(folder))
    try:
        with os.fdopen(fd, 'w') as output:
            json.dump(journal, output, sort_keys=True, indent=2)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
        os.replace(filename, folder / 'manifest.json')
        sync_directory(folder)
    finally:
        if os.path.exists(filename):
            os.unlink(filename)


def rename_new(source, destination):
    libc = ctypes.CDLL(None, use_errno=True)
    rename = getattr(libc, 'renameat2', None)
    need(rename is not None, 'Atomic move without replacement unavailable; files preserved.')
    rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    rename.restype = ctypes.c_int
    if rename(-100, os.fsencode(source), -100, os.fsencode(destination), 1) != 0:
        raise OSError(ctypes.get_errno(), 'Atomic move stopped; existing paths preserved.')


def archive(home, uid, report):
    eligible = report['eligible']
    if not eligible:
        return None
    # Revalidate every original before creating the archive or moving any file.
    for name in eligible:
        need(name in NAMES and candidate(home / name, uid) == report['records'][name],
             'Candidate changed after scan: ' + name + '; no files archived.')
    base = home / 'private-archives'
    canonical(base)
    base.mkdir(mode=0o700, exist_ok=True)
    sync_directory(home)
    need(base.is_dir() and base.stat().st_uid in {0, uid} and not base.stat().st_mode & 0o022,
         'Unexpected archive parent preserved.')
    need(base.stat().st_dev == home.stat().st_dev, 'Archive must be on the same filesystem.')
    prefix = 'sitesee-legacy-installers-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-'
    folder = pathlib.Path(tempfile.mkdtemp(prefix=prefix, dir=str(base)))
    sync_directory(base)
    journal = {'format': 1, 'home': str(home), 'uid': uid, 'state': 'prepared',
               'scan': {k: v for k, v in report.items() if k != 'records'},
               'files': {n: dict(report['records'][n], state='planned') for n in eligible}}
    write_journal(folder, journal)
    print('Prepared archive: ' + str(folder), flush=True)
    for name in eligible:
        expected = report['records'][name]
        need(candidate(home / name, uid) == expected, 'Changed candidate preserved: ' + name)
        rename_new(home / name, folder / name)
        sync_directory(home)
        sync_directory(folder)
        if candidate(folder / name, uid) != expected:
            # A path/content race is preserved and reversed only if its old slot is still empty.
            rename_new(folder / name, home / name)
            sync_directory(home)
            sync_directory(folder)
            journal['state'] = 'changed-file-preserved'
            write_journal(folder, journal)
            raise RuntimeError('Changed file returned to original location; preserve archive journal.')
        journal['files'][name]['state'] = 'archived'
        write_journal(folder, journal)
    journal['state'] = 'complete'
    write_journal(folder, journal)
    return folder


def restore(home, uid, folder):
    canonical(folder)
    need(folder.parent == home / 'private-archives' and folder.name.startswith('sitesee-legacy-installers-'),
         'Restore accepts only a private installer archive.')
    need(folder.is_dir() and folder.stat().st_uid == os.geteuid() and not folder.stat().st_mode & 0o077,
         'Archive ownership/mode differs; preserved.')
    raw, s = read_regular(folder / 'manifest.json')
    need(s.st_uid == os.geteuid() and s.st_nlink == 1 and not s.st_mode & 0o077,
         'Journal ownership/mode differs; preserved.')
    journal = json.loads(raw)
    need(journal.get('format') == 1 and journal.get('home') == str(home) and journal.get('uid') == uid
         and isinstance(journal.get('files'), dict) and set(journal['files']).issubset(NAMES),
         'Unknown restore manifest preserved.')
    todo = []
    for name, saved in journal['files'].items():
        expected = {key: saved[key] for key in (*META, 'sha256')}
        original, archived = home / name, folder / name
        if not archived.exists() and not archived.is_symlink():
            need(saved['state'] in {'planned', 'archived', 'restored'} and candidate(original, uid) == expected,
                 'Missing archived file preserved: ' + name)
            continue
        need(not original.exists() and not original.is_symlink(), 'Existing original preserved: ' + name)
        need(candidate(archived, uid) == expected, 'Changed archive preserved: ' + name)
        todo.append((name, expected))
    for name, expected in todo:
        need(candidate(folder / name, uid) == expected, 'Changed archive preserved: ' + name)
        rename_new(folder / name, home / name)
        sync_directory(home)
        sync_directory(folder)
        need(candidate(home / name, uid) == expected, 'Restored file differs; preserve originals and journal.')
        journal['files'][name]['state'] = 'restored'
        write_journal(folder, journal)
    journal['state'] = 'restored'
    write_journal(folder, journal)
    return len(todo)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check', action='store_true')
    actions.add_argument('--archive', action='store_true')
    actions.add_argument('--restore', type=pathlib.Path)
    args = parser.parse_args()
    need(os.geteuid() == 0, 'Run in WHM Terminal as root.')
    canonical(HOME)
    account = pwd.getpwnam('sitesee')
    need(HOME.stat().st_uid == account.pw_uid, 'Unexpected home owner; preserved.')
    if args.check:
        report = checked_host_scan(HOME, account.pw_uid)
        print(json.dumps({'result': 'DEPENDENCY CHECK PASS', **report}, indent=2))
        return
    base = HOME / 'private-archives'
    canonical(base)
    base.mkdir(mode=0o700, exist_ok=True)
    sync_directory(HOME)
    need(base.is_dir() and base.stat().st_uid in {0, account.pw_uid} and not base.stat().st_mode & 0o022,
         'Unexpected archive parent preserved.')
    lock = os.open(str(base / '.legacy-installers.lock'), os.O_CREAT | os.O_RDONLY |
                   os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    try:
        s = os.fstat(lock)
        need(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_nlink == 1 and not s.st_mode & 0o077,
             'Unexpected cleanup lock preserved.')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.restore:
            count = restore(HOME, account.pw_uid, args.restore)
            print(json.dumps({'result': 'RESTORE PASS', 'restored': count, 'archive': str(args.restore)}))
            return
        report = checked_host_scan(HOME, account.pw_uid)
        folder = archive(HOME, account.pw_uid, report)
        print(json.dumps({'result': 'ARCHIVE PASS' if folder else 'NO ELIGIBLE FILES',
                          'archived': len(report['eligible']), 'archive': str(folder) if folder else None,
                          'referenced_preserved': report['referenced'], 'already_absent': report['missing'],
                          'preserved_compiler_links': report['preserved_compiler_links'],
                          'preserved_system_links': report['preserved_system_links'],
                          'preserved_runtime_metadata': report['preserved_runtime_metadata'],
                          'preserved_storage': report['preserved_storage'],
                          'inspected_package_launchers': report['inspected_package_launchers'],
                          'sources_checked': report['sources_checked'], 'scope': report['scope']}))
        if folder:
            print('Restore: python3 ' + __file__ + ' --restore ' + str(folder))
    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Print no configuration contents, environment values, process arguments or provider details.
        detail = str(error) if isinstance(error, RuntimeError) else type(error).__name__
        if isinstance(error, OSError):
            detail = json.dumps({'error': type(error).__name__, 'errno': error.errno,
                                 'path': os.fsdecode(error.filename) if error.filename is not None else None})
        print('STOPPED: ' + detail +
              '. Preserve originals and any archive journal; no deletion or overwrite is performed.')
        raise SystemExit(1)
