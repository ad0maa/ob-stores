<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

final class JobRepo
{
    public const int PER_PAGE = 50;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Paginate in a derived table first, so the per-job counts run for the 50
     * rows on this page rather than for every job ever packed.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(int $page): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT j.id, j.name, j.job_date, j.positions, j.packed_at, j.returned_at, t.name AS template_name,
                   (SELECT COUNT(*) FROM movements m WHERE m.job_id = j.id AND m.type = 'checkout') AS gear_count,
                   (SELECT COUNT(*) FROM movements m WHERE m.job_id = j.id AND m.type = 'faulty') AS faulty_count
            FROM (SELECT * FROM jobs ORDER BY id DESC LIMIT :limit OFFSET :offset) j
            JOIN kit_templates t ON t.id = j.template_id
            ORDER BY j.id DESC
            SQL);
        $statement->bindValue('limit', self::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => (int) $this->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn()];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT j.id, j.name, j.job_date, j.positions, j.packed_at, j.returned_at, t.name AS template_name, t.id AS template_id
            FROM jobs j JOIN kit_templates t ON t.id = j.template_id
            WHERE j.id = ?
            SQL);
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /** Every serialised item that went out on the job, and what happened when it came back. @return list<array<string, mixed>> */
    public function gear(int $jobId): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT gi.id, gi.serial, gt.code, gt.name,
                   MAX(m.type = 'return') AS returned,
                   MAX(CASE WHEN m.type = 'faulty' THEN m.note END) AS faulty_note
            FROM movements m
            JOIN gear_items gi ON gi.id = m.gear_item_id
            JOIN gear_types gt ON gt.id = gi.gear_type_id
            WHERE m.job_id = ?
            GROUP BY gi.id
            ORDER BY gt.name, gi.serial
            SQL);
        $statement->execute([$jobId]);

        return $statement->fetchAll();
    }

    /** Consumables packed, one row per lot drawn from. @return list<array<string, mixed>> */
    public function consumables(int $jobId): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT c.id AS consumable_id, c.code, c.name, c.unit, l.lot_code, l.received_on, -m.qty AS qty
            FROM movements m
            JOIN lots l ON l.id = m.lot_id
            JOIN consumables c ON c.id = l.consumable_id
            WHERE m.job_id = ? AND m.type = 'consume'
            ORDER BY c.name, l.received_on
            SQL);
        $statement->execute([$jobId]);

        return $statement->fetchAll();
    }
}
