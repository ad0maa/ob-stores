<?php

declare(strict_types=1);

namespace App\Domain;

/** Mirrors the movements.type ENUM column. Backed, so ->value is what gets stored. */
enum MovementType: string
{
    case Receipt = 'receipt';
    case Consume = 'consume';
    case Checkout = 'checkout';
    case Return = 'return';
    case Faulty = 'faulty';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Received',
            self::Consume => 'Consumed',
            self::Checkout => 'Checked out',
            self::Return => 'Returned',
            self::Faulty => 'Marked faulty',
        };
    }
}
