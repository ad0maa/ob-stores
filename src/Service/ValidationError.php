<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Bad input, reported per field. The front controller turns it into a 422. */
final class ValidationError extends RuntimeException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
