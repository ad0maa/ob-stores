<?php

declare(strict_types=1);

// Apply pending migrations.
// Usage: php bin/migrate.php [--fresh] [--test]
//   --fresh  drop and recreate the database first
//   --test   target the <DB_NAME>_test database used by PHPUnit

use App\Env;
use App\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

$args = array_slice($argv, 1);
$database = Env::get('DB_NAME') . (in_array('--test', $args, true) ? '_test' : '');

$applied = Migrator::run($database, fresh: in_array('--fresh', $args, true));

echo $applied === [] ? "{$database}: nothing to apply\n" : "{$database}: applied " . implode(', ', $applied) . "\n";
