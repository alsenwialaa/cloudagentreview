#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -nE 's/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' "$ROOT/yassin-ai-assistant.php")"
[[ -n "$VERSION" ]] || { echo "Unable to read the plugin release version." >&2; exit 1; }

PROFILE="${YSAI_PACKAGE_PROFILE:-release}"
case "$PROFILE" in
  release)
    DEFAULT_SUFFIX="rebuilt"
    ;;
  candidate)
    DEFAULT_SUFFIX="candidate"
    ;;
  *)
    echo "YSAI_PACKAGE_PROFILE must be either release or candidate." >&2
    exit 1
    ;;
esac

OUTPUT="${1:-$ROOT/yassin-ai-assistant-${VERSION}-${DEFAULT_SUFFIX}.zip}"
[[ "$OUTPUT" == *.zip ]] || { echo "Package output must end in .zip." >&2; exit 1; }
OUTPUT="$(python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$OUTPUT")"
EXPECTED_BASENAME="yassin-ai-assistant-${VERSION}-${DEFAULT_SUFFIX}.zip"
if [[ "$(basename "$OUTPUT")" != "$EXPECTED_BASENAME" ]]; then
  echo "The $PROFILE package must be named exactly $EXPECTED_BASENAME." >&2
  exit 1
fi
CHECKSUM="${OUTPUT%.zip}.sha256"

source_digest() {
  python3 - "$ROOT" <<'PYDIGEST'
import hashlib
import pathlib
import sys

root = pathlib.Path(sys.argv[1]).resolve()
skip_dirs = {'.git', '.svn', '.hg', 'node_modules', 'vendor', '__pycache__', '.pytest_cache'}
skip_suffixes = {'.zip', '.sha256'}
digest = hashlib.sha256()
for path in sorted(root.rglob('*'), key=lambda candidate: candidate.relative_to(root).as_posix()):
    relative = path.relative_to(root)
    if any(part in skip_dirs for part in relative.parts):
        continue
    if path.is_symlink():
        raise SystemExit(f'Symbolic link found while hashing package source: {relative.as_posix()}')
    if not path.is_file() or path.suffix in skip_suffixes:
        continue
    encoded_path = relative.as_posix().encode('utf-8')
    content = path.read_bytes()
    digest.update(len(encoded_path).to_bytes(8, 'big'))
    digest.update(encoded_path)
    digest.update(len(content).to_bytes(8, 'big'))
    digest.update(content)
print(digest.hexdigest())
PYDIGEST
}

SOURCE_BEFORE="$(source_digest)"
if [[ "$PROFILE" == "release" ]]; then
  # A production-labelled package may only be created after the exact source
  # passes the real WordPress/WooCommerce/database fault suite, the live stable
  # Gemini contract, and the required Chromium contract. Missing environment or
  # credentials is a hard failure, not a skipped success.
  bash "$ROOT/scripts/verify-live-matrix.sh"
else
  # Candidate packages are intentionally explicit and receive a candidate
  # filename. They remain useful for code review and staging preparation, but
  # must never be represented as production-approved.
  bash "$ROOT/scripts/verify.sh"
fi
SOURCE_AFTER="$(source_digest)"
if [[ "$SOURCE_BEFORE" != "$SOURCE_AFTER" ]]; then
  echo "The package source changed while verification was running." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/yassin-ai-assistant"

(
  cd "$ROOT"
  tar \
    --exclude='./.git' \
    --exclude='./.svn' \
    --exclude='./.hg' \
    --exclude='./node_modules' \
    --exclude='./vendor' \
    --exclude='./__pycache__' \
    --exclude='./*.pyc' \
    --exclude='./*.zip' \
    --exclude='./*.sha256' \
    -cf - .
) | (cd "$STAGE/yassin-ai-assistant" && tar -xf -)

mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT" "$CHECKSUM"
(cd "$STAGE" && zip -q -r "$OUTPUT" yassin-ai-assistant)
unzip -tq "$OUTPUT" >/dev/null

python3 - "$OUTPUT" <<'PYZIP'
import pathlib
import stat
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
with zipfile.ZipFile(archive) as package:
    names = [info.filename for info in package.infolist()]
    if len(names) != len(set(names)):
        raise SystemExit('Duplicate ZIP entry found.')
    for info in package.infolist():
        name = info.filename
        pure = pathlib.PurePosixPath(name)
        if not name.startswith('yassin-ai-assistant/') or name.startswith('/') or '\\' in name or '..' in pure.parts:
            raise SystemExit(f'Unsafe ZIP path: {name}')
        mode = info.external_attr >> 16
        if stat.S_ISLNK(mode):
            raise SystemExit(f'Symbolic link ZIP entry found: {name}')
PYZIP

EXTRACT="$(mktemp -d)"
trap 'rm -rf "$STAGE" "$EXTRACT"' EXIT
unzip -q "$OUTPUT" -d "$EXTRACT"
diff -qr "$STAGE/yassin-ai-assistant" "$EXTRACT/yassin-ai-assistant" >/dev/null
(
  cd "$EXTRACT/yassin-ai-assistant"
  env \
    -u YSAI_PACKAGE_PROFILE \
    -u YSAI_WP_PATH \
    -u YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION \
    -u YSAI_GEMINI_API_KEY \
    -u YSAI_REQUIRE_GEMINI_V1_CONTRACT \
    -u YSAI_RUN_BROWSER_E2E \
    -u YSAI_REQUIRE_BROWSER_E2E \
    bash scripts/verify.sh
)

(
  cd "$(dirname "$OUTPUT")"
  sha256sum "$(basename "$OUTPUT")" > "$(basename "$CHECKSUM")"
  sha256sum -c "$(basename "$CHECKSUM")" >/dev/null
)

echo "Created: $OUTPUT"
echo "Checksum: $CHECKSUM"
echo "Package profile: $PROFILE"
echo "Verified source digest: $SOURCE_AFTER"
echo "Fresh extraction: verified and byte-for-byte matched to the staged package tree."
if [[ "$PROFILE" == "candidate" ]]; then
  echo "Candidate warning: live WooCommerce/database and credentialed Gemini acceptance were not required; this archive is not production-approved." >&2
fi
