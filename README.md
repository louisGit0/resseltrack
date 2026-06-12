# ResellTrack

Plateforme multi-utilisateurs de **suivi d'achat-revente avec calcul de rentabilité**.
Pensée pour l'achat en lots (souvent AliExpress, en USD avec port et douane) revendus à
l'unité (Vinted & co). PHP 8.3 + MySQL 8, architecture MVC maison, sans framework.

---

## 🚀 Installation

Prérequis : **Docker** + **Docker Compose**.

```bash
git clone <repo> reselltrack
cd reselltrack
docker compose up --build
```

C'est tout. Le schéma (`sql/schema.sql`) **et** les données de démo (`sql/seed.sql`) sont
exécutés automatiquement au premier démarrage via le mécanisme d'init de l'image MySQL
(`/docker-entrypoint-initdb.d`). Le conteneur `app` attend que la base soit saine
(`healthcheck`) avant de démarrer.

> `docker compose up` suffit : les identifiants par défaut sont fournis en valeurs de repli
> dans `docker-compose.yml`. Pour personnaliser les secrets, copiez `.env.example` vers `.env`
> (le fichier `.env` est volontairement exclu de Git).

```bash
cp .env.example .env   # optionnel
```

### URLs d'accès

| Service              | URL                       |
|----------------------|---------------------------|
| Application          | http://localhost:8080     |
| phpMyAdmin (dev only)| http://localhost:8081     |

### Identifiants de démo

```
Email        : demo@test.fr
Mot de passe : Demo1234!
```

Le compte de démo arrive avec 3 produits, 4 achats (dont un lot de 10 unités en **USD** avec
port et douane) et 6 ventes : le tableau de bord est parlant dès l'installation.

---

## 🧪 Tests

Les règles métier sont isolées dans `src/Services/ProfitCalculator.php` et couvertes par
PHPUnit.

```bash
# Dans le conteneur app (PHP 8.3 déjà présent)
docker compose exec app bash -lc "composer install && vendor/bin/phpunit"

# …ou en local si vous avez PHP 8.3 + Composer
composer install
composer test
```

Couverture : coût unitaire (EUR et USD avec taux), CUMP multi-lots, marge nette avec frais,
marge en %, stock, refus de vente si stock insuffisant, valeur du stock.

---

## 📐 Formules de calcul

Toutes implémentées **uniquement** dans `ProfitCalculator` (testé unitairement).

| Grandeur | Formule |
|---|---|
| **Coût unitaire d'un achat** | `(lot_price + shipping_cost + customs_cost) × exchange_rate ÷ quantity` |
| **Répartition du port d'une commande** | `part_ligne = port_global × (poids_ligne ÷ poids_total)` — repli sur le prix si aucun poids, puis répartition égale ; la somme des parts arrondies est exactement égale au port global |
| **CUMP d'un produit** (coût unitaire moyen pondéré) | `Σ(coûts de lots en EUR) ÷ Σ(quantités achetées)` |
| **Marge nette d'une vente** | `(sale_price − emballage − étiquette − boost − autres) − (CUMP × quantité)` |
| **Marge en %** | `marge_nette ÷ sale_price × 100` |
| **Stock d'un produit** | `Σ quantités achetées − Σ quantités vendues` |
| **Valeur du stock** | `stock × CUMP` |

Règles importantes :

