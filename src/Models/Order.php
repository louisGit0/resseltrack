<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Order
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Orders with aggregates over their lines.
     * @return array<int, array<string,mixed>>
     */
    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*,
                    COUNT(pu.id)                                  AS line_count,
                    COALESCE(SUM(pu.quantity), 0)                 AS units,
                    COALESCE(SUM(pu.unit_cost_eur * pu.quantity), 0) AS total_eur,
                    COALESCE(SUM(pu.weight_grams * pu.quantity), 0)  AS total_weight
             FROM orders o
             LEFT JOIN purchases pu ON pu.order_id = o.id
             WHERE o.user_id = :uid
             GROUP BY o.id
             ORDER BY o.ordered_at DESC, o.id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM orders WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Purchase lines of an order. @return array<int, array<string,mixed>> */
    public function lines(int $orderId, int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pu.*, pr.name AS product_name, pr.category AS product_category
             FROM purchases pu
             JOIN products pr ON pr.id = pu.product_id
             WHERE pu.order_id = :oid AND pu.user_id = :uid
             ORDER BY pu.id ASC'
        );
        $stmt->execute(['oid' => $orderId, 'uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function update(int $id, int $userId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE orders SET
                supplier = :supplier, supplier_id = :supplier_id, order_url = :url, currency = :currency,
                exchange_rate = :rate, shipping_cost = :ship, customs_cost = :customs,
                ordered_at = :ordered_at
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            'supplier'    => $data['supplier'] ?: null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'url'         => $data['order_url'] ?: null,
            'currency'    => $data['currency'],
            'rate'        => $data['exchange_rate'],
            'ship'        => $data['shipping_cost'],
            'customs'     => $data['customs_cost'],
            'ordered_at'  => $data['ordered_at'],
            'id'          => $id,
            'uid'         => $userId,
        ]);
    }

    public function create(int $userId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders
                (user_id, supplier, supplier_id, order_url, currency, exchange_rate,
                 shipping_cost, customs_cost, ordered_at)
             VALUES
                (:uid, :supplier, :supplier_id, :url, :currency, :rate, :ship, :customs, :ordered_at)'
        );
        $stmt->execute([
            'uid'         => $userId,
            'supplier'    => $data['supplier'] ?: null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'url'         => $data['order_url'] ?: null,
            'currency'    => $data['currency'],
            'rate'        => $data['exchange_rate'],
            'ship'        => $data['shipping_cost'],
            'customs'     => $data['customs_cost'],
            'ordered_at'  => $data['ordered_at'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Deleting an order cascades to its purchase lines (FK ON DELETE CASCADE). */
    public function delete(int $id, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM orders WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
    }
}
