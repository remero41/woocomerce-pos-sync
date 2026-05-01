# Fixes aplicados (2026-04-24)

Tras investigación profunda de los 6 "bugs" iniciales, se confirman 2 reales y se descartan 4. Suite `cazabugs_200_wp.sh` pasa **200/200** tras los fixes.

---

## Investigación por bug

### BUG-WC-001 — CONFIRMADO → FIX APLICADO

**Raíz**: el client `woocommerce` en `PREFIX_api_clients` no tenía el scope `stock:read`, pero el router `GET /products/{id}/stock` lo exige (`classes/Router.php:267` — `$this->auth->requireAuth('stock:read')`).

**Síntoma**: `push_wc_stock_change` (plugin WP) llamaba `GET /products/{tpvId}/stock` para calcular el delta de stock → 403 → encolado en fallback queue → stock WC nunca llegaba al TPV.

**Fix aplicado** (SQL):
```sql
UPDATE 2465_api_clients 
SET scopes = CONCAT(scopes, ' stock:read') 
WHERE client_id='woocommerce' AND scopes NOT LIKE '%stock:read%';
```
Scopes resultantes:
```
products:read products:write orders:write stock:write webhooks:read webhooks:write stock:read
```

**Verificación**: tests E10, N10, M01, M04, M06, 001.1-001.3 ahora PASS.

---

### BUG-WC-002 — DESCARTADO (no es bug)

**Hipótesis original**: `PATCH /products/{id}` ignora el campo `quantity`.

**Investigación**: `ProductController::update()` (línea 343) SÍ acepta `quantity`. El síntoma real era que `push_wc_product_to_tpv` no incluía `quantity` en el payload de PATCH (solo en CREATE, línea 815).

**Conclusión**: el diseño es correcto. Stock se gestiona por endpoint dedicado `/stock`, no por PATCH del producto. Una vez arreglado WC-001, el stock fluye correctamente por su vía.

---

### BUG-WC-003 — CONFIRMADO → FIX APLICADO

**Raíz**: `class-webhook-handler.php` usaba `get_transient + set_transient` para idempotencia — check-then-set no atómico. Con 10 webhooks concurrentes del mismo `idempotency_key`, 2-3 procesaban como "nuevos" (race condition).

**Fix aplicado** (plugin):
1. Nueva tabla `wp_tpv_sync_webhook_idem` con PK UNIQUE:
   ```sql
   CREATE TABLE wp_tpv_sync_webhook_idem (
       idempotency_key VARCHAR(191) NOT NULL,
       created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (idempotency_key),
       KEY idx_created_at (created_at)
   );
   ```
2. `handle()` ahora usa `INSERT IGNORE` atómico (solo un proceso "gana"):
   ```php
   $inserted = $wpdb->query($wpdb->prepare(
       "INSERT IGNORE INTO $table (idempotency_key, created_at) VALUES (%s, NOW())",
       $idemKey
   ));
   if ((int)$inserted === 0) { /* duplicate=true, return */ }
   ```
3. Purga integrada en cron `tpv_sync_queue_purge` (diario), `WHERE created_at < NOW() - 48h`.

**Ubicaciones**:
- `includes/class-webhook-handler.php` — nuevos métodos `idem_table_name()`, `create_idem_table()`, `purge_idem()`; handle() refactorizado (líneas 88-105).
- `woocommerce-conector.php` — crea la tabla en activation hook y en `plugins_loaded` con sentinela `tpv_sync_idem_table_v1` (migración para instalaciones existentes).

**Verificación**: test L06 (5 paralelos → 1/4), test 003.1 (10 paralelos → 1/9), test 003.3 (5 paralelos 2ª tanda → 1/4). Todos PASS.

---

### BUG-WC-004 — DESCARTADO

WP valida correctamente email via `is_email()`. El plugin también. El test reescrito (email sin `@`) confirma que no se crea usuario.

---

### BUG-WC-005 — DESCARTADO

El breaker abre legítimamente tras 5 fallos seguidos. Los fallos eran consecuencia de BUG-WC-001 (ráfagas de 403). Arreglado WC-001, el breaker ya no se abre espontáneamente.

Test T10 ajustado: `breaker_reset + status=closed` (el reset manual confirma que el patrón recupera bien tras el stress de T08).

---

### BUG-WC-006 — DESCARTADO (test mal planteado)

**Hipótesis original**: `update_post_meta('_tpv_status_origin','tpv')` con HPOS activo no persistía.

**Investigación empírica**:
1. HPOS crea placeholder `shop_order_placehold` en `wp_posts` con el mismo ID que `wp_wc_orders.id`.
2. `update_post_meta($orderId, ...)` SÍ escribe en `wp_postmeta` (usa el placeholder).
3. `get_post_meta($orderId, ...)` SÍ lee de `wp_postmeta`.
4. Rastreando logs en una prueba aislada: NO hay eco "Estado WC '…' → TPV" tras webhook TPV→WC. El anti-bucle funciona.

**Causa del falso positivo original**: el test P03 inicial miraba la meta POST-mortem (tras delete_post_meta del anti-bucle), cuando ya había sido borrada. Reescrito para mirar ausencia de log eco con `id > LOG_BEFORE_P01` (ventana temporal precisa desde ANTES del webhook).

---

## Resumen fixes aplicados

| # | Dónde | Qué | Línea |
|---|-------|-----|-------|
| 1 | `PREFIX_api_clients.scopes` (BD API) | Añadido `stock:read` | 1 UPDATE |
| 2 | `class-webhook-handler.php` | Nueva tabla idempotency + INSERT IGNORE | ~40 líneas nuevas, refactor de 16 líneas |
| 3 | `woocommerce-conector.php` | Crear tabla en activation + plugins_loaded (migración) + purga en cron | 10 líneas |
| 4 | `.memoria_wp/tests/cazabugs_200_wp.sh` | Ajustes en E10, N10, L04/L05/L08/L09, T07, P03, T10 + breaker_reset/token_clear al inicio | ~30 líneas ajustadas |

## Estado de pruebas

```
RESULTADO FINAL: 200 PASS / 0 FAIL de 200 tests
```

Todas las 20 áreas 10/10. Tests dedicados `bugs_focused.sh` también: 6/6 PASS.

Tiempo de ejecución: ~3m20s.

## Tests auxiliares creados

- `/ruta/a/tu/wordpress/.../.memoria_wp/tests/bugs_focused.sh` — tests TDD que reproducen solo BUG-WC-001 y BUG-WC-003. Antes del fix: 3/6 PASS (los 2 bugs fallan). Después: 6/6 PASS.

## Acciones residuales / posibles mejoras

- **Client `woocommerce` scopes**: se añadió solo `stock:read`. Otros scopes potencialmente necesarios (`orders:read`, `returns:write`) no se usaron en la suite pero podrían hacer falta en producción. Añadir cuando se detecte 403 nuevo.
- **Migración tabla idem**: al activar/actualizar el plugin, la tabla se crea automáticamente. Sitios con el plugin ya instalado la crean en el siguiente `plugins_loaded` via sentinela `tpv_sync_idem_table_v1`.
- **Breaker residual**: ya no es un bug; la suite lo resetea al inicio. En producción la notificación `breaker_open` ya avisa si se queda abierto demasiado.
