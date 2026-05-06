# Guía del desarrollador — Plugin TPV Sync

Cómo extender el plugin TPV Sync para WordPress/WooCommerce. Esta guía es para desarrolladores que quieren:

- Añadir lógica custom cuando llegan webhooks del TPV
- Modificar el payload antes de enviar al TPV
- Filtrar qué productos/pedidos se sincronizan
- Añadir nuevos tipos de evento

**Si solo quieres instalar/usar el plugin**, ver [WOOCOMMERCE.md](../../../tpv/public_html/api/v1/docs/WOOCOMMERCE.md).

---

## Arquitectura del plugin

```
tpv-sync/
├── tpv-sync.php                  # Entry point — hooks WC, init
├── includes/
│   ├── class-api-client.php      # HTTP client hacia API TPV (OAuth2, JWT)
│   ├── class-secrets.php         # Cifrado libsodium de secrets en options
│   ├── class-webhook-handler.php # Recibe webhooks del TPV, despacha a sync classes
│   ├── class-product-sync.php    # Productos: TPV→WC y WC→TPV
│   ├── class-order-sync.php      # Pedidos: WC→TPV + refunds
│   ├── class-queue.php           # Cola local para reintentos
│   ├── class-circuit-breaker.php # Hystrix pattern si TPV no responde
│   ├── class-admin.php           # UI admin (Settings, Queue, Logs)
│   ├── class-cli.php             # Comandos WP-CLI
│   └── class-notifications.php   # Alerts al admin
└── tests/
    ├── run_tests.php             # Tests con stubs WP
    └── wp-stubs.php              # Funciones WP stubbeadas
```

**Flujo TPV → WC**: `TPV_Sync_Webhook::handle()` recibe POST, verifica firma HMAC, deduplica por idempotency_key, y despacha al método `update_stock / update_from_tpv / delete_product`.

**Flujo WC → TPV**: hooks de WooCommerce (`woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_order_status_processing`, etc.) llaman `push_wc_*_to_tpv` que usa `TPV_Sync_API_Client` para hacer PATCH/POST al TPV.

---

## 1. Añadir lógica al recibir un webhook

Cuando el TPV te envía un webhook `product.updated`, `stock.adjusted`, etc., el plugin lo procesa automáticamente. Para ejecutar código custom además:

```php
// En functions.php del tema o tu plugin custom
add_action('tpv_sync_after_webhook', function($payload, $eventType) {
    if ($eventType === 'stock.adjusted') {
        $tpvId  = (int)$payload['resource_id'];
        $newQty = (float)($payload['changed_fields']['quantity'] ?? 0);

        // Ejemplo: notificar Slack si stock llega a 0
        if ($newQty === 0.0) {
            wp_remote_post('https://hooks.slack.com/...', [
                'body' => json_encode(['text' => "Producto TPV $tpvId agotado"])
            ]);
        }
    }
}, 10, 2);
```

**Acciones disponibles**:

| Hook | Params | Cuándo |
|---|---|---|
| `tpv_sync_before_webhook` | `$payload`, `$eventType` | Antes de procesar |
| `tpv_sync_after_webhook` | `$payload`, `$eventType` | Tras procesar OK |
| `tpv_sync_webhook_failed` | `$payload`, `$error` | Si el procesado lanzó excepción |

---

## 2. Filtrar qué productos se sincronizan hacia el TPV

Por defecto, cualquier edit en WC (title, price, stock) se manda al TPV. Si quieres excluir productos (ej: solo sincronizar los de cierta categoría):

```php
add_filter('tpv_sync_should_push_product', function($should, $postId) {
    $cats = wp_get_post_terms($postId, 'product_cat', ['fields' => 'slugs']);
    // Solo sincronizar productos en categoría 'for-tpv'
    return in_array('for-tpv', $cats, true);
}, 10, 2);
```

---

## 3. Modificar el payload antes de enviar al TPV

Añadir campos custom al payload de `POST /products` cuando WC crea uno nuevo:

```php
add_filter('tpv_sync_product_payload', function($payload, $wcProduct) {
    // Añadir custom field
    $payload['manufacturer_id'] = (int)get_post_meta($wcProduct->get_id(), '_fabricante_tpv', true);
    $payload['tag']             = implode(',', wp_get_post_terms($wcProduct->get_id(), 'product_tag', ['fields' => 'names']));
    return $payload;
}, 10, 2);
```

---

## 4. Añadir comando WP-CLI custom

Extender `TPV_Sync_CLI` para tareas operativas:

