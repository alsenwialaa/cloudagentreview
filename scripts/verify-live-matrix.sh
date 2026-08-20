#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

: "${YSAI_WP_PATH:?YSAI_WP_PATH must point to a disposable WordPress installation.}"
: "${YSAI_GEMINI_API_KEY:?YSAI_GEMINI_API_KEY must contain an acceptance-test key.}"

export YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION=1
export YSAI_REQUIRE_GEMINI_V1_CONTRACT=1
export YSAI_REQUIRE_BROWSER_E2E=1
export YSAI_RUN_BROWSER_E2E=1

bash scripts/verify.sh
