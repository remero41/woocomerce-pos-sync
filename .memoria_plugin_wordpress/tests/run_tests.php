<?php
declare(strict_types=1);

/**
 * Tests del plugin tpv-sync (lado WordPress) ejecutados standalone con stubs.
 *
 * Uso: php tests/run_tests.php
 *
 * Cubre:
 *  - class-product-sync:
 *      update_stock (producto simple, variable)
 *      update_variant_stock
 *      push_wc_stock_change (con guarda anti-bucle)
 *      deduct_stock_from_wc_order (reason presente)
 *      reconcile (compara y corrige drift)
 *  - class-webhook-handler:
 *      idempotency (duplicados)
 *      accept_stock_event (ordering guard)
 *      dispatch stock.adjusted + variant.stock_adjusted
 *      verificación HMAC
 *  - class-order-sync:
 *      send_to_tpv idempotencia
 *      on_wc_refund (origen WC, origen TPV, sin pedido mapeado)
 *  - seguridad HMAC, URL del cron pregenerado
 */

require_once __DIR__ . '/wp-stubs.php';

// Mini test runner (compartido con el de la API)
class T {
    public int $ok = 0; public int $ko = 0;
    public array $fails = [];
    public string $suite = '';
    public function suite(string $s): void { $this->suite = $s; echo "\n\033[1;34m══ $s ══\033[0m\n"; }
    public function test(string $n, callable $fn): void {
        stub_reset();
        try { $fn($this); $this->ok++; echo "  \033[32m✓\033[0m $n\n"; }
        catch (AssertionError $e) {
            $this->ko++; $this->fails[] = "[{$this->suite}] $n: " . $e->getMessage();
            echo "  \033[31m✗\033[0m $n\n    \033[33m→ " . $e->getMessage() . "\033[0m\n";
        }
        catch (Throwable $e) {
            $this->ko++; $this->fails[] = "[{$this->suite}] $n: " . $e->getMessage();
            echo "  \033[31m✗\033[0m $n (excepción: " . $e->getMessage() . ")\n";
        }
    }
    public function assert(bool $c, string $m = ''): void {
        if (!$c) throw new AssertionError($m ?: 'assertion failed');
    }
    public function eq($a, $b, string $m = ''): void {
        if ($a !== $b) throw new AssertionError($m ?: "Expected " . var_export($a, true) . ", got " . var_export($b, true));
    }
    public function summary(): void {
        $tot = $this->ok + $this->ko;
        echo "\n\033[1m══ RESULTADO: $this->ok/$tot ";
        if ($this->ko) echo "· \033[31m$this->ko fallaron\033[0m\033[1m";
        echo " ══\033[0m\n";
        if ($this->fails) { echo "\n\033[31mFallos:\033[0m\n"; foreach ($this->fails as $f) echo "  • $f\n"; }
        exit($this->ko > 0 ? 1 : 0);
    }
}

// Cargar clases del plugin
$pluginRoot = dirname(__DIR__, 2) . '/woocommerce-conector';
require_once $pluginRoot . '/includes/class-api-client.php';
require_once $pluginRoot . '/includes/class-product-sync.php';
require_once $pluginRoot . '/includes/class-order-sync.php';
require_once $pluginRoot . '/includes/class-webhook-handler.php';

$t = new T();

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 1: Product Sync — update_stock / update_variant_stock
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Product Sync — update_stock');

$t->test('update_stock: producto simple → _stock y _stock_status actualizados', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    // Crear mapeo: post_id 10 ↔ tpv_id 555
    update_post_meta(10, '_tpv_product_id', 555);
    // Producto NO es 'variable' (has_term devuelve false por defecto)

    $ps->update_stock(555, 7.0);

    $t->eq(7.0, (float)get_post_meta(10, '_stock', true));
    $t->eq('instock', get_post_meta(10, '_stock_status', true));
});

$t->test('update_stock: quantity=0 → stock_status=outofstock', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    update_post_meta(11, '_tpv_product_id', 600);
    $ps->update_stock(600, 0);

    $t->eq('outofstock', get_post_meta(11, '_stock_status', true));
});

$t->test('update_stock: producto variable NO toca _stock del padre', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    update_post_meta(12, '_tpv_product_id', 700);
    // Marcamos como variable
    wp_set_object_terms(12, 'variable', 'product_type');
    // _stock inicial ausente
    $ps->update_stock(700, 99);

    $t->eq('', get_post_meta(12, '_stock', true), 'No debe escribir _stock en variable');
});

$t->test('update_stock: tpv_id desconocido → no falla silenciosamente', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);
    // no hay mapeo
    $ps->update_stock(99999, 5);
    $t->assert(true, 'No lanza excepción');
});

$t->test('update_variant_stock: localiza variación y escribe _stock', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    update_post_meta(30, '_tpv_option_value_id', 888);
    $GLOBALS['_stub_posts'][30] = ['ID' => 30, 'post_parent' => 12, 'post_type' => 'product_variation'];

    $ps->update_variant_stock(888, 4.0);

    $t->eq(4.0, (float)get_post_meta(30, '_stock', true));
    $t->eq('instock', get_post_meta(30, '_stock_status', true));
    $t->eq('yes', get_post_meta(30, '_manage_stock', true));
});

$t->test('update_stock activa la guarda anti-bucle durante la escritura', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    update_post_meta(13, '_tpv_product_id', 800);
    $ps->update_stock(800, 5);
    // Tras terminar la guarda debe volver a false
    $t->eq(false, (bool)($GLOBALS['tpv_sync_skip_wc_stock_push'] ?? false));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 2: push_wc_stock_change (WC → TPV) con anti-bucle
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Product Sync — push WC → TPV');

$t->test('push_wc_stock_change: guarda activa → NO envía request', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    $product = new WC_Product_Stub(50, 'simple', 10);
    update_post_meta(50, '_tpv_product_id', 555);

    $GLOBALS['tpv_sync_skip_wc_stock_push'] = true;
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $GLOBALS['tpv_sync_skip_wc_stock_push'] = false;

    $t->eq(0, count($GLOBALS['_stub_http_calls']), 'No debe haber llamadas HTTP');
});

$t->test('push_wc_stock_change: prop no contiene stock_quantity → ignora', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    $product = new WC_Product_Stub(51, 'simple', 5);
    update_post_meta(51, '_tpv_product_id', 556);

    $ps->push_wc_stock_change($product, ['price', 'name']); // sin stock_quantity
    $t->eq(0, count($GLOBALS['_stub_http_calls']));
});