- Le **taux de change est saisi avec l'achat** (bouton « Taux du jour » via
  [frankfurter.app](https://www.frankfurter.app/), champ éditable). Le coût unitaire affiché
  en direct côté JS est **recalculé côté serveur** à l'enregistrement : **le serveur fait foi**.
- Le **CUMP et la marge sont figés** dans la ligne de vente au moment de la saisie. Un achat
  ultérieur ne modifie jamais les marges des ventes passées.
- Une vente est **refusée si la quantité dépasse le stock disponible** (validation serveur avec
  message clair).

---

## 🗂️ Structure

```
reselltrack/
  docker-compose.yml         services app / db (volume persistant) / phpmyadmin
  Dockerfile                 php:8.3-apache + pdo_mysql + mod_rewrite
  docker/apache.conf         vhost : DocumentRoot -> public/, AllowOverride All
  .env.example               secrets (copier vers .env)
  public/
    index.php                point d'entrée unique + routeur
    .htaccess                réécriture vers le front controller
    assets/                  css, js (Bootstrap & Chart.js via CDN), uploads
  src/
    Core/        Router, Database (PDO), Auth, Csrf, Env, Controller
    Controllers/ Auth, Product, Purchase, Sale, Dashboard, Export
    Models/      User, Product, Purchase, Sale     (PDO préparé, filtré par user_id)
    Services/    ProfitCalculator, ExchangeRateService, CsvExporter
    Views/       layout + pages
  sql/
    schema.sql               tables InnoDB / utf8mb4
    seed.sql                 données de démo
  tests/         ProfitCalculatorTest.php (PHPUnit)
```

---

## 🔐 Sécurité

- **PDO en requêtes préparées partout** — zéro concaténation SQL (tris par whitelist de colonnes).
- **Jeton CSRF** sur tous les formulaires POST (`src/Core/Csrf.php`).
- **`htmlspecialchars`** sur toute sortie HTML (helper `e()`).
- **Cookies de session** : `HttpOnly`, `SameSite=Lax`, `Secure` activable via
  `SESSION_SECURE=1` pour la prod HTTPS. **Régénération de l'ID de session à la connexion.**
- **Rate limiting du login** (`src/Core/RateLimiter.php`) : 5 échecs par email+IP sur 15 min
  → blocage temporaire avec message.
- **Headers de sécurité** : `Content-Security-Policy`, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy`.
- **Contrôle de stock transactionnel** : la vérification du stock et l'insertion de la vente
  sont dans une transaction avec `SELECT … FOR UPDATE` (pas de survente concurrente).
- **Uploads** : type MIME vérifié par `finfo` + taille limitée à 2 Mo, nom de fichier aléatoire.
- **Cloisonnement multi-utilisateurs** : chaque requête est filtrée par `user_id` — un
  utilisateur ne voit jamais les données d'un autre.
- **Validation serveur** : montants ≥ 0, quantités entières > 0, devise dans l'ENUM, taux > 0,
  dates valides.
- **Secrets dans `.env`** (lu au démarrage), `.env.example` fourni, `.env` ignoré par Git.

---

## ✨ Fonctionnalités

1. **Authentification** — inscription / connexion / déconnexion (`password_hash` /
   `password_verify`), sessions sécurisées, **anti brute-force** (5 échecs → blocage 15 min).
2. **Produits** — CRUD + **fiche détail** (KPI, ROI, délai moyen de vente, historique des lots
   et des ventes), liste avec stock, CUMP, valeur de stock et bénéfice cumulé.
3. **Commandes fournisseur** — une commande Superbuy/AliExpress avec **plusieurs articles** :
   liens, quantités, prix et poids par article ; frais de port et douane globaux **répartis
   automatiquement au prorata du poids** ; chaque article devient une ligne d'achat (coût
   unitaire figé) et les **produits inconnus sont créés à la volée** dans le catalogue.
4. **Achats (lots)** — CRUD, devise EUR/USD, bouton « Taux du jour », coût unitaire calculé en
   direct côté JS puis recalculé côté serveur.
5. **Ventes** — CRUD, frais (emballage, étiquette, boost, autres), marge prévisionnelle en
   direct, **contrôle de stock transactionnel** (verrou SQL anti-survente), **duplication de
   vente** en un clic, plateformes suggérées (Vinted, Leboncoin, eBay…).
6. **Recherche, tri & pagination** — sur les listes produits, achats et ventes (tri par
   colonnes whitelistées, 15 éléments/page), filtres par catégorie/état de stock/produit/période.
7. **Tableau de bord** — bénéfice net, CA, marge moyenne (€ et %), valeur du stock, **ROI,
   délai moyen de vente, panier moyen**, top 5 produits, **donut bénéfice par catégorie**,
   graphique de l'évolution mensuelle sur 12 mois, alertes stock faible, dernières ventes,
   filtres rapides de période (30 j / 3 mois / année).
8. **Mon compte** — modification du nom, de l'email et du mot de passe.
9. **Export CSV** — achats (avec poids et n° de commande), ventes, récap par produit.
   Séparateur `;`, UTF-8 avec BOM (compatibilité Excel FR).

---

## 🛠️ Commandes utiles

```bash
docker compose up --build      # démarrer (build initial)
docker compose down            # arrêter
docker compose down -v         # arrêter ET réinitialiser la base (relance schema + seed)
docker compose logs -f app     # logs du serveur PHP/Apache
docker compose exec app bash   # shell dans le conteneur applicatif
```

> Le schéma et le seed ne s'exécutent qu'à la **première** initialisation du volume `dbdata`.
> Pour repartir de zéro (re-seed) : `docker compose down -v && docker compose up`.
