<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/** Formats a movement timestamp. Services take an optional $at so the seeder can write history. */
final class Clock
{
    public static function stamp(?DateTimeImmutable $at = null): string
    {
        return ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }

    public static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
