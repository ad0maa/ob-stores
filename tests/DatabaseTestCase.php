<?php

declare(strict_types=1);

namespace Tests;

use App\Db;
use PDO;
use PHPUnit\Framework\TestCase;

/** Real MySQL, emptied before every test. TRUNCATE bypasses the append-only triggers on purpose. */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        $this->db = Db::connect();
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['movements', 'jobs', 'template_lines', 'kit_templates', 'lots', 'consumables', 'gear_items', 'gear_types'] as $table) {
            $this->db->exec("TRUNCATE TABLE {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<string, scalar|null> $row */
    protected function insert(string $table, array $row): int
    {
        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $this->db->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})")->execute(array_values($row));

        return (int) $this->db->lastInsertId();
    }

    protected function rowCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
