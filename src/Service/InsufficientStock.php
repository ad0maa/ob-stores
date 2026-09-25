<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\PlanLine;
use RuntimeException;

/** Thrown inside the pack transaction so it rolls back. Carries every shortfall, not just the first. */
final class InsufficientStock extends RuntimeException
{
    /** @param list<PlanLine> $shortfalls */
    public function __construct(public readonly array $shortfalls)
    {
        $codes = array_map(fn (PlanLine $line): string => "{$line->code} ({$line->shortfall} short)", $shortfalls);
        parent::__construct('Not enough stock: ' . implode(', ', $codes));
    }
}
