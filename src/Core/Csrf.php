<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Per-session CSRF token. token() emits a hidden input; validate() must be
 * called at the top of every POST handler.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /** Hidden field for forms. */
    public static function field(): string
    {
        $t = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $t . '">';
    }

    public static function isValid(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION[self::KEY])
            && hash_equals($_SESSION[self::KEY], $token);
    }

    /** Aborts the request with 419 if the submitted token is invalid. */
    public static function validate(): void
    {
        $token = $_POST['_csrf'] ?? null;
        if (!self::isValid($token)) {
            http_response_code(419);
            echo 'Jeton CSRF invalide ou expiré. Veuillez recharger la page.';
            exit;
        }
    }
}
