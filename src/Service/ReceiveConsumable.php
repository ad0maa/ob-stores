<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\MovementType;
use DateTimeImmutable;
use PDO;
use PDOException;

/** Receiving never edits a quantity: it creates a lot and one +qty ledger row, together or not at all. */
final class ReceiveConsumable
{
    private const int DUPLICATE_KEY = 1062;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return int the new lot id */
    public function receive(int $consumableId, string $lotCode, int $qty, string $receivedOn, ?DateTimeImmutable $at = null): int
    {
        $lotCode = trim($lotCode);
        $errors = [];
        if ($lotCode === '' || mb_strlen($lotCode) > 40) {
            $errors['lot_code'] = 'Lot code is required (40 characters max).';
        }
        if ($qty < 1 || $qty > 100_000) {
            $errors['qty'] = 'Quantity must be between 1 and 100,000.';
        }
        if (!Clock::isDate($receivedOn)) {
            $errors['received_on'] = 'Received date must be a real date (YYYY-MM-DD).';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }

        $exists = $this->db->prepare('SELECT 1 FROM consumables WHERE id = ?');
        $exists->execute([$consumableId]);
        if ($exists->fetchColumn() === false) {
            throw new ValidationError(['consumable_id' => 'Unknown consumable.']);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO lots (consumable_id, lot_code, received_on) VALUES (?, ?, ?)')
                ->execute([$consumableId, $lotCode, $receivedOn]);
            $lotId = (int) $this->db->lastInsertId();

            $this->db->prepare('INSERT INTO movements (type, qty, lot_id, created_at) VALUES (?, ?, ?, ?)')
                ->execute([MovementType::Receipt->value, $qty, $lotId, Clock::stamp($at)]);

            $this->db->commit();

            return $lotId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            if (($e->errorInfo[1] ?? null) === self::DUPLICATE_KEY) {
                throw new ValidationError(['lot_code' => "Lot {$lotCode} has already been received for this item."]);
            }
            throw $e;
        }
    }
}
