<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

/**
 * Applies database/migrations/*.sql in filename order, each exactly once,
 * recording them in schema_migrations. MySQL commits DDL implicitly, so a
 * migration can't be wrapped in a transaction; keep each file small.
 */
final class Migrator
{
    /** @return list<string> filenames applied on this run */
    public static function run(string $database, bool $fresh = false): array
    {
        if (preg_match('/^\w+$/', $database) !== 1) {
            throw new InvalidArgumentException("Unsafe database name: {$database}");
        }

        $server = Db::connect('');
        if ($fresh) {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

        $db = Db::connect($database);
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(255) PRIMARY KEY,
            applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        )');
        $alreadyApplied = $db->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        $files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $alreadyApplied, true)) {
                continue;
            }
            foreach (self::statements((string) file_get_contents($file)) as $statement) {
                $db->exec($statement);
            }
            $db->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$filename]);
            $applied[] = $filename;
        }

        return $applied;
    }

    /** Split on semicolons that end a line. Good enough for hand-written migrations. @return list<string> */
    private static function statements(string $sql): array
    {
        $withoutComments = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*$/m', $withoutComments) ?: []),
            fn (string $statement): bool => $statement !== '',
        ));
    }
}
