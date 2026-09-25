<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\HttpError;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/store', fn (): string => 'store');

        self::assertSame('store', $router->dispatch('GET', '/store'));
    }

    public function testPassesPlaceholderAsNamedIntArgument(): void
    {
        $router = new Router();
        $router->get('/jobs/{id}', fn (int $id): int => $id * 2);

        self::assertSame(84, $router->dispatch('GET', '/jobs/42'));
    }

    public function testPlaceholderOnlyMatchesDigits(): void
    {
        $router = new Router();
        $router->get('/jobs/{id}', fn (int $id): int => $id);

        $this->expectExceptionObject(new HttpError(404, 'Not found'));
        $router->dispatch('GET', '/jobs/abc');
    }

    public function testWrongMethodIs405(): void
    {
        $router = new Router();
        $router->post('/api/receipts', fn (): string => 'ok');

        try {
            $router->dispatch('GET', '/api/receipts');
            self::fail('Expected HttpError');
        } catch (HttpError $e) {
            self::assertSame(405, $e->status);
        }
    }
}
