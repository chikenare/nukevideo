# CLAUDE.md

Guía para Claude Code en este repositorio. Léela antes de escribir código.

## Qué es NukeVideo

Motor open-source de procesamiento y entrega de video: recibe un archivo subido directo a S3, lo
transcodifica en paralelo repartiendo ventanas entre nodos de trabajo remotos (CPU o GPU), lo
empaqueta como CMAF (HLS + DASH sobre los mismos segmentos) y lo sirve por CDN. El panel de
administración es una SPA propia y hay además una API pública para consumidores externos.

Monorepo: **backend Laravel 12 en la raíz**, **SPA Vue 3 en `front/`**, **docs VitePress en `docs/`**.

## Layout

```
.                       # Laravel 12 (PHP ^8.2) en la raíz
├── app/
│   ├── Console/Commands/   # videos:dispatch, videos:reap, videos:prune, videos:retry
│   ├── Data/               # Spatie Data — DTOs de entrada y salida (NO hay FormRequests)
│   ├── DTOs/               # DTOs planos, no Spatie (UploadMeta, StorageWebhookData)
│   ├── Enums/              # enums backed de string
│   ├── Http/Controllers/   # + Controllers/Api para admin/cuenta
│   ├── Http/Middleware/    # ResolveProject, DenyProjectKey, EnsureAdmin, Verify*
│   ├── Jobs/ + Jobs/Concerns/
│   ├── Models/             # Video, Output, Stream, Template, Node, Project, User, SshKey
│   ├── Observers/          # registrados con #[ObservedBy] en el modelo
│   ├── Rules/              # reglas usadas dentro de rules() de los Data
│   ├── Services/           # + Services/Cdn (proveedores) y Services/Concerns (traits)
│   ├── Settings/           # spatie/laravel-settings
│   └── Support/            # helpers sin estado: Cpu, Gpu, MediaDuration, MediaSource
├── database/
│   ├── migrations/             # MariaDB en prod, SQLite en tests
│   ├── clickhouse-migrations/  # php artisan clickhouse:migrate
│   └── settings/               # migraciones de spatie/laravel-settings
├── docs/                   # VitePress (guide/ + api/)
├── front/                  # SPA Vue 3 + Vite (proyecto pnpm independiente)
├── vod/                    # nginx del edge de entrega (secure_token)
├── vector/                 # config de Vector: logs del edge → ClickHouse
└── compose.yml             # el stack local completo
```

**No hay `app/Actions`, ni `app/Http/Requests`, ni `app/Policies`, ni `routes/web.php`.**

## Comandos

Todo corre en Docker. Nunca ejecutes `php`, `composer`, `pnpm` o `artisan` directo en el host.
El contenedor del backend es **`nukevideo-api`** (no `app`).

```bash
docker compose up -d                                          # levantar el stack
docker compose exec nukevideo-api php artisan <comando>
docker compose exec nukevideo-api composer <comando>

# Tests — config:clear SIEMPRE primero (ver "Gotchas")
docker compose exec nukevideo-api php artisan config:clear
docker compose exec nukevideo-api php artisan test

docker compose exec nukevideo-api ./vendor/bin/pint           # formatear PHP
docker compose exec nukevideo-api php artisan migrate
docker compose exec nukevideo-api php artisan clickhouse:migrate
docker compose exec nukevideo-api php artisan typescript:transform   # regenera front/src/types/generated.d.ts
docker compose exec nukevideo-api php artisan data:cache-structures  # tras editar app/Data
```

Frontend (`front/`, **pnpm exclusivamente** — el lockfile es `pnpm-lock.yaml`). El servidor de Vite
ya arranca solo con `docker compose up -d` (es el `CMD` del contenedor), así que `exec` es solo
para las verificaciones:

```bash
docker compose exec nukevideo-front pnpm run lint         # eslint . --fix --cache
docker compose exec nukevideo-front pnpm run type-check   # vue-tsc --build
docker compose exec nukevideo-front pnpm run format       # prettier
```

Docs (`docs/`, también pnpm): el contenedor `nukevideo-docs` sirve `docs:dev` en
`docs.nukevideo.localhost`. Para compilar: `docker compose exec nukevideo-docs pnpm run docs:build`.

**CI es la referencia de qué debe pasar** (`.github/workflows/ci.yml`, solo en PRs a `main`):
`./vendor/bin/pint --test` + `php artisan test` en la raíz, y `pnpm exec eslint .` (sin `--fix`) +
`pnpm run type-check` en `front/`. Las imágenes solo se publican en tags `v*`.

> `composer dev` y `composer setup` son los scripts stock de Laravel y **están rotos aquí**:
> invocan `npm run dev` y `npm install` en la raíz, donde no hay `package.json`. Usa
> `docker compose up -d`. `composer test` sí funciona (hace `config:clear` y luego `artisan test`).

## Stack

