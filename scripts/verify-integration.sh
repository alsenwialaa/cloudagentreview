#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${YSAI_WP_PATH:?Set YSAI_WP_PATH to a disposable local/development/staging WordPress root.}"
WP_CLI_BIN="${YSAI_WP_CLI_BIN:-wp}"
EXPECTED_VERSION="$(sed -nE 's/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' "$ROOT/yassin-ai-assistant.php")"
[[ -n "$EXPECTED_VERSION" ]] || {
  echo "Unable to read the expected plugin version." >&2
  exit 1
}

command -v "$WP_CLI_BIN" >/dev/null 2>&1 || {
  echo "WP-CLI executable not found: $WP_CLI_BIN" >&2
  exit 1
}
[[ -f "$YSAI_WP_PATH/wp-load.php" ]] || {
  echo "YSAI_WP_PATH is not a WordPress root: $YSAI_WP_PATH" >&2
  exit 1
}

export YSAI_INTEGRATION_ALLOW_DESTRUCTIVE=1
export YSAI_EXPECTED_PLUGIN_ROOT="$ROOT"
export YSAI_EXPECTED_PLUGIN_VERSION="$EXPECTED_VERSION"

wp_args=(--path="$YSAI_WP_PATH" --skip-themes)
if [[ "${EUID:-$(id -u)}" == "0" ]]; then
  wp_args+=(--allow-root)
fi

"$WP_CLI_BIN" "${wp_args[@]}" eval-file "$ROOT/tests/Integration/woocommerce-session-faults.php"
