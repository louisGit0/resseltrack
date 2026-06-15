-- ResellTrack schema — MySQL 8, InnoDB, utf8mb4
-- Executed automatically on first container start via docker-entrypoint-initdb.d.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS reselltrack
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reselltrack;

-- --------------------------------------------------------
-- Users
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Products
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(190) NOT NULL,
  category    VARCHAR(120) NULL,
  description TEXT NULL,
  image_path  VARCHAR(255) NULL,
  market_price_new  DECIMAL(10,2) NULL,  -- prix constaté neuf
  market_price_used DECIMAL(10,2) NULL,  -- prix constaté plateformes de revente
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_user (user_id),
  CONSTRAINT fk_products_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Product photo gallery (cover stays products.image_path)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_images (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Orders (one supplier order = several purchase lines)
-- Shipping & customs are global on the order and redistributed
-- across the lines proportionally to weight (see ProfitCalculator).
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Suppliers (per-user supplier directory — orders may link to one)
-- The orders FK to this table is added by Schema::ensure() (guarded ALTER),
-- NOT here — adding it to the orders block above would be a no-op on the
-- live table. Fresh volumes get suppliers from this block.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(150) NOT NULL,
  url        VARCHAR(500) NULL,
  rating     TINYINT UNSIGNED NULL,  -- 1..5, NULL = unrated
  comment    TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_suppliers_user (user_id),
  CONSTRAINT fk_suppliers_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Purchases (one row = one lot, possibly a line of an order)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchases (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  order_id      INT UNSIGNED NULL,
  quantity      INT UNSIGNED NOT NULL,
  weight_grams  INT UNSIGNED NULL,
  lot_price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  customs_cost  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency      ENUM('EUR','USD','CNY') NOT NULL DEFAULT 'EUR',
  exchange_rate DECIMAL(10,6) NOT NULL DEFAULT 1.000000, -- rate toward EUR (1 if already EUR)
  unit_cost_eur DECIMAL(10,4) NOT NULL DEFAULT 0.0000,    -- computed & frozen at save time
  supplier      VARCHAR(150) NULL,
  order_url     VARCHAR(500) NULL,
  purchased_at  DATE NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_purchases_user (user_id),
  KEY idx_purchases_product (product_id),
  KEY idx_purchases_order (order_id),
  CONSTRAINT fk_purchases_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_purchases_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_purchases_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Login attempts (brute-force protection)
-- Also created lazily by src/Core/RateLimiter.php for volumes
-- initialised before this table existed.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email        VARCHAR(190) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_email_ip (email, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Sessions (MySQL-backed PHP session store, Phase 3)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  id         VARCHAR(128) NOT NULL,
  data       MEDIUMBLOB   NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Sales
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  quantity       INT UNSIGNED NOT NULL,
  sale_price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  packaging_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  label_cost     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  boost_cost     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  other_cost     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  unit_cost_eur  DECIMAL(10,4) NOT NULL DEFAULT 0.0000, -- CUMP frozen at sale time
  net_margin_eur DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- frozen at sale time
  platform       VARCHAR(80) NOT NULL DEFAULT 'Vinted',
  sold_at        DATE NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sales_user (user_id),
  KEY idx_sales_product (product_id),
  CONSTRAINT fk_sales_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
