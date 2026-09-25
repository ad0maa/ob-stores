<?php

declare(strict_types=1);

namespace App\Domain;

/** One leaf of an exploded recipe: how many are needed against how many are available now. */
final class PlanLine
{
    public function __construct(
        public readonly string $kind,
        public readonly int $itemId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $unit,
        public readonly int $required,
        public readonly int $available,
    ) {
    }

    /** A virtual property (PHP 8.4 hook): computed on read, never stored, but still included by json_encode(). */
    public int $shortfall {
        get => max(0, $this->required - $this->available);
    }
}
