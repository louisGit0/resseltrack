<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Product
{
    /** Suggested categories for the resell use-case (merged with the user's own). */
    public const PRESET_CATEGORIES = [
        'Accessoires téléphone',
        'Électronique',
        'Audio & écouteurs',
        'Objets connectés',
        'Gaming',
        'Informatique',
        'Vêtements',
        'Chaussures',
        'Sacs & maroquinerie',
        'Bijoux & montres',
        'Beauté & soins',
        'Maison & déco',
        'Cuisine',
        'Jeux & jouets',
        'Sport & fitness',
        'Auto & moto',
        'Animaux',
        'Papeterie',
        'Bricolage & outils',
        'Puériculture',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** @return array<int, array<string,mixed>> */
    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM products WHERE user_id = :uid ORDER BY name ASC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Products matching a free-text search on name/category.
     * Sorting & pagination happen in PHP (computed columns: stock, profit…).
     * @return array<int, array<string,mixed>>
     */
    public function searchForUser(int $userId, string $q): array
    {
        if ($q === '') {
            return $this->allForUser($userId);
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM products
             WHERE user_id = :uid AND (name LIKE :q1 OR category LIKE :q2)
             ORDER BY name ASC'
        );
        $like = '%' . $q . '%';
        $stmt->execute(['uid' => $userId, 'q1' => $like, 'q2' => $like]);
        return $stmt->fetchAll();
    }

    /** Exact-name lookup (case-insensitive via MySQL collation). */
    public function findByName(int $userId, string $name): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM products WHERE user_id = :uid AND name = :name LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'name' => $name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Distinct non-empty categories of the user's products. @return string[] */
    public function categoriesForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT category FROM products
             WHERE user_id = :uid AND category IS NOT NULL AND category <> ''
             ORDER BY category ASC"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Presets + the user's own categories, deduplicated and sorted.
     * Used to feed the category datalists.
     * @return string[]
     */
    public function categorySuggestions(int $userId): array
    {
        $all = array_unique(array_merge(self::PRESET_CATEGORIES, $this->categoriesForUser($userId)));
        sort($all, SORT_NATURAL | SORT_FLAG_CASE);
        return $all;
    }

    /** Set (or clear with null) the cover image shown in lists. */
    public function setCover(int $id, int $userId, ?string $path): void
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET image_path = :path WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['path' => $path, 'id' => $id, 'uid' => $userId]);
    }

    /** Fill the category only when the product doesn't have one yet. */
    public function setCategoryIfEmpty(int $id, int $userId, string $category): void
    {
        if ($category === '') {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE products SET category = :cat
             WHERE id = :id AND user_id = :uid AND (category IS NULL OR category = '')"
        );
        $stmt->execute(['cat' => $category, 'id' => $id, 'uid' => $userId]);
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM products WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products
                (user_id, name, category, description, image_path, market_price_new, market_price_used)
             VALUES (:uid, :name, :category, :description, :image_path, :mnew, :mused)'
        );
        $stmt->execute([
            'uid'         => $userId,
            'name'        => $data['name'],
            'category'    => $data['category'] ?: null,
            'description' => $data['description'] ?: null,
            'image_path'  => $data['image_path'] ?: null,
            'mnew'        => $data['market_price_new'] ?? null,
            'mused'       => $data['market_price_used'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $userId, array $data): void
    {
        $params = [
            'name'        => $data['name'],
            'category'    => $data['category'] ?: null,
            'description' => $data['description'] ?: null,
            'mnew'        => $data['market_price_new'] ?? null,
            'mused'       => $data['market_price_used'] ?? null,
            'id'          => $id,
            'uid'         => $userId,
        ];

        // image_path only overwritten when a new one is provided.
        if (array_key_exists('image_path', $data) && $data['image_path'] !== null) {
            $stmt = $this->db->prepare(
                'UPDATE products SET name = :name, category = :category,
                    description = :description, image_path = :image_path,
                    market_price_new = :mnew, market_price_used = :mused
                 WHERE id = :id AND user_id = :uid'
            );
            $params['image_path'] = $data['image_path'];
            $stmt->execute($params);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE products SET name = :name, category = :category, description = :description,
                market_price_new = :mnew, market_price_used = :mused
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute($params);
    }

    public function delete(int $id, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
    }

    /**
     * Aggregated per-product stats for the current user: purchased & sold
     * quantities, total purchase cost (EUR), and cumulative net profit.
     * Returned keyed by product id.
     *
     * @return array<int, array{purchased_qty:int, total_cost_eur:float, sold_qty:int, profit_eur:float}>
     */
    public function statsForUser(int $userId): array
    {
        $stats = [];

        // Purchases: quantity and frozen cost (unit_cost_eur * quantity).
        $p = $this->db->prepare(
            'SELECT product_id,
                    COALESCE(SUM(quantity), 0)                       AS purchased_qty,
                    COALESCE(SUM(unit_cost_eur * quantity), 0)       AS total_cost_eur
             FROM purchases WHERE user_id = :uid GROUP BY product_id'
        );
        $p->execute(['uid' => $userId]);
        foreach ($p->fetchAll() as $row) {
            $stats[(int) $row['product_id']] = [
                'purchased_qty'  => (int) $row['purchased_qty'],
                'total_cost_eur' => (float) $row['total_cost_eur'],
                'sold_qty'       => 0,
                'profit_eur'     => 0.0,
            ];
        }

        // Sales: quantity sold and cumulative net margin.
        $s = $this->db->prepare(
            'SELECT product_id,
                    COALESCE(SUM(quantity), 0)        AS sold_qty,
                    COALESCE(SUM(net_margin_eur), 0)  AS profit_eur
             FROM sales WHERE user_id = :uid GROUP BY product_id'
        );
        $s->execute(['uid' => $userId]);
        foreach ($s->fetchAll() as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($stats[$pid])) {
                $stats[$pid] = ['purchased_qty' => 0, 'total_cost_eur' => 0.0, 'sold_qty' => 0, 'profit_eur' => 0.0];
            }
            $stats[$pid]['sold_qty'] = (int) $row['sold_qty'];
            $stats[$pid]['profit_eur'] = (float) $row['profit_eur'];
        }

        return $stats;
    }
}
