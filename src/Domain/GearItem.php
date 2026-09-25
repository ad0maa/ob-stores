<?php

declare(strict_types=1);

namespace App\Domain;

use LogicException;

/**
 * A serialised item whose status is rebuilt by replaying its ledger rows.
 *
 * Asymmetric visibility (PHP 8.4): anyone can read $status, but only this
 * class can write it, and only through apply(). That is the ledger rule in
 * miniature: status is never set, it is the result of the movements.
 */
final class GearItem
{
    public private(set) string $status = 'new';
    public private(set) int $movementCount = 0;

    public function __construct(
        public readonly int $id,
        public readonly string $serial,
        public readonly string $typeName,
        public readonly string $acquiredOn,
    ) {
    }

    public function apply(MovementType $movement): void
    {
        $this->status = match ($movement) {
            MovementType::Receipt, MovementType::Return => 'available',
            MovementType::Checkout => 'out',
            MovementType::Faulty => 'faulty',
            MovementType::Consume => throw new LogicException('Gear is checked out, not consumed'),
        };
        $this->movementCount++;
    }
}
