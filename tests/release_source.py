"""Historical installer fixtures keep testing the actual frozen deployed release."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def historical(path):
 path=Path(path)
 frozen=ROOT/'tests/fixtures/lifecycle-before'/path.name
 return frozen if '_private' in path.parts and frozen.is_file() else path
