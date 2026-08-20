#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

required=(
  yassin-ai-assistant.php
  uninstall.php
  README.md
  readme.txt
  LICENSE
  src/Plugin.php
  assets/js/widget.js
  assets/css/widget.css
  templates/widget.php
  tests/run.php
  src/Application/Catalog/CatalogSearchResult.php
  src/Infrastructure/Ai/FunctionToolValidator.php
  src/Infrastructure/Ai/GeminiSchemaProjector.php
  src/Infrastructure/WordPress/WidgetAppearance.php
  tests/Unit/ChatProviderFlowTest.php
  tests/Unit/GeminiSchemaProjectorTest.php
  tests/Unit/WidgetAppearanceTest.php
  tests/Integration/woocommerce-session-faults.php
  tests/Integration/gemini-v1-contract.php
  tests/Contract/Fixtures/gemini-v1/structured-completed.json
  tests/Contract/Fixtures/gemini-v1/function-requires-action.json
  tests/accessibility/widget-static.test.js
  tests/browser/widget-e2e.py
  tests/browser/widget-runtime-e2e.py
  tests/browser/widget-harness.php
  tests/browser/admin-appearance-e2e.py
  tests/browser/admin-appearance-harness.php
  scripts/verify-integration.sh
  scripts/verify-gemini-v1.sh
  scripts/verify-gemini-v1-fixtures.sh
  scripts/verify-concurrency.sh
  scripts/verify-accessibility.sh
  scripts/verify-browser.sh
  scripts/verify-live-matrix.sh
  scripts/verify-release-gate.sh
  scripts/verify-chat-flow-contract.py
  scripts/verify-widget-ui-contract.py
  scripts/verify-phase-one-contract.py
)

for file in "${required[@]}"; do
  [[ -f "$file" ]] || { echo "Missing required file: $file" >&2; exit 1; }
done

if [[ "${YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION:-0}" == "1" && -z "${YSAI_WP_PATH:-}" ]]; then
  echo "YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION=1 requires YSAI_WP_PATH." >&2
  exit 1
fi
if [[ "${YSAI_REQUIRE_GEMINI_V1_CONTRACT:-0}" == "1" && -z "${YSAI_GEMINI_API_KEY:-}" ]]; then
  echo "YSAI_REQUIRE_GEMINI_V1_CONTRACT=1 requires YSAI_GEMINI_API_KEY." >&2
  exit 1
fi
if [[ "${YSAI_REQUIRE_BROWSER_E2E:-0}" == "1" ]]; then
  export YSAI_RUN_BROWSER_E2E=1
fi

plugin_version="$(sed -nE 's/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' yassin-ai-assistant.php)"
constant_version="$(sed -nE "s/^define\('YSAI_VERSION', '([0-9]+\.[0-9]+\.[0-9]+)'\);$/\1/p" yassin-ai-assistant.php)"
stable_version="$(sed -nE 's/^Stable tag: ([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' readme.txt)"
readme_changelog_version="$(sed -nE '/^= ([0-9]+\.[0-9]+\.[0-9]+) =$/{s//\1/;p;q;}' readme.txt)"
changelog_version="$(sed -nE '/^## ([0-9]+\.[0-9]+\.[0-9]+)( .*)?$/{s//\1/;p;q;}' CHANGELOG.md)"
package_version="$(php -r '$p=json_decode(file_get_contents("package.json"),true,8,JSON_THROW_ON_ERROR); echo is_string($p["version"]??null)?$p["version"]:"";')"
schema_version="$(sed -nE "s/^    public const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';$/\1/p" src/Infrastructure/Database/Schema.php)"
widget_version="$(sed -nE "s/^\} from '\.\/client-utils\.js\?ver=([0-9]+\.[0-9]+\.[0-9]+)';$/\1/p" assets/js/widget.js)"
test_version="$(sed -nE "s/^    define\('YSAI_VERSION', '([0-9]+\.[0-9]+\.[0-9]+)-test'\);$/\1/p" tests/bootstrap.php)"
for version in "$plugin_version" "$constant_version" "$stable_version" "$readme_changelog_version" "$changelog_version" "$package_version" "$schema_version" "$widget_version" "$test_version"; do
  [[ -n "$version" && "$version" == "$plugin_version" ]] || {
    echo "Release version metadata is missing or inconsistent." >&2
    exit 1
  }
done
echo "Release version: $plugin_version"

if find . -type d \( -name .git -o -name .svn -o -name .hg -o -name node_modules -o -name vendor -o -name __pycache__ -o -name .pytest_cache \) -print -quit | grep -q .; then
  echo "Generated dependency, cache, or version-control directory found." >&2
  exit 1
fi

if find . -type f \( -name .gitignore -o -name .gitattributes -o -name composer.lock -o -name package-lock.json -o -name yarn.lock -o -name pnpm-lock.yaml -o -name '*.pyc' -o -name .DS_Store -o -name Thumbs.db \) -print -quit | grep -q .; then
  echo "Version-control, cache, or generated lock artifact found." >&2
  exit 1
