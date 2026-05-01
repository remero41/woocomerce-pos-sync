=== Catinfog Conector ===
Contributors: catinfog
Tags: woocommerce, tpv, pos, sync, inventory, orders
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecta WooCommerce con el TPV Catinfog: sincroniza productos, stock, pedidos y devoluciones en tiempo real.

== Description ==

Catinfog Conector vincula tu tienda WooCommerce con un TPV Catinfog para mantener coherencia total entre la tienda física y la online.

**Funciones principales**

* Importar catálogo completo (productos, variantes, imágenes, categorías) desde el TPV.
* Empujar altas y cambios de producto desde WooCommerce al TPV.
* Sincronización bidireccional de stock con *ordering guard* (ignora eventos fuera de orden).
* Envío de pedidos WooCommerce al TPV con desglose fiscal unitario y vouchers.
* Propagación de cambios de estado (proceso → completado → cancelado) en ambos sentidos, con anti-bucle.
* Devoluciones/refunds atómicos entre TPV y WooCommerce, incluyendo soporte parcial por línea.
* Gestión de clientes con NIF/CIF español detectado automáticamente.

**Arquitectura robusta**

* Cliente OAuth2 con token caché, reintentos 429 respetando `Retry-After`, y jitter.
* *Circuit breaker* client-side (5 fallos → abre 60 s → half-open).
* Cola de fallback con backoff exponencial (1/5/15/60/240/1440 min).
* Webhooks firmados HMAC-SHA256 + versionado + idempotencia atómica vía `INSERT IGNORE` sobre PK UNIQUE.
* Secretos cifrados (libsodium) usando las `AUTH_KEY`s de WordPress como KDF.
* Notificaciones por email / Slack / Telegram con reglas (cola abandonada, breaker abierto, TPV inalcanzable).
* Comandos WP-CLI: `wp tpv-sync status|test-connection|reconcile|push-all|queue-*|breaker-*|export-logs`.

**Compatibilidad**

* WooCommerce 7.0+ con HPOS (High Performance Order Storage) activo.
* WordPress 6.0+.
* PHP 8.0+.
* Testeado con catálogos de hasta ~1000 productos y ~300 pedidos/día.

== Installation ==

1. Sube el directorio `woocommerce-conector` a `/wp-content/plugins/` o instala vía "Plugins → Añadir nuevo".
2. Activa el plugin desde el menú "Plugins".
3. Ve a "Catinfog Conector" en el menú principal de admin.
4. Paso 1: introduce URL y contraseña/secret del TPV.
5. Paso 2: selecciona módulos (Catálogo y/o Pedidos).
6. Paso 3: pulsa "Registrar webhook" para que el TPV pueda enviar eventos.
7. Pulsa "Importar TPV → WC" para poblar el catálogo inicial.

== Frequently Asked Questions ==

= ¿Funciona con HPOS (pedidos en tablas propias de WC)? =

Sí. El plugin detecta HPOS y persiste metas vía la API de `WC_Order` (`update_meta_data` + `save`). La compatibilidad se verifica con 200 tests e2e.

= ¿Qué pasa si el TPV está caído cuando un cliente compra? =

El pedido se crea igualmente en WooCommerce. Si el envío al TPV falla, la cola interna lo reintenta con backoff. El circuit breaker evita martillear al TPV.

= ¿Cómo se autentican los webhooks entrantes del TPV? =

El TPV firma cada webhook con HMAC-SHA256 usando un secret compartido. El plugin verifica la firma, la versión del payload y aplica idempotencia atómica (no se procesa dos veces el mismo `idempotency_key`).

= ¿Qué ocurre si el stock está desincronizado? =

El comando `wp tpv-sync reconcile [--limit=100]` compara stock TPV vs WC y corrige discrepancias usando el TPV como fuente autoritativa. Se ejecuta semanalmente por cron.

= ¿Puedo exportar logs? =

Sí: `wp tpv-sync export-logs --days=7 --status=error` genera un CSV por stdout.

== Changelog ==

= 2.0.0 - 2026-04-24 =
* Fix: race condition en idempotencia de webhooks — nueva tabla `wp_tpv_sync_webhook_idem` con PK UNIQUE + `INSERT IGNORE` atómico (antes `get_transient + set_transient` no atómicos causaban que 2-3 de 10 webhooks concurrentes procesaran el mismo `idempotency_key` como "nuevos").
* Fix: tabla de idempotencia se crea automáticamente en instalaciones existentes vía `plugins_loaded` con sentinela `tpv_sync_idem_table_v1`.
* Fix: purga automática diaria de entradas de idempotencia >48h integrada en `tpv_sync_queue_purge`.
* Maduración: `declare(strict_types=1)` en todas las clases.
* Maduración: estructura de proyecto PSR-compatible (`composer.json`, `phpstan.neon`, `phpcs.xml`, CI en GitHub Actions).

= 1.x - versiones previas =
* Desarrollo inicial del conector con cliente OAuth2, circuit breaker, queue de fallback, webhooks firmados, sincronización bidireccional de productos/stock/pedidos.

== Upgrade Notice ==

= 2.0.0 =
Arregla una race condition crítica en la idempotencia de webhooks. Recomendado actualizar. Migración automática sin downtime.

== Privacy ==

El plugin envía datos de productos, stock, pedidos y clientes al TPV configurado. Los secretos (client_secret, webhook_secret) se cifran con libsodium antes de persistirse en la tabla `wp_options`. Ningún dato sale hacia terceros que no sean el TPV configurado.
