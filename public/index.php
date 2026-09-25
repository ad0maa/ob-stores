<?php

declare(strict_types=1);

use App\Env;
use App\Http\HttpError;
use App\Http\Response;

// Front controller: every request that isn't a real file lands here.
require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// The built-in server asks us first; hand real files (css, js, build output) back to it.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

Env::load(dirname(__DIR__) . '/.env');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$wantsJson = str_starts_with($path, '/api/');

try {
    if ($method === 'POST') {
        csrf_check();
    }
    $router = require dirname(__DIR__) . '/src/routes.php';
    $response = $router->dispatch($method, $path);
} catch (HttpError $error) {
    $response = $wantsJson
        ? Response::json(['error' => $error->getMessage(), 'details' => $error->details], $error->status)
        : page('Error', 'error', ['status' => $error->status, 'message' => $error->getMessage()])->withStatus($error->status);
} catch (Throwable $error) {
    error_log((string) $error);
    $message = Env::get('APP_DEBUG', '0') === '1' ? $error->getMessage() : 'Something went wrong';
    $response = $wantsJson
        ? Response::json(['error' => $message, 'details' => []], 500)
        : page('Error', 'error', ['status' => 500, 'message' => $message])->withStatus(500);
}

$response->send();