**Backend:** Laravel 12, PHP ^8.2 (CI compila con 8.5) · Sanctum (sesión para la SPA + tokens
personales y de proyecto) · Spatie Laravel Data (DTOs y validación) ·
spatie/laravel-typescript-transformer (genera los types del front) · Horizon sobre Redis ·
Telescope (solo en local, registrado condicionalmente porque está en `dont-discover`) ·
Pest 4 · Pint · spatie/laravel-activitylog · spatie/laravel-settings · Sentry · phpseclib (SSH a
los nodos) · ClickHouse vía cybercog/laravel-clickhouse.

**Frontend:** Vue 3 `<script setup lang="ts">` · Vite 7 · Pinia (setup stores) · Vue Router ·
Tailwind v4 (sin `tailwind.config.js`, la config vive en CSS) · shadcn-vue sobre reka-ui ·
axios · shaka-player / hls.js / dashjs · Uppy.

**Infra:** Docker + Traefik (`*.nukevideo.localhost`) · **MariaDB 11** (no PostgreSQL) · Redis ·
RustFS (S3) · ClickHouse · **ffmpeg** y **shaka-packager** (compilados en el Dockerfile) ·
**s5cmd** para todas las transferencias S3 · Vector.

## El pipeline de encoding

Es el corazón del proyecto; entiéndelo antes de tocar `app/Jobs` o `app/Services`.

Subida directa a S3 (Uppy multipart) → webhook del bucket → `OnVideoUploaded` crea el `Video`
(status `PENDING`) y su `Stream` de tipo `original` → el scheduler `videos:dispatch` reclama un
hueco de worker y despacha **`PrepareVideoJob`** (cola `orchestration`), que descarga la fuente
**una sola vez** de S3 principal, la espeja al almacén LAN `chunks`, la sondea para crear
`Output`s y `Stream`s, corre los probes (per-title CRF, bitrate, preflight), planea ventanas
alineadas a keyframes y hace fan-out de **un `Bus::batch` por cola de hardware** con un
`ProcessChunkJob` por (ventana × rendición). En paralelo corren `EncodeSidecarTracksJob` (audio y
subtítulos en una sola pasada, nunca chunked), `ExtractThumbnailJob` y `GenerateVideoStoryboard`.
El `then()` del último batch pasa el video a `UPLOADING` y despacha **`PackageVideoJob`** (cola
`packaging`, `ShouldBeUnique`), que concatena chunks con `-c copy`, corre shaka-packager una vez
por altura distinta sobre un árbol de segmentos compartido, injerta los subtítulos con
`ManifestEditor` y sincroniza todo a S3 principal con `s5cmd`. `CompletesVideo` cierra el video.

Conceptos que hay que tener claros:

- **Stream** = una pista concreta (`original` | `video` | `audio` | `subtitle`). Una "rendición"
  es un stream de video. Los segmentos viven en `{videoUlid}/{streamUlid}/`.
- **Output** = un paquete CMAF / familia de manifiestos, 1:1 con una entrada de
  `query.outputs[]` de la plantilla. **Los streams se comparten entre outputs** cuando sus
  parámetros resueltos son idénticos; lo que es por-output son los manifiestos.
- **Template** = columna JSON `query` con `outputs[].variants[]`. `config/ffmpeg.php` es el
  catálogo de códecs y el esquema de cada parámetro (reglas, `available_for`, plantilla del flag).
- **Disco `chunks` vs `s3`**: `chunks` es un RustFS en la LAN, desechable (fuente espejada,
  chunks, sidecars). El `s3` principal se lee una vez y se escribe una vez por video.
- **Colas**: `default` (solo la API), `orchestration` (todos los workers), `packaging`,
  `video-processing` (CPU) y `video-processing-{intel,nvidia}` (los nodos GPU no drenan la de CPU).
  `config/horizon.php` decide los supervisores según `NODE_TYPE`/`NODE_ACCEL`.

Configs propias: `ffmpeg.php` (catálogo de códecs y parámetros), `packager.php`, `nuke.php`
(secretos de webhook/interno, timeout de worker), `template-presets.php`, `horizon.php`,
`filesystems.php` (disco `chunks`).

## Convenciones de backend

- **La lógica va en `app/Services`**, no en controladores. No hay Actions: no inventes el patrón.
  El CRUD trivial sí vive inline en el controlador; lo destructivo o multi-paso va a un Service.
- **Los Data objects son la validación.** No hay FormRequests y nada llama a `$request->validate()`.
  - Entrada: `app/Data/{Dominio}/{Store|Update}{Modelo}Data.php` extiende `App\Data\RequestData`,
    que aporta `toDatabase()` (array en snake_case, omitiendo los `Optional` ausentes).
  - Salida: `app/Data/{Modelo}Data.php` extiende `Data`, con `fromModel()` por argumentos nombrados.
  - **Casing**: el mapper global de *entrada* es snake_case y el de *salida* camelCase
    (`config/data.php`). Por eso cada propiedad que deba aceptar camelCase del panel lleva
    `#[MapInputName(CamelCaseMapper::class)]` **a nivel de propiedad** — un `#[MapName]` de clase
    se ignora en la entrada. Las claves de `rules()` van en camelCase.
  - Nada de `#[TypeScript]`: se transforman los directorios `app/Data` y `app/Enums` completos.
