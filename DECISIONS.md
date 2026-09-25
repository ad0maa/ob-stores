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

## PHP 8.4 / MySQL 8 features used

- **`PDO::connect()` (PHP 8.4)** in `src/Db.php`. It returns the driver-specific subclass (`Pdo\Mysql`) instead of a generic `PDO`, so MySQL-only methods and constants live on a MySQL-only class.
- **`readonly` classes (PHP 8.2)** for `Response`: every property is set once in the constructor, and `withStatus()` returns a copy.
- **MySQL 8 `CHECK` constraints** (enforced since 8.0.16) on `template_lines` (exactly one target) and `movements` (exactly one of gear item or lot).
- **Triggers that `SIGNAL`** on `UPDATE` and `DELETE` of `movements`, so append-only is enforced by the database, not just by convention.
