#!/usr/bin/env bash
# The gate's own suite (test-guard.py) is Python, and the tooling gate only runs
# *.test.sh — so until this wrapper existed, nothing ran it. A test nothing runs
# is not a test.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$HERE/../test-guard.py"
