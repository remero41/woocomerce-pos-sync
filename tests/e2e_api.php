<?php
/**
 * Endpoint E2E extendido — análogo al AdminTpvsyncTest del plugin PrestaShop.
 * Expone múltiples acciones para lanzar una suite de 200 tests cazabugs.
 *
 * Seguridad:
 *   - Requiere define('TPV_SYNC_E2E_ENABLED', true) en wp-config.php
 *   - Requiere header X-Test-Secret = get_option('tpv_sync_e2e_trigger_secret')
 *   - Nunca responde en production (WP_ENVIRONMENT_TYPE=production)
 *
 * Uso:
 *   curl -H "X-Test-Secret: XXX" "https://tpv85.catinfog.com/wp-content/plugins/woocommerce_conector/tests/e2e_api.php?action=check_config"
 *
 * Acciones soportadas: ver switch() más abajo.
 */

declare(strict_types=1);

$wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($wpLoad)) { http_response_code(500); exit('wp-load.php no encontrado'); }
require_once $wpLoad;

header('Content-Type: application/json');

if (!defined('TPV_SYNC_E2E_ENABLED') || TPV_SYNC_E2E_ENABLED !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'E2E disabled']); exit;
}

$envType = defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : (getenv('WP_ENV') ?: 'production');
if (in_array($envType, ['production', 'prod'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'E2E not allowed in production']); exit;
}

$secret = get_option('tpv_sync_e2e_trigger_secret', '');
if (!$secret) {
    $secret = bin2hex(random_bytes(32));
    update_option('tpv_sync_e2e_trigger_secret', $secret, false);
}
$provided = $_SERVER['HTTP_X_TEST_SECRET'] ?? ($_GET['secret'] ?? '');
if (!hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'bad secret']); exit;
}

// Para que el hook deduct_stock no se ejecute en create_test_order cuando el
// módulo está en solo catálogo y no queremos descontar.
$sync = TPV_Sync::instance();
$api  = $sync->api;
$prod = $sync->products;
$ord  = $sync->orders;
$queue = $sync->queue;

$action = sanitize_key($_GET['action'] ?? '');
global $wpdb;

function ej_json($d): void { echo json_encode($d); exit; }
function ej_err(string $m, int $code = 500): void { http_response_code($code); echo json_encode(['error' => $m]); exit; }

function ej_get(string $k, $default = ''): string { return isset($_GET[$k]) ? (string)$_GET[$k] : (string)$default; }
function ej_int(string $k, int $default = 0): int { return isset($_GET[$k]) ? (int)$_GET[$k] : $default; }
function ej_unwrap(array $r): array {
    $b = $r['body'] ?? $r;
    if (isset($b['data']) && is_array($b['data'])) $b = $b['data'];
    return $b;
}

