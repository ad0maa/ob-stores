<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Method + path → handler. `{name}` in a pattern matches digits and is passed
 * to the handler as a named int argument, so `/jobs/{id}` calls `fn (int $id)`.
 */
final class Router
{
    /** @var array<string, list<array{string, callable}>> method → [regex, handler] */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(string $method, string $path): mixed
    {
        $pathExists = false;

        foreach ($this->routes as $routeMethod => $routes) {
            foreach ($routes as [$regex, $handler]) {
                if (preg_match($regex, $path, $match) !== 1) {
                    continue;
                }
                if ($routeMethod !== $method) {
                    $pathExists = true;
                    continue;
                }
                $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);

                return $handler(...array_map('intval', $params));
            }
        }

        throw $pathExists ? new HttpError(405, 'Method not allowed') : new HttpError(404, 'Not found');
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[$method][] = [$regex, $handler];
    }
}
