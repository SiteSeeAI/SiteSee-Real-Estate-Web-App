"""Historical installer fixtures keep testing the actual frozen deployed release."""
from pathlib import Path
import sys,tempfile
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
from unified_source import before_unified_update
_cache=tempfile.TemporaryDirectory(prefix='sitesee-historical-source-')
ROOT=Path(__file__).resolve().parents[1]
def historical(path):
 path=Path(path)
 frozen=ROOT/'tests/fixtures/lifecycle-before'/path.name
 if '_private' not in path.parts:return path
 if frozen.is_file():return frozen
 source=path.resolve().relative_to(ROOT).as_posix();raw=before_unified_update(ROOT,source)
 if raw==path.read_bytes():return path
 dest=Path(_cache.name)/source;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(raw);return dest
