<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\MovementType;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Faults found on the shelf, and repairs. Neither changes a status column:
 * each writes one qty-0 ledger row with a note, and status follows from it.
 */
final class GearMaintenance
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function reportFault(int $gearItemId, string $note, ?DateTimeImmutable $at = null): void
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 255) {
            throw new ValidationError(['note' => 'Describe the fault (255 characters max).']);
        }

        $this->record($gearItemId, MovementType::Faulty, $note, ['receipt', 'return', 'repaired'], 'Only an item that is in the store can be reported faulty.', $at);
    }

    public function markRepaired(int $gearItemId, string $note, ?DateTimeImmutable $at = null): void
    {
        $note = mb_substr(trim($note), 0, 255) ?: 'Repaired';

        $this->record($gearItemId, MovementType::Repaired, $note, ['faulty'], 'Only a faulty item can be marked repaired.', $at);
    }

    /** @param list<string> $allowedFrom movement types the item's latest row must be one of */
    private function record(int $gearItemId, MovementType $type, string $note, array $allowedFrom, string $refusal, ?DateTimeImmutable $at): void
    {
        $this->db->beginTransaction();
        try {
            // Same lock PackJob and ReturnJob take, so a repair can't race a pack of the same item.
            $lock = $this->db->prepare('SELECT id FROM gear_items WHERE id = ? FOR UPDATE');
            $lock->execute([$gearItemId]);
            if ($lock->fetchColumn() === false) {
                throw new ValidationError(['gear_item' => 'No such gear item.']);
            }

            $latest = $this->db->prepare('SELECT type FROM movements WHERE gear_item_id = ? ORDER BY id DESC LIMIT 1 FOR SHARE');
            $latest->execute([$gearItemId]);
            if (!in_array($latest->fetchColumn(), $allowedFrom, true)) {
                throw new ValidationError(['gear_item' => $refusal]);
            }

            $this->db->prepare('INSERT INTO movements (type, qty, gear_item_id, note, created_at) VALUES (?, 0, ?, ?, ?)')
                ->execute([$type->value, $gearItemId, $note, Clock::stamp($at)]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
