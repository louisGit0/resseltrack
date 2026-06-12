<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader. Values already present in the real environment
 * (e.g. injected by docker-compose) take precedence over the file, so the
 * app boots even when no .env file exists.
 */
final class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip surrounding quotes if present.
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
                    $value = substr($value, 1, -1);
                }
                self::$vars[$key] = $value;
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // Real environment wins (docker-compose `environment:` block).
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        return $default;
    }
}
