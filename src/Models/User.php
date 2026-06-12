<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Email already used by another account (optionally excluding one user id). */
    public function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        $params = ['email' => $email];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeUserId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public function update(int $id, string $name, string $email): void
    {
        $stmt = $this->db->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
        $stmt->execute(['name' => $name, 'email' => $email, 'id' => $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    public function create(string $email, string $passwordHash, string $name): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password_hash, name) VALUES (:email, :hash, :name)'
        );
        $stmt->execute([
            'email' => $email,
            'hash'  => $passwordHash,
            'name'  => $name,
        ]);
        return (int) $this->db->lastInsertId();
    }
}
