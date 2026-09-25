<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    /**
     * PDO::connect() (PHP 8.4) returns the driver-specific subclass, Pdo\Mysql.
     * Emulated prepares are off so the server does the parameter binding, and
     * errors throw instead of returning false.
     */
    public static function connect(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4%s',
            Env::get('DB_HOST'),
            Env::get('DB_PORT', '3306'),
            $database === '' ? '' : ';dbname=' . ($database ?? Env::get('DB_NAME')),
        );

        return PDO::connect($dsn, Env::get('DB_USER'), Env::get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
