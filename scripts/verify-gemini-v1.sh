#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

api_key="${YSAI_GEMINI_API_KEY:-}"
model="${YSAI_GEMINI_MODEL:-gemini-3.7-flash}"
endpoint="https://generativelanguage.googleapis.com/v1/interactions"

if [[ ! "$api_key" =~ ^[A-Za-z0-9_-]{20,200}$ ]]; then
  echo "YSAI_GEMINI_API_KEY is missing or malformed." >&2
  exit 2
fi
if [[ ! "$model" =~ ^[A-Za-z0-9._-]{1,100}$ ]]; then
  echo "YSAI_GEMINI_MODEL is malformed." >&2
  exit 2
fi
command -v curl >/dev/null 2>&1 || { echo "curl is required for the Gemini contract suite." >&2; exit 2; }

work="$(mktemp -d)"
cleanup() {
  rm -rf "$work"
}
trap cleanup EXIT
chmod 700 "$work"

config="$work/curl.conf"
{
  printf '%s\n' 'silent' 'show-error' 'connect-timeout = 15' 'max-time = 90' 'max-filesize = 2097152' 'proto = "=https"' 'tlsv1.2'
  printf 'header = "Content-Type: application/json"\n'
  printf 'header = "Accept: application/json"\n'
  printf 'header = "x-goog-api-key: %s"\n' "$api_key"
} > "$config"
chmod 600 "$config"

cat > "$work/structured.json" <<JSON
{
  "model": "$model",
  "input": "Return the exact requested JSON object.",
  "system_instruction": "You are a deterministic API contract probe. Return contract=stable-v1 and ready=true exactly.",
  "store": false,
  "stream": false,
  "response_format": {
    "type": "text",
    "mime_type": "application/json",
    "schema": {
      "type": "object",
      "properties": {
        "contract": {"type": "string", "enum": ["stable-v1"]},
        "ready": {"type": "boolean"}
      },
      "required": ["contract", "ready"],
      "additionalProperties": false
    }
  },
  "generation_config": {
    "max_output_tokens": 256,
    "thinking_level": "low",
    "thinking_summaries": "none",
    "tool_choice": "none"
  }
}
JSON

cat > "$work/function.json" <<JSON
{
  "model": "$model",
  "input": [
    {
      "type": "user_input",
      "content": [
        {"type": "text", "text": "Call contract_echo exactly once with value stable-v1. Do not answer with text."}
      ]
    }
  ],
  "system_instruction": "You are a deterministic API contract probe. You must call the supplied function exactly once.",
  "tools": [
    {
      "type": "function",
      "name": "contract_echo",
      "description": "Echo the stable API contract marker.",
      "parameters": {
        "type": "object",
        "properties": {
          "value": {"type": "string", "enum": ["stable-v1"]}
        },
        "required": ["value"],
        "additionalProperties": false
      }
    }
  ],
  "store": false,
  "stream": false,
  "generation_config": {
    "max_output_tokens": 256,
    "thinking_level": "low",
    "thinking_summaries": "none",
    "tool_choice": "any"
  }
}
JSON

request() {
  local mode="$1"
  local payload="$2"
  local response="$3"
  local status
  status="$(curl --config "$config" --request POST --data-binary "@$payload" --output "$response" --write-out '%{http_code}' "$endpoint")"
  if [[ ! "$status" =~ ^2[0-9][0-9]$ ]]; then
    echo "Gemini stable v1 $mode contract returned HTTP $status." >&2
    head -c 1000 "$response" >&2 || true
    echo >&2
    exit 1
  fi
  php tests/Integration/gemini-v1-contract.php "$mode" "$response"
}

request structured "$work/structured.json" "$work/structured-response.json"
request function "$work/function.json" "$work/function-response.json"
echo "Gemini stable v1 live contract suite: passed."
