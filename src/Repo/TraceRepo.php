<?php

declare(strict_types=1);

namespace App\Repo;

use App\Domain\GearItem;
use App\Domain\MovementType;
use PDO;

/** Tracing is just reading the ledger by a different key. */
final class TraceRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function gearItem(string $serial): ?GearItem
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT gi.id, gi.serial, gt.name AS type_name, gi.acquired_on
            FROM gear_items gi JOIN gear_types gt ON gt.id = gi.gear_type_id
            WHERE gi.serial = ?
            SQL);
        $statement->execute([$serial]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $item = new GearItem($row['id'], $row['serial'], $row['type_name'], $row['acquired_on']);
        $movements = $this->db->prepare('SELECT type FROM movements WHERE gear_item_id = ? ORDER BY id');
        $movements->execute([$item->id]);
        foreach ($movements->fetchAll(PDO::FETCH_COLUMN) as $type) {
            $item->apply(MovementType::from($type));
        }

        return $item;
    }

    /** Every job a gear item went out on. @return list<array<string, mixed>> */
    public function jobsForGear(int $gearItemId): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT j.id, j.name, j.job_date, t.name AS template_name,
                   MIN(CASE WHEN m.type = 'checkout' THEN m.created_at END) AS out_at,
                   MIN(CASE WHEN m.type = 'return' THEN m.created_at END) AS back_at,
                   MAX(CASE WHEN m.type = 'faulty' THEN m.note END) AS faulty_note
            FROM movements m
            JOIN jobs j ON j.id = m.job_id
            JOIN kit_templates t ON t.id = j.template_id
            WHERE m.gear_item_id = ?
            GROUP BY j.id
            ORDER BY out_at DESC
            SQL);
        $statement->execute([$gearItemId]);

        return $statement->fetchAll();
    }

    /** Lot codes are unique per consumable, so a bare code can match more than one lot. @return list<array<string, mixed>> */
    public function lots(string $lotCode, ?int $consumableId): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT b.lot_id, b.lot_code, b.received_on, b.on_hand, c.id AS consumable_id, c.name, c.unit,
                   (SELECT qty FROM movements WHERE lot_id = b.lot_id AND type = 'receipt' ORDER BY id LIMIT 1) AS received_qty
            FROM lot_balances b
            JOIN consumables c ON c.id = b.consumable_id
            WHERE b.lot_code = :code AND (:consumable IS NULL OR b.consumable_id = :consumable_again)
            ORDER BY c.name
            SQL);
        $statement->bindValue('code', $lotCode);
        $statement->bindValue('consumable', $consumableId, $consumableId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('consumable_again', $consumableId, $consumableId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** Every job a lot was packed into. @return list<array<string, mixed>> */
    public function jobsForLot(int $lotId): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT j.id, j.name, j.job_date, t.name AS template_name, CAST(-SUM(m.qty) AS SIGNED) AS qty
            FROM movements m
            JOIN jobs j ON j.id = m.job_id
            JOIN kit_templates t ON t.id = j.template_id
            WHERE m.lot_id = ? AND m.type = 'consume'
            GROUP BY j.id
            ORDER BY j.job_date DESC, j.id DESC
            SQL);
        $statement->execute([$lotId]);

        return $statement->fetchAll();
    }
}