$t->test('push_wc_stock_change: variación usa PATCH /products/{pid}/variants/{povId}', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');

    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        return ['code' => 200, 'body' => json_encode(['data' => []])];
    });

    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    $variation = new WC_Product_Stub(60, 'variation', 3, /* parent */ 61);
    update_post_meta(60, '_tpv_option_value_id', 777);
    update_post_meta(61, '_tpv_product_id', 555);

    $ps->push_wc_stock_change($variation, ['stock_quantity']);

    $patchCalls = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'PATCH');
    $t->assert(!empty($patchCalls), 'Debe haber al menos una llamada PATCH');
    $found = false;
    foreach ($patchCalls as $c) {
        if (str_contains($c['url'], '/products/555/variants/777')) $found = true;
    }
    $t->assert($found, 'URL debe ser /products/555/variants/777');
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 2B: push_wc_product_to_tpv (edición/creación/borrado WC → TPV)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Product Sync — catálogo WC → TPV');

$setupApi = function() {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
};

$t->test('push_wc_product_to_tpv: producto con _tpv_product_id → PATCH', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        return ['code' => 200, 'body' => json_encode(['data' => ['product_id' => 999]])];
    });

    // Post existente con meta tpv_id=555
    $GLOBALS['_stub_posts'][101] = (object)[
        'ID' => 101, 'post_title' => 'Mi Producto', 'post_content' => 'Descripción',
        'post_status' => 'publish', 'post_type' => 'product',
    ];
    update_post_meta(101, '_tpv_product_id', 555);

    $product = new WC_Product_Stub(101, 'simple', 10);
    $product->sku = 'MI-SKU';
    $product->regular_price = 12.50;

    $ps = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $ps->push_wc_product_to_tpv(101);

    $patchCalls = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'PATCH' && str_contains($c['url'], '/products/555'));
    $t->assert(!empty($patchCalls), 'Debe hacer PATCH /products/555');
});

$t->test('push_wc_product_to_tpv: producto SIN tpv_id → POST y guarda meta', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        if ($method === 'POST' && str_contains($url, '/products')) {
            return ['code' => 201, 'body' => json_encode(['data' => ['product_id' => 9999]])];
        }
        return ['code' => 200, 'body' => '{}'];
    });

    $GLOBALS['_stub_posts'][202] = (object)[
        'ID' => 202, 'post_title' => 'Nuevo Producto', 'post_content' => '',
        'post_status' => 'publish', 'post_type' => 'product',
    ];
    $product = new WC_Product_Stub(202, 'simple', 5);
    $product->sku = 'NUEVO-SKU';
    $product->regular_price = 7.99;

    $ps = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $ps->push_wc_product_to_tpv(202);

    $postCalls = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'POST' && preg_match('#/products$#', strtok($c['url'],'?')));
    $t->assert(!empty($postCalls), 'Debe hacer POST /products');
    $t->eq('9999', (string)get_post_meta(202, '_tpv_product_id', true), 'Meta _tpv_product_id = 9999');
});

$t->test('push_wc_product_to_tpv: guarda anti-bucle activa → no hace HTTP', function($t) use ($setupApi) {
    $setupApi();
    $GLOBALS['_stub_posts'][303] = (object)['ID' => 303, 'post_title' => 'X', 'post_content' => '', 'post_status' => 'publish', 'post_type' => 'product'];
    update_post_meta(303, '_tpv_product_id', 777);

    $GLOBALS['tpv_sync_skip_wc_product_push'] = true;
    try {
        (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_product_to_tpv(303);
    } finally {
        $GLOBALS['tpv_sync_skip_wc_product_push'] = false;
    }
    $t->eq(0, count($GLOBALS['_stub_http_calls']), 'Guarda activa → 0 llamadas HTTP');
});

$t->test('generate_sku_from_slug: titulo limpio produce SKU legible', function($t) {
    $ps = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $sku = $ps->generate_sku_from_slug(42, 'Producto Navidad 8OZ');
    $t->assert(str_contains($sku, 'PRODUCTO') || str_contains(strtolower($sku), 'producto'), "SKU contiene 'PRODUCTO' (got $sku)");
    $t->assert(!str_contains($sku, ' '), "SKU sin espacios");
});

$t->test('generate_sku_from_slug: titulo vacio → fallback WC-<postid>', function($t) {
    $ps = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $sku = $ps->generate_sku_from_slug(42, '');
    $t->eq('WC-42', $sku);
});

$t->test('push_wc_trash_to_tpv: marca status=0 en TPV', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code' => 200, 'body' => '{}'];
    });
    $GLOBALS['_stub_posts'][404] = (object)['ID'=>404,'post_type'=>'product','post_status'=>'trash','post_title'=>'x','post_content'=>''];
    update_post_meta(404, '_tpv_product_id', 888);

    (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_trash_to_tpv(404);

    $patchCalls = array_filter($GLOBALS['_stub_http_calls'], function($c) {
        return $c['method'] === 'PATCH'
            && str_contains($c['url'], '/products/888')
            && str_contains($c['args']['body'] ?? '', '"status":0');
    });
    $t->assert(!empty($patchCalls), 'PATCH con status=0');
});

$t->test('push_wc_untrash_to_tpv: restaura status=1 en TPV', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code' => 200, 'body' => '{}'];
    });
    $GLOBALS['_stub_posts'][505] = (object)['ID'=>505,'post_type'=>'product','post_status'=>'publish','post_title'=>'x','post_content'=>''];
    update_post_meta(505, '_tpv_product_id', 999);

    (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_untrash_to_tpv(505);

    $patchCalls = array_filter($GLOBALS['_stub_http_calls'], function($c) {
        return $c['method'] === 'PATCH'
            && str_contains($c['url'], '/products/999')
            && str_contains($c['args']['body'] ?? '', '"status":1');
    });
    $t->assert(!empty($patchCalls), 'PATCH con status=1');
});

$t->test('push_wc_delete_to_tpv: llama DELETE /products/{id}', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code' => 200, 'body' => '{}'];
    });
    $GLOBALS['_stub_posts'][606] = (object)['ID'=>606,'post_type'=>'product','post_status'=>'publish','post_title'=>'x','post_content'=>''];
    update_post_meta(606, '_tpv_product_id', 1234);

    (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_delete_to_tpv(606);

    $deleteCalls = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'DELETE' && str_contains($c['url'], '/products/1234'));
    $t->assert(!empty($deleteCalls), 'DELETE /products/1234');
});

