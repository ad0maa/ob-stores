<?php

declare(strict_types=1);

// Rebuild the database and fill it with invented, deterministic demo data.
// Usage: php bin/seed.php

use App\Db;
use App\Env;
use App\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

Migrator::run(Env::get('DB_NAME'), fresh: true);
$db = Db::connect();
$catalogue = require dirname(__DIR__) . '/database/seed/catalogue.php';

$insertType = $db->prepare('INSERT INTO gear_types (code, name, category) VALUES (?, ?, ?)');
foreach ($catalogue['gear'] as [$code, $name, $category]) {
    $insertType->execute([$code, $name, $category]);
}

echo 'Seeded ' . count($catalogue['gear']) . " gear types\n";
