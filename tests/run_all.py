"""Run every automated unit test in tests/ with one command.

    python tests/run_all.py            (from the project root or anywhere)
    python tests/run_all.py -v         (verbose)

The test modules read project files by relative path, import `lpr_engine`
from the project root and import helpers from sibling test modules, so both
folders must be on sys.path and the working directory must be the project
root. Running `python -m unittest tests.test_x` directly does not set this up.

Manual check scripts (test_plate_detection, test_gpt_vision,
test_lprnet_pipeline, test_quad_ensemble) are not unit tests and are skipped;
run them directly as described in docs/PROJECT_MAP.md.
"""
import os
import sys
import unittest

TESTS_DIR = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(TESTS_DIR)
MANUAL_SCRIPTS = {'test_plate_detection', 'test_gpt_vision', 'test_lprnet_pipeline', 'test_quad_ensemble'}


def main() -> int:
    os.chdir(ROOT)
    for path in (TESTS_DIR, ROOT):
        if path not in sys.path:
            sys.path.insert(0, path)

    verbosity = 2 if '-v' in sys.argv[1:] else 1
    loader = unittest.TestLoader()
    suite = unittest.TestSuite()
    for name in sorted(os.listdir(TESTS_DIR)):
        module, ext = os.path.splitext(name)
        if ext == '.py' and module.startswith('test_') and module not in MANUAL_SCRIPTS:
            suite.addTests(loader.loadTestsFromName(module))

    result = unittest.TextTestRunner(verbosity=verbosity).run(suite)
    return 0 if result.wasSuccessful() else 1


if __name__ == '__main__':
    sys.exit(main())
