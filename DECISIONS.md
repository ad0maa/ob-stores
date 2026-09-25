# Decisions

These are the things the brief left open, what was decided, and why.

## Environment and tooling

- **MySQL runs in Docker, PHP runs natively.** One `mysql:8.4.11` service in `docker-compose.yml` pins the database version in the repo, and PHP is served with `php -S` as the brief asks. Locally the app connects as root, because the test suite and the benchmark create their own `_test` and `_bench` databases. In production this would be a least-privilege user.
- **Node 24, not 22.** Node only runs Vite at build time, and PHP never depends on it. `package.json` declares `engines: >=22`, so 22 LTS works too.
- **PHP is pinned with `~8.4.26`** in `composer.json`: at least 8.4.26 and below 8.5.
- **No Vite dev server.** `npm run build` (or `npm run watch`) writes to `public/build`, and PHP reads `.vite/manifest.json` to print the hashed script tag. That means one server (`php -S`), no CORS and no HMR wiring, which is how the page would be served in production anyway.
- **jQuery 3.7.1 rather than 4.x** for the legacy screen, because a real legacy screen would be on 3.x. It comes from the pinned npm package and `npm run build` copies it into `public/assets/vendor/`. It is not downloaded from a CDN.
- **Migrations are split on semicolons that end a line** and run one statement at a time, so a failure points at a single statement. MySQL commits DDL implicitly, so migrations are not wrapped in a transaction.
- **Local preview config lives outside the repo.** Nothing in the repo refers to a local absolute path.
- **Migrations run on every deploy.** `php bin/migrate.php` is Railway's pre-deploy command: it runs in the new image before traffic switches, and a failure stops the deploy. The runner skips files it has already applied, so running it every time is safe. It's in `railway.json` and also set on the service, because the first deploy after adding it to `railway.json` alone didn't run it.
- **The database connection retries while MySQL wakes up.** Railway's free plan sleeps idle services, and the web container can wake before MySQL, so the first request failed with `Connection refused`. `Db::connect()` now retries errors 2002, 2006 and 2013 ("no server there yet") for about 8 s, then gives up. Anything else, such as a bad password, fails at once.
- **The seeder waits up to 30 s for MySQL**, so the four-command start works straight after `docker compose up -d`.
- **The favicon and logo are inline SVG.** No downloaded assets.

## The ledger and stock

- **Stock and gear status are derived, never stored.** They come from one `movements` table, through two views: `gear_item_status` (latest movement per item) and `lot_balances` (sum of signed movements per lot). Triggers make `movements` append-only in the database itself.
- **One `movements` table for gear and consumables**, with a `CHECK` that each row has exactly one of `gear_item_id` or `lot_id`. It was chosen over two ledgers, which would double every audit query, and over cached status columns, which would contradict "the ledger is the truth".
- **Faulty until marked repaired.** The brief put maintenance schedules out of scope, but gear has to come back into service. A repair is its own `repaired` ledger row (qty 0, with a note), so the history keeps both the fault and the fix. Faults can also be reported on the shelf, outside a job. Both actions live on the gear-type page (`/gear/{id}`), as plain form posts.
- **A faulty return writes two rows**: a `return`, then a `faulty` row (qty 0, with the note). The trail shows both "it came back" and "it's broken".
- **Packed consumables count as consumed.** Nothing comes back on return.
- **`jobs.returned_at` is written once.** It is job metadata, not stock, so the append-only rule for `movements` still holds.
- **Timestamps are stored and shown in UTC.** There's one store, so there's no timezone handling. A multi-site version would convert on display.
- **Lot codes are unique per consumable, not globally.** Tracing a bare lot code that matches two items asks which one you mean.

## Packing and concurrency

- **Rows that never change act as the mutex.** `gear_items` and `lots` rows are never updated after insert, so `SELECT … FOR UPDATE` on them locks a whole gear type or consumable without contending with anything else.
- **Availability is re-read under lock with `FOR SHARE` subqueries.** In InnoDB, a subquery inside a `FOR UPDATE` query is still a plain snapshot read unless it has its own locking clause. Under REPEATABLE READ that snapshot is taken at the transaction's first plain read, so the second gear type would be checked against stale data. Every availability read in `PackJob` is therefore a locking read. Switching the transaction to READ COMMITTED would also work, but it is less visible in the code.
- **Locks are always taken in the same order**: gear types by id, then consumables by id. Two packers therefore can't deadlock each other. A deadlock reported anyway (`40001`) is retried once.
- **Blocking locks, not `SKIP LOCKED`.** A second packer waits for the first, then sees what's left. `SKIP LOCKED` suits high-throughput queues, but it is harder to reason about here.
- **A short pack reports every shortfall**, not just the first, so the planner can show the whole problem at once.
- **The planner's availability is advisory.** The server re-checks under lock, and if another crew packed in the meantime the planner gets a 409 listing what is now short, then re-plans.
- **Packing always takes the lowest-id available serial.** It's simple and deterministic, but the first few items of each type do most of the work: the gear page shows one router on 320 jobs and several on none. A real store would rotate stock by picking the least-used item first. That's a one-line `ORDER BY` change, left as a talking point.
- **Seeded history goes through `PackJob` and `ReturnJob`** (with an injected timestamp) rather than bulk `INSERT`s, so the seed data obeys the same rules as real use.
- **`bin/race.php` proves the locking with two real processes** (`pcntl_fork`, one connection each). It is a CLI demo only, so the app does not depend on `pcntl`.

## Kits and the planner

