<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class ProductImage
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** @return array<int, array<string,mixed>> */
    public function allForProduct(int $userId, int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM product_images
             WHERE user_id = :uid AND product_id = :pid
             ORDER BY id ASC'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $productId]);
        return $stmt->fetchAll();
    }

    public function countForProduct(int $userId, int $productId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM product_images WHERE user_id = :uid AND product_id = :pid'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $productId]);
        return (int) $stmt->fetchColumn();
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM product_images WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $userId, int $productId, string $path): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_images (user_id, product_id, path) VALUES (:uid, :pid, :path)'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $productId, 'path' => $path]);
        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_images WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
    }
}