```php
// En includes/class-cli-custom.php
if (defined('WP_CLI') && WP_CLI) {
    class TPV_Custom_CLI {
        /**
         * Resincroniza todos los productos de una categoría.
         *
         * ## OPTIONS
         * <category>
         * : slug o id de categoría
         *
         * ## EXAMPLES
         *     wp tpv-sync resync-category alimentacion
         */
        public function resync_category($args) {
            $term = get_term_by(is_numeric($args[0]) ? 'id' : 'slug', $args[0], 'product_cat');
            if (!$term) { WP_CLI::error('Categoría no encontrada'); }

            $q = new WP_Query([
                'post_type' => 'product',
                'tax_query' => [['taxonomy' => 'product_cat', 'terms' => $term->term_id]],
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);

            $ps = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
            $done = 0;
            foreach ($q->posts as $postId) {
                $ps->push_wc_product_to_tpv($postId);
                $done++;
            }
            WP_CLI::success("Resincronizados $done productos de '{$term->name}'");
        }
    }
    WP_CLI::add_command('tpv-custom', 'TPV_Custom_CLI');
}
```

Ejecutar: `wp tpv-custom resync-category alimentacion`.

---

## 5. Añadir nuevo tipo de evento

Si el TPV empieza a emitir un nuevo evento (p.ej. `seller.clocked_in`), el plugin lo ignorará por defecto. Para manejarlo:

1. **Registrar el handler** en `class-webhook-handler.php` o vía hook externo:

   ```php
   add_action('tpv_sync_webhook_dispatch', function($payload, $eventType) {
       if ($eventType === 'seller.clocked_in') {
           $sellerId = (int)$payload['resource_id'];
           // Guardar en custom table, notificar, etc.
           do_action('mi_plugin_seller_started_shift', $sellerId);
       }
   }, 10, 2);
   ```

2. **Suscribirse** al evento desde WP (al registrar el webhook añadir este tipo):

   ```bash
   curl -X PATCH "https://tpv.cliente.com/api/v1/webhooks/<webhook_id>" \
     -H "Authorization: Bearer $TOKEN" \
     -d '{"events": ["order.created", "stock.adjusted", "seller.clocked_in"]}'
   ```

---

## 6. Reintentos desde código

Si tu lógica custom falla transitoriamente, aprovechá la cola local:

```php
$queue = new TPV_Sync_Queue(
    new TPV_Sync_API_Client(),
    new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()),
    new TPV_Sync_Order_Sync(new TPV_Sync_API_Client())
);

// Encolar una operación custom
$queue->enqueue('custom.my_op', [
    'product_id' => 42,
    'extra_data' => 'foo',
]);
```

Luego agregar el handler en `class-queue.php::execute()` para procesar `custom.my_op`.

---

## 7. Verificar la firma HMAC desde tu endpoint custom

Si creas tu propio receptor de webhooks (fuera del `/tpv-webhook/` default):

```php
$raw       = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$secret    = TPV_Sync_Secrets::decrypt(get_option('tpv_sync_webhook_secret', ''));

$expected  = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if (!hash_equals($expected, $signature)) {
    status_header(401);
    exit('Invalid signature');
}

// Verificar versión
$version = $_SERVER['HTTP_X_WEBHOOK_VERSION'] ?? '1';
if (!in_array($version, ['1'], true)) {
    status_header(426); // Upgrade Required
    exit;
}

$payload = json_decode($raw, true);
// ... tu lógica
```

**Timing-safe con `hash_equals`**, nunca `==`.

---

## 8. Debugging

### Logs del plugin

```bash
# Los errores de procesamiento se loguean en la tabla wp_tpv_sync_log
wp db query "SELECT * FROM wp_tpv_sync_log ORDER BY id DESC LIMIT 20"

# Si usas WP_DEBUG_LOG, hay logs adicionales en wp-content/debug.log
tail -f wp-content/debug.log | grep -i tpv
```

### Forzar procesamiento de webhook manualmente

```bash
# Usando el endpoint de test (solo en dev con TPV_SYNC_E2E_ENABLED=true)
curl -X GET "https://tu-wp.com/wp-content/plugins/tpv-sync/tests/e2e_trigger.php?op=update&post_id=42" \
  -H "X-Test-Secret: <secret-from-wp_options>"
```

### Circuit breaker status

```php
$cb = new TPV_Sync_Circuit_Breaker('api');
echo $cb->getState(); // CLOSED / OPEN / HALF_OPEN
```

Si está `OPEN`, el plugin no llama al TPV durante 60s (evita saturar si el TPV está caído).

---

## 9. Tests

Ejecutar los tests locales:

```bash
cd wp-content/plugins/tpv-sync
php tests/run_tests.php          # 30 tests unitarios (mocks WP)
php tests/run_cazabugs.php       # 90 cazabugs
php tests/run_cazabugs_30.php    # 30 cazabugs adicionales
```

**Escribir tests nuevos**: usar `wp-stubs.php` que simula funciones WP sin cargar WordPress real. Ver ejemplos existentes.

---

## 10. Recursos

- Manual API TPV: `/api/v1/docs/API.md`
- Eventos disponibles: `/api/v1/docs/API.md#181-eventos-disponibles`
- OpenAPI spec: `/api/v1/openapi.yaml`
- Hooks WC utilizados: ver `tpv-sync.php` líneas 85-105

Para reportar bugs o proponer features: [abrir issue en el repo del plugin].
