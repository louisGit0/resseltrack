<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * MySQL-backed PHP session handler.
 * Uses the Database::connection() singleton (TLS PDO from Phase 2).
 * Called by Auth::start() via session_set_save_handler($this, true).
 *
 * Table: sessions(id VARCHAR(128) PK, data MEDIUMBLOB, expires_at INT UNSIGNED)
 * TTL: 30 days (aligned with cookie lifetime D-05).
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private const TTL = 30 * 86400; // 30 days in seconds

    /**
     * Called by session_start(). PDO is managed by Database::connection().
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Called after write() at shutdown. PDO connection persists for request life.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Lazy expiry: expired rows return '' (PHP treats as a fresh session).
     * Returns '' (not false) on miss — false signals an error to PHP.
     */
    public function read(string $id): string|false
    {
        $stmt = Database::connection()->prepare(
            'SELECT data FROM sessions WHERE id = ? AND expires_at >= UNIX_TIMESTAMP()'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (string) $row['data'] : '';
    }

    /**
     * UPSERT on every request (D-06). Row-alias syntax (MySQL 8.0.19+).
     * PDO::PARAM_STR is binary-safe for MEDIUMBLOB with ATTR_EMULATE_PREPARES=false.
     */
    public function write(string $id, string $data): bool
    {
        $expiresAt = time() + self::TTL;
        $stmt = Database::connection()->prepare(
            'INSERT INTO sessions (id, data, expires_at)
             VALUES (?, ?, ?) AS new_row
             ON DUPLICATE KEY UPDATE
                 data       = new_row.data,
                 expires_at = new_row.expires_at'
        );
        $stmt->execute([$id, $data, $expiresAt]);
        return true;
    }

    /**
     * Called by session_regenerate_id(true) for old ID, and by Auth::logout().
     */
    public function destroy(string $id): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM sessions WHERE id = ?'
        );
        $stmt->execute([$id]);
        return true;
    }

    /**
     * Bulk cleanup. Only called explicitly (session.gc_probability = 0 in api/php.ini).
     * Ignores $max_lifetime in favour of the stored expires_at timestamp (D-04).
     */
    public function gc(int $max_lifetime): int|false
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM sessions WHERE expires_at < UNIX_TIMESTAMP()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
