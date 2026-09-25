<?php

declare(strict_types=1);

// Measure the numbers quoted in the README against a freshly seeded copy of
// the demo data (database <DB_NAME>_bench), so benchmark jobs never pollute
// the real one.
// Usage: php bin/bench.php

use App\Db;
use App\Env;
use App\Migrator;
use App\Repo\LedgerRepo;
use App\Repo\StockRepo;
use App\Seed\DemoSeeder;
use App\Service\PackJob;
use App\Service\ReceiveConsumable;
use App\Service\RecipeExploder;
use App\Service\ReturnJob;

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
putenv('DB_NAME=' . Env::get('DB_NAME') . '_bench');

echo "Seeding bench database…\n";
$seedStarted = hrtime(true);
Migrator::run(Env::get('DB_NAME'), fresh: true);
$db = Db::connect();
$seeder = new DemoSeeder($db, require dirname(__DIR__) . '/database/seed/catalogue.php', historyStart: new DateTimeImmutable('-18 months midnight'));
$seeder->catalogue();
$seeder->openingStock();
$seeder->history(until: new DateTimeImmutable('today'));
$seedSeconds = (hrtime(true) - $seedStarted) / 1e9;

$footy = (int) $db->query("SELECT id FROM kit_templates WHERE code = 'FOOTY-BOX'")->fetchColumn();

/** Run $fn $times times; return [median, p95] in milliseconds. @return array{float, float} */
function measure(int $times, callable $fn): array
{
    $fn(); // warm up
    $samples = [];
    for ($i = 0; $i < $times; $i++) {
        $started = hrtime(true);
        $fn();
        $samples[] = (hrtime(true) - $started) / 1e6;
    }
    sort($samples);

    return [$samples[intdiv($times, 2)], $samples[(int) floor($times * 0.95)]];
}

$results = [];

$results['Recipe explosion (Footy box × 3, recursive CTE + availability)'] =
    measure(200, fn () => new RecipeExploder($db)->explode($footy, 3));

$results['Store screen queries (gear summary + consumables)'] =
    measure(100, function () use ($db): void {
        $stock = new StockRepo($db);
        $stock->gearSummary();
        $stock->consumables();
    });

$results['Ledger page 1, unfiltered (window over every movement)'] =
    measure(50, fn () => new LedgerRepo($db)->page(null, null, 1));

$results['Ledger page 1, one serial'] =
    measure(200, fn () => new LedgerRepo($db)->page('CDC-0011', null, 1));

// Pack a real job (17 serials + 6 consumables, 23 ledger rows), then return it so stock recovers.
$packJob = new PackJob($db);
$returnJob = new ReturnJob($db);
// 100 packs use up consumables, so top every one up first (bench database only).
foreach ($db->query('SELECT id FROM consumables')->fetchAll(PDO::FETCH_COLUMN) as $consumableId) {
    new ReceiveConsumable($db)->receive($consumableId, 'BENCH-TOPUP', 50_000, date('Y-m-d'));
}

// Time only the pack call; the return afterwards puts the gear back so every run sees the same stock.
$packSamples = [];
for ($i = 0; $i < 100; $i++) {
    $started = hrtime(true);
    $jobId = $packJob->pack($footy, 3, 'Bench', date('Y-m-d'));
    $packSamples[] = (hrtime(true) - $started) / 1e6;
    $returnJob->return($jobId);
}
sort($packSamples);
$results['Pack a job (Footy box × 3: locks, allocation, 24 inserts, commit)'] = [$packSamples[50], $packSamples[95]];

$counts = $db->query('SELECT (SELECT COUNT(*) FROM movements) AS movements, (SELECT COUNT(*) FROM jobs) AS jobs, (SELECT COUNT(*) FROM lots) AS lots')->fetch();

printf("\nData: %s movements, %s jobs, %s lots, 300 serialised items (seeded in %.1fs)\n", number_format($counts['movements']), number_format($counts['jobs']), $counts['lots'], $seedSeconds);
printf("PHP %s, MySQL %s, times include the round trip to MySQL in Docker\n\n", PHP_VERSION, $db->query('SELECT VERSION()')->fetchColumn());
printf("| %-70s | %8s | %8s |\n|%s|%s|%s|\n", 'Operation', 'median', 'p95', str_repeat('-', 72), str_repeat('-', 10), str_repeat('-', 10));
foreach ($results as $label => [$median, $p95]) {
    printf("| %-70s | %6.2f ms | %6.2f ms |\n", $label, $median, $p95);
}

$explain = str_replace([':positions', ':template_id'], ['3', (string) $footy], RecipeExploder::SQL);
echo "\nEXPLAIN ANALYZE, recipe explosion:\n\n";
echo $db->query('EXPLAIN ANALYZE ' . $explain)->fetchColumn(), "\n";
