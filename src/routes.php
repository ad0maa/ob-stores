<?php

declare(strict_types=1);

use App\Db;
use App\Http\Router;

$router = new Router();

// One connection per request, opened only if a handler needs it.
$db = function (): PDO {
    static $pdo = null;

    return $pdo ??= Db::connect();
};

$router->get('/', fn () => page('ob-stores', 'home', [
    'mysqlVersion' => (string) $db()->query('SELECT VERSION()')->fetchColumn(),
], vite_tags('frontend/planner/main.js')));

return $router;
