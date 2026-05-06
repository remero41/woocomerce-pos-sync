# Catinfog Conector — plugin WooCommerce

Plugin WordPress que conecta una tienda **WooCommerce** con un **TPV Catinfog** (basado en OpenCart) y mantiene catálogo, stock y pedidos sincronizados en tiempo real, en ambas direcciones.

> Para la descripción orientada a usuario final (WordPress.org), mira [`readme.txt`](readme.txt).
> Para el changelog, mira [`CHANGELOG.md`](CHANGELOG.md).

---

## ¿Qué hace?

El cliente típico tiene una tienda online en WooCommerce y un punto de venta físico Catinfog. Sin este plugin, mantener los precios, stock y catálogo coherentes entre ambos es manual y propenso a errores. El plugin automatiza el flujo:

- **Catálogo bidireccional**: productos creados/editados/eliminados en cualquiera de los dos lados se replican en el otro. El admin elige cuál es la **fuente de catálogo** durante el wizard inicial (modo `principal=tpv` o `principal=wc`); en el lado no-principal los cambios se revierten para evitar divergencia.
- **Variantes**: productos variables WC (Talla / Color / etc.) se aplanan al modelo del TPV (una opción con valores combinados tipo "S-Rojo", "M-Verde"). Multi-atributo se gestiona con producto cartesiano restringido a las combinaciones realmente existentes en WC, preservando código de barras por variante.
- **Imágenes**: thumbnail + galería WC se suben al TPV vía `POST /products/{id}/images` con descarga server-side validada por dominio (anti-SSRF). El plugin marca cada URL ya enviada para evitar reenvíos.
- **Stock**: movimientos de stock se propagan en deltas (no totales) via `PATCH /products/{id}/stock` con anti-bucle bidireccional.
- **Pedidos WC → TPV**: cada pedido pagado en WC se crea en el TPV con líneas, IVA por línea, cupones, dirección, NIF y total bruto. El TPV emite ticket o factura (VeriFactu).
- **Webhooks TPV → WC**: stock, status de pedido, devoluciones, clientes y categorías llegan como webhooks HMAC firmados. Idempotencia atómica vía tabla con PK única.
- **Mapeo de impuestos**: panel admin para asociar cada `tax_class` de WC con el `tax_class_id` del TPV. Conversión gross↔net automática usando `wc_get_price_excluding_tax` para respetar la configuración fiscal del shop. Banner crítico si la combinación WC es peligrosa (precios brutos sin rates → riesgo de doble IVA).

## Arquitectura

```
WooCommerce ───push HTTP───→  TPV API
WooCommerce ←──webhook HMAC── TPV
```

