# Requirements: ResellTrack — Déploiement Vercel

**Defined:** 2026-06-12
**Core Value:** Tout ce qui fonctionne en local fonctionne à l'identique une fois déployé sur Vercel — le site en ligne est pleinement opérationnel pour de vrais utilisateurs.

> Jalon **brownfield** : l'application existe déjà et fonctionne en local. Ces requirements couvrent uniquement ce qu'il faut pour la **rendre fonctionnelle déployée sur Vercel serverless**. Les capacités produit existantes (auth, produits, achats, ventes, commandes, dashboard, export, profil) sont la base « Validated » et ne sont pas re-spécifiées.

## v1 Requirements

Requirements pour la mise en ligne fonctionnelle. Chacun est mappé à une phase du roadmap.

### Déploiement & Routing

- [ ] **DEPLOY-01**: L'application répond à toutes ses routes une fois déployée sur Vercel (front controller atteint via `vercel.json`, sans Apache/`.htaccess`)
- [ ] **DEPLOY-02**: Les assets statiques (CSS, JS) sont servis par le CDN Vercel sans transiter par une fonction PHP
- [ ] **DEPLOY-03**: Le développement local Docker continue de fonctionner inchangé (`.htaccess` préservé pour le dev)

### Base de données

- [ ] **DB-01**: L'application se connecte à une base **Aiven MySQL 8** managée en **TLS** (certificat CA) depuis les fonctions Vercel
- [ ] **DB-02**: Le schéma complet est appliqué via une migration **one-shot** (`bin/migrate.php`), hors du chemin par-requête
- [ ] **DB-03**: `Database::connection()` n'exécute plus de DDL au runtime (`Schema::ensure()` retiré du chemin par-requête) — aucune race condition au cold start

### Sessions

- [ ] **SESS-01**: La session utilisateur **persiste** à travers les invocations serverless (l'utilisateur reste connecté d'une page à l'autre)
- [ ] **SESS-02**: Les sessions sont stockées en base MySQL via un `SessionHandlerInterface`
- [ ] **SESS-03**: Le cookie de session porte les flags `Secure` + `HttpOnly` + `SameSite=Lax` en production (`SESSION_SECURE=1`)
- [ ] **SESS-04**: La protection CSRF reste fonctionnelle sur le nouveau stockage de session (token validé sur les POST)

### Stockage des images

- [ ] **STORE-01**: L'upload d'une image produit l'écrit sur **Cloudflare R2** et stocke son URL publique en base
- [ ] **STORE-02**: Les images uploadées s'affichent en production depuis leur URL R2
- [ ] **STORE-03**: La suppression d'une image produit retire l'objet correspondant sur R2
- [ ] **STORE-04**: Les images existantes (chemins disque locaux) sont migrées vers R2 avant la mise en ligne, ou une stratégie de repli est documentée
- [ ] **STORE-05**: Une garde de taille (~3,5 Mo) empêche les uploads dépassant la limite Vercel (4,5 Mo) avec un message clair à l'utilisateur

### Sécurité & Configuration production

- [ ] **SEC-01**: Tous les secrets sont fournis via les variables d'environnement Vercel (aucun secret committé ; `.env` gitignoré)
- [ ] **SEC-02**: L'en-tête `Strict-Transport-Security` (HSTS) est émis en production
- [ ] **SEC-03**: La CSP autorise le domaine public R2 pour les images (`img-src`)
- [ ] **SEC-04**: L'application refuse de démarrer en production avec une configuration dangereuse (ex. `SESSION_SECURE=0` ou credentials par défaut)

### Fiabilité (bloquant pour « fonctionnel »)

- [ ] **PERF-01**: La page de création/édition de vente charge sans timeout — le N+1 de `SaleController::productsMeta()` est remplacé par une requête agrégée
- [ ] **PERF-02**: `ExchangeRateService` utilise `curl` avec timeout (5 s) et logge ses échecs ; un échec d'API n'enregistre **pas** silencieusement un coût de 0,00 € (avertissement visible)

### Vérification en production

- [ ] **VERIF-01**: Chaque fonctionnalité existante (auth, produits + image, achats multi-devises, ventes avec garde de stock, commandes, dashboard, export CSV, profil) est vérifiée fonctionnelle sur l'URL Vercel déployée

## v2 Requirements

Reporté à un futur jalon. Suivi mais hors roadmap courant.

### Robustesse & Exploitation

- **OPS-01**: Endpoint `/health` exposant l'état DB + le nombre de connexions actives (surveillance de la limite Aiven)
- **OPS-02**: Page 503 gracieuse (HTML) en cas d'échec de connexion à la base, au lieu d'un `exit` texte brut
- **OPS-03**: Isolation de la base pour les preview deploys (par `VERCEL_ENV`)
- **OPS-04**: Pooler de connexions (ex. ProxySQL) si la limite de connexions Aiven est atteinte sous charge
- **OPS-05**: Domaine personnalisé

### Durcissement complémentaire

- **HARD-01**: Rotation du token CSRF après chaque soumission réussie
- **HARD-02**: Rate limiting sur `/register` et les endpoints `/export/*`

## Out of Scope

Exclusions explicites pour éviter le scope creep.

| Feature | Reason |
|---------|--------|
| Nouvelles fonctionnalités produit (« mises à jour » du site) | Reporté à un futur jalon ; priorité = déploiement fonctionnel d'abord |
| Hébergement Docker-natif (Railway / Render / Fly.io) | Écarté : l'utilisateur a choisi Vercel |
| Migration vers PostgreSQL | Code PDO/SQL spécifiques MySQL ; on conserve MySQL (Aiven) |
| Refonte du système de migrations (Phinx / Doctrine) | Hors périmètre ; `bin/migrate.php` one-shot suffit pour ce jalon |
| Couverture de tests étendue (contrôleurs, modèles, Core) | Hors périmètre ; seul `ProfitCalculator` reste testé |
| SRI sur les CDN, et concerns sécurité LOW non bloquants | Non bloquant pour la mise en ligne ; reporté |

## Traceability

Quelles phases couvrent quels requirements.

| Requirement | Phase | Status |
|-------------|-------|--------|
| DEPLOY-01 | Phase 1 | Pending |
| DEPLOY-02 | Phase 1 | Pending |
| DEPLOY-03 | Phase 1 | Pending |
| DB-01 | Phase 2 | Pending |
| DB-02 | Phase 2 | Pending |
| DB-03 | Phase 2 | Pending |
| SESS-01 | Phase 3 | Pending |
| SESS-02 | Phase 3 | Pending |
| SESS-03 | Phase 3 | Pending |
| SESS-04 | Phase 3 | Pending |
| STORE-01 | Phase 4 | Pending |
| STORE-02 | Phase 4 | Pending |
| STORE-03 | Phase 4 | Pending |
| STORE-04 | Phase 4 | Pending |
| STORE-05 | Phase 4 | Pending |
| SEC-01 | Phase 5 | Pending |
| SEC-02 | Phase 5 | Pending |
| SEC-03 | Phase 5 | Pending |
| SEC-04 | Phase 5 | Pending |
| PERF-01 | Phase 6 | Pending |
| PERF-02 | Phase 6 | Pending |
| VERIF-01 | Phase 7 | Pending |

**Coverage:**
- v1 requirements: 22 total
- Mapped to phases: 22
- Unmapped: 0 ✓

---
*Requirements defined: 2026-06-12*
*Last updated: 2026-06-12 — traceability filled after roadmap creation*