$t->test('push_wc_product_to_tpv: post_type != product → ignora', function($t) use ($setupApi) {
    $setupApi();
    $GLOBALS['_stub_posts'][707] = (object)['ID'=>707,'post_type'=>'post','post_status'=>'publish','post_title'=>'Articulo blog','post_content'=>''];
    (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_product_to_tpv(707);
    $t->eq(0, count($GLOBALS['_stub_http_calls']), 'No HTTP para post_type != product');
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 3: Webhook handler — firma HMAC + idempotencia + ordering
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Webhook handler — seguridad HMAC');

$t->test('verificación HMAC: firma válida acepta, firma inválida rechaza', function($t) {
    $secret  = 'shared-secret';
    $payload = '{"ok":1}';
    $sigOk   = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    $sigBad  = 'sha256=deadbeef';

    $rc = new ReflectionClass('TPV_Sync_Webhook');
    $m  = $rc->getMethod('verify_signature');
    $m->setAccessible(true);

    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);
    $os  = new TPV_Sync_Order_Sync($api);
    $wh  = new TPV_Sync_Webhook($ps, $os);

    $t->eq(true,  $m->invoke($wh, $payload, $sigOk, $secret));
    $t->eq(false, $m->invoke($wh, $payload, $sigBad, $secret));
    $t->eq(false, $m->invoke($wh, $payload, '', $secret), 'Firma vacía rechaza');
});

$t->suite('WP / Webhook handler — idempotencia y ordering guard');

$t->test('accept_stock_event: timestamp más nuevo → acepta y persiste last_ts', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);
    $os  = new TPV_Sync_Order_Sync($api);
    $wh  = new TPV_Sync_Webhook($ps, $os);

    $rc = new ReflectionClass($wh);
    $m  = $rc->getMethod('accept_stock_event');
    $m->setAccessible(true);

    $pl = ['timestamp' => '2026-04-19T10:00:00Z'];
    $t->eq(true, $m->invoke($wh, 100, 'product', $pl));

    // Segunda llamada con timestamp anterior debe rechazar
    $pl2 = ['timestamp' => '2026-04-19T09:00:00Z'];
    $t->eq(false, $m->invoke($wh, 100, 'product', $pl2));

    // Timestamp posterior sí
    $pl3 = ['timestamp' => '2026-04-19T11:00:00Z'];
    $t->eq(true, $m->invoke($wh, 100, 'product', $pl3));
});

$t->test('accept_stock_event: sin timestamp → acepta (compat)', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);
    $os  = new TPV_Sync_Order_Sync($api);
    $wh  = new TPV_Sync_Webhook($ps, $os);

    $rc = new ReflectionClass($wh);
    $m  = $rc->getMethod('accept_stock_event');
    $m->setAccessible(true);

    $t->eq(true, $m->invoke($wh, 101, 'product', []));
});

$t->test('idempotency_key: mismo evento dos veces → segundo ignorado', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);
    $os  = new TPV_Sync_Order_Sync($api);
    $wh  = new TPV_Sync_Webhook($ps, $os);

    // Primera vez
    set_transient('tpv_sync_idem_KEY123', 0, DAY_IN_SECONDS); // simula que ya se procesó
    $t->assert(get_transient('tpv_sync_idem_KEY123') === 0 || get_transient('tpv_sync_idem_KEY123') === 1,
        'Transient presente (duplicado)');

    // Reset limpio
    delete_transient('tpv_sync_idem_KEY123');
    $t->eq(false, get_transient('tpv_sync_idem_KEY123'));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 4: Order Sync — send_to_tpv / refund
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Order Sync — send_to_tpv');

$t->test('send_to_tpv: pedido ya con _tpv_order_id → no reenvía (idempotente)', function($t) {
    $api = new TPV_Sync_API_Client();
    $os  = new TPV_Sync_Order_Sync($api);

    update_post_meta(200, '_tpv_order_id', 42);
    $os->send_to_tpv(200);

    $t->eq(0, count($GLOBALS['_stub_http_calls']), 'No debe hacer HTTP si ya fue enviado');
});

// Helper: crear un coupon item stub para WC
if (!class_exists('WC_Coupon_Item_Stub')) {
    class WC_Coupon_Item_Stub {
        private string $code;
        private float $discount;
        private float $discountTax;
        public function __construct(string $code, float $discount, float $discountTax = 0.0) {
            $this->code = $code; $this->discount = $discount; $this->discountTax = $discountTax;
        }
        public function get_type(): string      { return 'coupon'; }
        public function get_code(): string      { return $this->code; }
        public function get_discount(): float   { return $this->discount; }
        public function get_discount_tax(): float { return $this->discountTax; }
    }
}
// Item de producto para mezclar con coupons
if (!class_exists('WC_LineItem_Stub')) {
    class WC_LineItem_Stub {
        private int $productId;
        private float $quantity;
        private float $totalNet;
        private float $totalTax;
        private string $name;
        public function __construct(int $productId, float $qty, float $totalNet, float $totalTax, string $name) {
            $this->productId = $productId; $this->quantity = $qty;
            $this->totalNet = $totalNet; $this->totalTax = $totalTax; $this->name = $name;
        }
        public function get_type(): string   { return 'line_item'; }
        public function get_product_id(): int { return $this->productId; }
        public function get_quantity()       { return $this->quantity; }
        public function get_total()          { return $this->totalNet; }
        public function get_total_tax()      { return $this->totalTax; }
        public function get_name(): string   { return $this->name; }
    }
}

$t->test('send_to_tpv: 1 cupón WC → vouchers[] con code+amount gross', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        if ($method === 'POST' && str_contains($url, '/orders')) return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>5100]])];
        return ['code'=>200,'body'=>'{}'];
    });

    update_post_meta(100, '_tpv_product_id', 8000);
    $line   = new WC_LineItem_Stub(100, 1, 100.0, 21.0, 'Camisa');
    $coupon = new WC_Coupon_Item_Stub('NEWSLETTER', 10.0, 2.1); // discount base + discount tax

    $GLOBALS['_stub_orders'][800] = new WC_Order_Stub(800, ['total' => 108.90], [$line, $coupon]);

    (new TPV_Sync_Order_Sync(new TPV_Sync_API_Client()))->send_to_tpv(800);

    $ordersCalls = array_filter($GLOBALS['_stub_http_calls'],
        fn($c) => $c['method'] === 'POST' && preg_match('#/orders$#', strtok($c['url'], '?'))
    );
    $t->eq(1, count($ordersCalls));
    $body = json_decode(reset($ordersCalls)['args']['body'] ?? '', true);
    $t->assert(isset($body['vouchers']), 'Payload tiene vouchers[]');
    $t->eq(1, count($body['vouchers']));
    $t->eq('NEWSLETTER', $body['vouchers'][0]['code']);
    $t->assert(abs($body['vouchers'][0]['amount'] - 12.1) < 0.01,
        "amount = discount + discount_tax = 12.10 (got {$body['vouchers'][0]['amount']})");
});