El plugin actúa como cliente OAuth2 contra la API REST del TPV (módulo `api/v1` del proyecto [api_tpv](https://github.com/remero41/api_tpv)). Defensa en profundidad:
- Cliente HTTP con **circuit breaker** (apaga la integración tras N fallos seguidos) y **retry con backoff** ante 429/5xx.
- **Auto-recovery HMAC**: si el TPV responde 401 con `signature_invalid` (rotación de secret no propagada), el plugin re-registra el webhook silenciosamente y reintenta.
- **Cifrado libsodium** para los secrets en `wp_options` (no en plain text).
- **Queue persistente** con backoff exponencial para operaciones que fallan y deben reintentarse fuera del request.

## Requisitos

- PHP 8.0+
- WordPress 6.0+ con WooCommerce 7.0+
- HPOS (High Performance Order Storage) activo
- libsodium habilitado (PHP 7.2+ lo trae)
- MySQL 5.7+ / MariaDB 10.3+

## Estructura del repo

```
woocommerce-conector/             # ⚑ El plugin que se distribuye en WP.org
    woocommerce-conector.php          Bootstrap: hooks, activación, cron, rewrite
    readme.txt                        Readme estilo WP.org
    CHANGELOG.md
    includes/
        class-admin.php                  UI admin (wizard, tabs, DLQ panel)
        class-api-client.php             OAuth2, HMAC, circuit breaker
        class-circuit-breaker.php
        class-cli.php                    Comandos `wp tpv-sync *`
        class-customer-sync.php          WC↔TPV clientes (signup/edit/delete)
        class-notifications.php          Alertas email
        class-order-sync.php             WC↔TPV pedidos: refunds, cupones
        class-product-sync.php           WC↔TPV productos, variantes, imágenes
        class-queue.php                  Fallback queue con backoff
        class-secrets.php                Cifrado libsodium de secrets
        class-webhook-handler.php        Receptor HMAC + idempotencia + DLQ
    assets/                           Imágenes del admin
    languages/                        i18n (.po + .mo: es_ES, en_US, fr_FR)
    tests/                            Endpoints HTTP dev-only que viajan con
                                      el plugin (gated por TPV_SYNC_E2E_ENABLED)
        e2e_api.php
        e2e_trigger.php

.memoria_plugin_wordpress/        # Recursos NO distribuidos en WP.org
    composer.json, phpcs.xml, phpstan.neon, phpunit.xml.dist, infection.json5
    tests/
        unit/                            PHPUnit (Queue, CircuitBreaker, Secrets,
                                         WebhookSignature, options flatten,
                                         tax mapping)
        dlq_e2e.php                      DLQ contra wpdb real
        customers_e2e_full.php           Sync clientes contra ambas BDs
        cazabugs_wc.php
        run_cazabugs*.php                Suites cazabugs (PHP)
        run_sprint*.php
        wp-stubs.php                     Stubs WP/WC para tests aislados
    dev-tools/
        findings/                        Análisis de bugs encontrados
        tests/
            cazabugs_200_wp.sh           Suite 200 tests bash sobre WP+TPV
            bugs_focused.sh              Tests TDD para bugs concretos
            README.md
            .env.example
    docs/
        PLUGIN_DEVELOPER.md              Guía para desarrolladores

.github/workflows/                # CI: phpcs, phpstan, phpunit, semgrep
README.md                         # Este fichero
.gitignore
```

## Setup local

```bash
# Todas las herramientas de dev viven en .memoria_plugin_wordpress/
cd .memoria_plugin_wordpress

composer install                  # Instala dev-tools
composer analyze                  # PHPStan level 5
composer lint                     # PHPCS (WordPress-Extra)
composer lint:fix
composer test                     # PHPUnit unit tests
composer test:focused             # Bugs concretos (requiere WP+TPV reales)
composer test:suite               # Suite completa 200 tests bash
```

## Despliegue

Sólo el directorio `woocommerce-conector/` se copia al servidor WP. Por
ejemplo, para desplegar a `tpv85`:

```bash
rsync -av --delete woocommerce-conector/ \
    /var/www/html/tpv85/public_html/wp-content/plugins/woocommerce_conector/
```

Nota: WP usa la carpeta `woocommerce_conector` (guion bajo) en
`wp-content/plugins/` por compatibilidad histórica del slug. El repo usa
`woocommerce-conector` (guion) por consistencia con el nombre del fichero
principal.

## Flujo de sincronización

### Productos WC → TPV

Hooks `woocommerce_new_product`, `woocommerce_update_product`, `wp_trash_post`:

1. `push_wc_product_to_tpv()` lee `_tpv_product_id` meta del post.
2. Si existe → `PATCH /products/{id}` con `name`, `description`, `price`, `tax_class_id` (mapeado), `status`. Si responde 404 (mapping huérfano tras reset del TPV) → borra el meta y cae al flujo de creación.
3. Si no existe → busca por `model`/`sku` en el cache del catálogo TPV para reconciliar. Si no se encuentra → `POST /products`.
4. Tras crear/actualizar: subir imagen destacada (`is_main=true`) + galería con `image_url` (anti-SSRF por `allowed_domain`). Idempotente vía meta `_tpv_images_sent`.
5. Variantes: `build_options_for_tpv` genera la estructura `options[]` que la API entiende. 1 atributo → directo. >1 atributo → aplana combinaciones reales con guion (`xs-rojo`, `s-verde`).

### Webhooks TPV → WC

Endpoint `/tpv-webhook/` registrado vía rewrite rule:

1. Verifica firma HMAC-SHA256 y versión del payload.
2. **Idempotencia atómica**: `INSERT IGNORE` sobre tabla `wp_tpv_sync_webhook_idem` (PK única en `idempotency_key`).
3. Si `affected_rows=0` → ya procesado, responde `{duplicate: true}`.
4. Si no → procesa async (`fastcgi_finish_request`) y dispatcha por `event_type`: `product.updated`, `stock.adjusted`, `order.payment_changed`, `return.created`, etc.

## Mapeo de impuestos

`Catinfog Conector → Impuestos` en el admin de WP.

- Tabla con todas las clases fiscales WC (Standard implícita + custom).
- Por cada una, dropdown con clases del TPV. Default "Sin impuestos".
- AJAX `tpv_sync_load_tax_mapping` carga las clases TPV via `GET /tax-classes` (cacheado 24h).
- Almacenamiento en `wp_options.tpv_sync_tax_class_mapping` como JSON-safe array (slug → tax_class_id).

Banner crítico se muestra al entrar al panel si:
- `woocommerce_calc_taxes=yes` + `wp_woocommerce_tax_rates` vacía → ventas web sin IVA mientras TPV sí cobra IVA.
- Adicionalmente `prices_include_tax=yes` → riesgo de **doble IVA** porque WC no puede descontar el bruto.

## Observabilidad

- Tabla `wp_tpv_sync_log` con todos los eventos (event_type, resource, resource_id, status, message, timestamp).
- Tab "Log" en el admin con filtros (todos / pendientes / abandonados / completados).
- Exportación CLI: `wp tpv-sync export-logs --days=7 --status=error`.
- Notificaciones horarias evalúan reglas: queue abandoned, breaker open, TPV unreachable, ratio de errores > umbral.

## Convenciones de código

- Clases: prefijo `TPV_Sync_*` (Pascal con guiones bajos, convención plugin WP).
- Un fichero por clase: `class-*.php`.
- `declare(strict_types=1)` en todos los ficheros PHP.
- Metas WC: `_tpv_product_id`, `_tpv_option_value_id`, `_tpv_order_id`, `_tpv_customer_id`, `_tpv_status_origin`, `_tpv_refund_origin`, `_tpv_refund_synced`, `_tpv_images_sent`.
- Text domain: `tpv-sync`.

## Licencia

GPLv2 o posterior.
