---
description: Run the Pest suite inside the API container
argument-hint: "[path or --filter=name]"
allowed-tools: Bash(docker compose exec:*), Bash(docker compose ps:*), Bash(docker compose up:*)
---

Run the backend test suite. Everything runs in Docker — never call `php` or `artisan` on the host.

Clear the config first, always: `TestCase` drops and re-migrates the database, and a cached config
makes it read the **development** connection instead of the test one, wiping the real MariaDB.
This is what `composer test` does too.

```bash
docker compose exec -T nukevideo-api php artisan config:clear
docker compose exec -T nukevideo-api php artisan test $ARGUMENTS
```

If `nukevideo-api` is not running, start its dependencies first
(`docker compose up -d db redis nukevideo-api`) and then retry.

Report the pass/fail counts. On failure, show the failing test names and assertion output — do not
summarize a failure as a success, and do not fix anything unless asked.