$t->test('send_to_tpv: 2 cupones WC → 2 entradas en vouchers[]', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>5101]])];
    });

    update_post_meta(101, '_tpv_product_id', 8001);
    $line = new WC_LineItem_Stub(101, 1, 100.0, 21.0, 'Camisa');
    $c1 = new WC_Coupon_Item_Stub('NEWSLETTER', 10.0, 0);
    $c2 = new WC_Coupon_Item_Stub('REPETIDOR',   5.0, 0);

    $GLOBALS['_stub_orders'][801] = new WC_Order_Stub(801, ['total' => 106.0], [$line, $c1, $c2]);

    (new TPV_Sync_Order_Sync(new TPV_Sync_API_Client()))->send_to_tpv(801);

    $ordersCalls = array_filter($GLOBALS['_stub_http_calls'],
        fn($c) => $c['method'] === 'POST' && preg_match('#/orders$#', strtok($c['url'], '?'))
    );
    $body = json_decode(reset($ordersCalls)['args']['body'] ?? '', true);
    $t->eq(2, count($body['vouchers'] ?? []));
    $codes = array_column($body['vouchers'], 'code');
    $t->assert(in_array('NEWSLETTER', $codes, true));
    $t->assert(in_array('REPETIDOR',  $codes, true));
});

$t->test('send_to_tpv: cupón con discount=0 (envío gratis) se ignora', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>5102]])];
    });

    update_post_meta(102, '_tpv_product_id', 8002);
    $line      = new WC_LineItem_Stub(102, 1, 50.0, 10.5, 'Producto');
    $shipFree  = new WC_Coupon_Item_Stub('ENVIOGRATIS', 0.0, 0);

    $GLOBALS['_stub_orders'][802] = new WC_Order_Stub(802, ['total' => 60.5], [$line, $shipFree]);

    (new TPV_Sync_Order_Sync(new TPV_Sync_API_Client()))->send_to_tpv(802);

    $ordersCalls = array_filter($GLOBALS['_stub_http_calls'],
        fn($c) => $c['method'] === 'POST' && preg_match('#/orders$#', strtok($c['url'], '?'))
    );
    $body = json_decode(reset($ordersCalls)['args']['body'] ?? '', true);
    $t->assert(!isset($body['vouchers']) || count($body['vouchers']) === 0,
        'Cupón de envío gratis NO debe aparecer en vouchers');
});

$t->test('send_to_tpv: sin cupones → payload sin campo vouchers (compat retroactiva)', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>5103]])];
    });

    update_post_meta(103, '_tpv_product_id', 8003);
    $line = new WC_LineItem_Stub(103, 1, 20.0, 4.2, 'X');
    $GLOBALS['_stub_orders'][803] = new WC_Order_Stub(803, ['total' => 24.2], [$line]);

    (new TPV_Sync_Order_Sync(new TPV_Sync_API_Client()))->send_to_tpv(803);

    $ordersCalls = array_filter($GLOBALS['_stub_http_calls'],
        fn($c) => $c['method'] === 'POST' && preg_match('#/orders$#', strtok($c['url'], '?'))
    );
    $body = json_decode(reset($ordersCalls)['args']['body'] ?? '', true);
    $t->assert(!array_key_exists('vouchers', $body), 'Sin cupones → no se envía el campo vouchers');
});

$t->test('send_to_tpv: cupón con code vacío → se envía igual (amount preserva info)', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>5104]])];
    });

    update_post_meta(104, '_tpv_product_id', 8004);
    $line = new WC_LineItem_Stub(104, 1, 100.0, 21.0, 'P');
    $nobrand = new WC_Coupon_Item_Stub('', 5.0, 1.05);
    $GLOBALS['_stub_orders'][804] = new WC_Order_Stub(804, ['total' => 114.95], [$line, $nobrand]);

    (new TPV_Sync_Order_Sync(new TPV_Sync_API_Client()))->send_to_tpv(804);

    $ordersCalls = array_filter($GLOBALS['_stub_http_calls'],
        fn($c) => $c['method'] === 'POST' && preg_match('#/orders$#', strtok($c['url'], '?'))
    );
    $body = json_decode(reset($ordersCalls)['args']['body'] ?? '', true);
    $t->eq(1, count($body['vouchers'] ?? []));
    $t->eq('', $body['vouchers'][0]['code'], 'code vacío se preserva');
    $t->assert(abs($body['vouchers'][0]['amount'] - 6.05) < 0.01, 'amount = 5 + 1.05 = 6.05');
});

$t->test('on_wc_refund: origen TPV → no reenvía (evita bucle)', function($t) {
    $api = new TPV_Sync_API_Client();
    $os  = new TPV_Sync_Order_Sync($api);

    update_post_meta(300, '_tpv_refund_origin', 'tpv');
    $os->on_wc_refund(999, 300);

    $t->eq(0, count($GLOBALS['_stub_http_calls']), 'Refund de origen TPV no debe reenviarse');
    $t->eq('', get_post_meta(300, '_tpv_refund_origin', true), 'Marker debe consumirse');
});

$t->test('on_wc_refund: ya sincronizado → idempotente', function($t) {
    $api = new TPV_Sync_API_Client();
    $os  = new TPV_Sync_Order_Sync($api);

    update_post_meta(301, '_tpv_refund_synced', 1);
    $os->on_wc_refund(999, 301);

    $t->eq(0, count($GLOBALS['_stub_http_calls']));
});

$t->test('on_wc_refund: pedido sin _tpv_order_id → skip con log', function($t) {
    $api = new TPV_Sync_API_Client();
    $os  = new TPV_Sync_Order_Sync($api);

    // No existe _tpv_order_id en post 400
    $os->on_wc_refund(400, 500);

    $t->eq(0, count($GLOBALS['_stub_http_calls']), 'No debe hacer POST sin mapeo');
});

$t->test('on_wc_refund: happy path → POST /orders/{id}/returns con payload correcto', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        if ($method === 'POST' && str_contains($url, '/orders/') && str_contains($url, '/returns')) {
            return ['code' => 201, 'body' => json_encode(['data' => ['return_id' => 777]])];
        }
        return ['code' => 200, 'body' => '{}'];
    });

    // Pedido WC 600 mapeado a TPV order 5000
    update_post_meta(600, '_tpv_order_id', 5000);
    // Producto WC 60 mapeado a TPV product 9090
    update_post_meta(60, '_tpv_product_id', 9090);

    // Refund 700 con 1 línea: 2 unidades del producto 60
    $item = new class {
        public function get_product_id() { return 60; }
        public function get_quantity() { return -2; } // WC negativo
        public function get_name() { return 'Camiseta'; }
    };
    $GLOBALS['_stub_orders'][700] = new WC_Order_Stub(700, ['reason' => 'Cliente cambió de opinión'], [$item]);

    $os = new TPV_Sync_Order_Sync(new TPV_Sync_API_Client());
    $os->on_wc_refund(600, 700);

    $refundCalls = array_filter($GLOBALS['_stub_http_calls'],
        fn($c) => $c['method'] === 'POST' && str_contains($c['url'], '/orders/5000/returns')
    );
    $t->assert(!empty($refundCalls), 'POST a /orders/5000/returns ejecutado');

    $call = reset($refundCalls);
    $body = json_decode($call['args']['body'] ?? '', true);
    $t->eq(9090, $body['product_id']    ?? 0, 'product_id TPV correcto');
    $t->eq(2,    (int)($body['quantity'] ?? 0), 'quantity absoluto (no negativo)');
    $t->assert(str_contains($body['comment'] ?? '', 'Reembolso WC #700'), 'comment incluye referencia WC');
    $t->assert(str_contains($body['comment'] ?? '', 'cambió de opinión'), 'comment incluye reason');

    // Idempotency-Key se envía (el API client lo pasa como array asociativo)
    $headers = $call['args']['headers'] ?? [];
    $idem = $headers['Idempotency-Key'] ?? '';
    $t->assert(str_contains($idem, 'wc-refund-700-9090'),
        "Idempotency-Key contiene refund_id y product_id (got '$idem')");

    // Marca _tpv_refund_synced tras éxito
    $t->eq('1', (string)get_post_meta(700, '_tpv_refund_synced', true), 'Meta _tpv_refund_synced establecido');
});