try {
    switch ($action) {

    // ─── Config / introspección ─────────────────────────────────────────
    case 'check_config': {
        $api_url = get_option('tpv_sync_api_url', '');
        $client_id = get_option('tpv_sync_client_id', '');
        $cs = get_option('tpv_sync_client_secret', '');
        $ws = get_option('tpv_sync_webhook_secret', '');
        ej_json([
            'api_url' => $api_url,
            'client_id' => $client_id,
            'has_secret' => $cs !== '' && $cs !== false,
            'has_webhook_secret' => $ws !== '' && $ws !== false,
            'module_catalog' => (int)get_option('tpv_sync_module_catalog', 0),
            'module_orders' => (int)get_option('tpv_sync_module_orders', 0),
            'webhook_id' => (string)get_option('tpv_sync_webhook_id', ''),
            'e2e' => true,
        ]);
    }

    case 'raw_sql': {
        $sql = (string)($_GET['sql'] ?? '');
        if (!preg_match('/^\s*SELECT\s+/i', $sql)) ej_json(['error' => 'SELECT only']);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) ej_json(['error' => $wpdb->last_error]);
        ej_json(['rows' => $rows ?: []]);
    }

    case 'count_products_mapped': {
        $c = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_tpv_product_id' AND meta_value>0");
        ej_json(['mapped' => $c]);
    }

    // ─── API client / health / auth ─────────────────────────────────────
    case 'health': {
        try { $r = $api->get('/health'); ej_json(['ok' => true, 'body' => $r]); }
        catch (Throwable $e) { ej_json(['ok' => false, 'error' => $e->getMessage()]); }
    }

    case 'token_clear': {
        delete_transient('tpv_sync_token');
        ej_json(['cleared' => true]);
    }

    case 'token_probe': {
        // Hace un GET /health forzando la regeneración si acabamos de limpiar
        try { $r = $api->get('/health'); ej_json(['ok' => true]); }
        catch (Throwable $e) { ej_json(['ok' => false, 'error' => $e->getMessage()]); }
    }

    // ─── Circuit breaker ────────────────────────────────────────────────
    case 'breaker_status': {
        $b = $api->breaker();
        if (!$b) ej_json(['state' => 'disabled']);
        ej_json($b->stats());
    }

    case 'breaker_reset': {
        $b = $api->breaker();
        if ($b) $b->reset();
        ej_json(['ok' => true]);
    }

    case 'breaker_force_open': {
        $b = $api->breaker();
        if (!$b) ej_json(['error' => 'no breaker']);
        // Registrar fallos hasta abrir
        for ($i = 0; $i < 10; $i++) $b->recordFailure();
        ej_json($b->stats());
    }

    // ─── Productos ─────────────────────────────────────────────────────
    case 'create_simple_product': {
        $name = (string)($_GET['name'] ?? 'E2E Product');
        $price = (float)($_GET['price'] ?? 10);
        $qty = (int)($_GET['qty'] ?? 5);
        $sku = (string)($_GET['sku'] ?? '');
        if ($sku === '') $sku = 'e2e-' . substr(md5(uniqid('', true)), 0, 10);
        $gtin = (string)($_GET['gtin'] ?? '');

        $post_id = wp_insert_post([
            'post_title' => wp_strip_all_tags($name),
            'post_content' => 'Creado por E2E',
            'post_status' => 'publish',
            'post_type' => 'product',
        ]);
        if (!$post_id || is_wp_error($post_id)) ej_json(['error' => 'wp_insert_post fail']);
        wp_set_object_terms($post_id, 'simple', 'product_type');
        update_post_meta($post_id, '_sku', $sku);
        if ($gtin !== '') update_post_meta($post_id, '_global_unique_id', $gtin);
        update_post_meta($post_id, '_price', $price);
        update_post_meta($post_id, '_regular_price', $price);
        update_post_meta($post_id, '_manage_stock', 'yes');
        update_post_meta($post_id, '_stock', $qty);
        update_post_meta($post_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock');
        update_post_meta($post_id, '_visibility', 'visible');
        update_post_meta($post_id, '_virtual', 'no');

        // Invalidar caché para que wc_get_product() dentro del push lea los
        // metas que acabamos de escribir directamente (precio, stock, sku…).
        clean_post_cache($post_id);
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($post_id);
        }

        // Push WC→TPV
        $pushed = $prod->push_wc_product_to_tpv($post_id);
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        ej_json(['post_id' => $post_id, 'tpv_id' => $tpv_id, 'pushed' => $pushed, 'sku' => $sku]);
    }

    case 'verify_tpv': {
        $tpv_id = ej_int('tpv_id');
        if (!$tpv_id) ej_json(['error' => 'tpv_id required']);
        try {
            $r = $api->get('/products/' . $tpv_id);
            $body = ej_unwrap($r);
            ej_json([
                'name' => $body['name'] ?? null,
                'model' => $body['model'] ?? null,
                'price' => $body['price'] ?? null,
                'quantity' => $body['quantity'] ?? null,
                'status' => $body['status'] ?? null,
                'raw' => $body,
            ]);
        } catch (Throwable $e) {
            ej_json(['error' => $e->getMessage()]);
        }
    }

    case 'product_info': {
        $post_id = ej_int('post_id');
        if (!$post_id) ej_json(['error' => 'post_id required']);
        ej_json([
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'sku' => get_post_meta($post_id, '_sku', true),
            'gtin' => get_post_meta($post_id, '_global_unique_id', true),
            'price' => (float)get_post_meta($post_id, '_price', true),
            'stock' => (float)get_post_meta($post_id, '_stock', true),
            'status' => get_post_status($post_id),
            'tpv_id' => (int)get_post_meta($post_id, '_tpv_product_id', true),
        ]);
    }

    case 'update_price': {
        $post_id = ej_int('post_id');
        $price = (float)($_GET['price'] ?? 0);
        update_post_meta($post_id, '_price', $price);
        update_post_meta($post_id, '_regular_price', $price);
        // Invalidar la caché de objeto de WC: get_regular_price() lee de un
        // WC_Product hidratado en el primer wc_get_product() del request, así
        // que update_post_meta directo NO se refleja a menos que limpiemos.
        clean_post_cache($post_id);
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($post_id);
        }
        $prod->push_wc_product_to_tpv($post_id);
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        try { $r = $api->get('/products/' . $tpv_id); $b = ej_unwrap($r); }
        catch (Throwable $e) { ej_json(['error' => $e->getMessage()]); }
        ej_json(['tpv_id' => $tpv_id, 'tpv_price' => isset($b['price']) ? (float)$b['price'] : null]);
    }

    case 'update_stock': {
        $post_id = ej_int('post_id');
        $qty = (int)($_GET['qty'] ?? 0);
        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        if ($product) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($qty);
            $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            $product->save();
        } else {
            update_post_meta($post_id, '_stock', $qty);
            update_post_meta($post_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock');
            clean_post_cache($post_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($post_id);
            }
            $prod->push_wc_product_to_tpv($post_id);
        }
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        // stock real vive en /products/{id}/stock
        $qty_seen = null;
        try {
            $r2 = $api->get('/products/' . $tpv_id . '/stock');
            $b2 = $r2['body'] ?? $r2;
            if (isset($b2['data']) && is_array($b2['data'])) $b2 = $b2['data'];
            if (isset($b2['total']['quantity'])) $qty_seen = (float)$b2['total']['quantity'];
            elseif (isset($b2['quantity'])) $qty_seen = (float)$b2['quantity'];
        } catch (Throwable $e) { /* fall through */ }
        if ($qty_seen === null) {
            try { $r = $api->get('/products/' . $tpv_id); $b = ej_unwrap($r); $qty_seen = isset($b['quantity']) ? (float)$b['quantity'] : null; }
            catch (Throwable $e) { ej_json(['error' => $e->getMessage()]); }
        }
        ej_json(['tpv_id' => $tpv_id, 'tpv_qty' => $qty_seen]);
    }

    case 'trash_product': {
        $post_id = ej_int('post_id');
        wp_trash_post($post_id);
        // push_wc_trash_to_tpv se engancha a wp_trash_post automáticamente
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        try { $r = $api->get('/products/' . $tpv_id); $b = ej_unwrap($r); }
        catch (Throwable $e) { ej_json(['error' => $e->getMessage()]); }
        ej_json(['tpv_id' => $tpv_id, 'tpv_status' => isset($b['status']) ? (int)$b['status'] : null]);
    }

    case 'untrash_product': {
        $post_id = ej_int('post_id');
        wp_untrash_post($post_id);
        // Tras untrash, WP deja el post en su estado anterior pero el hook
        // push_wc_untrash_to_tpv puede no haber rotado el `post_status` a
        // 'publish' a tiempo para que el push lea el valor correcto.
        // Forzamos invalidación de caché y un push explícito para garantizar
        // que el TPV vea status=1.
        clean_post_cache($post_id);
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($post_id);
        }
        $prod->push_wc_product_to_tpv($post_id);
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        try { $r = $api->get('/products/' . $tpv_id); $b = ej_unwrap($r); }
        catch (Throwable $e) { ej_json(['error' => $e->getMessage()]); }
        ej_json(['tpv_id' => $tpv_id, 'tpv_status' => isset($b['status']) ? (int)$b['status'] : null]);
    }

    case 'delete_product': {
        $post_id = ej_int('post_id');
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        wp_delete_post($post_id, true);
        if ($tpv_id > 0) {
            try { $r = $api->get('/products/' . $tpv_id); $b = ej_unwrap($r); ej_json(['tpv_status' => isset($b['status']) ? (int)$b['status'] : null, 'tpv_id' => $tpv_id]); }
            catch (Throwable $e) { ej_json(['tpv_id' => $tpv_id, 'not_found' => true]); }
        }
        ej_json(['deleted' => true]);
    }

    case 'force_push': {
        $post_id = ej_int('post_id');
        $prod->push_wc_product_to_tpv($post_id);
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        ej_json(['tpv_id' => $tpv_id]);
    }

    case 'delete_mapping': {
        $post_id = ej_int('post_id');
        delete_post_meta($post_id, '_tpv_product_id');
        ej_json(['ok' => true]);
    }

    case 'import_from_tpv': {
        $r = $prod->import_all();
        ej_json(['stats' => $r]);
    }

    case 'reconcile': {
        $limit = ej_int('limit', 20);
        $r = $prod->reconcile($limit);
        ej_json(['stats' => $r]);
    }

    case 'update_stock_from_tpv': {
        $tpv_id = ej_int('tpv_id');
        $qty = (float)($_GET['qty'] ?? 0);
        $prod->update_stock($tpv_id, $qty);
        $post_id = $prod->find_wc_post($tpv_id);
        $wcqty = (float)get_post_meta($post_id, '_stock', true);
        ej_json(['post_id' => $post_id, 'wc_stock' => $wcqty]);
    }

    // ─── Webhooks firmados ──────────────────────────────────────────────
    case 'send_webhook': {
        $event = (string)($_GET['event_type'] ?? 'stock.adjusted');
        $res_id = ej_int('resource_id');
        $qty = (float)($_GET['qty'] ?? 0);
        $idem = (string)($_GET['idem'] ?? ('e2e-' . uniqid('', true)));
        $ts = (string)($_GET['ts'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $bad_sig = ej_int('bad_sig') === 1;
        $version = (string)($_GET['version'] ?? '1');
        $raw_body = $_GET['raw'] ?? null;

        $payload = [
            'event_type' => $event,
            'resource_id' => $res_id,
            'changed_fields' => ['product_id' => $res_id, 'quantity' => $qty],
            'timestamp' => $ts,
            'idempotency_key' => $idem,
        ];
        if ($event === 'variant.stock_adjusted') {
            $payload['changed_fields'] = ['product_option_value_id' => $res_id, 'quantity' => $qty];
        }
        if ($event === 'order.status_changed') {
            $payload['changed_fields'] = ['order_status_id' => (int)($_GET['status_id'] ?? 5)];
        }
        if ($event === 'return.created') {
            $payload['changed_fields'] = [
                'order_id' => ej_int('order_id'),
                'return_id' => ej_int('return_id', 999),
                'quantity' => (int)$qty,
                'product_id' => ej_int('product_id'),
            ];
        }
        if ($event === 'customer.updated' || $event === 'customer.created') {
            $payload['changed_fields'] = [
                'email' => (string)($_GET['email'] ?? 'e2e+' . $res_id . '@mitia.test'),
                'firstname' => 'E2E', 'lastname' => 'User',
                'telephone' => '', 'vat_number' => '',
            ];
        }
        if ($event === 'category.updated' || $event === 'category.created') {
            $payload['changed_fields'] = ['name' => (string)($_GET['name'] ?? 'E2E-Cat')];
        }

        $body = $raw_body !== null ? (string)$raw_body : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $wsec = get_option('tpv_sync_webhook_secret', '');
        $sig = $bad_sig ? 'sha256=FAKE' : 'sha256=' . hash_hmac('sha256', $body, (string)$wsec);

        $resp = wp_remote_post(home_url('/tpv-webhook/'), [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $sig,
                'X-Webhook-Version' => $version,
            ],
            'body' => $body,
        ]);
        if (is_wp_error($resp)) ej_json(['error' => $resp->get_error_message()]);
        $http = wp_remote_retrieve_response_code($resp);
        $rbody = wp_remote_retrieve_body($resp);
        $j = json_decode($rbody, true);
        ej_json(['http_code' => $http, 'body' => $j !== null ? $j : $rbody]);
    }

    // ─── Queue ──────────────────────────────────────────────────────────
    case 'queue_enqueue': {
        $op = (string)($_GET['op'] ?? 'stock.push');
        $payload_json = (string)($_GET['payload'] ?? '{"tpv_product_id":0,"delta":0}');
        $payload = json_decode($payload_json, true) ?: [];
        $id = $queue->enqueue($op, $payload, 'e2e');
        ej_json(['id' => $id]);
    }

    case 'queue_list': {
        $table = $wpdb->prefix . 'tpv_sync_queue';
        $rows = $wpdb->get_results("SELECT id, operation, status, attempts, next_retry_at, last_error FROM $table ORDER BY id DESC LIMIT 50", ARRAY_A) ?: [];
        ej_json(['queue' => $rows]);
    }

    case 'queue_process': {
        $batch = ej_int('batch', 50);
        $r = $queue->process($batch);
        ej_json(['stats' => $r]);
    }

    case 'queue_stats': { ej_json($queue->stats()); }

    case 'queue_retry': { $id = ej_int('id'); ej_json(['ok' => $queue->retry($id)]); }

    case 'queue_purge': {
        $days = ej_int('days', 30);
        $deleted = $queue->purge($days);
        ej_json(['deleted' => $deleted]);
    }

    case 'queue_truncate': {
        $table = $wpdb->prefix . 'tpv_sync_queue';
        $wpdb->query("TRUNCATE TABLE $table");
        ej_json(['ok' => true]);
    }

    // ─── Orders ────────────────────────────────────────────────────────
    case 'create_test_order': {
        if (!function_exists('wc_create_order')) ej_json(['error' => 'WC not loaded']);
        $post_id = ej_int('post_id');
        $qty = (int)($_GET['qty'] ?? 1);

        $order = wc_create_order();
        $product = wc_get_product($post_id);
        if (!$product) ej_json(['error' => 'product not found']);
        $order->add_product($product, $qty);
        $order->set_address(['first_name' => 'E2E', 'last_name' => 'Test', 'email' => 'e2e@mitia.test', 'country' => 'ES'], 'billing');
        $order->set_address(['first_name' => 'E2E', 'last_name' => 'Test', 'country' => 'ES'], 'shipping');
        $order->calculate_totals();
        $order->update_status('processing', 'E2E test order');
        $wc_id = $order->get_id();

        $ord->send_to_tpv($wc_id);
        $tpv_id = (int)get_post_meta($wc_id, '_tpv_order_id', true);
        if (!$tpv_id) { $tpv_id = (int)$order->get_meta('_tpv_order_id'); }
        ej_json(['wc_id' => $wc_id, 'tpv_id' => $tpv_id]);
    }

    case 'change_order_status': {
        $wc_id = ej_int('wc_id');
        $to = (string)($_GET['to'] ?? 'completed');
        $order = wc_get_order($wc_id);
        if (!$order) ej_json(['error' => 'order not found']);
        $order->update_status($to, 'E2E test');
        ej_json(['ok' => true, 'status' => $order->get_status()]);
    }

    case 'refund_order': {
        $wc_id = ej_int('wc_id');
        $amount = (float)($_GET['amount'] ?? 1);
        $order = wc_get_order($wc_id);
        if (!$order) ej_json(['error' => 'order not found']);
        $refund = wc_create_refund([
            'order_id' => $wc_id,
            'amount' => $amount,
            'reason' => 'E2E refund',
        ]);
        if (is_wp_error($refund)) ej_json(['error' => $refund->get_error_message()]);
        // Disparar hook
        do_action('woocommerce_order_refunded', $wc_id, $refund->get_id());
        ej_json(['ok' => true, 'refund_id' => $refund->get_id()]);
    }

    case 'push_order_again': {
        $wc_id = ej_int('wc_id');
        $ord->send_to_tpv($wc_id);
        $tpv_id = (int)get_post_meta($wc_id, '_tpv_order_id', true);
        if (!$tpv_id) { $o = wc_get_order($wc_id); if ($o) $tpv_id = (int)$o->get_meta('_tpv_order_id'); }
        ej_json(['tpv_id' => $tpv_id]);
    }

    // ─── Logs ──────────────────────────────────────────────────────────
    case 'logs_tail': {
        $table = $wpdb->prefix . 'tpv_sync_log';
        $limit = ej_int('limit', 20);
        $rows = $wpdb->get_results("SELECT id, event_type, resource, resource_id, status, message, created_at FROM $table ORDER BY id DESC LIMIT $limit", ARRAY_A) ?: [];
        ej_json(['logs' => $rows]);
    }

    case 'logs_since_id': {
        $since = ej_int('since', 0);
        $like = (string)($_GET['like'] ?? '');
        $status = (string)($_GET['status'] ?? '');
        $table = $wpdb->prefix . 'tpv_sync_log';
        $sql = "SELECT COUNT(*) FROM $table WHERE id > %d";
        $args = [$since];
        if ($like !== '') { $sql .= " AND message LIKE %s"; $args[] = '%' . $like . '%'; }
        if ($status !== '') { $sql .= " AND status = %s"; $args[] = $status; }
        $c = (int)$wpdb->get_var($wpdb->prepare($sql, ...$args));
        ej_json(['count' => $c]);
    }

    case 'logs_max_id': {
        $table = $wpdb->prefix . 'tpv_sync_log';
        $max = (int)$wpdb->get_var("SELECT IFNULL(MAX(id),0) FROM $table");
        ej_json(['max' => $max]);
    }

    // ─── Meta helpers ──────────────────────────────────────────────────
    case 'set_post_meta': {
        $post_id = ej_int('post_id');
        $key = (string)($_GET['key'] ?? '');
        $val = $_GET['val'] ?? '';
        update_post_meta($post_id, $key, $val);
        ej_json(['ok' => true]);
    }

    case 'get_post_meta': {
        $post_id = ej_int('post_id');
        $key = (string)($_GET['key'] ?? '');
        ej_json(['val' => get_post_meta($post_id, $key, true)]);
    }

    case 'delete_post_meta': {
        $post_id = ej_int('post_id');
        $key = (string)($_GET['key'] ?? '');
        delete_post_meta($post_id, $key);
        ej_json(['ok' => true]);
    }

    // ─── Variantes ─────────────────────────────────────────────────────
    case 'create_variable_product': {
        $name = (string)($_GET['name'] ?? 'E2E Variable');
        $price = (float)($_GET['price'] ?? 20);
        $sku = 'e2ev-' . substr(md5(uniqid('', true)), 0, 10);

        $post_id = wp_insert_post([
            'post_title' => wp_strip_all_tags($name),
            'post_status' => 'publish',
            'post_type' => 'product',
        ]);
        wp_set_object_terms($post_id, 'variable', 'product_type');
        update_post_meta($post_id, '_sku', $sku);
        update_post_meta($post_id, '_price', $price);
        update_post_meta($post_id, '_regular_price', $price);
        update_post_meta($post_id, '_manage_stock', 'no');

        // Crear 3 variaciones con una taxonomía global pa_e2etalla
        $tax = 'pa_e2etalla';
        if (!taxonomy_exists($tax)) {
            $exists = $wpdb->get_var("SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name='e2etalla'");
            if (!$exists) {
                $wpdb->insert($wpdb->prefix . 'woocommerce_attribute_taxonomies', [
                    'attribute_name' => 'e2etalla', 'attribute_label' => 'E2ETalla',
                    'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0,
                ]);
            }
            register_taxonomy($tax, 'product', ['hierarchical' => false]);
        }
        $values = ['S', 'M', 'L'];
        foreach ($values as $v) {
            if (!term_exists($v, $tax)) wp_insert_term($v, $tax);
        }
        wp_set_object_terms($post_id, $values, $tax);
        update_post_meta($post_id, '_product_attributes', [
            'e2etalla' => ['name' => $tax, 'value' => '', 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 1],
        ]);

        $var_ids = [];
        foreach ($values as $i => $v) {
            $vid = wp_insert_post([
                'post_title' => "$name - $v",
                'post_status' => 'publish',
                'post_type' => 'product_variation',
                'post_parent' => $post_id,
                'menu_order' => $i,
            ]);
            update_post_meta($vid, 'attribute_' . $tax, strtolower($v));
            update_post_meta($vid, '_price', $price);
            update_post_meta($vid, '_regular_price', $price);
            update_post_meta($vid, '_manage_stock', 'yes');
            update_post_meta($vid, '_stock', 5);
            update_post_meta($vid, '_stock_status', 'instock');
            $var_ids[] = $vid;
        }

        $prod->push_wc_product_to_tpv($post_id);
        $tpv_id = (int)get_post_meta($post_id, '_tpv_product_id', true);
        ej_json(['post_id' => $post_id, 'tpv_id' => $tpv_id, 'variations' => $var_ids]);
    }

    // ─── Reset helpers de tests ────────────────────────────────────────
    case 'reset_ordering_guard': {
        $tpv_id = ej_int('tpv_id');
        $scope = (string)($_GET['scope'] ?? 'product');
        delete_option('tpv_sync_last_stock_' . $scope . '_' . $tpv_id);
        ej_json(['ok' => true]);
    }

    case 'clear_webhook_idem': {
        global $wpdb;
        // Borrar transients (legacy) y tabla atómica
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tpv_sync_idem_%' OR option_name LIKE '_transient_timeout_tpv_sync_idem_%'");
        $t = $wpdb->prefix . 'tpv_sync_webhook_idem';
        $wpdb->query("TRUNCATE TABLE $t");
        ej_json(['ok' => true]);
    }

    case 'flush_rewrite': {
        flush_rewrite_rules(false);
        $r = (array)get_option('rewrite_rules', []);
        $has = false;
        foreach ($r as $k => $v) { if (strpos((string)$k, 'tpv-webhook') !== false) { $has = true; break; } }
        ej_json(['flushed' => true, 'has_tpv_rule' => $has, 'rules_count' => count($r)]);
    }

    case 'cleanup_e2e': {
        // Borra productos/ órdenes de test (cualquier SKU 'e2e-*' o titulo 'E2E*')
        $post_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE (post_type IN ('product','product_variation','shop_order','shop_order_refund')) AND (post_title LIKE 'E2E%' OR post_title LIKE 'e2e%' OR ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND (meta_value LIKE 'e2e-%' OR meta_value LIKE 'e2ev-%')))");
        $n = 0;
        foreach ($post_ids as $id) { wp_delete_post((int)$id, true); $n++; }
        ej_json(['deleted' => $n]);
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action', 'action' => $action]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
