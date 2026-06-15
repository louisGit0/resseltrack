# ResellTrack

## What This Is

ResellTrack est une plateforme multi-utilisateurs de **suivi d'achat-revente avec calcul de rentabilité** : achat en lots (souvent AliExpress, en USD avec port et douane) revendus à l'unité (Vinted & co). PHP 8.3 + MySQL 8, architecture MVC maison sans framework, conteneurisée avec Docker.

L'application est désormais **déployée et pleinement fonctionnelle sur Vercel** (milestone v1.0 livré : Aiven MySQL/TLS, sessions persistantes, images Cloudinary, sécurité de prod, vérification end-to-end). Le jalon courant (v2.0) ajoute des fonctionnalités produit.

## Core Value

Tout ce qui fonctionne en local doit fonctionner **à l'identique une fois déployé sur Vercel** — le site en ligne est pleinement opérationnel pour de vrais utilisateurs (connexion qui persiste, images qui s'affichent, données qui se sauvegardent).

## Current Milestone: v2.0 — Fournisseurs, notation produit & auto-remplissage

**Goal:** Enrichir le suivi achat-revente : gérer ses fournisseurs (notés), noter les produits reçus, et accélérer la saisie via l'import d'une page produit publique.

**Target features:**
- **Fournisseurs** : onglet dédié + CRUD (nom, URL, note 1-5, commentaire) ; lien optionnel depuis les commandes (champ `supplier` libre → menu déroulant).
- **Notation produit** : note (1-5) + commentaire, éditable après réception, affichée en liste/fiche produit.
- **Auto-remplissage best-effort** : coller une URL de **produit public** → scrape serveur (curl + parsing) pré-remplit titre/prix/image ; repli manuel ; site par site (cible initiale AliExpress). Scraping de pages de **commande privées** = hors périmètre.
- **Nettoyage** : la suppression d'un produit purge ses objets Cloudinary (dette de v1.0).

## Requirements

### Validated

<!-- Capacités existantes, déduites de la cartographie .planning/codebase/. Fonctionnent en local. -->

- ✓ Authentification : inscription, connexion, déconnexion, sessions, CSRF, rate-limiting du login — existing
- ✓ Produits : CRUD, upload d'images, catégories, statut de stock — existing
- ✓ Achats en lots multi-devises (USD→EUR via frankfurter.app), allocation port/douane/poids — existing
- ✓ Ventes à l'unité avec garde de stock concurrent (`FOR UPDATE`) et CUMP figé — existing
- ✓ Commandes fournisseurs + URLs de réapprovisionnement — existing
- ✓ Tableau de bord Chart.js (profit, marge nette, ROI) — existing
- ✓ Export CSV (UTF-8 BOM, compatible Excel FR) — existing
- ✓ Gestion de profil utilisateur — existing

### Active

<!-- Périmètre du jalon v2.0 : fonctionnalités fournisseurs / notation / auto-remplissage. -->

- [ ] Onglet **Fournisseurs** : CRUD (nom, URL, note 1-5, commentaire) scopé par utilisateur
- [ ] Lien optionnel des commandes vers un fournisseur (champ `supplier` libre → menu déroulant, rétrocompatible)
- [ ] **Notation produit** (note 1-5 + commentaire), éditable après réception, affichée en liste/fiche
- [ ] **Auto-remplissage best-effort** depuis une URL de produit public (scrape serveur titre/prix/image, repli manuel)
- [ ] Purge des images Cloudinary à la suppression d'un produit (dette v1.0)

### Shipped — v1.0 (déploiement Vercel)

<!-- Livré et vérifié en production le 2026-06-15. -->

- ✓ Routing serverless Vercel + assets CDN ; dev Docker préservé (DEPLOY-01/02/03)
- ✓ Aiven MySQL 8 en TLS + migration one-shot `bin/migrate.php` (DB-01/02/03)
- ✓ Sessions persistantes MySQL + cookie Secure/HttpOnly/SameSite + CSRF (SESS-01..04)
- ✓ Images sur **Cloudinary** (pivot depuis R2) + garde 3,5 Mo (STORE-01..05)
- ✓ HSTS + garde de boot prod + secrets hors dépôt (SEC-01..04)
- ✓ N+1 corrigé + `ExchangeRateService` durci (frankfurter.dev) (PERF-01/02)
- ✓ Vérification end-to-end live (VERIF-01)

