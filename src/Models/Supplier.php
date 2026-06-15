<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Supplier
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Suppliers with their linked-orders count (D-07).
     * Mirrors the LEFT JOIN + COUNT + GROUP BY aggregate shape of Order::allForUser().
     * @return array<int, array<string,mixed>>
     */
    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, COUNT(o.id) AS orders_count
             FROM suppliers s
             LEFT JOIN orders o ON o.supplier_id = s.id AND o.user_id = s.user_id
             WHERE s.user_id = :uid
             GROUP BY s.id
             ORDER BY s.name ASC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM suppliers WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO suppliers (user_id, name, url, rating, comment)
             VALUES (:uid, :name, :url, :rating, :comment)'
        );
        $stmt->execute([
            'uid'     => $userId,
            'name'    => $data['name'],
            'url'     => $data['url'] ?: null,
            'rating'  => $data['rating'] ?? null, // already int|null from the controller
            'comment' => $data['comment'] ?: null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $userId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE suppliers SET
                name = :name, url = :url, rating = :rating, comment = :comment
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            'name'    => $data['name'],
            'url'     => $data['url'] ?: null,
            'rating'  => $data['rating'] ?? null,
            'comment' => $data['comment'] ?: null,
            'id'      => $id,
            'uid'     => $userId,
        ]);
    }

    /**
     * Plain DELETE. Orders referencing this supplier are unlinked automatically
     * by the FK ON DELETE SET NULL (D-08) — no manual unlink statement here.
     */
    public function delete(int $id, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM suppliers WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
    }
}