- **The planner count multiplies only `per_position` lines, and only at the top level of a template.** "Footy commentary box × 3" means one codec and one mixer plus three commentary positions, not three of everything. Lines inside a sub-kit are per unit of that sub-kit.
- **Sub-kits can't be planned or packed directly.** `/api/templates` lists only top-level templates, and asking for a sub-kit's plan returns 404.
- **Positions run from 1 to 50.** The bound is `RecipeExploder::MAX_POSITIONS`, and the Vue screen mirrors it.
- **The planner URL is shareable** (`?template=2&positions=3`) and is kept in sync with `history.replaceState`.

## Screens

- **Three generations of front end use the same services.** The job return form is a plain HTML POST with no JavaScript. The store is server-rendered with jQuery. The planner is Vue. The README describes how to move a screen from one generation to the next.
- **The receive modal is a native `<dialog>`** rather than a jQuery plugin, because it gives focus handling and Esc-to-close for free. Everything else on that screen is deliberately old-style jQuery.
- **The Vue planner has no `<style>` blocks.** Its styles live in the one hand-written `app.css`, as the brief requires, so Vite emits JavaScript only.
- **Keyboard flow is one plain script (`keys.js`) on every page.** `j`/`k` (or `↓`/`↑` once a row has focus) move between table rows, and `Enter` opens the row. `/` focuses the store filter.
- **The ledger filters by gear serial or by consumable.** For a consumable, the balance runs across all its lots. For a serial, it is 1 (in store) or 0 (out).
- **The job page is the third trace** ("from a job, show everything that went"). Its serials and lots link to their own traces.

## Performance

- **Pagination happens before the joins.** `/jobs` paginates in a derived table before counting per-job movements (270 ms → 26 ms). `/ledger` runs its window over `movements` alone, then joins names onto just the 50 rows shown (220 ms → 120 ms through the browser, 52 ms for the query).
- **Benchmarks run against their own database** (`<DB_NAME>_bench`, freshly seeded), so benchmark jobs never pollute the demo data.
- **The explosion's time goes on availability, not recursion.** `EXPLAIN ANALYZE` shows the recursive CTE takes about 0.7 ms of the 8 ms total. The rest is the correlated latest-movement lookup behind `gear_item_status`. If stock grew large, the first step would be a `(gear_item_id, id)` covering lookup per type, or a small projection table written in the same transaction as each movement.

## PHP 8.4 / MySQL 8 features used

- **`PDO::connect()` (PHP 8.4)** in `src/Db.php`. It returns the driver-specific subclass (`Pdo\Mysql`) instead of a generic `PDO`.
- **Property hook (PHP 8.4)**: `PlanLine::$shortfall` is a virtual property, `get => max(0, $this->required - $this->available)`. It reads like a field but is always computed, so it can never disagree with `required` and `available`.
- **Asymmetric visibility (PHP 8.4)**: `GearItem` has `public private(set) string $status`. Anyone can read it, but only `apply(MovementType)` can change it, and the trace page rebuilds it by replaying the item's ledger rows. Unlike `readonly`, it lets the class itself keep changing the value.
- **`new` without extra parentheses (PHP 8.4)**: `new ReceiveConsumable($db)->receive(...)`. Before 8.4 this needed `(new ReceiveConsumable($db))->receive(...)`.
- **Backed enum `MovementType`** mirrors the MySQL `ENUM` column. `MovementType::from($row['type'])` turns a database string into a typed value, and `match` over it gives the display label.
- **Typed class constants (PHP 8.3)**: `private const int DUPLICATE_KEY = 1062;`.
- **`readonly` classes (PHP 8.2)** for `Response`: every property is set once, and `withStatus()` returns a copy.
- **`Random\Randomizer` with a seeded `Mt19937` engine (PHP 8.2)** makes the seeder deterministic without global `mt_srand()` state.
- **Recursive CTE (MySQL 8)**: `RecipeExploder` walks the template tree in one `WITH RECURSIVE` query, multiplying quantities down each level, then sums the leaves. The anchor casts to `UNSIGNED` because a recursive CTE's column types come from the anchor row only.
- **Window function (MySQL 8)**: the ledger's running balance is `SUM(qty) OVER (PARTITION BY … ORDER BY id)`, computed before `LIMIT` so every page shows correct balances.
- **Locking reads (InnoDB)**: `FOR UPDATE` and `FOR SHARE`, as described under "Packing and concurrency".
- **`CHECK` constraints (MySQL 8.0.16+)**: before 8.0.16, MySQL parsed them and ignored them. Now they're enforced.
- **Triggers that `SIGNAL`** refuse `UPDATE` and `DELETE` on `movements`.
- **Appending an `ENUM` value is instant (MySQL 8)**: migration 003 adds `repaired` to `movements.type`. Adding a value at the end of an `ENUM` only changes table metadata, so it doesn't rebuild the table however many rows it has. Inserting a value in the middle would force a full rebuild.

## Notes from the build

These are candidate answers to "what surprised you about PHP 8.4?". They are facts that came up while building, so pick whichever is genuinely yours.

- A property hook can't go on a `readonly` property, or in a `readonly class`. So `PlanLine` is a normal class with `readonly` promoted properties plus one hooked virtual property.
- PHPUnit 13 marks `TestCase::count()` as `final`, so a helper named `count()` in a base test case is a fatal error.
- `Return` works as an enum case name (`MovementType::Return`) even though `return` is a keyword.
- A virtual (hooked, unbacked) property still shows up in `json_encode()` and `get_object_vars()`, so `PlanLine` serialises to JSON with `shortfall` included and no `JsonSerializable` needed.
- `PDO::connect()` means the connection object's class tells you which database it is (`Pdo\Mysql`), which is the kind of type information the older `new PDO(...)` never had.
