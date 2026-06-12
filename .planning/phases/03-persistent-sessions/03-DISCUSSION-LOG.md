# Phase 3 Discussion Log

**Date:** 2026-06-12
**Mode:** discuss (interactive)

## Gray areas presented
Durée de vie & expiration · Cookie persistant ou non · Écriture DB à chaque requête · Schéma table sessions.
User chose to discuss **all four**.

## Decisions

| Area | Decision |
|------|----------|
| Expiration | Paresseuse (expires_at, delete/ignore si périmée), **TTL 30 jours**, pas de dépendance au GC PHP probabiliste (D-04) |
| Cookie | **Persistant 30 jours** (lifetime aligné au TTL serveur), flags Secure/HttpOnly/SameSite=Lax conservés (D-05) |
| Écriture DB | **À chaque requête** (UPSERT au write_close), pas de dirty-tracking — différé (D-06) |
| Schéma | **id VARCHAR(128) PK, data MEDIUMBLOB, expires_at INT indexé**, pas de user_id, InnoDB utf8mb4 (D-03) |

## Notes
- Déjà verrouillé (non redemandé) : stockage MySQL via SessionHandlerInterface (pas de Redis), flags cookie prod, CSRF doit rester fonctionnel.
- CSRF (SESS-04) : token dans $_SESSION → transparent une fois le store MySQL en place ; aucun changement de code Csrf (D-07). Corrige au passage le bug latent CSRF-419 en serverless.
- Étapes opérateur : re-run bin/migrate.php (table sessions) + variable Vercel SESSION_SECURE=1 (D-09/D-10).
- Routé vers le chercheur : ordering session_set_save_handler/session_start, regenerate_id avec handler custom, GC, MEDIUMBLOB, réutilisation du PDO au shutdown.
