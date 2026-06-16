# ResellTrack

## What This Is

ResellTrack est une plateforme multi-utilisateurs de **suivi d'achat-revente avec calcul de rentabilité** : achat en lots (souvent AliExpress, en USD avec port et douane) revendus à l'unité (Vinted & co). PHP 8.3 + MySQL 8, architecture MVC maison sans framework, conteneurisée avec Docker.

L'application est **déployée et pleinement fonctionnelle sur Vercel**. Deux jalons sont livrés et vérifiés en production : **v1.0** (Aiven MySQL/TLS, sessions persistantes, images Cloudinary, sécurité de prod) et **v2.0** (fournisseurs notés + lien commandes, notation produit, auto-remplissage URL best-effort, purge Cloudinary).

## Core Value

Tout ce qui fonctionne en local doit fonctionner **à l'identique une fois déployé sur Vercel** — le site en ligne est pleinement opérationnel pour de vrais utilisateurs (connexion qui persiste, images qui s'affichent, données qui se sauvegardent).

## Current State

**Shipped:** v2.0 (2026-06-16) — 3 phases, 14 plans, all verified live on https://resseltrack-nu.vercel.app. Previous: v1.0 (2026-06-15).

Archives: [`milestones/v2.0-ROADMAP.md`](milestones/v2.0-ROADMAP.md), [`milestones/v2.0-REQUIREMENTS.md`](milestones/v2.0-REQUIREMENTS.md), [`MILESTONES.md`](MILESTONES.md).

**Next milestone:** not yet defined — run `/gsd:new-milestone` to scope v3.0.

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

<!-- Aucun jalon en cours. Définir le prochain via /gsd:new-milestone. -->

- (Aucun — jalon v2.0 livré ; périmètre v3.0 à définir)

### Shipped — v2.0 (fonctionnalités produit)

<!-- Livré et vérifié en production le 2026-06-16. -->

- ✓ Onglet **Fournisseurs** : CRUD scopé utilisateur (nom, URL, note 1-5, commentaire) (SUP-01)
- ✓ Lien optionnel commande → fournisseur (menu déroulant + « Autre », rétrocompatible, `ON DELETE SET NULL`) (SUP-02)
- ✓ **Notation produit** (note 1-5 + commentaire) : formulaire + quick-rate sur la fiche, badge en liste (RATE-01)
- ✓ **Auto-remplissage best-effort** depuis une URL produit publique : `ProductImportService` SSRF-gardé, AliExpress + Open Graph, conversion EUR, aperçu data:URI (IMPORT-01)
- ✓ Purge des images Cloudinary à la suppression d'un produit (OPS-06)

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
| Déployer sur Vercel malgré un stack PHP/Apache/MySQL peu adapté | Choix explicite de l'utilisateur après présentation des alternatives Docker-natives | ✓ Good — v1.0 + v2.0 livrés et vérifiés en prod |
| Conserver MySQL via **Aiven for MySQL 8** (pas de Postgres, pas de TiDB) | Code PDO/SQL spécifiques MySQL ; TiDB casse le verrou `FOR UPDATE` des ventes (transactions optimistes) | ✓ Good — confirmé après recherche |
| Sessions stockées en base MySQL (`SessionHandlerInterface`) | Réutilise l'infra DB existante ; évite d'ajouter un service Redis | ✓ Good — confirmé après recherche |
| Upload d'images via **Cloudinary** (pivot depuis R2/`aws-sdk-php`) | R2/Supabase exigeaient carte/quota ; Cloudinary gratuit sans carte, REST signé via curl (pas de SDK) | ✓ Good — STORE-01..05 vérifiés live |
| v2.0 : lien commande↔fournisseur rétrocompatible via dual-write id+nom + `ON DELETE SET NULL` | Zéro migration des commandes existantes ; le texte libre reste affiché si le fournisseur est supprimé | ✓ Good — SUP-02 vérifié live |
| v2.0 : auto-remplissage = scrape serveur SSRF-gardé, pages **publiques** uniquement (commande privée hors scope) | Pages de commande authentifiées infaisables (session + anti-bot) ; SSRF = risque clé du fetch d'URL utilisateur | ✓ Good — IMPORT-01 vérifié live (SSRF rejette IP internes) |
| v2.0 : aperçu image scrapée en `data:` URI (pas d'upload, pas de modif CSP) | Évite orphelins Cloudinary + ne pas ouvrir la CSP à des CDN arbitraires | ✓ Good — D-05a |

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
*Last updated: 2026-06-16 after v2.0 milestone*
