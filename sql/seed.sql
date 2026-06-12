-- ResellTrack demo data.
-- Login: demo@test.fr / Demo1234!
-- All monetary/frozen values below are pre-computed with the exact ProfitCalculator formulas:
--   purchase.unit_cost_eur = (lot_price + shipping + customs) * exchange_rate / quantity
--   product CUMP           = sum(lot_cost_eur) / sum(quantity_purchased)
--   sale.net_margin_eur    = (sale_price - packaging - label - boost - other) - (CUMP * quantity)

USE reselltrack;

-- --------------------------------------------------------
-- Demo user (password = Demo1234!, bcrypt hash, PHP-compatible $2b$)
-- --------------------------------------------------------
INSERT INTO users (id, email, password_hash, name) VALUES
  (1, 'demo@test.fr', '$2b$10$jP/jUMaqERFy3OKFrIotoejqC35QVqL8I46UEDynpWhOz/wuvZPRC', 'Démo Reseller');

-- --------------------------------------------------------
-- Products
-- --------------------------------------------------------
INSERT INTO products (id, user_id, name, category, description, image_path) VALUES
  (1, 1, 'Coque iPhone 15 silicone', 'Accessoires téléphone', 'Coques silicone achetées en lot, revendues à l''unité.', NULL),
  (2, 1, 'Chargeur USB-C 20W',        'Accessoires téléphone', 'Chargeurs rapides importés des USA.', NULL),
  (3, 1, 'Montre connectée fitness',  'Objets connectés',      'Montres fitness, lot importé avec douane.', NULL);

-- --------------------------------------------------------
-- Purchases (lots)
-- --------------------------------------------------------
-- P1: 20 coques EUR -> (30 + 5 + 0) * 1 / 20 = 1.7500
-- P2: 10 coques EUR -> (18 + 4 + 0) * 1 / 10 = 2.2000   => product 1 CUMP = (35 + 22) / 30 = 1.9000
-- P3: 10 chargeurs USD (port + douane) -> (40 + 8 + 5) * 0.92 / 10 = 4.8760
-- P4: 5 montres USD (port + douane)    -> (75 + 12 + 10) * 0.92 / 5 = 17.8480
INSERT INTO purchases
  (id, user_id, product_id, quantity, lot_price, shipping_cost, customs_cost, currency, exchange_rate, unit_cost_eur, supplier, order_url, purchased_at) VALUES
  (1, 1, 1, 20, 30.00, 5.00,  0.00, 'EUR', 1.000000, 1.7500,  'AliExpress - PhoneCaseStore', 'https://aliexpress.com/item/1001', '2026-01-10'),
  (2, 1, 1, 10, 18.00, 4.00,  0.00, 'EUR', 1.000000, 2.2000,  'AliExpress - PhoneCaseStore', 'https://aliexpress.com/item/1002', '2026-02-15'),
  (3, 1, 2, 10, 40.00, 8.00,  5.00, 'USD', 0.920000, 4.8760,  'AliExpress - FastCharge',     'https://aliexpress.com/item/2001', '2026-01-20'),
  (4, 1, 3,  5, 75.00, 12.00, 10.00,'USD', 0.920000, 17.8480, 'AliExpress - SmartGear',      'https://aliexpress.com/item/3001', '2026-02-05');

-- --------------------------------------------------------
-- Sales (CUMP & net margin frozen at sale time)
-- Product CUMPs: P1 = 1.9000, P2 = 4.8760, P3 = 17.8480
-- --------------------------------------------------------
-- S1: P1 q1  net = (8.00  - 0.30 - 0.00 - 0.80 - 0.00) - 1.9000*1  = 5.00
-- S2: P1 q2  net = (15.00 - 0.50 - 0.99 - 0.00 - 0.00) - 1.9000*2  = 9.71
-- S3: P1 q1  net = (7.00  - 0.30 - 0.00 - 0.00 - 0.20) - 1.9000*1  = 4.60
-- S4: P2 q1  net = (12.00 - 0.40 - 0.99 - 1.20 - 0.00) - 4.8760*1  = 4.53
-- S5: P2 q2  net = (22.00 - 0.60 - 0.00 - 0.00 - 0.50) - 4.8760*2  = 11.15
-- S6: P3 q1  net = (35.00 - 0.80 - 0.99 - 2.00 - 0.00) - 17.8480*1 = 13.36
INSERT INTO sales
  (id, user_id, product_id, quantity, sale_price, packaging_cost, label_cost, boost_cost, other_cost, unit_cost_eur, net_margin_eur, platform, sold_at) VALUES
  (1, 1, 1, 1, 8.00,  0.30, 0.00, 0.80, 0.00, 1.9000,  5.00,  'Vinted', '2026-02-20'),
  (2, 1, 1, 2, 15.00, 0.50, 0.99, 0.00, 0.00, 1.9000,  9.71,  'Vinted', '2026-03-12'),
  (3, 1, 1, 1, 7.00,  0.30, 0.00, 0.00, 0.20, 1.9000,  4.60,  'Vinted', '2026-05-03'),
  (4, 1, 2, 1, 12.00, 0.40, 0.99, 1.20, 0.00, 4.8760,  4.53,  'Vinted', '2026-03-25'),
  (5, 1, 2, 2, 22.00, 0.60, 0.00, 0.00, 0.50, 4.8760,  11.15, 'Vinted', '2026-04-18'),
  (6, 1, 3, 1, 35.00, 0.80, 0.99, 2.00, 0.00, 17.8480, 13.36, 'Vinted', '2026-06-02');
