#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export YSAI_TEST_PATTERN='concurr|processing turn|active turn|stale worker|stale turn|claim version|lease|fenc|reclaim|replay|idempot|operation marker|rollback|durable work|blocking conversation|atomic upsert|cart lock|serializ|lost response|uncertain state'
php tests/run.php
