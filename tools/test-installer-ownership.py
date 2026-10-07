#!/usr/bin/env python3
"""Require actual host privileges and complete the preserved ownership suites."""
import os
import importlib.util
from pathlib import Path
import unittest


if __name__ == '__main__':
    if os.geteuid() != 0:
        raise SystemExit('Historical installer ownership checks require real root privileges.')
    root = Path(__file__).resolve().parents[1]
    os.chdir(root)
    suite = unittest.defaultTestLoader.discover(str(root / 'tests'), pattern='*_test.py')
    # This preserved portal script uses the other filename convention and also
    # contains a real application-user ownership case.
    spec = importlib.util.spec_from_file_location('portal_re_enrollment_checks', root / 'tests/portal-re-enrollment.test.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    suite.addTests(unittest.defaultTestLoader.loadTestsFromModule(module))
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if result.skipped:
        print('STOP: privileged installer validation skipped cases; ownership acceptance is incomplete.')
        raise SystemExit(1)
    if result.testsRun == 0 or not result.wasSuccessful():
        raise SystemExit(1)
    print(f'PASS: {result.testsRun} privileged installer cases completed; no skips.')