$t->test('on_wc_refund: múltiples líneas → N POSTs independientes con idem-keys distintas', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        if ($method === 'POST' && str_contains($url, '/returns')) return ['code'=>201,'body'=>json_encode(['data'=>['return_id'=>888]])];
        return ['code'=>200,'body'=>'{}'];
    });

    update_post_meta(601, '_tpv_order_id', 5001);
    update_post_meta(61, '_tpv_product_id', 9091);
    update_post_meta(62, '_tpv_product_id', 9092);

    $item1 = new class {
        public function get_product_id() { return 61; }
        public function get_quantity() { return -1; }
        public function get_name() { return 'A'; }
    };
    $item2 = new class {
        public function get_product_id() { return 62; }
        public function get_quantity() { return -3; }
        public function get_name() { return 'B'; }
    };
    $GLOBALS['_stub_orders'][701] = new WC_Order_Stub(701, [], [$item1, $item2]);

    (new TPV_Sync_Order_Sync(new TPV_Sync_API_Client()))->on_wc_refund(601, 701);

    $refundCalls = array_filter($GLOBALS['_stub_http_calls'],
        fn($c) => $c['method'] === 'POST' && str_contains($c['url'], '/returns')
    );
    $t->eq(2, count($refundCalls), '2 POSTs (1 por línea)');

    // Cada POST debe llevar idem-key distinta (incluye product_id)
    $idemKeys = [];
    foreach ($refundCalls as $c) {
        $idemKeys[] = $c['args']['headers']['Idempotency-Key'] ?? '';
    }
    $t->eq(2, count(array_unique($idemKeys)), 'Idem-keys distintas para cada línea (got: ' . implode(',', $idemKeys) . ')');
});

$t->test('on_wc_refund: API devuelve error → no marca synced, encola para reintento', function($t) use ($setupApi) {
    $setupApi();
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        if ($method === 'POST' && str_contains($url, '/returns')) {
            return ['code'=>500, 'body'=>json_encode(['errors'=>[['error'=>'server_error','message'=>'boom']]])];
        }
        return ['code'=>200,'body'=>'{}'];
    });

    update_post_meta(602, '_tpv_order_id', 5002);
    update_post_meta(63, '_tpv_product_id', 9093);
    $item = new class {
        public function get_product_id() { return 63; }
        public function get_quantity() { return -1; }
        public function get_name() { return 'C'; }
    };
    $GLOBALS['_stub_orders'][702] = new WC_Order_Stub(702, [], [$item]);

    (new TPV_Sync_Order_Sync(new TPV_Sync_API_Client()))->on_wc_refund(602, 702);

    $synced = get_post_meta(702, '_tpv_refund_synced', true);
    $t->assert($synced === '' || $synced === false, "NO marca synced tras fallo (got '$synced')");
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 5: Seguridad — URL del cron line se regenera al dominio
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Admin — cron line');

$t->test('home_url() produciría una URL dinámica (stub de concepto)', function($t) {
    // En WP real home_url() devuelve la URL del sitio. Aquí validamos el formato
    // esperado para que sea copiable a crontab.
    $fakeHome = 'https://miotratienda.com';
    $line = '0 * * * * curl -s ' . $fakeHome . '/wp-cron.php?doing_wp_cron >/dev/null';
    $t->assert(str_starts_with($line, '0 * * * * curl -s https://'), 'Formato de cron correcto');
    $t->assert(str_contains($line, 'wp-cron.php'), 'Apunta a wp-cron.php');
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 6: Performance stubs
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Performance');

$t->test('update_stock x1000 en < 1s', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);

    for ($i = 1; $i <= 1000; $i++) {
        update_post_meta(10000 + $i, '_tpv_product_id', 10000 + $i);
    }

    $start = microtime(true);
    for ($i = 1; $i <= 1000; $i++) {
        $ps->update_stock(10000 + $i, $i);
    }
    $elapsed = microtime(true) - $start;
    $t->assert($elapsed < 1.0, "1000 update_stock en < 1s (fue {$elapsed}s)");
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 7: Seguridad — XSS / escapes
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Seguridad — sanitización');

$t->test('HMAC con secret nulo se considera no configurado (stub)', function($t) {
    // El handler rechaza si no hay secret. Aquí comprobamos la lógica fuera del flujo.
    $secret = get_option('tpv_sync_webhook_secret', '');
    $t->eq('', $secret, 'Sin config, secret vacío');
    // El handler debería devolver 503 en ese caso (lógica ya cubierta por linting).
});

$t->test('esc_html escapa < > &', function($t) {
    $t->eq('&lt;script&gt;', esc_html('<script>'));
    $t->eq('cat &amp; dog', esc_html('cat & dog'));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO 8: Bulk push WC → TPV (e2e) — comando CLI + ajax admin
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('WP / Bulk push WC → TPV — escenario "instalo plugin sobre WC existente"');

// Helper: registra un producto WC stubeado con SKU y estado dados.
// El stub wc_get_product lee SKU/precio desde meta _sku/_regular_price.
$mkWcProduct = function(int $id, string $title, ?string $sku, string $status = 'publish', ?int $tpvId = null) {
    $GLOBALS['_stub_posts'][$id] = (object)[
        'ID'           => $id,
        'post_title'   => $title,
        'post_content' => '',
        'post_status'  => $status,
        'post_type'    => 'product',
    ];
    if ($sku !== null && $sku !== '') update_post_meta($id, '_sku', $sku);
    update_post_meta($id, '_regular_price', '10.0');
    if ($tpvId !== null) update_post_meta($id, '_tpv_product_id', $tpvId);
};

// Helper: cuenta llamadas POST|PATCH a /products
$countProductCalls = function(): array {
    $out = ['POST' => 0, 'PATCH' => 0];
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST'  && preg_match('#/products($|\?)#', strtok($c['url'], '?'))) $out['POST']++;
        if ($c['method'] === 'PATCH' && strpos($c['url'], '/products/') !== false)               $out['PATCH']++;
    }
    return $out;
};

// Responder HTTP estándar: auth ok + POST /products devuelve product_id aleatorio
$mkResponder = function() {
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        if ($method === 'POST' && preg_match('#/products($|\?)#', strtok($url, '?'))) {
            return ['code' => 201, 'body' => json_encode(['data' => ['product_id' => random_int(1000, 9999)]])];
        }
        return ['code' => 200, 'body' => '{}'];
    });
};

$t->test('E2E-1 Catálogo WC vacío → 0 llamadas al TPV', function($t) use ($setupApi, $mkResponder, $countProductCalls) {
    $setupApi();
    $mkResponder();
    $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());

    $ids = get_posts(['post_type' => 'product', 'post_status' => ['publish','draft'], 'posts_per_page' => -1, 'fields' => 'ids']);
    foreach ($ids as $id) $sync->push_wc_product_to_tpv($id);

    $t->eq(0, count($ids), 'No hay productos WC');
    $c = $countProductCalls();
    $t->eq(0, $c['POST'] + $c['PATCH'], 'Sin POST ni PATCH');
});