### Out of Scope

<!-- Frontières explicites avec justification, pour éviter le scope creep. -->

- Nouvelles fonctionnalités / « mises à jour » du site — reporté à un futur jalon ; la priorité est d'abord un déploiement **fonctionnel**
- Hébergement Docker-natif (Railway / Render / Fly.io) — écarté : l'utilisateur a explicitement choisi Vercel malgré le mauvais ajustement
- Migration vers PostgreSQL — écarté : code PDO et SQL spécifiques à MySQL, on conserve MySQL
- Durcissement complet des points relevés par la cartographie (N+1, refonte du système de migrations, couverture de tests étendue) — hors périmètre, sauf ce qui est **indispensable** au déploiement

## Context

- Architecture MVC maison (Front Controller + Router regex), sans framework ni conteneur d'injection de dépendances. Cartographie complète dans `.planning/codebase/` (ARCHITECTURE, STACK, CONCERNS, CONVENTIONS, INTEGRATIONS, STRUCTURE, TESTING).
- **Points de friction connus, directement pertinents pour le déploiement serverless** :
  - Filesystem éphémère sur Vercel → casse l'upload d'images (`public/assets/uploads/`) **et** les sessions PHP fichiers.
  - `Schema::ensure()` lance du DDL (`SHOW COLUMNS`, `ALTER TABLE`) à chaque requête → inadapté au serverless, à sortir vers une migration one-shot.
  - `SESSION_SECURE=0` par défaut et absence de header HSTS → à corriger pour une mise en ligne HTTPS.
  - Routing reposant sur Apache `.htaccess` + `public/index.php` → à reporter dans `vercel.json`.
- Le routing front-controller se prête bien à une réécriture « tout vers `index.php` » côté Vercel.
- `ExchangeRateService` fait un appel HTTP sortant (frankfurter.app) → compatible serverless.
- Couverture de tests : seul `src/Services/ProfitCalculator.php` est testé (PHPUnit).

## Constraints

- **Plateforme**: Cible imposée = **Vercel** (serverless) — choix explicite de l'utilisateur, malgré un stack PHP/Apache/MySQL peu adapté.
- **Tech stack**: Conserver PHP 8.3 et **MySQL** (pas de migration Postgres) ; adapter le code au minimum, ne pas réécrire l'application.
- **Filesystem**: Aucune écriture disque persistante en serverless — uploads et sessions doivent passer par des services externes.
- **Sécurité**: Déploiement HTTPS public → secrets hors du dépôt, cookies sécurisés, en-têtes de sécurité de production.

## Key Decisions

<!-- Décisions qui contraignent le travail futur. -->

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Déployer sur Vercel malgré un stack PHP/Apache/MySQL peu adapté | Choix explicite de l'utilisateur après présentation des alternatives Docker-natives | — Pending |
| Conserver MySQL via **Aiven for MySQL 8** (pas de Postgres, pas de TiDB) | Code PDO/SQL spécifiques MySQL ; TiDB casse le verrou `FOR UPDATE` des ventes (transactions optimistes) | ✓ Good — confirmé après recherche |
| Sessions stockées en base MySQL (`SessionHandlerInterface`) | Réutilise l'infra DB existante ; évite d'ajouter un service Redis | ✓ Good — confirmé après recherche |
| Upload d'images via **Cloudflare R2** (`aws/aws-sdk-php`) | Vercel Blob n'a aucun SDK PHP officiel (API interne fragile) ; R2 est S3-compatible et maintenable | ✓ Good — confirmé après recherche |
| Nouvelles fonctionnalités reportées à un futur jalon | Priorité : un déploiement fonctionnel d'abord | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-06-12 after initialization*
