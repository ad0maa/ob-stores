<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Db
{
    /**
     * Connect-time errors that mean "no server there yet": 2002 refused,
     * 2006 gone away (a proxy accepted, but nothing behind it), 2013 lost
     * during the handshake.
     */
    private const array SERVER_NOT_READY = [2002, 2006, 2013];

    private const int CONNECT_ATTEMPTS = 8;

    /**
     * PDO::connect() (PHP 8.4) returns the driver-specific subclass, Pdo\Mysql.
     * Emulated prepares are off so the server does the parameter binding, and
     * errors throw instead of returning false.
     *
     * A connection the server isn't ready for is retried with a growing pause (about 8 s in all),
     * because on a host that sleeps idle services the web container can wake
     * before MySQL does. Any other error fails immediately.
     */
    public static function connect(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4%s',
            Env::get('DB_HOST'),
            Env::get('DB_PORT', '3306'),
            $database === '' ? '' : ';dbname=' . ($database ?? Env::get('DB_NAME')),
        );

        for ($attempt = 1; ; $attempt++) {
            try {
                return PDO::connect($dsn, Env::get('DB_USER'), Env::get('DB_PASSWORD'), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                if (!in_array($e->getCode(), self::SERVER_NOT_READY, true) || $attempt === self::CONNECT_ATTEMPTS) {
                    throw $e;
                }
                usleep(300_000 * $attempt);
            }
        }
    }
}