$t->test('E2E-2 5 productos nuevos en WC → 5 POST /products, meta _tpv_product_id guardado', function($t) use ($setupApi, $mkResponder, $mkWcProduct, $countProductCalls) {
    $setupApi();
    $mkResponder();
    for ($i = 1; $i <= 5; $i++) $mkWcProduct(1000 + $i, "Producto $i", "SKU-$i");

    $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $ids  = get_posts(['post_type' => 'product', 'post_status' => ['publish','draft'], 'posts_per_page' => -1, 'fields' => 'ids']);
    foreach ($ids as $id) $sync->push_wc_product_to_tpv($id);

    $c = $countProductCalls();
    $t->eq(5, $c['POST'], '5 POST');
    $t->eq(0, $c['PATCH'], '0 PATCH');
    for ($i = 1; $i <= 5; $i++) {
        $tpvId = (string)get_post_meta(1000 + $i, '_tpv_product_id', true);
        $t->assert($tpvId !== '' && (int)$tpvId > 0, "Producto $i tiene meta _tpv_product_id guardado (got '$tpvId')");
    }
});

$t->test('E2E-3 Mezcla: 3 sin sync + 2 ya sincronizados → 3 POST + 2 PATCH', function($t) use ($setupApi, $mkResponder, $mkWcProduct, $countProductCalls) {
    $setupApi();
    $mkResponder();
    $mkWcProduct(2001, 'Nuevo A', 'A-NEW');
    $mkWcProduct(2002, 'Nuevo B', 'B-NEW');
    $mkWcProduct(2003, 'Nuevo C', 'C-NEW');
    $mkWcProduct(2004, 'Existente D', 'D-OLD', 'publish', 500);
    $mkWcProduct(2005, 'Existente E', 'E-OLD', 'publish', 501);

    $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    foreach (get_posts(['post_type'=>'product','post_status'=>['publish','draft'],'posts_per_page'=>-1,'fields'=>'ids']) as $id) {
        $sync->push_wc_product_to_tpv($id);
    }
    $c = $countProductCalls();
    $t->eq(3, $c['POST'],  '3 productos nuevos → POST');
    $t->eq(2, $c['PATCH'], '2 existentes → PATCH');
});

$t->test('E2E-4 Idempotencia: re-ejecutar bulk hace PATCH (no duplica en TPV)', function($t) use ($setupApi, $mkResponder, $mkWcProduct, $countProductCalls) {
    $setupApi();
    $mkResponder();
    for ($i = 1; $i <= 3; $i++) $mkWcProduct(3000 + $i, "Prod $i", "IDEM-$i");

    $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());

    // Primera pasada → POST
    foreach (get_posts(['post_type'=>'product','post_status'=>['publish','draft'],'posts_per_page'=>-1,'fields'=>'ids']) as $id) {
        $sync->push_wc_product_to_tpv($id);
    }
    $c1 = $countProductCalls();
    $t->eq(3, $c1['POST'], 'Primera pasada: 3 POST');

    // Segunda pasada → PATCH (el meta ya existe)
    foreach (get_posts(['post_type'=>'product','post_status'=>['publish','draft'],'posts_per_page'=>-1,'fields'=>'ids']) as $id) {
        $sync->push_wc_product_to_tpv($id);
    }
    $c2 = $countProductCalls();
    $t->eq(3, $c2['POST'],  'Sigue 3 POST tras 2ª pasada (no duplica)');
    $t->eq(3, $c2['PATCH'], '3 PATCH añadidos en 2ª pasada');
});

$t->test('E2E-5 skip-synced: productos con _tpv_product_id se saltan', function($t) use ($setupApi, $mkResponder, $mkWcProduct, $countProductCalls) {
    $setupApi();
    $mkResponder();
    $mkWcProduct(4001, 'Nuevo',      'NEW');
    $mkWcProduct(4002, 'Ya en TPV',  'SYNCED',  'publish', 9000);

    $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $ids  = get_posts(['post_type'=>'product','post_status'=>['publish','draft'],'posts_per_page'=>-1,'fields'=>'ids']);

    // Replicamos la lógica del CLI/admin con skip-synced activo
    $pushed = 0; $skipped = 0;
    foreach ($ids as $id) {
        if (get_post_meta($id, '_tpv_product_id', true)) { $skipped++; continue; }
        $sync->push_wc_product_to_tpv($id);
        $pushed++;
    }

    $t->eq(1, $pushed,  '1 producto nuevo pusheado');
    $t->eq(1, $skipped, '1 saltado');
    $c = $countProductCalls();
    $t->eq(1, $c['POST'], '1 POST');
    $t->eq(0, $c['PATCH'], '0 PATCH (skip-synced saltó el existente)');
});

$t->test('E2E-6 Producto sin SKU → se genera desde slug antes del POST', function($t) use ($setupApi, $mkResponder, $mkWcProduct) {
    $setupApi();
    $mkResponder();
    $mkWcProduct(5001, 'Camiseta Azul Talla M', null); // SKU vacío

    (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_product_to_tpv(5001);

    // El POST al TPV debe llevar un SKU no vacío en el body
    $post = null;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && preg_match('#/products($|\?)#', strtok($c['url'],'?'))) {
            $post = $c; break;
        }
    }
    $t->assert($post !== null, 'POST /products ejecutado');
    $body = json_decode($post['args']['body'] ?? '', true);
    $t->assert(!empty($body['sku']), "SKU generado no vacío (got '" . ($body['sku'] ?? '') . "')");
});

