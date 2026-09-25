<?php

declare(strict_types=1);

use App\Http\HttpError;
use App\Http\Response;

const APP_ROOT = __DIR__ . '/..';

/** The only way anything reaches HTML. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

/** Every POST must echo the session token back, as a form field or an X-CSRF-Token header. */
function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        throw new HttpError(403, 'Invalid or missing CSRF token');
    }
}

/** @param array<string, mixed> $vars */
function render(string $template, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . "/templates/{$template}.php";

    return (string) ob_get_clean();
}

/** @param array<string, mixed> $vars */
function page(string $title, string $template, array $vars = [], string $head = ''): Response
{
    return Response::html(render('layout', [
        'title' => $title,
        'content' => render($template, $vars),
        'head' => $head,
    ]));
}

/** @return array<string, mixed> */
function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new HttpError(400, 'Request body must be JSON');
    }

    return is_array($data) ? $data : throw new HttpError(400, 'Request body must be a JSON object');
}

/** Script and style tags for a Vite entry, read from the build manifest. */
function vite_tags(string $entry): string
{
    $manifestPath = APP_ROOT . '/public/build/.vite/manifest.json';
    if (!is_file($manifestPath)) {
        return '<!-- Vite manifest missing: run npm run build -->';
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    $chunk = $manifest[$entry] ?? throw new RuntimeException("No Vite entry {$entry}");

    $tags = array_map(fn (string $css): string => '<link rel="stylesheet" href="/build/' . e($css) . '">', $chunk['css'] ?? []);
    $tags[] = '<script type="module" src="/build/' . e($chunk['file']) . '"></script>';

    return implode("\n", $tags);
}
