#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

command -v python3 >/dev/null 2>&1 || { echo "python3 is required for the browser contract." >&2; exit 2; }
command -v timeout >/dev/null 2>&1 || { echo "The timeout command is required for bounded browser verification." >&2; exit 2; }
python3 - <<'PY'
try:
    import playwright.sync_api  # noqa: F401
except Exception as error:
    raise SystemExit(f"Python Playwright is required for the browser contract: {error}")
PY

BROWSER_TIMEOUT_SECONDS="${YSAI_BROWSER_TEST_TIMEOUT_SECONDS:-300}"
if [[ ! "$BROWSER_TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] \
  || (( BROWSER_TIMEOUT_SECONDS < 30 || BROWSER_TIMEOUT_SECONDS > 900 )); then
  echo "YSAI_BROWSER_TEST_TIMEOUT_SECONDS must be an integer from 30 through 900." >&2
  exit 2
fi

run_browser_contract() {
  local test_file="$1"
  if timeout --signal=TERM --kill-after=10 "${BROWSER_TIMEOUT_SECONDS}s" python3 "$test_file"; then
    return 0
  else
    local status=$?
    if [[ "$status" -eq 124 || "$status" -eq 137 ]]; then
      echo "Browser contract exceeded ${BROWSER_TIMEOUT_SECONDS}s: $test_file" >&2
    fi
    return "$status"
  fi
}

run_browser_contract tests/browser/widget-e2e.py
run_browser_contract tests/browser/widget-runtime-e2e.py
run_browser_contract tests/browser/admin-appearance-e2e.py