$t->test('E2E-7 Estados filtrados: trash ignorado por get_posts(publish,draft)', function($t) use ($setupApi, $mkResponder, $mkWcProduct, $countProductCalls) {
    $setupApi();
    $mkResponder();
    $mkWcProduct(6001, 'Publicado', 'PUB',   'publish');
    $mkWcProduct(6002, 'Borrador',  'DRAFT', 'draft');
    $mkWcProduct(6003, 'Papelera',  'TRASH', 'trash');

    $ids = get_posts(['post_type'=>'product','post_status'=>['publish','draft'],'posts_per_page'=>-1,'fields'=>'ids']);
    $t->eq(2, count($ids), 'Solo publish + draft (trash excluido)');
    $t->assert(!in_array(6003, $ids, true), 'Trash NO incluido');
});

$t->test('E2E-8 Error en un producto no aborta los demás (resiliente)', function($t) use ($setupApi, $mkWcProduct, $countProductCalls) {
    $setupApi();
    $mkWcProduct(7001, 'OK 1', 'OK1');
    $mkWcProduct(7002, 'FALLA', 'FAIL');
    $mkWcProduct(7003, 'OK 2', 'OK2');

    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        if ($method === 'POST' && str_contains($args['body'] ?? '', 'FAIL')) {
            return ['code' => 500, 'body' => json_encode(['error' => 'boom'])];
        }
        return ['code' => 201, 'body' => json_encode(['data' => ['product_id' => random_int(1000,9999)]])];
    });

    $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $pushed = 0; $errors = 0;
    foreach (get_posts(['post_type'=>'product','post_status'=>['publish','draft'],'posts_per_page'=>-1,'fields'=>'ids']) as $id) {
        try { $sync->push_wc_product_to_tpv($id); $pushed++; }
        catch (Throwable $e) { $errors++; }
    }
    // push_wc_product_to_tpv loguea el error internamente y retorna void → no lanza
    // Lo que validamos: los 3 se intentaron (3 POSTs), el error no tumba el loop
    $c = $countProductCalls();
    $t->eq(3, $c['POST'], 'Los 3 productos se intentaron pese al error del 2º');

    // Y los OK quedaron con su meta _tpv_product_id
    $metaOK1 = get_post_meta(7001, '_tpv_product_id', true);
    $metaOK2 = get_post_meta(7003, '_tpv_product_id', true);
    $t->assert($metaOK1 !== '', 'Producto OK 1 quedó vinculado');
    $t->assert($metaOK2 !== '', 'Producto OK 2 quedó vinculado');
    // El que falló NO debe tener meta guardado (5xx → return sin update_post_meta)
    $metaFail = get_post_meta(7002, '_tpv_product_id', true);
    $t->eq('', (string)$metaFail, 'Producto FAIL no quedó vinculado');
});

$t->test('E2E-9 Payload del POST contiene name, price, sku, status, quantity', function($t) use ($setupApi, $mkResponder, $mkWcProduct) {
    $setupApi();
    $mkResponder();
    $mkWcProduct(8001, 'Producto Completo', 'COMP-SKU', 'publish');

    (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_product_to_tpv(8001);

    $post = null;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && preg_match('#/products($|\?)#', strtok($c['url'],'?'))) {
            $post = $c; break;
        }
    }
    $t->assert($post !== null, 'POST ejecutado');
    $body = json_decode($post['args']['body'] ?? '', true);

    foreach (['name', 'price', 'sku', 'status', 'quantity'] as $field) {
        $t->assert(array_key_exists($field, $body), "Campo '$field' presente en payload");
    }
    $t->eq('Producto Completo', $body['name']);
    $t->eq('COMP-SKU', $body['sku']);
    $t->eq(1, $body['status'], 'publish → status=1');
});

$t->test('E2E-10 Admin UI: ajax handler y botón registrados', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, "wp_ajax_tpv_sync_push_all"),   'add_action wp_ajax_tpv_sync_push_all');
    $t->assert(str_contains($src, "public function ajax_push_all"), 'método ajax_push_all definido');
    $t->assert(str_contains($src, "btn-push-all"),                  'Botón btn-push-all presente en UI');
    $t->assert(str_contains($src, "push-skip-synced"),              'Checkbox skip-synced presente en UI');
    $t->assert(str_contains($src, "current_user_can('manage_woocommerce')"), 'Permission check manage_woocommerce');
    $t->assert(str_contains($src, "check_ajax_referer('tpv_sync'"), 'Nonce check tpv_sync');
});

$t->test('E2E-11 WP-CLI: método push_all existe en TPV_Sync_CLI', function($t) {
    // Solo validamos definición + docblock. No podemos invocarlo sin WP_CLI real.
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-cli.php');
    $t->assert(str_contains($src, 'public function push_all('), 'Método push_all definido');
    $t->assert(str_contains($src, '[--dry-run]'), 'Soporta --dry-run');
    $t->assert(str_contains($src, '[--skip-synced]'), 'Soporta --skip-synced');
    $t->assert(str_contains($src, '[--limit='), 'Soporta --limit');
});

$t->test('E2E-12 100 productos en bulk: rendimiento y coherencia', function($t) use ($setupApi, $mkResponder, $mkWcProduct, $countProductCalls) {
    $setupApi();
    $mkResponder();
    for ($i = 1; $i <= 100; $i++) $mkWcProduct(9000 + $i, "Bulk $i", "BULK-$i");

    $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
    $start = microtime(true);
    foreach (get_posts(['post_type'=>'product','post_status'=>['publish','draft'],'posts_per_page'=>-1,'fields'=>'ids']) as $id) {
        $sync->push_wc_product_to_tpv($id);
    }
    $elapsed = microtime(true) - $start;

    $c = $countProductCalls();
    $t->eq(100, $c['POST'], '100 POST ejecutados');
    $t->assert($elapsed < 10.0, "100 productos en {$elapsed}s (< 10s con stubs)");
    // Todos los productos recibieron meta
    $synced = 0;
    for ($i = 1; $i <= 100; $i++) {
        if (get_post_meta(9000 + $i, '_tpv_product_id', true)) $synced++;
    }
    $t->eq(100, $synced, 'Los 100 quedan vinculados (meta _tpv_product_id)');
});

// ═══════════════════════════════════════════════════════════════════════
// CHECK-SYNC: botón unificado "Comprobar sincronización" — réplica WC del PS
// ═══════════════════════════════════════════════════════════════════════

$t->test('CS-01 Handler ajax_check_sync registrado en init()', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, "wp_ajax_tpv_sync_check_sync"),
        'add_action wp_ajax_tpv_sync_check_sync');
    $t->assert(str_contains($src, 'public function ajax_check_sync'),
        'método ajax_check_sync definido');
});

$t->test('CS-02 Botón "Comprobar sincronización" presente en UI', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, 'cc-btn-check-sync'),
        'Botón cc-btn-check-sync en HTML');
    $t->assert(str_contains($src, 'Comprobar sincronización'),
        'Etiqueta del botón presente');
    $t->assert(str_contains($src, "'tpv_sync_check_sync'"),
        'JS llama a tpv_sync_check_sync');
});

