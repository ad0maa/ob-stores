<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** Minimal .env loader. Real environment variables win over the file, so production never needs one. */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            self::$values[$key] = trim($value, "\"'");
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        $fromEnvironment = getenv($key);

        return match (true) {
            $fromEnvironment !== false => $fromEnvironment,
            isset(self::$values[$key]) => self::$values[$key],
            $default !== null => $default,
            default => throw new RuntimeException("Missing environment variable {$key}"),
        };
    }
}
