<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Purchase
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** @return array<int, array<string,mixed>> */
    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pu.*, pr.name AS product_name
             FROM purchases pu
             JOIN products pr ON pr.id = pu.product_id
             WHERE pu.user_id = :uid
             ORDER BY pu.purchased_at DESC, pu.id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** Sortable columns exposed to the UI, mapped to real SQL columns. */
    private const SORTABLE = [
        'date'     => 'pu.purchased_at',
        'product'  => 'pr.name',
        'quantity' => 'pu.quantity',
        'lot'      => 'pu.lot_price',
        'unit'     => 'pu.unit_cost_eur',
    ];

    /**
     * Search + filter + sort + paginate, with aggregates over the whole
     * filtered set (not just the current page). Sort key is whitelisted.
     *
     * Filters: q (product name / supplier), sort, dir, product_id, from, to.
     *
     * @param array{q?:string, sort?:string, dir?:string, product_id?:?int, from?:?string, to?:?string} $filters
     * @return array{rows: array<int, array<string,mixed>>, total: int, units: int, invested: float}
     */
    public function searchForUser(int $userId, array $filters, int $page, int $perPage): array
    {
        $col = self::SORTABLE[$filters['sort'] ?? 'date'] ?? self::SORTABLE['date'];
        $dirSql = strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $offset = max(0, ($page - 1) * $perPage);

        $where = 'WHERE pu.user_id = :uid';
        $params = ['uid' => $userId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (pr.name LIKE :q1 OR pu.supplier LIKE :q2)';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }
        if (!empty($filters['product_id'])) {
            $where .= ' AND pu.product_id = :pid';
            $params['pid'] = (int) $filters['product_id'];
        }
        if (!empty($filters['from'])) {
            $where .= ' AND pu.purchased_at >= :dfrom';
            $params['dfrom'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where .= ' AND pu.purchased_at <= :dto';
            $params['dto'] = $filters['to'];
        }

        // Aggregates over the full filtered set (frozen EUR values).
        $agg = $this->db->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(pu.quantity), 0) AS units,
                    COALESCE(SUM(pu.unit_cost_eur * pu.quantity), 0) AS invested
             FROM purchases pu JOIN products pr ON pr.id = pu.product_id {$where}"
        );
        $agg->execute($params);
        $a = $agg->fetch();

        // LIMIT/OFFSET are internal integers (sanitised above), safe to inline.
        $stmt = $this->db->prepare(
            "SELECT pu.*, pr.name AS product_name
             FROM purchases pu
             JOIN products pr ON pr.id = pu.product_id
             {$where}
             ORDER BY {$col} {$dirSql}, pu.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);

        return [
            'rows'     => $stmt->fetchAll(),
            'total'    => (int) $a['cnt'],
            'units'    => (int) $a['units'],
            'invested' => (float) $a['invested'],
        ];
    }

    /** All lots of one product (newest first). @return array<int, array<string,mixed>> */
    public function allForProduct(int $userId, int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM purchases
             WHERE user_id = :uid AND product_id = :pid
             ORDER BY purchased_at DESC, id DESC'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $productId]);
        return $stmt->fetchAll();
    }

    /** Date of the very first lot for a product, or null. */
    public function firstPurchaseDate(int $userId, int $productId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT MIN(purchased_at) FROM purchases WHERE user_id = :uid AND product_id = :pid'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $productId]);
        $d = $stmt->fetchColumn();
        return $d !== false && $d !== null ? (string) $d : null;
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM purchases WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO purchases
                (user_id, product_id, order_id, quantity, weight_grams, lot_price,
                 shipping_cost, customs_cost, currency, exchange_rate, unit_cost_eur,
                 supplier, order_url, purchased_at)
             VALUES
                (:uid, :pid, :oid, :qty, :weight, :lot, :ship, :customs,
                 :currency, :rate, :unit, :supplier, :url, :purchased_at)'
        );
        $params = $this->bindings($userId, $data);
        $params['oid'] = $data['order_id'] ?? null;
        $params['weight'] = $data['weight_grams'] ?? null;
        $stmt->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $userId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE purchases SET
                product_id = :pid, quantity = :qty, lot_price = :lot,
                shipping_cost = :ship, customs_cost = :customs, currency = :currency,
                exchange_rate = :rate, unit_cost_eur = :unit, supplier = :supplier,
                order_url = :url, purchased_at = :purchased_at
             WHERE id = :id AND user_id = :uid'
        );
        $params = $this->bindings($userId, $data);
        $params['id'] = $id;
        $stmt->execute($params);
    }

    public function delete(int $id, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM purchases WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
    }

    /** Remove all lines of an order (used when re-saving an edited order). */
    public function deleteByOrder(int $orderId, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM purchases WHERE order_id = :oid AND user_id = :uid');
        $stmt->execute(['oid' => $orderId, 'uid' => $userId]);
    }

    /**
     * Latest known supplier link per product (most recent lot first).
     * Used to surface a prominent "reorder" link on product screens.
     * @return array<int, array{url: string, supplier: ?string, purchased_at: string}>
     */
    public function latestOrderUrls(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT product_id, order_url, supplier, purchased_at
             FROM purchases
             WHERE user_id = :uid AND order_url IS NOT NULL AND order_url <> ''
             ORDER BY purchased_at DESC, id DESC"
        );
        $stmt->execute(['uid' => $userId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($map[$pid])) {
                $map[$pid] = [
                    'url'          => (string) $row['order_url'],
                    'supplier'     => $row['supplier'] !== null ? (string) $row['supplier'] : null,
                    'purchased_at' => (string) $row['purchased_at'],
                ];
            }
        }
        return $map;
    }

    /**
     * Lots for a product, shaped for ProfitCalculator::cump().
     * @return array<int, array{cost_eur: float, quantity: int}>
     */
    public function lotsForProduct(int $userId, int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT (unit_cost_eur * quantity) AS cost_eur, quantity
             FROM purchases WHERE user_id = :uid AND product_id = :pid'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $productId]);
        $lots = [];
        foreach ($stmt->fetchAll() as $row) {
            $lots[] = ['cost_eur' => (float) $row['cost_eur'], 'quantity' => (int) $row['quantity']];
        }
        return $lots;
    }

    /**
     * All lots for all of a user's products, shaped for ProfitCalculator::cump().
     * Add product_id to the projection so the caller can group by it in PHP.
     * @return array<int, array{product_id: int, cost_eur: float, quantity: int}>
     */
    public function lotsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT product_id, (unit_cost_eur * quantity) AS cost_eur, quantity
             FROM purchases WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $lots = [];
        foreach ($stmt->fetchAll() as $row) {
            $lots[] = [
                'product_id' => (int) $row['product_id'],
                'cost_eur'   => (float) $row['cost_eur'],
                'quantity'   => (int) $row['quantity'],
            ];
        }
        return $lots;
    }

    /**
     * Total purchased units for a product. With $forUpdate=true (inside a
     * transaction) the underlying rows are locked to serialize concurrent
     * stock checks.
     */
    public function purchasedQty(int $userId, int $productId, bool $forUpdate = false): int
    {
        $sql = 'SELECT COALESCE(SUM(quantity), 0) FROM purchases WHERE user_id = :uid AND product_id = :pid';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['uid' => $userId, 'pid' => $productId]);
        return (int) $stmt->fetchColumn();
    }

    private function bindings(int $userId, array $data): array
    {
        return [
            'uid'          => $userId,
            'pid'          => $data['product_id'],
            'qty'          => $data['quantity'],
            'lot'          => $data['lot_price'],
            'ship'         => $data['shipping_cost'],
            'customs'      => $data['customs_cost'],
            'currency'     => $data['currency'],
            'rate'         => $data['exchange_rate'],
            'unit'         => $data['unit_cost_eur'],
            'supplier'     => $data['supplier'] ?: null,
            'url'          => $data['order_url'] ?: null,
            'purchased_at' => $data['purchased_at'],
        ];
    }
}
