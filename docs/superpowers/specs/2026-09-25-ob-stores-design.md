# ob-stores: design

An equipment store for a regional radio station's outside broadcast (OB) team. Serialised gear and lot-tracked consumables, kit templates with a two-level recipe, an append-only ledger with safe concurrent checkout, and full traceability. A legacy jQuery screen and a modern Vue screen sit side by side over one JSON API.

Source brief: `../demo-brief.md` (outside the repo). All names in seed data are invented.

## Decisions taken during brainstorming

| Topic | Decision |
|---|---|
| MySQL | `mysql:8.4` via a single-service `docker-compose.yml`. PHP runs natively with `php -S`. |
| Node | Node 24 (installed) used for Vite; `package.json` declares `engines: >=22`. Recorded in `DECISIONS.md`. |
| PHP | Upgrade Homebrew `php@8.4` to latest 8.4 patch at scaffold; pin the installed version in `composer.json` `require.php`. |
| Repo | `ob-stores`, private, under the `ad0maa` GitHub account. No CI, no deploy, no public visibility. |
| Seed size | ~300 serialised items across ~15 gear types, 8 consumables with several lots each, 6 templates, ~18 months of generated historical jobs (~20–50k ledger rows). |
| Planner count | Multiplies only lines flagged `per_position`, and only at the top level of a template. Sub-kit internals are per unit of the sub-kit. |
| Ledger shape | One `movements` table (Approach A), not two ledgers, not cached status columns. |
| Faulty | Terminal. No repair flow (maintenance is out of scope). |
| Unused consumables | Packed consumables count as consumed; nothing comes back on return. |

## 1. Architecture

```
public/index.php          front controller: .env, session, CSRF, dispatch
public/assets/app.css     the one stylesheet (CSS custom properties)
public/assets/legacy/     store.js (jQuery screen)
public/assets/vendor/     jquery.min.js, copied from the pinned npm `jquery` 3.7.x package by `npm run build` (gitignored)
public/build/             Vite output (gitignored)
src/Env.php               .env loader (hand-rolled)
src/Db.php                PDO factory: ERRMODE_EXCEPTION, EMULATE_PREPARES=false, utf8mb4
src/Http/Router.php       < 60 lines: method + regex path → [Controller, method]
src/Http/Response.php     html(), json(), redirect()
src/helpers.php           e(), csrf_token(), csrf_check(), vite_tags()
src/Domain/               MovementType (backed enum), readonly value objects, GearItem
src/Repo/                 raw SQL, prepared statements only
src/Service/              RecipeExploder, PackJob, ReturnJob
src/Controller/           Store, Planner, Jobs, Trace, Ledger, Api
templates/                plain PHP templates + layout.php
frontend/planner/         Planner.vue + main.js
database/migrations/      001_init.sql, 002_views.sql, …
bin/migrate.php           applies unapplied .sql files, tracks them in schema_migrations
bin/seed.php              deterministic generator (fixed RNG seed)
tests/                    PHPUnit against a real ob_stores_test database
docker-compose.yml        mysql:8.4 only
```

Request flow: `index.php` → `Router` → controller → repo/service → template or JSON. `vite_tags()` reads `public/build/.vite/manifest.json` to emit hashed script/style tags (~10 lines, no plugin).

`declare(strict_types=1);` in every PHP file. Typed properties and return types throughout. Only runtime dependency beyond PHP extensions: none. Composer dev dependency: `phpunit/phpunit` only.

PHP 8.4 features, used where they fit and each noted in `DECISIONS.md`:
- `MovementType` backed string enum, mapped to the `movements.type` column.
- `readonly` value objects: `PlanLine`, `Shortfall`, `Allocation`.
- Asymmetric visibility (`public private(set)`) on `GearItem` properties.
- A property hook where a derived value reads naturally (e.g. `PlanLine::$shortfall`).

## 2. Schema

