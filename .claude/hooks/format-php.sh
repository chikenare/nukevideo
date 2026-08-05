#!/usr/bin/env bash
# PostToolUse: run Pint on a PHP file that was just edited.
# Reads the hook payload on stdin; stays silent and never blocks the edit.
set -uo pipefail

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
SERVICE="nukevideo-api"

file_path=$(jq -r '.tool_input.file_path // empty')
[[ "$file_path" == *.php ]] || exit 0

# Pint runs inside the container, which mounts the repo root at /var/www/html.
rel="${file_path#"$ROOT"/}"
[[ "$rel" != /* ]] || exit 0
[[ -f "$ROOT/$rel" ]] || exit 0

cd "$ROOT" || exit 0
docker compose exec -T "$SERVICE" ./vendor/bin/pint "$rel" >/dev/null 2>&1

exit 0
