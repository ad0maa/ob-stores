<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

/**
 * Read-only history of every movement. The running balance is a window
 * function over the item's own rows, computed before pagination so page 7
 * shows the same balances as page 1 would.
 */
final class LedgerRepo
{
    public const int PER_PAGE = 50;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function page(?string $serial, ?int $consumableId, int $page): array
    {
        [$where, $params] = $this->filter($serial, $consumableId);

        // Window over the narrow movements table only, then join the names onto
        // just the 50 rows on this page.
        $statement = $this->db->prepare(<<<SQL
            WITH page AS (
                SELECT m.id, m.type, m.qty, m.note, m.created_at, m.job_id, m.gear_item_id, m.lot_id,
                       SUM(m.qty) OVER (
                           PARTITION BY m.gear_item_id, l.consumable_id
                           ORDER BY m.id
                       ) AS balance
                FROM movements m
                LEFT JOIN lots l ON l.id = m.lot_id
                WHERE {$where}
                ORDER BY m.id DESC
                LIMIT :limit OFFSET :offset
            )
            SELECT p.id, p.type, p.qty, p.note, p.created_at, p.job_id, j.name AS job_name,
                   gi.serial, gt.name AS gear_name,
                   l.lot_code, c.id AS consumable_id, c.code AS consumable_code, c.name AS consumable_name,
                   p.balance
            FROM page p
            LEFT JOIN gear_items gi ON gi.id = p.gear_item_id
            LEFT JOIN gear_types gt ON gt.id = gi.gear_type_id
            LEFT JOIN lots l ON l.id = p.lot_id
            LEFT JOIN consumables c ON c.id = l.consumable_id
            LEFT JOIN jobs j ON j.id = p.job_id
            ORDER BY p.id DESC
            SQL);
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', self::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $statement->execute();

        $count = $this->db->prepare(<<<SQL
            SELECT COUNT(*) FROM movements m
            LEFT JOIN lots l ON l.id = m.lot_id
            WHERE {$where}
            SQL);
        $count->execute($params);

        return ['rows' => $statement->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @return list<array{id:int, code:string, name:string}> */
    public function consumableOptions(): array
    {
        return $this->db->query('SELECT id, code, name FROM consumables ORDER BY name')->fetchAll();
    }

    /** @return array{string, array<string, string|int>} */
    private function filter(?string $serial, ?int $consumableId): array
    {
        return match (true) {
            $serial !== null => ['m.gear_item_id = (SELECT id FROM gear_items WHERE serial = :serial)', ['serial' => $serial]],
            $consumableId !== null => ['l.consumable_id = :consumable', ['consumable' => $consumableId]],
            default => ['1 = 1', []],
        };
    }
}
