# Phase 4 Discussion Log

**Date:** 2026-06-12
**Mode:** discuss (interactive)

## Gray areas presented
Bundling vendor/ (Composer) · Stockage URL & clés d'objet · Garde de taille + php.ini · Images existantes + orphelins.
User chose to discuss **all four**.

## Decisions

| Area | Decision |
|------|----------|
| vendor/ | **Install au build** (vendor/ hors git), brancher vendor/autoload.php (guard is_file) dans public/index.php ; mécanisme exact (auto vs buildCommand) → recherche (D-01/02/03) |
| URL/clé | **URL publique r2.dev complète** stockée en base, **clé plate** random.ext → zéro changement de vue (D-05) |
| Taille | **Garde 3,5 Mo** + `upload_max_filesize=5M`/`post_max_size=5M` dans api/php.ini ; >4,5 Mo = 413 Vercel documenté (D-07) |
| Existant/orphelins | **Repli documenté** (prod vide, 0 produit) + **suppression best-effort** avec log (D-08) |

## Notes
- Déjà verrouillé (non redemandé) : Cloudflare R2 + aws/aws-sdk-php v3 (pas de Vercel Blob).
- R2Storage dans src/Services/ (I/O pur) ; ProductController swap move_uploaded_file→put / unlink→delete ; ProductImage.path stocke l'URL R2.
- Opérateur : créer bucket R2 + accès public r2.dev + token API → variables Vercel R2_ACCOUNT_ID/R2_ACCESS_KEY_ID/R2_SECRET_ACCESS_KEY/R2_BUCKET/R2_PUBLIC_BASE_URL.
- UI : seul le flash d'erreur >3,5 Mo (STORE-05), pas de ui-phase complet (D-10).
- Routé vers la recherche : mécanisme composer build sur vercel-php@0.7.4, config S3Client pour R2, accès public r2.dev, empreinte SDK en Lambda 256 Mo, coexistence autoloaders.