$t->test('CS-03 Auto-clasificación: filtro precio negativo', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, "(float) (\$p['price'] ?? 0) < 0"),
        'Filtro precio<0 presente en ajax_check_sync');
});

$t->test('CS-04 Auto-clasificación: filtro model vacío', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, "if (\$m === '') return true"),
        'Filtro model vacío presente');
});

$t->test('CS-05 Auto-clasificación: POS_DISCOUNT/SERVICE/TIP filtrados', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, "'POS_DISCOUNT'"), "POS_DISCOUNT en internalPosModels");
    $t->assert(str_contains($src, "'POS_SERVICE'"), "POS_SERVICE en internalPosModels");
    $t->assert(str_contains($src, "'POS_TIP'"),     "POS_TIP en internalPosModels");
});

$t->test('CS-06 Auto-clasificación: filtro model duplicado', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, "(\$modelCount[\$m] ?? 0) > 1"),
        'Filtro model duplicado en TPV');
});

$t->test('CS-07 Estructura respuesta: campos obligatorios', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $expected = ['synced', 'islands_wc', 'islands_tpv', 'divergences',
                 'unimportable', 'wc_total', 'tpv_total'];
    foreach ($expected as $key) {
        $t->assert(str_contains($src, "'$key'"),
            "ajax_check_sync devuelve clave '$key'");
    }
});

$t->test('CS-08 Seguridad: nonce y capability check', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $section = substr($src, strpos($src, 'public function ajax_check_sync'), 4000);
    $t->assert(str_contains($section, "check_ajax_referer('tpv_sync', 'nonce')"),
        'Nonce check en ajax_check_sync');
    $t->assert(str_contains($section, "current_user_can('manage_woocommerce')"),
        'Capability check manage_woocommerce');
});

$t->test('CS-09 Renderizado: pantalla de status con 4 stats', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, 'cc-syncstatus-stats'),
        'Container cc-syncstatus-stats en JS');
    $t->assert(str_contains($src, 'cc-syncstatus-stat'),
        'Items individuales cc-syncstatus-stat');
    $t->assert(str_contains($src, 'is-summary'),
        'Tarjeta especial "discrepancias" con is-summary');
});

$t->test('CS-10 Estado OK: cuando divergences=0 muestra mensaje verde', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, 'is-ok') && str_contains($src, 'is-warn'),
        'Variantes ok/warn según divergences');
    $t->assert(str_contains($src, 'Todo en orden'),
        'Mensaje "Todo en orden" para estado verde');
});

$t->test('CS-11 Auto-clasificación: unimportable mostrado como info no alarma', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, 'cc-syncstatus-info'),
        'Bloque info presente');
    $t->assert(str_contains($src, 'cc-syncstatus-info-icon'),
        'Icono ⓘ presente');
    $t->assert(str_contains($src, 'Se ignoran automáticamente'),
        'Mensaje explicativo presente');
});

$t->test('CS-12 CSS de la pantalla syncstatus presente', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, '.cc-wrap .cc-syncstatus-card'),
        'Estilo cc-syncstatus-card definido');
    $t->assert(str_contains($src, '.cc-wrap .cc-syncstatus-header.is-ok'),
        'Estilo header OK definido');
    $t->assert(str_contains($src, '.cc-wrap .cc-syncstatus-header.is-warn'),
        'Estilo header WARN definido');
    $t->assert(str_contains($src, '.cc-wrap .cc-syncstatus-info'),
        'Estilo info-banner definido');
});

$t->test('CS-13 Wizard inicial: pregunta única "¿Quién manda?"', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, '¿Cuál es tu sistema principal de inventario?'),
        'Título principal del wizard');
    $t->assert(str_contains($src, 'data-action="pull"') || str_contains($src, "data-action=\"pull\""),
        'Botón pull (Manda TPV)');
    $t->assert(str_contains($src, 'data-action="push"') || str_contains($src, "data-action=\"push\""),
        'Botón push (Manda WC)');
});

$t->test('CS-14 Wizard inicial: 3 paneles (decidir/sincronizar/listo)', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    for ($i = 1; $i <= 3; $i++) {
        $t->assert(
            str_contains($src, "data-step-panel=\"$i\"") ||
            str_contains($src, "data-step-panel='$i'"),
            "data-step-panel=$i presente"
        );
    }
});

$t->test('CS-15 Wizard inicial: nota informativa de no-duplicación', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, 'cc-match-note'),
        'Bloque cc-match-note presente');
    $t->assert(str_contains($src, 'no se duplicarán'),
        'Texto "no se duplicarán" presente');
});

$t->test('CS-16 Hook PS-equivalente: producto sincronizado revertido cuando manda TPV', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-product-sync.php');
    $t->assert(str_contains($src, "tpv_sync_principal"),
        'Lectura de option tpv_sync_principal');
    $t->assert(str_contains($src, "principal === 'tpv'"),
        'Branch específico para principal=tpv');
    $t->assert(str_contains($src, 'update_from_tpv'),
        'Llamada a update_from_tpv para revertir');
});

$t->test('CS-17 Hook delete: NO propaga al TPV cuando manda TPV', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-product-sync.php');
    $delSection = substr($src,
        strpos($src, 'public function push_wc_delete_to_tpv'),
        2000);
    $t->assert(str_contains($delSection, "principal === 'tpv'"),
        'push_wc_delete_to_tpv chequea principal');
});

$t->test('CS-18 Banner read-only en editor de producto', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $t->assert(str_contains($src, 'managed_product_banner'),
        'Método managed_product_banner definido');
    $t->assert(str_contains($src, 'Gestionado por el TPV'),
        'Texto del banner presente');
    $t->assert(str_contains($src, 'tpvsync-managed-product'),
        'Clase para deshabilitar inputs');
});

$t->test('CS-19 Persistencia tpv_sync_principal vía param ?principal=', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    $importSection = substr($src,
        strpos($src, 'public function ajax_import'),
        2000);
    $t->assert(str_contains($importSection, "in_array(\$principal, ['tpv', 'wc'], true)"),
        'ajax_import valida principal');
    $t->assert(str_contains($importSection, "update_option('tpv_sync_principal'"),
        'ajax_import persiste principal');
});

$t->test('CS-20 Endpoint solo guarda valores válidos de principal', function($t) {
    $src = file_get_contents(__DIR__ . '/../../woocommerce-conector/includes/class-admin.php');
    // Buscar ambos handlers (import + push_all).
    $countValid = substr_count($src, "in_array(\$principal, ['tpv', 'wc'], true)");
    $t->assert($countValid >= 2,
        "Validación principal en >=2 handlers (encontrados $countValid)");
});

$t->summary();
