# Phase 8: Suppliers and Product Cleanup - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-15
**Phase:** 8-suppliers-and-product-cleanup
**Areas discussed:** Lien commande ↔ fournisseur, Champs & note fournisseur, Suppression d'un fournisseur lié, Affichage de la liste fournisseurs

---

## Lien commande ↔ fournisseur

| Option | Description | Selected |
|--------|-------------|----------|
| Déroulant + « Autre » (libre) | Menu déroulant des fournisseurs + option « Autre » révélant le texte libre ; stocke supplier_id si choisi, conserve toujours le nom en texte (rétrocompatible) | ✓ |
| Déroulant seul + créer à la volée | Menu déroulant uniquement + bouton « + nouveau fournisseur » depuis la commande | |
| Déroulant seul, sans texte libre | Strict : que le menu déroulant, anciennes commandes à rerattacher | |

**User's choice:** Déroulant + « Autre » (libre)
**Notes:** Garantit zéro migration des commandes historiques (D-02).

---

## Champs & note fournisseur

| Option | Description | Selected |
|--------|-------------|----------|
| Optionnelle, étoiles cliquables | Note 1-5 facultative en étoiles ; liste affiche le nb de commandes liées | ✓ |
| Optionnelle, menu 1-5 | Note facultative via menu déroulant | |
| Obligatoire, étoiles cliquables | Note 1-5 forcée à chaque création/édition | |

**User's choice:** Optionnelle, étoiles cliquables
**Notes:** Widget étoiles à garder réutilisable pour la notation produit (Phase 9).

---

## Suppression d'un fournisseur lié

| Option | Description | Selected |
|--------|-------------|----------|
| Délier, garder le nom | SET NULL sur supplier_id ; la commande conserve le nom en texte | ✓ |
| Bloquer la suppression | Refuser tant que des commandes y sont rattachées | |

**User's choice:** Délier, garder le nom
**Notes:** Cohérent avec la rétrocompatibilité (ON DELETE SET NULL).

---

## Affichage de la liste des fournisseurs

| Option | Description | Selected |
|--------|-------------|----------|
| Tableau complet | Nom, URL cliquable, note étoiles, commentaire, nb commandes, actions | ✓ |
| Cartes | Grille de cartes par fournisseur | |
| Tableau minimal | Nom, note, actions seulement | |

**User's choice:** Tableau complet
**Notes:** Cohérent avec la liste Produits existante.

---

## Claude's Discretion

- Implémentation exacte du widget étoiles (CSS/JS), ordre/style des colonnes, formulation des messages de validation, choix `<select>`+JS vs datalist pour le déroulant fournisseur, ajout éventuel du tri par note.

## Deferred Ideas

- Tri/filtre de la liste fournisseurs par note (nice-to-have).
- Statistiques agrégées par fournisseur (dépense totale, marge moyenne) — phase future.
- Notation produit (Phase 9) et auto-remplissage URL (Phase 10) — déjà au roadmap.
