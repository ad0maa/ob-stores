<?php

declare(strict_types=1);

use App\Controller\ApiController;
use App\Controller\LedgerController;
use App\Controller\StoreController;
use App\Db;
use App\Http\Response;
use App\Http\Router;

$router = new Router();

// One connection per request, opened only if a handler needs it.
$db = function (): PDO {
    static $pdo = null;

    return $pdo ??= Db::connect();
};

$router->get('/', fn () => Response::redirect('/store'));

// Legacy screen (server-rendered + jQuery)
$router->get('/store', fn () => new StoreController($db())->index());
$router->get('/ledger', fn () => new LedgerController($db())->index());

// JSON API shared by both front-end generations
$router->get('/api/stock', fn () => new ApiController($db())->stock());
$router->post('/api/receipts', fn () => new ApiController($db())->receive());

return $router;
