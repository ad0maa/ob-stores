<?php

declare(strict_types=1);

use App\Env;
use App\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

// Integration tests run against a real MySQL database, rebuilt once per run.
Env::load(dirname(__DIR__) . '/.env');
putenv('DB_NAME=' . Env::get('DB_NAME') . '_test');
Migrator::run(Env::get('DB_NAME'), fresh: true);