```
gear_types      id, code UNIQUE, name, category
gear_items      id, gear_type_id FK, serial UNIQUE, acquired_on      -- never updated
consumables     id, code UNIQUE, name, unit, reorder_point
lots            id, consumable_id FK, lot_code, received_on          -- never updated
                UNIQUE (consumable_id, lot_code)
kit_templates   id, code UNIQUE, name, is_subkit
template_lines  id, template_id FK, qty > 0, per_position BOOL,
                gear_type_id NULL, consumable_id NULL, child_template_id NULL
                CHECK exactly one of the three FKs is set
jobs            id, name, job_date, template_id FK, positions, packed_at, returned_at NULL
movements       id BIGINT, type ENUM('receipt','consume','checkout','return','faulty'),
                qty INT signed, gear_item_id NULL, lot_id NULL, job_id NULL,
                note NULL, created_at DATETIME(6)
                CHECK ((gear_item_id IS NULL) <> (lot_id IS NULL))
                INDEX (gear_item_id, id), (lot_id, id), (job_id)
schema_migrations  filename PK, applied_at
```

Views (`002_views.sql`):
- `gear_item_status`: each gear item with the type of its latest movement → `available` (receipt/return), `out` (checkout), `faulty`, plus the current `job_id` when out.
- `lot_balances`: each lot with `SUM(qty)` as `on_hand`.

Movement conventions:
- Gear: `receipt` +1 (seeded acquisition), `checkout` −1, `return` +1, `faulty` 0 with note (written after the `return`).
- Consumables: `receipt` +N, `consume` −N, always against a specific lot.
- Stock and status are never stored columns; they only change by inserting movement rows. No `UPDATE` or `DELETE` on `movements`, ever.

## 3. Recipe explosion

`RecipeExploder::explode(int $templateId, int $positions): list<PlanLine>` runs one `WITH RECURSIVE` query:

```sql
WITH RECURSIVE tree AS (
  SELECT gear_type_id, consumable_id, child_template_id,
         qty * IF(per_position, :positions, 1) AS mult, 1 AS depth
  FROM template_lines WHERE template_id = :template_id
  UNION ALL
  SELECT c.gear_type_id, c.consumable_id, c.child_template_id,
         t.mult * c.qty, t.depth + 1
  FROM tree t JOIN template_lines c ON c.template_id = t.child_template_id
  WHERE t.depth < 5
)
SELECT ... SUM(mult) AS required ... FROM tree
WHERE child_template_id IS NULL
GROUP BY gear_type_id, consumable_id
```

The aggregated leaves are `LEFT JOIN`ed to availability counts from `gear_item_status` and `lot_balances`, returning `kind, code, name, required, available, shortfall` per line. `depth < 5` guards against a cyclic template.

## 4. Pack a job (`PackJob`)

`POST /api/jobs` `{template_id, positions, name, job_date}`:

1. Explode the recipe (outside the transaction).
2. `BEGIN`; insert the `jobs` row.
3. For each required gear type, ascending `gear_type_id`:
   `SELECT id FROM gear_items WHERE gear_type_id = ? ORDER BY id FOR UPDATE`,
   then read availability with a locking read (`FOR SHARE`) so it sees the latest committed movements rather than the REPEATABLE READ snapshot. Take the first N available; insert a `checkout` row per item.
4. For each required consumable, ascending `consumable_id`:
   lock its lots `ORDER BY received_on, id FOR UPDATE`, read balances with a locking read, allocate oldest lot first, insert `consume` rows split across lots.
5. Collect every shortfall. If any: throw `InsufficientStock`, roll back, respond 409 with the full list. Otherwise commit and respond 201 with the job id.

Consistent lock order (gear types then consumables, each by id) prevents packers deadlocking each other. On a deadlock (SQLSTATE `40001`) the service retries the whole transaction once. `SKIP LOCKED` is deliberately not used (simpler, obviously correct); noted as a talking point.

## 5. Return a job (`ReturnJob`)

`POST /api/jobs/{id}/return` `{items: [{gear_item_id, faulty, note}]}`: in one transaction, lock the job's gear items, verify each one's latest movement is a `checkout` on this job, insert `return` (+ `faulty` with note where flagged), set `jobs.returned_at`. Any mismatch rolls back with 409. The job page's return form defaults every item to "returned OK" with a per-row faulty checkbox and note field.

## 6. Screens

All pages share `templates/layout.php`: a top nav (Store · Planner · Jobs · Ledger · Trace), the one stylesheet, dense tables, visible focus rings.

**Store (legacy, server-rendered PHP + jQuery).** `/store`
- Gear table grouped by type: in / out / faulty counts.
- Consumables table: on hand, reorder point, flagged row when below reorder point.
- Inline text filter (jQuery, filters rows client-side, `/` focuses it).
- "Receive" button per consumable opens a hand-built modal (lot code, quantity, received date). Posts via `$.ajax` to `POST /api/receipts`; on success replaces that row's on-hand cell and reorder flag without reload; on 422 shows field errors inside the modal.
- Receiving inserts a `lots` row and a `receipt` movement in one transaction. A duplicate lot code for the same consumable is a 422.

