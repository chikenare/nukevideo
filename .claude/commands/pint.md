---
description: Format PHP with Laravel Pint
argument-hint: "[path] [--test]"
allowed-tools: Bash(docker compose exec:*), Bash(git status:*), Bash(git diff:*)
---

Format the PHP source with Laravel Pint. There is no `pint.json`, so the default Laravel
preset applies — do not add one without being asked.

```bash
docker compose exec -T nukevideo-api ./vendor/bin/pint $ARGUMENTS
```

CI runs `./vendor/bin/pint --test`, which only reports and never writes. Pass `--test` through
when the caller wants to check without touching files.

Report which files Pint changed. If it rewrote a file you did not touch in this session, say so
rather than folding it silently into your change.
