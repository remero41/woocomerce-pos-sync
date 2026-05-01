# Changelog

Todas las versiones notables de este plugin. Formato: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versionado: [SemVer](https://semver.org/).

## [2.0.0] - 2026-04-24

### Fixed
- **BUG-WC-003** (MEDIA): race condition en idempotencia de webhooks. `get_transient + set_transient` no era atómico: con 10 webhooks concurrentes del mismo `idempotency_key`, 2-3 se procesaban como "nuevos". Ahora se usa una tabla dedicada `wp_tpv_sync_webhook_idem` con PK UNIQUE y `INSERT IGNORE`. Solo un proceso gana la inserción; los demás reciben `affected_rows=0` y responden con `duplicate: true`. Verificado con 10 webhooks concurrentes → 1 nuevo + 9 duplicados.

### Added
- Tabla `wp_tpv_sync_webhook_idem` creada automáticamente en activation hook y en `plugins_loaded` (con sentinela `tpv_sync_idem_table_v1`) para migrar instalaciones existentes sin downtime.
- Purga automática de entradas de idempotencia > 48 h integrada en el cron `tpv_sync_queue_purge` (diario).
- Endpoint e2e dev-only (`tests/e2e_api.php`) con 35+ acciones para suites de test externas. Requiere `TPV_SYNC_E2E_ENABLED=true` + header `X-Test-Secret`.
- Suite de tests e2e `cazabugs_200_wp.sh` (200 tests / 20 áreas) + `bugs_focused.sh` (TDD para bugs concretos).

### Changed
- `declare(strict_types=1)` añadido en todas las clases del plugin (`includes/*.php` y `woocommerce-conector.php`). Mejora el tipado estático y detección de bugs en PHPStan.
- Estructura de proyecto madurada: `composer.json` (dev-tools), `phpstan.neon` (level 5), `phpcs.xml` (WordPress-Extra), CI en `.github/workflows/ci.yml` (lint + static analysis + e2e).

### API del TPV (cambio no incluido en el plugin, documentado para trazabilidad)
- **BUG-WC-001** arreglado en la BD del TPV (`2465_api_clients.scopes`): añadido scope `stock:read` al client `woocommerce`. Antes: `GET /products/{id}/stock → 403 Forbidden`, causando que los cambios manuales de stock en WC se encolaran pero nunca llegaran al TPV.

## [1.x]

Desarrollo inicial. Ver historia en git.
