# Catinfog Conector — plugin WooCommerce

Conector bidireccional entre WooCommerce y el TPV Catinfog. Sincroniza productos, stock, pedidos y devoluciones en tiempo real.

Para la descripción orientada a usuario final (WP.org), mira [`readme.txt`](readme.txt).
Para el changelog, mira [`CHANGELOG.md`](CHANGELOG.md).

---

## Desarrollo

### Requisitos

- PHP 8.0+
- WordPress 6.0+ con WooCommerce 7.0+ (HPOS activo)
- Composer (para dev-tools)
- MySQL / MariaDB

### Setup local

```bash
# Instala dev-tools
composer install

# Análisis estático
composer analyze        # PHPStan level 5

# Lint (WordPress-Extra)
composer lint           # ver issues
composer lint:fix       # arreglar automáticamente lo arreglable

# Tests
composer test           # PHPUnit (unitarios; WIP)
composer test:focused   # Tests dedicados bugs concretos (requiere WP running)
composer test:suite     # Suite e2e completa 200 tests (requiere WP + TPV)
```

### Estructura

```
woocommerce-conector.php     # Bootstrap: hooks, activación, cron, rewrite
includes/
    class-admin.php          # UI admin (wizard + logs + diagnóstico)
    class-api-client.php     # Cliente OAuth2 + retry + HMAC signing
    class-circuit-breaker.php
    class-cli.php            # Comandos wp tpv-sync *
    class-notifications.php  # Alertas email/Slack/Telegram
    class-order-sync.php     # WC↔TPV: pedidos, refunds, anti-bucle
    class-product-sync.php   # WC↔TPV: productos, variantes, imágenes, stock
    class-queue.php          # Fallback queue con backoff exponencial
    class-secrets.php        # Cifrado libsodium de secrets
    class-webhook-handler.php # Receptor con firma HMAC + idempotencia atómica
tests/
    e2e_api.php              # Endpoint dev-only para suites e2e externas
    wp-stubs.php             # Stubs WP/WC para tests unitarios
    run_cazabugs*.php        # Tests internos con stubs
.memoria_wp/
    tests/
        cazabugs_200_wp.sh   # Suite e2e 200 tests (bash)
        bugs_focused.sh      # Tests TDD para bugs concretos
    findings/
        BUGS_WC_*.md         # Análisis de bugs encontrados
        FIXES_APLICADOS_*.md # Documentación de fixes aplicados
docs/
    PLUGIN_DEVELOPER.md      # Guía para desarrolladores
```

### Flujo de sincronización

**Productos WC → TPV** (hooks `woocommerce_new_product`, `woocommerce_update_product`, `wp_trash_post`, etc.):
1. `push_wc_product_to_tpv()` busca `_tpv_product_id` meta.
2. Si existe → `PATCH /products/{id}`. Si no → busca por `model`/`sku` para reconciliar. Si no → `POST /products`.
3. Stock se propaga en un hook dedicado (`push_wc_stock_change`) vía `PATCH /products/{id}/stock` con delta.

**Webhooks TPV → WC** (endpoint `/tpv-webhook/`):
1. Verifica firma HMAC-SHA256 y versión.
2. Idempotencia atómica: `INSERT IGNORE` sobre tabla `wp_tpv_sync_webhook_idem` (PK UNIQUE).
3. Si `affected_rows=0` → ya procesado, responde `duplicate: true`.
4. Si no → procesa async (`fastcgi_finish_request`) y dispatcha por `event_type`.

### Tests e2e

La suite `.memoria_wp/tests/cazabugs_200_wp.sh` ejecuta 200 tests contra una instancia WP real (por defecto `https://tu-tienda.ejemplo.com`) con TPV API también real. Cubre 20 áreas (config, auth, circuit breaker, CRUD productos, variantes, webhooks de todos los tipos, queue, pedidos, refunds, integridad/race).

Requiere:
- `define('TPV_SYNC_E2E_ENABLED', true)` en `wp-config.php`.
- Endpoint `tests/e2e_api.php` accesible con header `X-Test-Secret` (secret auto-generado en opción `tpv_sync_e2e_trigger_secret`).

El endpoint NUNCA se habilita en `WP_ENVIRONMENT_TYPE=production`.

### Observabilidad

- Tabla `wp_tpv_sync_log` con todos los eventos (event_type, resource, resource_id, status, message).
- Exportación: `wp tpv-sync export-logs --days=7 --status=error`.
- Notificaciones horarias evalúan: queue abandoned, breaker open, TPV unreachable.

### Convenciones

- Naming de clases: `TPV_Sync_*` (prefijo corto + Pascal).
- Fichero por clase: `class-*.php` (convención WP).
- `declare(strict_types=1)` en todos los ficheros.
- Metas de WC: `_tpv_product_id`, `_tpv_option_value_id`, `_tpv_order_id`, `_tpv_customer_id`, `_tpv_status_origin`, `_tpv_refund_origin`, `_tpv_refund_synced`.

### Licencia

GPLv2 o posterior.
