#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cat > "$work/structured-reordered.json" <<'JSON'
{"status":"completed","output_text":"{\"ready\":true,\"contract\":\"stable-v1\"}"}
JSON
cat > "$work/invalid-structured.json" <<'JSON'
{"status":"completed","output_text":"{\"contract\":\"stable-v1\",\"ready\":true,\"extra\":1}"}
JSON
cat > "$work/invalid-function.json" <<'JSON'
{"status":"requires_action","steps":[{"type":"function_call","id":"fc_1","name":"contract_echo","arguments":{"value":"stable-v1"}},{"type":"function_call","id":"fc_2","name":"contract_echo","arguments":{"value":"stable-v1"}}]}
JSON

php tests/Integration/gemini-v1-contract.php structured tests/Contract/Fixtures/gemini-v1/structured-completed.json >/dev/null
php tests/Integration/gemini-v1-contract.php function tests/Contract/Fixtures/gemini-v1/function-requires-action.json >/dev/null
php tests/Integration/gemini-v1-contract.php structured "$work/structured-reordered.json" >/dev/null

if php tests/Integration/gemini-v1-contract.php structured "$work/invalid-structured.json" >/dev/null 2>&1; then
  echo "Gemini v1 fixture validator accepted extra structured-output properties." >&2
  exit 1
fi
if php tests/Integration/gemini-v1-contract.php function "$work/invalid-function.json" >/dev/null 2>&1; then
  echo "Gemini v1 fixture validator accepted multiple function calls." >&2
  exit 1
fi

echo "Gemini stable v1 local contract fixtures: 5 passed."
