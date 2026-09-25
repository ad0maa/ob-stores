<?php

declare(strict_types=1);

use App\Controller\ApiController;
use App\Controller\GearController;
use App\Controller\JobsController;
use App\Controller\LedgerController;
use App\Controller\PlannerController;
use App\Controller\StoreController;
use App\Controller\TraceController;
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
$router->get('/gear/{id}', fn (int $id) => new GearController($db())->show($id));
$router->post('/gear/{id}/items/{item}/fault', fn (int $id, int $item) => new GearController($db())->reportFault($id, $item));
$router->post('/gear/{id}/items/{item}/repair', fn (int $id, int $item) => new GearController($db())->repair($id, $item));
$router->get('/ledger', fn () => new LedgerController($db())->index());
$router->get('/jobs', fn () => new JobsController($db())->index());
$router->get('/jobs/{id}', fn (int $id) => new JobsController($db())->show($id));
$router->post('/jobs/{id}/return', fn (int $id) => new JobsController($db())->return($id));
$router->get('/trace', fn () => new TraceController($db())->index());

// Modern screen (Vue, built by Vite)
$router->get('/planner', fn () => new PlannerController($db())->index());

// JSON API shared by both front-end generations
$router->get('/api/stock', fn () => new ApiController($db())->stock());
$router->post('/api/receipts', fn () => new ApiController($db())->receive());
$router->get('/api/templates', fn () => new PlannerController($db())->templates());
$router->get('/api/templates/{id}/plan', fn (int $id) => new PlannerController($db())->plan($id));
$router->post('/api/jobs', fn () => new JobsController($db())->pack());

return $router;