**Planner (modern, Vue SFC built by Vite).** `/planner`
- Template select, positions number input (↑/↓ adjust).
- Debounced (150ms) fetch of `GET /api/templates/{id}/plan?positions=N`, with `AbortController` cancelling the previous request.
- One table of required / available / short; shortfall rows highlighted; summary line ("3 shortfalls" / "Ready to pack").
- Pack form (job name, date) → `POST /api/jobs`; 409 renders the server's shortfall list; 201 links to the job page.

**Jobs.** `/jobs` (list, newest first, with out/returned state) and `/jobs/{id}` (everything that went: serials and lot allocations; return form when still out).

**Trace.** `/trace?serial=…` (every job a gear item went out on, with dates and return/faulty outcome), `/trace?lot=…` (every job a lot was consumed into, with quantities), and the job page itself as the third trace.

**Ledger.** `/ledger?item=…&page=N`: read-only, 50 rows per page, newest first, with running balance per item computed by `SUM(qty) OVER (PARTITION BY … ORDER BY id)`. Filter by gear serial or consumable.

## 7. JSON API

| Method | Path | Used by |
|---|---|---|
| GET | `/api/stock` | Store (row refresh) |
| POST | `/api/receipts` | Store modal |
| GET | `/api/templates` | Planner |
| GET | `/api/templates/{id}/plan?positions=N` | Planner |
| POST | `/api/jobs` | Planner pack form |
| POST | `/api/jobs/{id}/return` | Job page |

JSON errors use one shape: `{"error": "message", "details": {...}}` with 400 (bad input), 403 (CSRF), 404, 409 (stock conflict), 422 (validation).

## 8. Security and error handling

- Prepared statements for every query with user input; emulated prepares off.
- All template output through `e()` (`htmlspecialchars` with `ENT_QUOTES`, UTF-8).
- CSRF token per session, stored in a `<meta>` tag; every POST (form or AJAX) must send it (form field or `X-CSRF-Token` header), checked with `hash_equals`. Failure → 403.
- Front controller catches uncaught exceptions: JSON routes get a 500 JSON body, HTML routes get a plain error page; details only when `APP_DEBUG=1`.
- `.env` is gitignored; `.env.example` is committed. No absolute local paths.

## 9. Testing

PHPUnit against a real MySQL test database (`ob_stores_test`, created by the migrate script with `--test`):
- `RecipeExploderTest`: a fixture template with fixed and per-position lines plus a two-level sub-kit; asserts exact quantities at positions 1 and 3.
- `PackJobTest`: a pack that is short on one consumable throws `InsufficientStock` and leaves zero new rows in `jobs` and `movements`.

Manual checks each phase in the browser. Measured numbers for the README come from a small `bin/bench.php` (explosion query time, pack transaction time, averaged over repeated runs on the seed data).

## 10. Build phases (one commit per phase, pushed)

1. **Scaffold.** Git init, private remote, Composer (PSR-4 + PHPUnit dev), front controller, router, `.env`, PDO, migration runner, `001_init.sql`, seeder skeleton, Vite config, hello page, `DECISIONS.md`, `.env.example`, `docker-compose.yml`.
2. **Ledger and legacy screen.** Views, full seeder, Store screen with jQuery filter and receive modal, `/api/receipts`, `/api/stock`, Ledger view.
3. **Templates and planner.** Template seed, `RecipeExploder` + test, template/plan API, Vue planner with live shortfalls. *Minimum viable stopping point.*
4. **Pack, return, trace.** `PackJob` + test, `ReturnJob`, jobs pages, the three traces, pack form in planner.
5. **Polish.** README (what, four commands, measured numbers, jQuery→Vue migration path, live-URL placeholder), `DECISIONS.md` tidy, keyboard navigation on tables.

Commit bodies explain any PHP 8.4 or MySQL 8 feature introduced in that phase in one or two sentences.

## Out of scope

Auth/users, booking calendars, pricing/invoicing, customers, multiple stores, maintenance/repair, uploads, email, template admin UI, deployment, CI, public repo.
