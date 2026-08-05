---
description: Add an API endpoint end to end, with its docs page entry
argument-hint: "<METHOD /path — what it does>"
allowed-tools: Bash(docker compose exec:*), Read, Write, Edit, Glob, Grep
---

Add the endpoint: **$ARGUMENTS**

Read the nearest existing equivalent before writing anything — `VideoController` +
`app/Data/Video/UpdateVideoData.php` + `docs/api/videos.md` is the reference chain. Match it.
Work in this order; each step depends on the one before it.

## 1. Data objects (`app/Data/`)

Validation lives here, never in a FormRequest — there are none in this repo.

- **Input**: `app/Data/{Domain}/{Store|Update}{Model}Data.php`, extends `App\Data\RequestData`.
  Properties are camelCase. The global input mapper is **snake_case**
  (`config/data.php`), so every property that must accept camelCase JSON from the panel needs
  `#[MapInputName(CamelCaseMapper::class)]` **on the property** — a class-level `#[MapName]` is
  ignored for input. Optional fields are typed `string|Optional|null`.
  Prefer validation attributes (`#[Max(255)]`); add a `public static function rules(): array`
  only when attributes cannot express it, and key it in camelCase to match the mapped name.
- **Output**: `app/Data/{Model}Data.php`, extends `Spatie\LaravelData\Data`, with
  `public static function fromModel(Model $m): self` built with named arguments. Annotate array
  properties (`/** @var StreamData[] */`) — that docblock is what drives the generated TS type.
  Do not add `#[TypeScript]`; the whole `app/Data` and `app/Enums` directories are transformed.

## 2. Controller

Thin. Constructor-promote the service it needs; inject the Data object as an **action argument**
so validation resolves implicitly (`public function update(Request $request, UpdateVideoData $data, string $ulid)`).
Never call `$request->validate()`.

Scope every project resource through `$request->project()` (a Request macro that aborts 400 when
unresolved) — `$request->project()->videos()->where('ulid', $ulid)->firstOrFail()`. Fetching by
ULID without that scoping is a cross-tenant leak.

Return `response()->json(['data' => XData::fromModel($m)])`, plus a
`'message' => '... successfully'` for writes. Push multi-step or destructive logic into a Service
in `app/Services/`, which signals domain errors with
`ValidationException::withMessages([...])->status(409)`.

## 3. Route (`routes/api.php`)

There is no versioning — routes are flat. Put it in the group that matches its scope, and nowhere
else:

- `no-project-key` → account-wide (the caller's user, spanning projects)
- `resolve.project` → project-scoped (the normal case for resources)
- `['no-project-key', EnsureAdmin::class]` → admin only

Getting this wrong is a security bug, not a style choice: a project API key authenticates as the
**project**, so an account route reachable with one leaks across the tenant boundary.

## 4. Regenerate the TypeScript types

```bash
docker compose exec -T nukevideo-api php artisan typescript:transform
```

Writes `front/src/types/generated.d.ts` (tracked in git — commit it; never hand-edit it).

If a new property does not appear, the Spatie Data structure cache is stale — it lives in the
`file` store, not Redis:

```bash
docker compose exec -T nukevideo-api php artisan data:cache-structures
```

## 5. Test (`tests/Feature/{Domain}/`)

Pest, `it('...', function () {...})`, `uses(RefreshDatabase::class)` at the top. Authenticate with
`Sanctum::actingAs($user)` **and** `$this->withHeader('X-Project-Ulid', $project->ulid)`, like the
panel does. Only `User` and `Project` have factories; build everything else with `Model::create()`
in `beforeEach`. Assert against camelCase response keys (`assertJsonPath('data.externalUserId', …)`).

Cover at minimum: happy path, validation failure, and that another project cannot reach it.

## 6. Documentation (`docs/api/`)

Add a section to the matching page in `docs/api/`, in the house format — no frontmatter, `##`
heading in Verb-Noun form (`## Update Stream`), then:

````markdown
## Update Stream

One or two sentences on what it does and any lifecycle caveat.

```
PUT /api/streams/{ulid}
```

**Request Body:**

```json
{ "name": "Latin American Spanish" }
```

| Field | Type | Notes |
|-------|------|-------|
| `name` | string | What it means, and when it is required. |

Returns the updated stream. Responds `400` for … and `422` when validation fails.
````

Notes on the format: the method+path fence carries **no language**; the JSON fence comes **before**
its field table; auth is stated once in the page intro, never per endpoint; use `**Query
Parameters:**` / `**Request Body:**` / `**Response:**` as bold labels, not headings. Write JSON
examples in **camelCase** — some older pages are snake_case, do not copy that.

If the endpoint needs a brand-new page, also add it to the `/api/` sidebar list in
`docs/.vitepress/config.mts`, which is hardcoded.

## 7. Verify

```bash
docker compose exec -T nukevideo-api ./vendor/bin/pint
docker compose exec -T nukevideo-api php artisan config:clear
docker compose exec -T nukevideo-api php artisan test
```

Then report which files you touched and what the tests said.
