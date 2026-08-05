---
description: Create a migration following this repo's naming and structure
argument-hint: "<what the migration does> [--clickhouse]"
allowed-tools: Bash(docker compose exec:*), Read, Write, Edit, Glob, Grep
---

Create a migration for: **$ARGUMENTS**

Write the file by hand rather than running `make:migration` — the generator picks a wall-clock
timestamp and a stock stub, and neither matches what this repo does. Read two or three recent
migrations in the target directory first and match them.

## Naming

Prefix is always `YYYY_MM_DD_HHMMSS_`, but hand-written migrations use **round sequential times**
(`000000`, `000001`, `120000`), not a real clock. Use today's date and the next free sequence for
that day.

The descriptive part follows what kind of change it is:

- **Schema shape** — stock verb form with the `_table` suffix dropped:
  `add_chunk_count_to_videos`, `add_forced_to_streams`, `add_keep_original_to_templates`
- **New table** — keep the full stock form: `create_outputs_table`
- **Behaviour or constraint change** — a descriptive sentence naming the semantic change, no
  `add`/`create` verb: `make_video_template_nullable_on_delete`,
  `split_stream_size_into_package_and_file`

## Structure (`database/migrations/`)

- `return new class extends Migration` — anonymous class, no `declare(strict_types=1)` here.
- **A docblock explaining WHY**, above the class for "why does this migration exist at all"
  cases, or on `up()` for mechanical ones. Drop the stock `/** Run the migrations. */`. State the
  problem the migration fixes and name the class that consumes the column
  (`See PackageVideoJob::pruneProcessedRenditions()`). This is the strongest convention in the
  directory — do not skip it.
- **Always write `down()`**, and make it genuinely reverse the change, data included.
- **Guard for re-runs**: early-return on `Schema::hasColumn(...)` for add-column migrations.
- **MariaDB in production, SQLite in tests.** Both have to pass, so: no JSON functions
  (`JSON_EXTRACT`) — backfill in PHP or the query builder; guard `dropForeign` with
  `Schema::getConnection()->getDriverName() !== 'sqlite'`; `->after('col')` is fine (SQLite
  ignores it); `->json()->nullable()` rather than a literal JSON default.

## ClickHouse (`--clickhouse` → `database/clickhouse-migrations/`)

Different rules: `declare(strict_types=1)`, extend `AbstractClickhouseMigration`, `up()` only
(**no `down()`**), SQL through `$this->clickhouseClient->write(<<<'SQL' ... SQL,)`. The
descriptive part of the filename is just the table name (`video_usage`) or `drop_<table>`. Use
`CREATE TABLE IF NOT EXISTS`, an explicit `ORDER BY`, `PARTITION BY toYYYYMM(date)` and a `TTL`.
These run under `php artisan clickhouse:migrate`, not `migrate`.

## After writing

Run it and confirm it applies cleanly:

```bash
docker compose exec -T nukevideo-api php artisan migrate
```

Do not edit a migration that has already been applied — write a new one. If the change destroys
data, stop and ask before running it.
