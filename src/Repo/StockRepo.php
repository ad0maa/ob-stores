<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

/** Read side of the store screen. Every number here is derived from the ledger views. */
final class StockRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array{id:int, code:string, name:string, category:string, available:int, out:int, faulty:int, total:int}> */
    public function gearSummary(): array
    {
        return $this->db->query(<<<'SQL'
            SELECT gt.id, gt.code, gt.name, gt.category,
                   CAST(COALESCE(SUM(s.status = 'available'), 0) AS SIGNED) AS available,
                   CAST(COALESCE(SUM(s.status = 'out'), 0) AS SIGNED) AS `out`,
                   CAST(COALESCE(SUM(s.status = 'faulty'), 0) AS SIGNED) AS faulty,
                   COUNT(s.gear_item_id) AS total
            FROM gear_types gt
            LEFT JOIN gear_item_status s ON s.gear_type_id = gt.id
            GROUP BY gt.id
            ORDER BY gt.category, gt.name
            SQL)->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function consumables(): array
    {
        return array_map($this->withReorderFlag(...), $this->db->query($this->consumableSql('1 = 1'))->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function consumable(int $id): ?array
    {
        $statement = $this->db->prepare($this->consumableSql('c.id = ?'));
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->withReorderFlag($row);
    }

    private function consumableSql(string $where): string
    {
        return <<<SQL
            SELECT c.id, c.code, c.name, c.unit, c.reorder_point,
                   CAST(COALESCE(SUM(b.on_hand), 0) AS SIGNED) AS on_hand,
                   COUNT(CASE WHEN b.on_hand > 0 THEN 1 END) AS open_lots,
                   MIN(CASE WHEN b.on_hand > 0 THEN b.received_on END) AS oldest_open_lot
            FROM consumables c
            LEFT JOIN lot_balances b ON b.consumable_id = c.id
            WHERE {$where}
            GROUP BY c.id
            ORDER BY c.name
            SQL;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function withReorderFlag(array $row): array
    {
        return $row + ['below_reorder' => $row['on_hand'] < $row['reorder_point']];
    }
}
