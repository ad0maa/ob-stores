# Decisions

Things the brief left open, and what was decided. Newest at the bottom of each section.

## Environment and tooling

- **MySQL runs in Docker, PHP runs natively.** One `mysql:8.4.11` service in `docker-compose.yml` pins the database version in the repo; PHP is served with `php -S` as the brief asks. Locally the app connects as root because the test suite creates its own `ob_stores_test` database. In production this would be a least-privilege user.
- **Node 24, not 22.** Node only runs Vite at build time and PHP never depends on it. `package.json` declares `engines: >=22`, so 22 LTS works too.
- **PHP pinned with `~8.4.26`** in `composer.json` (at least 8.4.26, below 8.5).
- **No Vite dev server.** `npm run build` (or `npm run watch`) writes to `public/build`, and PHP reads `.vite/manifest.json` to print the hashed script tags. This means one server (`php -S`), no CORS and no HMR wiring, which is how the page would be served in production anyway.
- **jQuery 3.7.1 rather than 4.x** for the legacy screen, because a real legacy screen would be on 3.x. It comes from the pinned npm package and is copied into `public/assets/vendor/` by `npm run build` instead of being downloaded from a CDN.

- **Migrations are split on a semicolon at the end of a line** and run one statement at a time, so a failure points at a single statement. MySQL commits DDL implicitly, so migrations are not wrapped in a transaction.
- **Local preview config lives outside the repo.** Nothing in the repo refers to a local absolute path.

## Domain

- **Planner count multiplies only `per_position` lines, and only at the top level of a template.** "Footy commentary box × 3" means one codec and one mixer plus three commentary positions, not three of everything. Lines inside a sub-kit are per unit of that sub-kit.
- **Faulty is terminal.** There is no repair flow, because maintenance is out of scope.
- **Packed consumables count as consumed.** Nothing comes back on return.
- **A faulty return writes two rows**: a `return` and then a `faulty` (qty 0, with the note). The trail shows both "it came back" and "it's broken".
- **`jobs.returned_at` is written once.** It is job metadata, not stock, so this does not break the append-only rule for `movements`.

- **Timestamps are stored and shown in UTC.** There's one store, so there's no timezone handling. A multi-site version would store UTC and convert on display.
- **Receive modal uses a native `<dialog>`** rather than a jQuery plugin, because it gives focus handling and Esc-to-close for free. Everything else on that screen is deliberately old-style jQuery.
- **Ledger filters by gear serial or by consumable.** For a consumable, the balance is across all its lots; for a serial it is 1 (in store) or 0 (out). The lot is shown on each row and links to its trace.

- **Sub-kits can't be planned or packed directly.** `/api/templates` lists only top-level templates, and asking for a sub-kit's plan returns 404.
- **Positions are 1 to 50.** That bound is in `RecipeExploder::MAX_POSITIONS`, and the Vue screen mirrors it.
- **The Vue planner has no `<style>` blocks.** Its styles live in the one hand-written `app.css`, as the brief requires, so Vite emits JavaScript only.
- **The planner URL is shareable** (`?template=2&positions=3`), kept in sync with `history.replaceState`.

- **Availability is re-read under lock with `FOR SHARE` subqueries.** In InnoDB a subquery inside a `FOR UPDATE` query is still a plain snapshot read unless it has its own locking clause. Under REPEATABLE READ the snapshot is taken at the transaction's first plain read, so the second gear type would be checked against stale data. Every availability read in `PackJob` is therefore a locking read. The alternative, switching the transaction to READ COMMITTED, would also work, but it is less visible in the code.
- **Blocking locks, not `SKIP LOCKED`.** A second packer waits for the first, then sees what's left. `SKIP LOCKED` would let it grab different codecs without waiting, which is better for high-throughput queues but harder to reason about here.
- **A short pack reports every shortfall**, not just the first, so the planner can show the whole problem at once.
- **The return form is a plain HTML form POST** with no JavaScript. The app therefore shows all three generations: plain form (job return), jQuery (store) and Vue (planner), all using the same services.
- **Seeded history goes through `PackJob` and `ReturnJob`** (with an injected timestamp) rather than bulk `INSERT`s, so the seed data obeys the same rules as real use.
- **Lot codes are unique per consumable, not globally.** Tracing a bare lot code that matches two items asks which one you mean.
- **The job page is the third trace** ("from a job, show everything that went"): serials link to their own trace and lots link to theirs.
- **Pagination happens before the joins.** `/jobs` paginates in a derived table before counting per-job movements (270 ms → 26 ms). `/ledger` runs its window over `movements` alone, then joins names onto the 50 rows shown (220 ms → 120 ms).

## PHP 8.4 / MySQL 8 features used

- **`PDO::connect()` (PHP 8.4)** in `src/Db.php`. It returns the driver-specific subclass (`Pdo\Mysql`) instead of a generic `PDO`, so MySQL-only methods and constants live on a MySQL-only class.
- **`readonly` classes (PHP 8.2)** for `Response`: every property is set once in the constructor, and `withStatus()` returns a copy.
- **MySQL 8 `CHECK` constraints** (enforced since 8.0.16) on `template_lines` (exactly one target) and `movements` (exactly one of gear item or lot).
- **Triggers that `SIGNAL`** on `UPDATE` and `DELETE` of `movements`, so append-only is enforced by the database, not just by convention.
- **Backed enum `MovementType`** mirrors the MySQL `ENUM` column. `MovementType::from($row['type'])` turns a database string into a typed value, and `match` over it gives the display label.
- **`new` without extra parentheses (PHP 8.4)**: `new ReceiveConsumable($db)->receive(...)`. Before 8.4 this needed `(new ReceiveConsumable($db))->receive(...)`.
- **Typed class constants (PHP 8.3)**: `private const int DUPLICATE_KEY = 1062;`.
- **`Random\Randomizer` with a seeded `Mt19937` engine (PHP 8.2)** makes the seeder deterministic without relying on global `mt_srand()` state.
- **Window function (MySQL 8)**: the ledger's running balance is `SUM(qty) OVER (PARTITION BY … ORDER BY id)`, computed before `LIMIT`, so every page shows correct balances.
- **Views** (`gear_item_status`, `lot_balances`) hold the "derive current state from the ledger" logic in one place.
- **Property hook (PHP 8.4)**: `PlanLine::$shortfall` is a virtual property, `get => max(0, $this->required - $this->available)`. It reads like a field but is always computed, so it can never disagree with `required` and `available`.
- **Recursive CTE (MySQL 8)**: `RecipeExploder` walks the template tree in one `WITH RECURSIVE` query, multiplying quantities down each level, then sums the leaves. The anchor casts to `UNSIGNED` because a recursive CTE's column types come from the anchor row only.
- **Asymmetric visibility (PHP 8.4)**: `GearItem` has `public private(set) string $status`. Anyone can read it, but only `apply(MovementType)` can change it, and the trace page rebuilds it by replaying the item's ledger rows. This differs from `readonly` because the class itself can keep changing it after construction.
- **Locking reads (MySQL/InnoDB)**: `SELECT … FOR UPDATE` on the never-updated `gear_items` and `lots` rows acts as a mutex per gear type or consumable. `FOR SHARE` on the availability subqueries makes them read the latest committed data instead of the REPEATABLE READ snapshot.
