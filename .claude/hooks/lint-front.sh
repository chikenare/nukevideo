#!/usr/bin/env bash
# PostToolUse: run ESLint --fix on a front/ file that was just edited.
# Reads the hook payload on stdin; stays silent and never blocks the edit.
set -uo pipefail

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
SERVICE="nukevideo-front"

file_path=$(jq -r '.tool_input.file_path // empty')
case "$file_path" in
    *.ts | *.tsx | *.vue | *.js | *.mjs) ;;
    *) exit 0 ;;
esac

# The container mounts ./front at /app, so paths are relative to front/.
rel="${file_path#"$ROOT"/front/}"
[[ "$rel" != "$file_path" ]] || exit 0

# Generated and vendored sources are outside ESLint's config on purpose.
case "$rel" in
    src/types/generated.d.ts | src/components/ui/*) exit 0 ;;
esac

cd "$ROOT" || exit 0

# --fix silently settles what it can; whatever survives is a real problem in a file that was
# just written, so hand it back instead of letting it surface later in CI.
output=$(docker compose exec -T "$SERVICE" pnpm exec eslint --fix "$rel" 2>/dev/null)
status=$?

if [[ $status -ne 0 && -n "$output" ]]; then
    printf 'ESLint on %s:\n%s\n' "$rel" "$output" >&2
    exit 2
fi

exit 0
