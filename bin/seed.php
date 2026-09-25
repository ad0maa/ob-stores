<?php

declare(strict_types=1);

// Rebuild the database and fill it with invented, deterministic demo data.
// Usage: php bin/seed.php

use App\Db;
use App\Env;
use App\Migrator;
use App\Seed\DemoSeeder;

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

// A freshly started MySQL container takes a few seconds to accept connections.
for ($attempt = 1; ; $attempt++) {
    try {
        Db::connect('');
        break;
    } catch (PDOException $e) {
        if ($attempt === 30) {
            throw $e;
        }
        echo "Waiting for MySQL…\n";
        sleep(1);
    }
}

$started = hrtime(true);
Migrator::run(Env::get('DB_NAME'), fresh: true);
$db = Db::connect();

$seeder = new DemoSeeder(
    $db,
    require dirname(__DIR__) . '/database/seed/catalogue.php',
    historyStart: new DateTimeImmutable('-18 months midnight'),
);
$seeder->catalogue();
$seeder->openingStock();
$history = $seeder->history(until: new DateTimeImmutable('today'));

$counts = $db->query(<<<'SQL'
    SELECT (SELECT COUNT(*) FROM gear_items) AS gear_items,
           (SELECT COUNT(*) FROM lots) AS lots,
           (SELECT COUNT(*) FROM kit_templates) AS templates,
           (SELECT COUNT(*) FROM jobs) AS jobs,
           (SELECT COUNT(*) FROM movements) AS movements
    SQL)->fetch();

printf("Seeded in %.1fs: %s\n", (hrtime(true) - $started) / 1e9, http_build_query($counts, arg_separator: ', '));
printf("History: %s\n", http_build_query($history, arg_separator: ', '));
