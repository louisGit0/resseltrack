# Phase 10: Product URL Auto-fill - Discussion Log

> **Audit trail only.** Not consumed by downstream agents — decisions live in CONTEXT.md.

**Date:** 2026-06-16
**Phase:** 10-product-url-auto-fill
**Areas discussed:** Périmètre des sites, Mapping du prix, Gestion de l'image, Champs déjà remplis

---

## Périmètre des sites
| Option | Selected |
|--------|----------|
| AliExpress + repli Open Graph (SSRF gardé) | ✓ |
| AliExpress uniquement (allowlist stricte) | |

**Notes:** Parsing AliExpress + fallback OG générique pour tout autre http(s) ; gardes SSRF obligatoires (D-01, D-02).

## Mapping du prix
| Option | Selected |
|--------|----------|
| market_price_new, valeur brute | |
| market_price_new + conversion EUR | ✓ |
| Ne pas remplir le prix | |

**Notes:** Conversion EUR si devise détectée via ExchangeRateService ; sinon valeur brute + avertissement (best-effort, pas de mauvais label silencieux) (D-03, D-04).

## Gestion de l'image
| Option | Selected |
|--------|----------|
| Upload Cloudinary à l'enregistrement | |
| Aperçu seulement | ✓ |
| URL externe stockée telle quelle | |

**Notes:** Aperçu seulement → pas de modif CSP, pas d'orphelin ; upload manuel via le champ cover existant (D-05).

## Champs déjà remplis
| Option | Selected |
|--------|----------|
| Ne remplir que les champs vides | ✓ |
| Écraser tous les champs | |

**Notes:** Non destructif, populate côté client uniquement sur champs vides (D-06).

## Claude's Discretion
- Stratégie d'extraction AliExpress (JSON-LD vs sélecteurs vs meta) ; implémentation exacte des gardes IP SSRF ; état loading du bouton.

## Deferred Ideas
- Parsers dédiés autres sites (Amazon, Vinted) ; upload-by-URL Cloudinary ; scraping pages de commande privées (hors scope).
