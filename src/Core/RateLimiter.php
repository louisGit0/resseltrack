<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Login brute-force protection backed by a small MySQL table.
 * Policy: max 5 failed attempts per (email, ip) within 15 minutes.
 *
 * The table is created lazily (CREATE TABLE IF NOT EXISTS) so existing
 * deployments whose data volume predates this feature work without a
 * manual migration; fresh installs also get it from sql/schema.sql.
 */
final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->ensureTable();
    }

    /** Minutes remaining before retry is allowed, or 0 if not blocked. */
    public function minutesUntilRetry(string $email, string $ip): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS cnt, MAX(attempted_at) AS last_try
             FROM login_attempts
             WHERE email = :email AND ip = :ip
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute(['email' => $email, 'ip' => $ip]);
        $row = $stmt->fetch();

        if ((int) $row['cnt'] < self::MAX_ATTEMPTS) {
            return 0;
        }

        $last = new \DateTimeImmutable((string) $row['last_try']);
        $unlockAt = $last->modify('+' . self::WINDOW_MINUTES . ' minutes');
        $now = new \DateTimeImmutable();
        if ($unlockAt <= $now) {
            return 0;
        }
        return max(1, (int) ceil(($unlockAt->getTimestamp() - $now->getTimestamp()) / 60));
    }

    public function recordFailure(string $email, string $ip): void
    {
        // Opportunistic cleanup of stale rows (> 1 hour).
        $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (:email, :ip, NOW())'
        );
        $stmt->execute(['email' => $email, 'ip' => $ip]);
    }

    public function reset(string $email, string $ip): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM login_attempts WHERE email = :email AND ip = :ip'
        );
        $stmt->execute(['email' => $email, 'ip' => $ip]);
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                attempted_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_attempts_email_ip (email, ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
