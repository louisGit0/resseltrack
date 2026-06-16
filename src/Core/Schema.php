<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Lightweight runtime migrations. The MySQL image only replays
 * sql/schema.sql on a FRESH data volume, so structural additions are also
 * applied here, idempotently, when the app boots. Cost: two cheap queries
 * per request — negligible at this scale.
 */
final class Schema
{
    public static function ensure(PDO $db): void
    {
        self::ensureCurrencyEnum($db);

        // Orders: one purchase order grouping several purchase lines.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS orders (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id       INT UNSIGNED NOT NULL,
                supplier      VARCHAR(150) NULL,
                order_url     VARCHAR(500) NULL,
                currency      ENUM('EUR','USD','CNY') NOT NULL DEFAULT 'EUR',
                exchange_rate DECIMAL(10,6) NOT NULL DEFAULT 1.000000,
                shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                customs_cost  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                ordered_at    DATE NOT NULL,
                created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_orders_user (user_id),
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Products: market reference prices (new / second-hand) for the
        // CUMP-vs-market comparison.
        $hasMarket = $db->query("SHOW COLUMNS FROM products LIKE 'market_price_new'")->fetch();
        if (!$hasMarket) {
            $db->exec(
                'ALTER TABLE products
                    ADD COLUMN market_price_new DECIMAL(10,2) NULL AFTER image_path,
                    ADD COLUMN market_price_used DECIMAL(10,2) NULL AFTER market_price_new'
            );
        }

        // Products: per-product rating (1-5) + free-text note (RATE-01). Additive,
        // nullable, backward-compatible — existing products keep rating = NULL.
        $hasRating = $db->query("SHOW COLUMNS FROM products LIKE 'rating'")->fetch();
        if (!$hasRating) {
            $db->exec(
                'ALTER TABLE products
                    ADD COLUMN rating TINYINT UNSIGNED NULL AFTER market_price_used,
                    ADD COLUMN rating_note TEXT NULL AFTER rating'
            );
        }

        // Product photo gallery (the cover stays products.image_path).
        $db->exec(
            'CREATE TABLE IF NOT EXISTS product_images (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                path       VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pimages_product (product_id),
                KEY idx_pimages_user (user_id),
                CONSTRAINT fk_pimages_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_pimages_product FOREIGN KEY (product_id)
                    REFERENCES products (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Sessions: MySQL-backed PHP session store (Phase 3). No FK.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sessions (
                id         VARCHAR(128) NOT NULL,
                data       MEDIUMBLOB   NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sessions_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Purchases: link to an order + per-unit weight (grams).
        $hasOrderId = $db->query("SHOW COLUMNS FROM purchases LIKE 'order_id'")->fetch();
        if (!$hasOrderId) {
            $db->exec(
                'ALTER TABLE purchases
                    ADD COLUMN order_id INT UNSIGNED NULL AFTER product_id,
                    ADD COLUMN weight_grams INT UNSIGNED NULL AFTER quantity'
            );
            $db->exec(
                'ALTER TABLE purchases
                    ADD KEY idx_purchases_order (order_id),
                    ADD CONSTRAINT fk_purchases_order FOREIGN KEY (order_id)
                        REFERENCES orders (id) ON DELETE CASCADE'
            );
        }

        // Suppliers: per-user supplier directory (name, url, rating, comment).
        // Created BEFORE the orders.supplier_id FK so the FK target exists even
        // when Schema::ensure() runs standalone on an existing volume.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS suppliers (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    INT UNSIGNED NOT NULL,
                name       VARCHAR(150) NOT NULL,
                url        VARCHAR(500) NULL,
                rating     TINYINT UNSIGNED NULL,
                comment    TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_suppliers_user (user_id),
                CONSTRAINT fk_suppliers_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Orders: optional, nullable FK to a saved supplier. Added via a guarded
        // ALTER (the schema.sql CREATE TABLE IF NOT EXISTS orders block is a no-op
        // on the live table). ON DELETE SET NULL unlinks orders when a supplier is
        // deleted (D-08) — the free-text orders.supplier column is preserved.
        $hasSupplierId = $db->query("SHOW COLUMNS FROM orders LIKE 'supplier_id'")->fetch();
        if (!$hasSupplierId) {
            $db->exec('ALTER TABLE orders ADD COLUMN supplier_id INT UNSIGNED NULL AFTER user_id');
            $db->exec(
                'ALTER TABLE orders
                    ADD KEY idx_orders_supplier (supplier_id),
                    ADD CONSTRAINT fk_orders_supplier FOREIGN KEY (supplier_id)
                        REFERENCES suppliers (id) ON DELETE SET NULL'
            );
        }
    }

    /** Widen the currency ENUM to include CNY on existing volumes. */
    private static function ensureCurrencyEnum(PDO $db): void
    {
        foreach (['orders', 'purchases'] as $table) {
            // The table may not exist yet on a brand-new volume — skip then.
            try {
                $col = $db->query("SHOW COLUMNS FROM {$table} LIKE 'currency'")->fetch();
            } catch (\Throwable $e) {
                continue;
            }
            if ($col && stripos((string) $col['Type'], 'CNY') === false) {
                $db->exec("ALTER TABLE {$table} MODIFY COLUMN currency ENUM('EUR','USD','CNY') NOT NULL DEFAULT 'EUR'");
            }
        }
    }
}
