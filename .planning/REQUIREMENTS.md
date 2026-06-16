# Requirements: ResellTrack — Déploiement Vercel

**Defined:** 2026-06-12
**Core Value:** Tout ce qui fonctionne en local fonctionne à l'identique une fois déployé sur Vercel — le site en ligne est pleinement opérationnel pour de vrais utilisateurs.

> Jalon **brownfield** : l'application existe déjà et fonctionne en local. Ces requirements couvrent uniquement ce qu'il faut pour la **rendre fonctionnelle déployée sur Vercel serverless**. Les capacités produit existantes (auth, produits, achats, ventes, commandes, dashboard, export, profil) sont la base « Validated » et ne sont pas re-spécifiées.

## v1 Requirements

Requirements pour la mise en ligne fonctionnelle. Chacun est mappé à une phase du roadmap.

### Déploiement & Routing

- [x] **DEPLOY-01**: L'application répond à toutes ses routes une fois déployée sur Vercel (front controller atteint via `vercel.json`, sans Apache/`.htaccess`)
- [x] **DEPLOY-02**: Les assets statiques (CSS, JS) sont servis par le CDN Vercel sans transiter par une fonction PHP
- [x] **DEPLOY-03**: Le développement local Docker continue de fonctionner inchangé (`.htaccess` préservé pour le dev)

### Base de données

- [x] **DB-01**: L'application se connecte à une base **Aiven MySQL 8** managée en **TLS** (certificat CA) depuis les fonctions Vercel
- [x] **DB-02**: Le schéma complet est appliqué via une migration **one-shot** (`bin/migrate.php`), hors du chemin par-requête
- [x] **DB-03**: `Database::connection()` n'exécute plus de DDL au runtime (`Schema::ensure()` retiré du chemin par-requête) — aucune race condition au cold start

### Sessions

- [x] **SESS-01**: La session utilisateur **persiste** à travers les invocations serverless (l'utilisateur reste connecté d'une page à l'autre)
- [x] **SESS-02**: Les sessions sont stockées en base MySQL via un `SessionHandlerInterface`
- [x] **SESS-03**: Le cookie de session porte les flags `Secure` + `HttpOnly` + `SameSite=Lax` en production (`SESSION_SECURE=1`)
- [x] **SESS-04**: La protection CSRF reste fonctionnelle sur le nouveau stockage de session (token validé sur les POST)

### Stockage des images

- [x] **STORE-01**: L'upload d'une image produit l'écrit sur **Cloudflare R2** et stocke son URL publique en base
- [x] **STORE-02**: Les images uploadées s'affichent en production depuis leur URL R2
- [x] **STORE-03**: La suppression d'une image produit retire l'objet correspondant sur R2
- [x] **STORE-04**: Les images existantes (chemins disque locaux) sont migrées vers R2 avant la mise en ligne, ou une stratégie de repli est documentée
- [x] **STORE-05**: Une garde de taille (~3,5 Mo) empêche les uploads dépassant la limite Vercel (4,5 Mo) avec un message clair à l'utilisateur

### Sécurité & Configuration production

- [x] **SEC-01**: Tous les secrets sont fournis via les variables d'environnement Vercel (aucun secret committé ; `.env` gitignoré)
- [x] **SEC-02**: L'en-tête `Strict-Transport-Security` (HSTS) est émis en production
- [x] **SEC-03**: La CSP autorise le domaine public R2 pour les images (`img-src`)
- [x] **SEC-04**: L'application refuse de démarrer en production avec une configuration dangereuse (ex. `SESSION_SECURE=0` ou credentials par défaut)

### Fiabilité (bloquant pour « fonctionnel »)

- [x] **PERF-01**: La page de création/édition de vente charge sans timeout — le N+1 de `SaleController::productsMeta()` est remplacé par une requête agrégée
- [x] **PERF-02**: `ExchangeRateService` utilise `curl` avec timeout (5 s) et logge ses échecs ; un échec d'API n'enregistre **pas** silencieusement un coût de 0,00 € (avertissement visible)

### Vérification en production

- [x] **VERIF-01**: Chaque fonctionnalité existante (auth, produits + image, achats multi-devises, ventes avec garde de stock, commandes, dashboard, export CSV, profil) est vérifiée fonctionnelle sur l'URL Vercel déployée

## v2.0 Requirements (jalon courant)

Fonctionnalités produit ajoutées dans le jalon v2.0. Chacune sera mappée à une phase du roadmap.

### Fournisseurs

- [x] **SUP-01**: Onglet **Fournisseurs** avec CRUD complet (nom, URL, note 1-5, commentaire), scopé par utilisateur (`WHERE user_id`)
- [x] **SUP-02**: Les commandes peuvent référencer **optionnellement** un fournisseur de la liste — le champ `supplier` texte libre actuel devient un menu déroulant optionnel, **rétrocompatible** avec les commandes existantes (le texte libre reste accepté)

### Notation produit

- [x] **RATE-01**: Un produit porte une **note (1-5) + un commentaire**, éditables après réception, affichés sur la liste et la fiche produit

### Auto-remplissage

- [ ] **IMPORT-01**: Coller une **URL de produit public** sur le formulaire tente un **scrape serveur best-effort** (curl + parsing HTML) pour pré-remplir titre + prix + image, avec **repli manuel** si le site bloque ; implémenté **site par site** (cible initiale : pages produit AliExpress). Le scraping de pages de **commande privées** (authentifiées) est explicitement **hors périmètre**.

### Nettoyage (dette v1.0)

- [x] **OPS-06**: La suppression d'un produit **purge ses objets Cloudinary** (cover + galerie), best-effort avec log — comme `deleteImage()` le fait déjà pour une photo

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
| DEPLOY-01 | Phase 1 | Complete |
| DEPLOY-02 | Phase 1 | Complete |
| DEPLOY-03 | Phase 1 | Complete |
| DB-01 | Phase 2 | Complete |
| DB-02 | Phase 2 | Complete |
| DB-03 | Phase 2 | Complete |
| SESS-01 | Phase 3 | Done |
| SESS-02 | Phase 3 | Done |
| SESS-03 | Phase 3 | Done |
| SESS-04 | Phase 3 | Done |
| STORE-01 | Phase 4 | Complete |
| STORE-02 | Phase 4 | Complete |
| STORE-03 | Phase 4 | Complete |
| STORE-04 | Phase 4 | Complete |
| STORE-05 | Phase 4 | Complete |
| SEC-01 | Phase 5 | Complete |
| SEC-02 | Phase 5 | Complete |
| SEC-03 | Phase 5 | Complete |
| SEC-04 | Phase 5 | Complete |
| PERF-01 | Phase 6 | Complete |
| PERF-02 | Phase 6 | Complete |
| VERIF-01 | Phase 7 | Done |
| SUP-01 | Phase 8 | Complete |
| SUP-02 | Phase 8 | Complete |
| OPS-06 | Phase 8 | Complete |
| RATE-01 | Phase 9 | In Progress (storage landed 09-01; behavior in 09-02..04) |
| IMPORT-01 | Phase 10 | Pending |

**Coverage:**
- v1 requirements: 22 total — all Done (milestone v1.0 shipped)
- v2.0 requirements: 5 total — all mapped (SUP-01/02 + OPS-06 → Phase 8 ; RATE-01 → Phase 9 ; IMPORT-01 → Phase 10)

---
*Requirements defined: 2026-06-12*
*Last updated: 2026-06-15 — v2.0 traceability filled in (phases 8-10 assigned)*