fi

if find . -type l -print -quit | grep -q .; then
  echo "Symbolic links are not permitted in the release package." >&2
  exit 1
fi

mapfile -d '' php_files < <(find . -type f -name '*.php' -print0 | sort -z)
for file in "${php_files[@]}"; do
  php -l "$file" >/dev/null
done
echo "PHP syntax: ${#php_files[@]} files"

mapfile -d '' js_files < <(find assets tests -type f -name '*.js' -print0 | sort -z)
for file in "${js_files[@]}"; do
  node --check "$file" >/dev/null
done
echo "JavaScript syntax: ${#js_files[@]} files"

mapfile -d '' shell_files < <(find scripts -type f -name '*.sh' -print0 | sort -z)
for file in "${shell_files[@]}"; do
  bash -n "$file"
done
echo "Shell syntax: ${#shell_files[@]} files"

mapfile -d '' python_files < <(find tests scripts -type f -name '*.py' -print0 | sort -z)
for file in "${python_files[@]}"; do
  python3 - "$file" <<'PYSYNTAX'
import pathlib
import sys
path = pathlib.Path(sys.argv[1])
compile(path.read_text(encoding='utf-8'), str(path), 'exec')
PYSYNTAX
done
echo "Python syntax: ${#python_files[@]} files"

python3 scripts/verify-chat-flow-contract.py
python3 scripts/verify-widget-ui-contract.py
python3 scripts/verify-phase-one-contract.py
php tests/run.php
node --test tests/js/*.test.js
bash scripts/verify-concurrency.sh
bash scripts/verify-accessibility.sh
bash scripts/verify-gemini-v1-fixtures.sh
bash scripts/verify-release-gate.sh
python3 scripts/verify-chat-flow-contract.py
python3 scripts/verify-widget-ui-contract.py
python3 scripts/verify-phase-one-contract.py

browser_status=0
if [[ "${YSAI_RUN_BROWSER_E2E:-0}" == "1" ]]   || { command -v chromium >/dev/null 2>&1 && python3 -c 'import playwright.sync_api' >/dev/null 2>&1; }; then
  set +e
  bash scripts/verify-browser.sh
  browser_status=$?
  set -e
  if [[ "$browser_status" -eq 1 ]]; then
    echo "Browser contract executed and failed." >&2
    exit 1
  fi
  if [[ "$browser_status" -ne 0 && "${YSAI_REQUIRE_BROWSER_E2E:-0}" == "1" ]]; then
    echo "The mandatory browser contract could not run." >&2
    exit "$browser_status"
  fi
  if [[ "$browser_status" -ne 0 ]]; then
    echo "Chromium widget contract: unavailable in this environment (set YSAI_REQUIRE_BROWSER_E2E=1 to fail closed)."
  fi
elif [[ "${YSAI_REQUIRE_BROWSER_E2E:-0}" == "1" ]]; then
  echo "The mandatory browser contract dependencies are unavailable." >&2
  exit 2
else
  echo "Chromium widget contract: not available in this environment."
fi

if grep -RIn --include='*.php' --include='*.md' --include='*.txt' 'generativelanguage.googleapis.com/v1beta/interactions' src docs README.md readme.txt; then
  echo "Gemini beta Interactions endpoint reference found in production or documentation." >&2
  exit 1
fi
if ! grep -Fq "https://generativelanguage.googleapis.com/v1/interactions" src/Infrastructure/Ai/GeminiInteractionsClient.php; then
  echo "Gemini stable v1 Interactions endpoint is not pinned in the production client." >&2
  exit 1
fi
if grep -Fq "Api-Revision" src/Infrastructure/Ai/GeminiInteractionsClient.php; then
  echo "Obsolete Gemini migration revision header found in the production client." >&2
  exit 1
fi
python3 - <<'PYPROVIDER'
from pathlib import Path
client = Path('src/Infrastructure/Ai/GeminiInteractionsClient.php').read_text(encoding='utf-8')
validator = Path('src/Infrastructure/Ai/FunctionToolValidator.php').read_text(encoding='utf-8')
projector = Path('src/Infrastructure/Ai/GeminiSchemaProjector.php').read_text(encoding='utf-8')
tools = Path('src/Application/Tool/ToolRegistry.php').read_text(encoding='utf-8')
provider_tests = Path('tests/Unit/GeminiInteractionsClientTest.php').read_text(encoding='utf-8')
flow_tests = Path('tests/Unit/ChatProviderFlowTest.php').read_text(encoding='utf-8')
required_client = (
    "'tool_choice' => $prepared['tools'] === array() ? 'none' : 'any'",
    "Gemini did not return the function call required by the production chat contract.",
    "$this->schemaProjector->project($schema)",
)
for term in required_client:
    if term not in client:
        raise SystemExit(f'Missing production Gemini invariant: {term}')
if "'tool_choice' => 'auto'" in client:
    raise SystemExit('Production chat uses permissive automatic tool selection.')
if 'schemaForWire' in client or 'schemaForWire' in validator:
    raise SystemExit('Provider schema projection bypasses the shared portable projector.')
for term in (
    'private const WIRE_KEYWORDS',
    'private const LOCAL_ONLY_KEYWORDS',
    "'minLength'",
    "'maxLength'",
    "'pattern'",
    "'minProperties'",
    "'maxProperties'",
    r'new \stdClass()',
    'private function portableEnum',
):
    if term not in projector:
        raise SystemExit(f'Portable provider schema projection is incomplete: {term}')
if "'minItems' => 0" not in tools or 'Use an empty array for no cards' not in tools:
    raise SystemExit('Terminal answer tools do not support an explicit empty product list.')
for term in (
    'rejects direct prose when production tools require a function call',
    'projects function schemas to the portable wire subset but validates original arguments locally',
    'projects structured schemas portably and still rejects locally invalid output',
    'treats a null SDK convenience output as absent and reconstructs REST model text',
):
    if term not in provider_tests:
        raise SystemExit(f'Missing provider regression: {term}')
for term in (
    'End-to-end chat flow durably presents a function-only provider protocol failure',
    "assert_same('any', $payload->generation_config->tool_choice)",
    'assert_same(0, $productRefs->minItems)',
):
    if term not in flow_tests:
        raise SystemExit(f'Missing end-to-end provider regression: {term}')
PYPROVIDER
python3 - <<'PYTOOLERRORS'
from pathlib import Path
source = Path('src/Application/Tool/ToolRegistry.php').read_text(encoding='utf-8')
start = source.find('} catch (\\InvalidArgumentException')
end = source.find('} catch (\\Throwable', start)
if start < 0 or end < 0:
    raise SystemExit('The generic tool invalid-argument boundary is missing.')
boundary = source[start:end]
if 'getMessage()' in boundary or '$error' in boundary:
    raise SystemExit('Raw invalid-argument details can enter model-visible tool history.')
if 'SAFE_INVALID_ARGUMENT_MESSAGE' not in boundary:
    raise SystemExit('The model-visible tool error is not bound to fixed remediation guidance.')
PYTOOLERRORS

python3 - <<'PYCATALOG'
from pathlib import Path
source = Path('src/Infrastructure/WooCommerce/WooCatalogGateway.php').read_text(encoding='utf-8')
for forbidden in (
    r'new \WP_Query',
    "'meta_query'",
    '"meta_query"',
    "'_price'",
    '"_price"',
    "'_stock_status'",
    '"_stock_status"',
):
    if forbidden in source:
        raise SystemExit(f'Raw WordPress catalog prefilter found: {forbidden}')
if 'wc_get_products(' not in source or 'search_products(' not in source:
    raise SystemExit('The WooCommerce-native catalog retrieval boundary is incomplete.')
PYCATALOG

if [[ -n "${YSAI_GEMINI_API_KEY:-}" ]]; then
  bash scripts/verify-gemini-v1.sh
else
  echo "Gemini stable v1 live contract: not requested (set YSAI_GEMINI_API_KEY, or require it with YSAI_REQUIRE_GEMINI_V1_CONTRACT=1)."
fi

if [[ -n "${YSAI_WP_PATH:-}" ]]; then
  bash scripts/verify-integration.sh
else
  echo "WooCommerce integration: not requested (set YSAI_WP_PATH, or require it with YSAI_REQUIRE_WOOCOMMERCE_INTEGRATION=1)."
fi

if grep -RInE --exclude-dir=tests --exclude='README.md' --exclude='SECURITY.md' \
  'AIza[0-9A-Za-z_-]{20,}|sk-[0-9A-Za-z]{20,}|BEGIN (RSA|OPENSSH|EC) PRIVATE KEY' .; then
  echo "Possible embedded credential found." >&2
  exit 1
fi

if grep -RInE --include='*.php' --include='*.js' \
  'var_dump\(|print_r\(|console\.log\(|eval\(|shell_exec\(|passthru\(' src assets templates yassin-ai-assistant.php uninstall.php; then
  echo "Debug or unsafe execution call found." >&2
  exit 1
fi

if grep -RInE --include='*.php' --include='*.js' --include='*.css' --include='*.md' --include='*.txt' \
  '(TODO|FIXME|HACK|XXX)' src assets templates docs README.md readme.txt yassin-ai-assistant.php uninstall.php; then
  echo "Unresolved production marker found." >&2
  exit 1
fi

if grep -RInE --include='*.php' 'wp_(ysai_|.*ysai_)(conversations|messages|turns|rate_limits)' src uninstall.php; then
  echo "Unversioned legacy assistant table reference found." >&2
  exit 1
fi

if find . -type d -empty -print -quit | grep -q .; then
  echo "Empty directory found in release tree." >&2
  exit 1
fi

echo "Verification passed."
