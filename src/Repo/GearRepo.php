<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

final class GearRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function type(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, code, name, category FROM gear_types WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /**
     * Every serial of one type, faulty first (they need attention), then out, then available.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $typeId): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT s.gear_item_id AS id, s.serial, s.status, s.since, s.job_id, j.name AS job_name, gi.acquired_on,
                   (SELECT m.note FROM movements m
                    WHERE m.gear_item_id = s.gear_item_id AND m.type = 'faulty'
                    ORDER BY m.id DESC LIMIT 1) AS fault_note,
                   (SELECT COUNT(*) FROM movements m
                    WHERE m.gear_item_id = s.gear_item_id AND m.type = 'checkout') AS job_count
            FROM gear_item_status s
            JOIN gear_items gi ON gi.id = s.gear_item_id
            LEFT JOIN jobs j ON j.id = s.job_id
            WHERE s.gear_type_id = ?
            ORDER BY FIELD(s.status, 'faulty', 'out', 'available'), s.serial
            SQL);
        $statement->execute([$typeId]);

        return $statement->fetchAll();
    }
}
