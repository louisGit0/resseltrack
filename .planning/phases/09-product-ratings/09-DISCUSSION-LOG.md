# Phase 9: Product Ratings - Discussion Log

> **Audit trail only.** Not consumed by downstream agents — decisions live in CONTEXT.md.

**Date:** 2026-06-15
**Phase:** 9-product-ratings
**Areas discussed:** Disponibilité « après réception », Saisie de la note, Affichage en liste, Affichage sur la fiche produit

---

## Disponibilité « après réception »

| Option | Selected |
|--------|----------|
| Toujours autorisée (pas de gating) | ✓ |
| Conditionnée à un achat existant | |

**Notes:** Le produit n'a pas d'état « reçu » formel → note toujours éditable, frictionless (D-01).

## Saisie de la note

| Option | Selected |
|--------|----------|
| Dans le formulaire d'édition produit | |
| Formulaire **+ notation rapide inline sur la fiche** | ✓ |

**Notes:** Formulaire (section étoiles + commentaire) ET quick-rate inline sur la fiche via POST /products/{id}/rate (D-02, D-03).

## Affichage en liste

| Option | Selected |
|--------|----------|
| Colonne étoiles, commentaire sur la fiche | |
| Badge accolé au nom | ✓ |
| Colonne étoiles + commentaire tronqué | |

**Notes:** Petites étoiles à côté du nom, pas de colonne, commentaire non affiché en liste (D-04).

## Affichage sur la fiche produit

| Option | Selected |
|--------|----------|
| Bloc dédié « Note » | |
| En-tête près du nom | ✓ |

**Notes:** Étoiles interactives (quick-rate) à côté du titre, commentaire dessous (D-05).

## Claude's Discretion

- Réutilisation directe de initStarRating pour le formulaire vs petite adaptation « submit on click » pour le quick-rate inline ; style du badge en liste ; redirect vs JSON pour /rate.

## Deferred Ideas

- Tri/filtre par note ; analytics agrégées ; auto-remplissage URL (Phase 10).