- **Controladores delgados**: inyección por constructor promovido, el Data object se inyecta como
  argumento de la acción, y se devuelve `response()->json(['data' => XData::fromModel($m)])`.
- **Scoping por proyecto**: `$request->project()` es un macro de Request que aborta 400 si no hay
  proyecto resuelto. Toda consulta de recurso sale de ahí
  (`$request->project()->videos()->where('ulid', $ulid)->firstOrFail()`). Buscar por ULID sin ese
  scoping es una fuga entre tenants, no un descuido de estilo.
- **Rutas** (`routes/api.php`, plano, **sin versionado**): tres grupos dentro de
  `auth:sanctum` + `throttle:api` — `no-project-key` (cuenta), `resolve.project` (recursos del
  proyecto) y `['no-project-key', EnsureAdmin::class]` (admin). Elegir mal el grupo es un bug de
  seguridad: una API key de proyecto autentica **como el proyecto**.
- **Jobs**: siempre `$tries` y `$backoff` explícitos, con un comentario del porqué; los pesados
  además `$timeout`. La cola va en una constante de clase (`private const QUEUE = 'orchestration';`)
  y se despacha con `->onQueue(self::QUEUE)`. Los constructores reciben **primitivos**, no modelos.
  `failed()` marca el video con `markAsFailed()`.
- **Modelos**: ULID asignado en un `boot()` escrito a mano (no hay trait `HasUlid`); la PK sigue
  siendo el autoincremental y el ULID es el identificador público. `casts()` como método. Los
  observers se registran con `#[ObservedBy]`. Los modelos cargan bastante lógica de dominio y las
  constantes de rutas de almacenamiento.
- **Migraciones**: ver `/migration`. Clase anónima, docblock explicando el **porqué**, `down()`
  siempre, guardas `Schema::hasColumn` para poder re-correrlas, y compatibles con MariaDB **y**
  SQLite (sin `JSON_EXTRACT`; `dropForeign` guardado para sqlite).
- **Estilo**: Pint con el preset por defecto (no hay `pint.json`). El código **no** usa
  `declare(strict_types=1)` salvo en `Services/Cdn` y las migraciones de ClickHouse, y no hay
  clases `final`: sigue lo que hay, no introduzcas un segundo estilo.
- Los comentarios explican **por qué**, y razonan sobre modos de fallo. Esa densidad es la casa.

## Convenciones de frontend

- Composition API con `<script setup lang="ts">`, siempre.
- **Nunca escribas a mano types de la API.** Vienen de `front/src/types/generated.d.ts`
  (`App.Data.VideoData`, `App.Enums.VideoStatus`), generado desde los Data objects. Si falta algo,
  se arregla el Data object y se regenera. El archivo está versionado; no lo edites.
- `front/src/services/` — una clase por dominio con `BASE_PATH`, `constructor(private api = apiClient)`
  y export de una instancia única. El cliente axios central es `front/src/services/api.ts`
  (interceptores: cabecera `X-Project-Ulid` desde localStorage, 401 → `/login`, 422 →
  `ValidationException`).
- Páginas en `front/src/pages/` (no `views/`). Stores Pinia en formato setup.
- `front/src/components/ui/` es shadcn-vue generado: no se edita a mano (está fuera de ESLint).
- Prettier: sin punto y coma, comillas simples, ancho 100.

## Gotchas que cuestan horas

- **`config:clear` antes de los tests.** `TestCase::setUp` tira y remigra la base; con la config
  cacheada lee la conexión de desarrollo y **borra la base real**. Hay una guarda que lo aborta,
  pero corre `config:clear` igual.
- **Caché de estructuras de Spatie Data**: tras editar una clase de `app/Data`, las propiedades
  nuevas desaparecen del JSON. Vive en el store `file` (no en Redis):
  `php artisan data:cache-structures`.
- **La DB es MariaDB**, aunque la guía global hable de PostgreSQL. Escribe migraciones para MySQL.
- Los contenedores de worker llevan la imagen horneada, sin mount del código: para probar un
  cambio hay que `docker cp` + `horizon:terminate`.
- Reintentar un video no basta con `status = pending`: hay que borrar antes su fila de
  `job_batches` (los chunks sobreviven, así que el reintento reaprovecha).

## Reglas para Claude

- Antes de crear un archivo, busca el equivalente que ya exista y sigue su patrón.
- No agregues dependencias sin preguntar.
- **Nunca edites archivos generados**: `front/src/types/generated.d.ts`, `front/src/components/ui/`.
  Corrige la fuente y regenera.
- No toques `.env` ni secretos; si falta una variable, dilo y agrégala a `.env.example`.
- No modifiques infraestructura (`compose.yml`, `Dockerfile`, Traefik, nginx del `vod/`, CI) salvo
  que se pida explícitamente.
- Al terminar backend: Pint y los tests relevantes. Al terminar frontend: lint y type-check.
- Si un cambio implica una migración destructiva de datos, detente y pregunta.
- Commits en Conventional Commits, en inglés e imperativo. No hagas push salvo que te lo pidan.
- **Código, nombres y comentarios en inglés. Respuestas y explicaciones en español.**
