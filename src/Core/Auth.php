<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Session-based authentication. Passwords are hashed with password_hash()
 * and checked with password_verify(). Session id is regenerated on login.
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // D-02: Register MySQL-backed handler before session_start().
        $handler = new DatabaseSessionHandler();
        session_set_save_handler($handler, true); // true = register session_write_close as shutdown fn

        $secure   = Env::get('SESSION_SECURE', '0') === '1';
        $lifetime = 30 * 86400; // D-05: 30-day persistent cookie

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('RESELLTRACK_SESS');
        session_start();
    }

    /** Verify credentials and, on success, log the user in. */
    public static function attempt(string $email, string $password): bool
    {
        $user = (new User())->findByEmail($email);
        if ($user === null) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        self::login((int) $user['id'], $user['name']);
        return true;
    }

    public static function login(int $userId, string $name): void
    {
        // Prevent session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $name;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function name(): string
    {
        return $_SESSION['user_name'] ?? '';
    }

    /** Redirect to login if not authenticated. */
    public static function require(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }
}
