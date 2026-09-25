<?php

declare(strict_types=1);

// Two crews try to pack the same kit at the same instant, when there is only
// enough gear for one. Each runs in its own process with its own database
// connection. Expected: one packs, the other waits on the row locks, then
// fails cleanly with nothing taken. The winning job is returned afterwards
// unless you pass --keep.
// Usage: php bin/race.php [--keep]   (needs the pcntl extension; CLI only)

use App\Db;
use App\Env;
use App\Service\InsufficientStock;
use App\Service\PackJob;
use App\Service\ReturnJob;

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "bin/race.php needs the pcntl extension (CLI).\n");
    exit(1);
}

$db = Db::connect();
$template = (int) $db->query("SELECT id FROM kit_templates WHERE code = 'FOOTY-BOX'")->fetchColumn();
$receivers = (int) $db->query(<<<'SQL'
    SELECT COUNT(*) FROM gear_item_status s JOIN gear_types gt ON gt.id = s.gear_type_id
    WHERE gt.code = 'IFB-RX' AND s.status = 'available'
    SQL)->fetchColumn();

// Enough positions that one crew fits but two can't (each position needs one IFB receiver).
$positions = intdiv($receivers, 2) + 1;
printf("%d IFB receivers available. Each crew packs a Footy box × %d, so only one can succeed.\n\n", $receivers, $positions);
unset($db); // children open their own connections

$startAt = microtime(true) + 0.5;
$pids = [];
foreach (['Crew A', 'Crew B'] as $crew) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $childDb = Db::connect();
        time_sleep_until($startAt);
        $started = hrtime(true);
        try {
            $jobId = new PackJob($childDb)->pack($template, $positions, "Race: {$crew}", date('Y-m-d'));
            printf("%s  packed job #%d in %.0f ms\n", $crew, $jobId, (hrtime(true) - $started) / 1e6);
            exit(0);
        } catch (InsufficientStock $e) {
            printf("%s  refused after %.0f ms, nothing taken: %s\n", $crew, (hrtime(true) - $started) / 1e6, $e->getMessage());
            exit(2);
        }
    }
    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$db = Db::connect();
$jobs = $db->query("SELECT id, name FROM jobs WHERE name LIKE 'Race: %' AND returned_at IS NULL ORDER BY id")->fetchAll();
printf("\nJobs created: %d. ", count($jobs));
if (!in_array('--keep', $argv, true)) {
    foreach ($jobs as $job) {
        new ReturnJob($db)->return($job['id']);
    }
    echo "Returned them so the store is back as it was (pass --keep to leave them out).\n";
} else {
    echo "Left out on purpose (--keep).\n";
}
