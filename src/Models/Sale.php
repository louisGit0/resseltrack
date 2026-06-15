<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Sale
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
            'SELECT s.*, pr.name AS product_name
             FROM sales s
             JOIN products pr ON pr.id = s.product_id
             WHERE s.user_id = :uid
             ORDER BY s.sold_at DESC, s.id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** Sortable columns exposed to the UI, mapped to real SQL columns. */
    private const SORTABLE = [
        'date'     => 's.sold_at',
        'product'  => 'pr.name',
        'quantity' => 's.quantity',
        'price'    => 's.sale_price',
        'margin'   => 's.net_margin_eur',
    ];

    /**
     * Search + filter + sort + paginate, with aggregates over the whole
     * filtered set (not just the current page). Sort key is whitelisted.
     *
     * Filters: q (product name / platform), sort, dir, product_id, from, to.
     *
     * @param array{q?:string, sort?:string, dir?:string, product_id?:?int, from?:?string, to?:?string} $filters
     * @return array{rows: array<int, array<string,mixed>>, total: int, units: int, revenue: float, margin: float}
     */
    public function searchForUser(int $userId, array $filters, int $page, int $perPage): array
    {
        $col = self::SORTABLE[$filters['sort'] ?? 'date'] ?? self::SORTABLE['date'];
        $dirSql = strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $offset = max(0, ($page - 1) * $perPage);

        $where = 'WHERE s.user_id = :uid';
        $params = ['uid' => $userId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (pr.name LIKE :q1 OR s.platform LIKE :q2)';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }
        if (!empty($filters['product_id'])) {
            $where .= ' AND s.product_id = :pid';
            $params['pid'] = (int) $filters['product_id'];
        }
        if (!empty($filters['from'])) {
            $where .= ' AND s.sold_at >= :dfrom';
            $params['dfrom'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where .= ' AND s.sold_at <= :dto';
            $params['dto'] = $filters['to'];
        }

        // Aggregates over the full filtered set.
        $agg = $this->db->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(s.quantity), 0) AS units,
                    COALESCE(SUM(s.sale_price), 0) AS revenue,
                    COALESCE(SUM(s.net_margin_eur), 0) AS margin
             FROM sales s JOIN products pr ON pr.id = s.product_id {$where}"
        );
        $agg->execute($params);
        $a = $agg->fetch();

        $stmt = $this->db->prepare(
            "SELECT s.*, pr.name AS product_name
             FROM sales s
             JOIN products pr ON pr.id = s.product_id
             {$where}
             ORDER BY {$col} {$dirSql}, s.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);

        return [
            'rows'    => $stmt->fetchAll(),
            'total'   => (int) $a['cnt'],
            'units'   => (int) $a['units'],
            'revenue' => (float) $a['revenue'],
            'margin'  => (float) $a['margin'],
        ];
    }

    /** All sales of one product (newest first). @return array<int, array<string,mixed>> */
    public function allForProduct(int $userId, int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sales
             WHERE user_id = :uid AND product_id = :pid
             ORDER BY sold_at DESC, id DESC'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $productId]);
        return $stmt->fetchAll();
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sales WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sales
                (user_id, product_id, quantity, sale_price, packaging_cost, label_cost,
                 boost_cost, other_cost, unit_cost_eur, net_margin_eur, platform, sold_at)
             VALUES
                (:uid, :pid, :qty, :sale, :pack, :label, :boost, :other,
                 :unit, :margin, :platform, :sold_at)'
        );
        $stmt->execute($this->bindings($userId, $data));
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $userId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sales SET
                product_id = :pid, quantity = :qty, sale_price = :sale,
                packaging_cost = :pack, label_cost = :label, boost_cost = :boost,
                other_cost = :other, unit_cost_eur = :unit, net_margin_eur = :margin,
                platform = :platform, sold_at = :sold_at
             WHERE id = :id AND user_id = :uid'
        );
        $params = $this->bindings($userId, $data);
        $params['id'] = $id;
        $stmt->execute($params);
    }

    public function delete(int $id, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM sales WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
    }

    /**
     * Aggregate totals over an optional [from, to] sold_at range.
     * cost = sum of frozen CUMP × quantity (basis for ROI).
     * @return array{revenue: float, profit: float, cost: float, count: int}
     */
    public function summary(int $userId, ?string $from, ?string $to): array
    {
        [$where, $params] = $this->dateRange($userId, $from, $to);
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(sale_price), 0) AS revenue,
                    COALESCE(SUM(net_margin_eur), 0) AS profit,
                    COALESCE(SUM(unit_cost_eur * quantity), 0) AS cost,
                    COUNT(*) AS cnt
             FROM sales s ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return [
            'revenue' => (float) $row['revenue'],
            'profit'  => (float) $row['profit'],
            'cost'    => (float) $row['cost'],
            'count'   => (int) $row['cnt'],
        ];
    }

    /**
     * Average days between a product's first purchase and each sale,
     * over an optional period. Null when no matching sales.
     */
    public function avgDaysToSell(int $userId, ?string $from, ?string $to): ?float
    {
        [$where, $params] = $this->dateRange($userId, $from, $to);
        $stmt = $this->db->prepare(
            'SELECT AVG(DATEDIFF(s.sold_at, fp.first_purchase)) AS avg_days
             FROM sales s
             JOIN (
                 SELECT product_id, MIN(purchased_at) AS first_purchase
                 FROM purchases WHERE user_id = :uid2 GROUP BY product_id
             ) fp ON fp.product_id = s.product_id
             ' . $where
        );
        $params['uid2'] = $userId;
        $stmt->execute($params);
        $avg = $stmt->fetchColumn();
        return $avg !== null && $avg !== false ? max(0.0, (float) $avg) : null;
    }

    /**
     * Net profit grouped by product category over an optional period.
     * @return array<int, array{category: string, profit: float}>
     */
    public function profitByCategory(int $userId, ?string $from, ?string $to): array
    {
        [$where, $params] = $this->dateRange($userId, $from, $to);
        $stmt = $this->db->prepare(
            "SELECT COALESCE(NULLIF(pr.category, ''), 'Sans catégorie') AS category,
                    COALESCE(SUM(s.net_margin_eur), 0) AS profit
             FROM sales s
             JOIN products pr ON pr.id = s.product_id
             {$where}
             GROUP BY category
             ORDER BY profit DESC"
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = ['category' => (string) $r['category'], 'profit' => (float) $r['profit']];
        }
        return $rows;
    }

    /**
     * Top products by cumulative net profit over an optional range.
     * @return array<int, array{name: string, profit: float, qty: int}>
     */
    public function topProductsByProfit(int $userId, ?string $from, ?string $to, int $limit = 5): array
    {
        [$where, $params] = $this->dateRange($userId, $from, $to);
        $sql = 'SELECT pr.name AS name,
                       COALESCE(SUM(s.net_margin_eur), 0) AS profit,
                       COALESCE(SUM(s.quantity), 0) AS qty
                FROM sales s
                JOIN products pr ON pr.id = s.product_id ' . $where . '
                GROUP BY s.product_id, pr.name
                ORDER BY profit DESC
                LIMIT ' . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = ['name' => (string) $r['name'], 'profit' => (float) $r['profit'], 'qty' => (int) $r['qty']];
        }
        return $rows;
    }

    /**
     * Revenue & profit per month for the last $months months (oldest first).
     * @return array<int, array{ym: string, revenue: float, profit: float}>
     */
    public function monthlySeries(int $userId, int $months = 12): array
    {
        // $months is an internal integer (never user input); inline it safely after an int cast.
        $back = (int) $months - 1;
        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(sold_at, '%Y-%m') AS ym,
                    COALESCE(SUM(sale_price), 0) AS revenue,
                    COALESCE(SUM(net_margin_eur), 0) AS profit
             FROM sales
             WHERE user_id = :uid
               AND sold_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL {$back} MONTH)
             GROUP BY ym ORDER BY ym ASC"
        );
        $stmt->execute(['uid' => $userId]);
        $byMonth = [];
        foreach ($stmt->fetchAll() as $r) {
            $byMonth[$r['ym']] = ['revenue' => (float) $r['revenue'], 'profit' => (float) $r['profit']];
        }

        // Fill any missing months with zeros so the chart has a continuous axis.
        $series = [];
        $cursor = new \DateTimeImmutable('first day of this month');
        $start = $cursor->modify('-' . ($months - 1) . ' months');
        for ($d = $start; $d <= $cursor; $d = $d->modify('+1 month')) {
            $ym = $d->format('Y-m');
            $series[] = [
                'ym'      => $ym,
                'revenue' => $byMonth[$ym]['revenue'] ?? 0.0,
                'profit'  => $byMonth[$ym]['profit'] ?? 0.0,
            ];
        }
        return $series;
    }

    /** Build a WHERE clause for user + optional date range. */
    private function dateRange(int $userId, ?string $from, ?string $to): array
    {
        // Prefixed with the `s` alias so it is unambiguous in JOINed queries
        // (both sales and products carry a user_id column).
        $where = 'WHERE s.user_id = :uid';
        $params = ['uid' => $userId];
        if ($from !== null && $from !== '') {
            $where .= ' AND s.sold_at >= :from';
            $params['from'] = $from;
        }
        if ($to !== null && $to !== '') {
            $where .= ' AND s.sold_at <= :to';
            $params['to'] = $to;
        }
        return [$where, $params];
    }

    /**
     * Latest sales (for the dashboard).
     * @return array<int, array<string,mixed>>
     */
    public function recentForUser(int $userId, int $limit = 5): array
    {
        $sql = 'SELECT s.*, pr.name AS product_name
                FROM sales s
                JOIN products pr ON pr.id = s.product_id
                WHERE s.user_id = :uid
                ORDER BY s.sold_at DESC, s.id DESC
                LIMIT ' . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Units already sold for a product (optionally excluding one sale id,
     * for edits). With $forUpdate=true the rows are locked (transaction).
     */
    public function soldQty(int $userId, int $productId, ?int $excludeSaleId = null, bool $forUpdate = false): int
    {
        $sql = 'SELECT COALESCE(SUM(quantity), 0) FROM sales WHERE user_id = :uid AND product_id = :pid';
        $params = ['uid' => $userId, 'pid' => $productId];
        if ($excludeSaleId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeSaleId;
        }
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Units sold per product for a user. Returns a map [product_id => soldQty].
     * @return array<int, int>
     */
    public function soldQtyByProduct(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT product_id, COALESCE(SUM(quantity), 0) AS qty
             FROM sales WHERE user_id = :uid GROUP BY product_id'
        );
        $stmt->execute(['uid' => $userId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['product_id']] = (int) $row['qty'];
        }
        return $map;
    }

    private function bindings(int $userId, array $data): array
    {
        return [
            'uid'      => $userId,
            'pid'      => $data['product_id'],
            'qty'      => $data['quantity'],
            'sale'     => $data['sale_price'],
            'pack'     => $data['packaging_cost'],
            'label'    => $data['label_cost'],
            'boost'    => $data['boost_cost'],
            'other'    => $data['other_cost'],
            'unit'     => $data['unit_cost_eur'],
            'margin'   => $data['net_margin_eur'],
            'platform' => $data['platform'] ?: 'Vinted',
            'sold_at'  => $data['sold_at'],
        ];
    }
}
