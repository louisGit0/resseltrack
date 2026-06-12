<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Thin PDO singleton. All queries in the app go through prepared statements
 * obtained from this connection — never string concatenation.
 */
final class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'reselltrack');
        $user = Env::get('DB_USER', 'reselltrack');
        $pass = Env::get('DB_PASSWORD', 'reselltrack');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $pass, $options);
            Schema::ensure(self::$instance);
        } catch (PDOException $e) {
            // Avoid leaking credentials; show a friendly message.
            http_response_code(500);
            echo 'Database connection failed. Is the db service ready?';
            error_log('DB connection error: ' . $e->getMessage());
            exit;
        }

        return self::$instance;
    }
}
