#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
VERSION="$(sed -nE 's/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' "$ROOT/yassin-ai-assistant.php")"
[[ -n "$VERSION" ]] || { echo "Unable to read the plugin release version." >&2; exit 1; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The default packaging path is the production release path and must fail
# before creating an archive when its live acceptance environment is absent.
set +e
env \
  -u YSAI_PACKAGE_PROFILE \
  -u YSAI_WP_PATH \
  -u YSAI_GEMINI_API_KEY \
  -u YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION \
  -u YSAI_REQUIRE_GEMINI_V1_CONTRACT \
  -u YSAI_RUN_BROWSER_E2E \
  -u YSAI_REQUIRE_BROWSER_E2E \
  bash scripts/package.sh "$work/yassin-ai-assistant-${VERSION}-rebuilt.zip" >"$work/default.out" 2>&1
default_status=$?
set -e
if [[ "$default_status" -eq 0 ]]; then
  echo "Default production packaging succeeded without the mandatory live matrix." >&2
  exit 1
fi
if [[ -e "$work/yassin-ai-assistant-${VERSION}-rebuilt.zip" \
      || -e "$work/yassin-ai-assistant-${VERSION}-rebuilt.sha256" ]]; then
  echo "Default production packaging left an artifact after a failed live gate." >&2
  exit 1
fi
if ! grep -Eq 'YSAI_WP_PATH|YSAI_GEMINI_API_KEY' "$work/default.out"; then
  echo "Default production packaging did not fail for a missing live acceptance dependency." >&2
  cat "$work/default.out" >&2
  exit 1
fi

set +e
YSAI_PACKAGE_PROFILE=unsupported \
  bash scripts/package.sh "$work/invalid-profile.zip" >"$work/invalid.out" 2>&1
invalid_status=$?
set -e
if [[ "$invalid_status" -eq 0 ]] || ! grep -Fq 'must be either release or candidate' "$work/invalid.out"; then
  echo "The package profile boundary did not fail closed." >&2
  cat "$work/invalid.out" >&2
  exit 1
fi

# A candidate build must not be allowed to take a production-looking release
# filename. Artifact classification is part of the fail-closed release boundary,
# not merely a convention in the default output path.
set +e
YSAI_PACKAGE_PROFILE=candidate \
  bash scripts/package.sh "$work/yassin-ai-assistant-${VERSION}-rebuilt.zip" >"$work/mislabeled.out" 2>&1
mislabeled_status=$?
set -e
if [[ "$mislabeled_status" -eq 0 ]]; then
  echo "Candidate packaging accepted a production-looking release filename." >&2
  exit 1
fi
if [[ -e "$work/yassin-ai-assistant-${VERSION}-rebuilt.zip" \
      || -e "$work/yassin-ai-assistant-${VERSION}-rebuilt.sha256" ]]; then
  echo "Mislabeled candidate packaging left an artifact after rejection." >&2
  exit 1
fi
if ! grep -Fq 'candidate.zip' "$work/mislabeled.out"; then
  echo "Candidate filename rejection did not explain the required candidate label." >&2
  cat "$work/mislabeled.out" >&2
  exit 1
fi

echo "Production packaging gate: fail-closed profile behavior passed."
