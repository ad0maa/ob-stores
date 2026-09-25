<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpError;
use App\Http\Response;
use App\Repo\GearRepo;
use App\Service\GearMaintenance;
use App\Service\ValidationError;
use PDO;

/** One gear type: every serial, where it is, and repair / report-fault as plain form posts. */
final class GearController
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string, string> $errors */
    public function show(int $id, array $errors = [], ?int $errorItem = null): Response
    {
        $gear = new GearRepo($this->db);
        $type = $gear->type($id) ?? throw new HttpError(404, 'Gear type not found');
        $items = $gear->items($id);

        return page($type['name'], 'gear', [
            'type' => $type,
            'items' => $items,
            'counts' => array_count_values(array_column($items, 'status')) + ['available' => 0, 'out' => 0, 'faulty' => 0],
            'errors' => $errors,
            'errorItem' => $errorItem,
        ])->withStatus($errors === [] ? 200 : 422);
    }

    public function reportFault(int $id, int $item): Response
    {
        return $this->act($id, $item, fn (GearMaintenance $maintenance, string $note) => $maintenance->reportFault($item, $note));
    }

    public function repair(int $id, int $item): Response
    {
        return $this->act($id, $item, fn (GearMaintenance $maintenance, string $note) => $maintenance->markRepaired($item, $note));
    }

    private function act(int $id, int $item, callable $action): Response
    {
        $this->assertItemBelongsToType($id, $item);
        try {
            $action(new GearMaintenance($this->db), is_string($_POST['note'] ?? null) ? $_POST['note'] : '');
        } catch (ValidationError $e) {
            return $this->show($id, $e->errors, $item);
        }

        return Response::redirect("/gear/{$id}#item-{$item}");
    }

    private function assertItemBelongsToType(int $typeId, int $itemId): void
    {
        $statement = $this->db->prepare('SELECT 1 FROM gear_items WHERE id = ? AND gear_type_id = ?');
        $statement->execute([$itemId, $typeId]);
        if ($statement->fetchColumn() === false) {
            throw new HttpError(404, 'That item is not part of this gear type');
        }
    }
}
