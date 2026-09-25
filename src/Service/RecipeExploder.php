<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\PlanLine;
use PDO;

/**
 * Turns a kit template and a position count into a flat list of everything
 * needed, with live availability. One query, however deep the recipe goes.
 */
final class RecipeExploder
{
    public const int MAX_POSITIONS = 50;

    /*
     * tree: walk template_lines from the chosen template down through sub-kits.
     *   Anchor: the template's own lines. Only these can be per_position, so
     *   the planner count multiplies here and nowhere else.
     *   Recursive step: for every line that points at a child template, pull in
     *   the child's lines with the parent's multiplier carried down.
     *   depth < 5 stops a template that (wrongly) contains itself.
     * required: keep only leaves (lines that aren't sub-kits) and add them up,
     *   so a headset needed by three positions becomes one row of 3.
     * Final select: attach current availability from the ledger views.
     */
    public const string SQL = <<<'SQL'
        WITH RECURSIVE tree (gear_type_id, consumable_id, child_template_id, mult, depth) AS (
            SELECT gear_type_id, consumable_id, child_template_id,
                   CAST(qty * IF(per_position, :positions, 1) AS UNSIGNED),
                   CAST(1 AS UNSIGNED)
            FROM template_lines
            WHERE template_id = :template_id
            UNION ALL
            SELECT child.gear_type_id, child.consumable_id, child.child_template_id,
                   parent.mult * child.qty,
                   parent.depth + 1
            FROM tree parent
            JOIN template_lines child ON child.template_id = parent.child_template_id
            WHERE parent.depth < 5
        ),
        required AS (
            SELECT gear_type_id, consumable_id, CAST(SUM(mult) AS UNSIGNED) AS required
            FROM tree
            WHERE child_template_id IS NULL
            GROUP BY gear_type_id, consumable_id
        )
        SELECT 'gear' AS kind, gt.id AS item_id, gt.code, gt.name, 'each' AS unit, r.required,
               (SELECT COUNT(*) FROM gear_item_status s
                WHERE s.gear_type_id = gt.id AND s.status = 'available') AS available
        FROM required r
        JOIN gear_types gt ON gt.id = r.gear_type_id
        UNION ALL
        SELECT 'consumable', c.id, c.code, c.name, c.unit, r.required,
               (SELECT CAST(COALESCE(SUM(b.on_hand), 0) AS SIGNED) FROM lot_balances b
                WHERE b.consumable_id = c.id)
        FROM required r
        JOIN consumables c ON c.id = r.consumable_id
        ORDER BY kind DESC, name
        SQL;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<PlanLine> gear first, then consumables, each by name */
    public function explode(int $templateId, int $positions): array
    {
        if ($positions < 1 || $positions > self::MAX_POSITIONS) {
            throw new ValidationError(['positions' => 'Positions must be between 1 and ' . self::MAX_POSITIONS . '.']);
        }

        $statement = $this->db->prepare(self::SQL);
        $statement->bindValue('positions', $positions, PDO::PARAM_INT);
        $statement->bindValue('template_id', $templateId, PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): PlanLine => new PlanLine(
            kind: $row['kind'],
            itemId: $row['item_id'],
            code: $row['code'],
            name: $row['name'],
            unit: $row['unit'],
            required: (int) $row['required'],
            available: (int) $row['available'],
        ), $statement->fetchAll());
    }
}
