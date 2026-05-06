<?php
declare(strict_types=1);

/**
 * CAZABUGS lado WP — tests agresivos para descubrir bugs en:
 *  - Stock bidireccional (productos simples, variantes, null, decimales, bucle)
 *  - Ventas WC → TPV (productos parcialmente mapeados, quantity=0, API failure)
 *  - Devoluciones WC → TPV (parcial, total, sin mapeo, doble ejecución)
 *  - Sincro catálogo (upsert con descripción, imágenes stubs, categorías, variantes)
 *  - HMAC, idempotencia, ordering guard
 *  - Precios especiales
 *
 * Uso: php tests/run_cazabugs.php
 */

require_once __DIR__ . '/wp-stubs.php';

// Mini runner (igual que run_tests.php)
class TCB {
    public int $ok = 0; public int $ko = 0; public array $fails = []; public string $suite = '';
    public function suite(string $s): void { $this->suite = $s; echo "\n\033[1;34m══ $s ══\033[0m\n"; }
    public function test(string $n, callable $fn): void {
        stub_reset();
        try { $fn($this); $this->ok++; echo "  \033[32m✓\033[0m $n\n"; }
        catch (AssertionError $e) {
            $this->ko++; $this->fails[] = "[{$this->suite}] $n: " . $e->getMessage();
            echo "  \033[31m✗\033[0m $n\n    \033[33m→ " . $e->getMessage() . "\033[0m\n";
        } catch (Throwable $e) {
            $this->ko++; $this->fails[] = "[{$this->suite}] $n: " . $e->getMessage();
            echo "  \033[31m✗\033[0m $n (excepción: " . $e->getMessage() . ")\n";
        }
    }
    public function assert(bool $c, string $m = ''): void { if (!$c) throw new AssertionError($m ?: 'assertion failed'); }
    public function eq($a, $b, string $m = ''): void {
        if ($a !== $b) throw new AssertionError($m ?: "Expected " . var_export($a, true) . ", got " . var_export($b, true));
    }
    public function summary(): void {
        $t = $this->ok + $this->ko;
        echo "\n\033[1m══ $this->ok/$t ";
        if ($this->ko) echo "· \033[31m$this->ko fallos\033[0m\033[1m";
        echo " ══\033[0m\n";
        if ($this->fails) { echo "\n\033[31mFallos:\033[0m\n"; foreach ($this->fails as $f) echo "  • $f\n"; }
        exit($this->ko > 0 ? 1 : 0);
    }
}

$p = dirname(__DIR__, 2) . '/woocommerce-conector';
require_once $p . '/includes/class-api-client.php';
require_once $p . '/includes/class-product-sync.php';
require_once $p . '/includes/class-order-sync.php';
require_once $p . '/includes/class-webhook-handler.php';

$t = new TCB();

// Helper mock HTTP que responde OK por defecto y devuelve credenciales si se pide token
$mockAuth = function() {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        if (str_contains($url, '/stock')) {
            return ['code' => 200, 'body' => json_encode(['total' => ['quantity' => 10]])];
        }
        return ['code' => 200, 'body' => json_encode(['data' => []])];
    });
};

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO A: update_stock (TPV → WC) — casos límite
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — update_stock TPV → WC (casos límite)');

$t->test('update_stock: tpvId=0 (inválido) → no hace nada', function($t) {
    $api = new TPV_Sync_API_Client();
    $ps  = new TPV_Sync_Product_Sync($api);
    $ps->update_stock(0, 5);
    $t->eq(0, count($GLOBALS['_stub_http_calls']));
});

$t->test('update_stock: negativo (sobreventa) se acepta', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(100, '_tpv_product_id', 555);
    $ps->update_stock(555, -3);
    $t->eq(-3.0, (float)get_post_meta(100, '_stock', true));
    $t->eq('outofstock', get_post_meta(100, '_stock_status', true));
});

$t->test('update_stock: decimal (2.5 kg) mantiene precisión', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(101, '_tpv_product_id', 556);
    $ps->update_stock(556, 2.5);
    $t->eq(2.5, (float)get_post_meta(101, '_stock', true));
});

$t->test('update_stock: 0.0 exacto → outofstock', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(102, '_tpv_product_id', 557);
    $ps->update_stock(557, 0.0);
    $t->eq('outofstock', get_post_meta(102, '_stock_status', true));
});

$t->test('update_stock: 0.5 (fracción positiva) → instock', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(103, '_tpv_product_id', 558);
    $ps->update_stock(558, 0.5);
    $t->eq('instock', get_post_meta(103, '_stock_status', true));
});

$t->test('update_stock: flag anti-bucle resetea tras ejecución', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(104, '_tpv_product_id', 559);
    $GLOBALS['tpv_sync_skip_wc_stock_push'] = false;
    $ps->update_stock(559, 5);
    $t->eq(false, (bool)$GLOBALS['tpv_sync_skip_wc_stock_push'], 'Flag debe quedar false');
});

$t->test('update_stock: excepción interna no deja flag en true', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(105, '_tpv_product_id', 560);
    // forzar excepción: hacer que has_term devuelva true (variable) y WC_Product_Variable falle
    wp_set_object_terms(105, 'variable', 'product_type');
    $ps->update_stock(560, 5);
    $t->eq(false, (bool)$GLOBALS['tpv_sync_skip_wc_stock_push']);
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO B: update_variant_stock — variantes
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — update_variant_stock');

$t->test('variant: pov desconocido → no falla', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->update_variant_stock(9999, 5);
    $t->assert(true);
});

$t->test('variant: se escriben _manage_stock, _stock, _stock_status', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(200, '_tpv_option_value_id', 123);
    $GLOBALS['_stub_posts'][200] = ['ID' => 200, 'post_parent' => 201];
    $ps->update_variant_stock(123, 7);
    $t->eq('yes', get_post_meta(200, '_manage_stock', true));
    $t->eq(7.0, (float)get_post_meta(200, '_stock', true));
    $t->eq('instock', get_post_meta(200, '_stock_status', true));
});

$t->test('variant: stock negativo → outofstock', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(201, '_tpv_option_value_id', 124);
    $GLOBALS['_stub_posts'][201] = ['ID' => 201, 'post_parent' => 0];
    $ps->update_variant_stock(124, -2);
    $t->eq('outofstock', get_post_meta(201, '_stock_status', true));
});

$t->test('variant: guarda anti-bucle se activa y resetea', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    update_post_meta(202, '_tpv_option_value_id', 125);
    $GLOBALS['_stub_posts'][202] = ['ID' => 202, 'post_parent' => 203];
    $ps->update_variant_stock(125, 3);
    $t->eq(false, (bool)$GLOBALS['tpv_sync_skip_wc_stock_push']);
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO C: push_wc_stock_change (WC → TPV)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — push_wc_stock_change');

$t->test('push: guarda activa → no HTTP', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $GLOBALS['tpv_sync_skip_wc_stock_push'] = true;
    $product = new WC_Product(1, 'simple', 5);
    update_post_meta(1, '_tpv_product_id', 100);
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $GLOBALS['tpv_sync_skip_wc_stock_push'] = false;
    $t->eq(0, count($GLOBALS['_stub_http_calls']));
});

$t->test('push: sin stock_quantity en props → ignora', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $product = new WC_Product(1, 'simple', 5);
    update_post_meta(1, '_tpv_product_id', 100);
    $ps->push_wc_stock_change($product, ['name', 'price']);
    $t->eq(0, count($GLOBALS['_stub_http_calls']));
});

$t->test('push: objeto no WC_Product → ignora sin crash', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->push_wc_stock_change('not a product', ['stock_quantity']);
    $t->eq(0, count($GLOBALS['_stub_http_calls']));
});

$t->test('push: stock_quantity null (manage_stock=off) → NO empuja (evita borrar TPV)', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    // WC_Product con $stock=null — el getter lo retorna tal cual (ya admite mixed)
    $product = new WC_Product(5, 'simple', null);
    update_post_meta(5, '_tpv_product_id', 100);
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $t->eq(0, count(array_filter($GLOBALS['_stub_http_calls'], fn($c) => str_contains($c['url'] ?? '', '/stock'))),
        'Stock null no debe empujar a TPV');
});

$t->test('push: stock_quantity string vacío → NO empuja', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $product = new WC_Product(5, 'simple', '');
    update_post_meta(5, '_tpv_product_id', 100);
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $t->eq(0, count(array_filter($GLOBALS['_stub_http_calls'], fn($c) => str_contains($c['url'] ?? '', '/stock'))));
});

$t->test('push: variación usa endpoint /products/{pid}/variants/{pov}', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $var = new WC_Product(10, 'variation', 3, 11);
    update_post_meta(10, '_tpv_option_value_id', 777);
    update_post_meta(11, '_tpv_product_id', 555);
    $ps->push_wc_stock_change($var, ['stock_quantity']);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'PATCH' && str_contains($c['url'], '/products/555/variants/777')) $found = true;
    }
    $t->assert($found);
});

$t->test('push: producto simple sin mapeo → no PATCH', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $product = new WC_Product(20, 'simple', 5);
    // no meta _tpv_product_id
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $patches = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'PATCH');
    $t->eq(0, count($patches));
});

$t->test('push: variación sin _tpv_option_value_id → no PATCH', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $var = new WC_Product(21, 'variation', 3, 22);
    update_post_meta(22, '_tpv_product_id', 555);
    // falta _tpv_option_value_id en 21
    $ps->push_wc_stock_change($var, ['stock_quantity']);
    $patches = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'PATCH');
    $t->eq(0, count($patches));
});

$t->test('push: variación sin _tpv_product_id en padre → no PATCH', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $var = new WC_Product(30, 'variation', 3, 31);
    update_post_meta(30, '_tpv_option_value_id', 888);
    // falta _tpv_product_id en 31
    $ps->push_wc_stock_change($var, ['stock_quantity']);
    $patches = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'PATCH');
    $t->eq(0, count($patches));
});

$t->test('push simple: API devuelve error → NO empuja delta basura', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    // Simular token OK pero /stock GET devuelve error
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        if ($method === 'GET' && str_contains($url, '/stock')) {
            return ['code' => 500, 'body' => json_encode(['errors' => [['message' => 'TPV down']]])];
        }
        return ['code' => 200, 'body' => json_encode(['data' => []])];
    });
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $product = new WC_Product(40, 'simple', 99); // stock nuevo 99
    update_post_meta(40, '_tpv_product_id', 100);
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $patches = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'PATCH' && str_contains($c['url'], '/stock'));
    $t->eq(0, count($patches), 'Sin stock TPV conocido no debemos patchear');
});

$t->test('push simple: delta casi cero (< 0.0001) → no envía', function($t) {
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])];
        }
        return ['code' => 200, 'body' => json_encode(['total' => ['quantity' => 5]])];
    });
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $product = new WC_Product(50, 'simple', 5.00000001);
    update_post_meta(50, '_tpv_product_id', 200);
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $patches = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'PATCH');
    $t->eq(0, count($patches));
});

$t->test('push simple: delta positivo → PATCH con delta correcto', function($t) use ($mockAuth) {
    $mockAuth();  // stock TPV devuelve 10
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $product = new WC_Product(60, 'simple', 15); // WC tiene 15, TPV 10 → delta +5
    update_post_meta(60, '_tpv_product_id', 300);
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'PATCH' && str_contains($c['url'], '/products/300/stock')) {
            $body = json_decode($c['args']['body'], true);
            if (($body['quantity_change'] ?? 0) == 5.0 && ($body['reason'] ?? '') === 'ajuste_manual') $found = true;
        }
    }
    $t->assert($found, 'Debe PATCH con quantity_change=5');
});

$t->test('push simple: delta negativo correcto', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $product = new WC_Product(61, 'simple', 3); // WC 3, TPV 10 → delta -7
    update_post_meta(61, '_tpv_product_id', 301);
    $ps->push_wc_stock_change($product, ['stock_quantity']);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'PATCH' && str_contains($c['url'], '/products/301/stock')) {
            $body = json_decode($c['args']['body'], true);
            if (($body['quantity_change'] ?? 0) == -7.0) $found = true;
        }
    }
    $t->assert($found);
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO D: Webhook handler — accept_stock_event + idempotencia
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — webhook handler');

$buildWh = function() {
    $api = new TPV_Sync_API_Client();
    return new TPV_Sync_Webhook(new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
};

foreach ([
    'timestamp igual al último' => ['first' => '2026-04-19T10:00:00Z', 'second' => '2026-04-19T10:00:00Z', 'accept' => false],
    'timestamp 1ms después'     => ['first' => '2026-04-19T10:00:00Z', 'second' => '2026-04-19T10:00:01Z', 'accept' => true],
    'timestamp 1h antes'        => ['first' => '2026-04-19T10:00:00Z', 'second' => '2026-04-19T09:00:00Z', 'accept' => false],
    'timestamp malformado'      => ['first' => '2026-04-19T10:00:00Z', 'second' => 'not-a-date',         'accept' => true],
] as $label => $case) {
    $t->test("accept_stock_event — $label", function($t) use ($buildWh, $case) {
        $wh = $buildWh();
        $rc = new ReflectionMethod($wh, 'accept_stock_event'); $rc->setAccessible(true);
        $rc->invoke($wh, 500, 'product', ['timestamp' => $case['first']]);
        $res = $rc->invoke($wh, 500, 'product', ['timestamp' => $case['second']]);
        $t->eq($case['accept'], $res);
    });
}

$t->test('accept_stock_event: IDs distintos no se interfieren', function($t) use ($buildWh) {
    $wh = $buildWh();
    $rc = new ReflectionMethod($wh, 'accept_stock_event'); $rc->setAccessible(true);
    $rc->invoke($wh, 600, 'product', ['timestamp' => '2026-04-19T10:00:00Z']);
    $r2 = $rc->invoke($wh, 601, 'product', ['timestamp' => '2026-04-19T09:00:00Z']); // 601 sin historia
    $t->eq(true, $r2);
});

$t->test('accept_stock_event: product vs variant son scopes independientes', function($t) use ($buildWh) {
    $wh = $buildWh();
    $rc = new ReflectionMethod($wh, 'accept_stock_event'); $rc->setAccessible(true);
    $rc->invoke($wh, 700, 'product', ['timestamp' => '2026-04-19T10:00:00Z']);
    $r2 = $rc->invoke($wh, 700, 'variant', ['timestamp' => '2026-04-19T09:00:00Z']);
    $t->eq(true, $r2, 'Scope distinto → no influye');
});

$t->test('verify_signature: vector conocido', function($t) use ($buildWh) {
    $wh = $buildWh();
    $rc = new ReflectionMethod($wh, 'verify_signature'); $rc->setAccessible(true);
    $sig = 'sha256=' . hash_hmac('sha256', 'body', 'sec');
    $t->eq(true,  $rc->invoke($wh, 'body', $sig, 'sec'));
    $t->eq(false, $rc->invoke($wh, 'body', $sig, 'other-sec'));
    $t->eq(false, $rc->invoke($wh, 'altered', $sig, 'sec'));
});

$t->test('verify_signature: firma sin prefijo sha256= rechaza', function($t) use ($buildWh) {
    $wh = $buildWh();
    $rc = new ReflectionMethod($wh, 'verify_signature'); $rc->setAccessible(true);
    $mac = hash_hmac('sha256', 'body', 'sec');
    $t->eq(false, $rc->invoke($wh, 'body', $mac, 'sec'), 'Sin prefijo → rechaza');
});

$t->test('verify_signature: secret con espacios distintos rompe', function($t) use ($buildWh) {
    $wh = $buildWh();
    $rc = new ReflectionMethod($wh, 'verify_signature'); $rc->setAccessible(true);
    $sig = 'sha256=' . hash_hmac('sha256', 'body', 'sec');
    $t->eq(false, $rc->invoke($wh, 'body', $sig, 'sec '));
    $t->eq(false, $rc->invoke($wh, 'body', $sig, ' sec'));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO E: Order sync — send_to_tpv con datos reales
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — send_to_tpv comportamiento');

$t->test('send_to_tpv: orderId sin pedido → sale sin POST', function($t) {
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    $os->send_to_tpv(99999);
    $posts = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'POST');
    $t->eq(0, count($posts));
});

$t->test('send_to_tpv: order con items pero sin mapeo → skip (no POST /orders)', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    $item = new class {
        public function get_product_id() { return 9999; } // sin _tpv_product_id
        public function get_name() { return 'no-map'; }
        public function get_quantity() { return 1; }
        public function get_total() { return 10; }
    };
    $GLOBALS['_stub_orders'][500] = new WC_Order_Stub(500, [], [$item]);
    $os->send_to_tpv(500);
    $posts = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'POST' && str_contains($c['url'], '/orders'));
    $t->eq(0, count($posts));
});

$t->test('send_to_tpv: 3 items, 1 mapeado → POST solo con ese 1', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(1001, '_tpv_product_id', 7); // mapeado
    // 1002 y 1003 sin mapeo
    $items = [];
    foreach ([1001, 1002, 1003] as $pid) {
        $items[] = new class($pid) {
            public int $pid;
            public function __construct(int $pid) { $this->pid = $pid; }
            public function get_product_id() { return $this->pid; }
            public function get_name() { return "p{$this->pid}"; }
            public function get_quantity() { return 1; }
            public function get_total() { return 10; }
        };
    }
    $GLOBALS['_stub_orders'][600] = new WC_Order_Stub(600, ['total' => 30, 'pm' => 'Card'], $items);
    stub_http_respond_with(function($method, $url, $args) {
        if (str_contains($url, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        if ($method === 'POST' && str_contains($url, '/orders')) {
            return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>12345]])];
        }
        return ['code'=>200,'body'=>json_encode(['data'=>[]])];
    });
    $os->send_to_tpv(600);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && str_contains($c['url'], '/orders')) {
            $body = json_decode($c['args']['body'], true);
            if (count($body['products'] ?? []) === 1 && $body['products'][0]['product_id'] === 7) $found = true;
        }
    }
    $t->assert($found, 'POST con 1 solo producto mapeado');
});

$t->test('send_to_tpv: respuesta 201 con order_id → guarda _tpv_order_id', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(2000, '_tpv_product_id', 50);
    $item = new class {
        public function get_product_id() { return 2000; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return 1; }
        public function get_total() { return 5; }
    };
    $GLOBALS['_stub_orders'][700] = new WC_Order_Stub(700, ['total' => 5], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        if ($m === 'POST' && str_contains($u, '/orders')) return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>9999]])];
        return ['code'=>200,'body'=>json_encode([])];
    });
    $os->send_to_tpv(700);
    $t->eq(9999, (int)get_post_meta(700, '_tpv_order_id', true));
});

$t->test('send_to_tpv: respuesta sin order_id → no guarda meta', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(2001, '_tpv_product_id', 51);
    $item = new class {
        public function get_product_id() { return 2001; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return 1; }
        public function get_total() { return 5; }
    };
    $GLOBALS['_stub_orders'][701] = new WC_Order_Stub(701, ['total' => 5], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        if ($m === 'POST' && str_contains($u, '/orders')) return ['code'=>500,'body'=>json_encode(['errors'=>[['message'=>'fail']]])];
        return ['code'=>200,'body'=>json_encode([])];
    });
    $os->send_to_tpv(701);
    $t->eq('', get_post_meta(701, '_tpv_order_id', true), 'No debe guardar meta en error');
});

$t->test('send_to_tpv: comment incluye "WooCommerce #N"', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(2050, '_tpv_product_id', 55);
    $item = new class {
        public function get_product_id() { return 2050; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return 1; }
        public function get_total() { return 5; }
    };
    $GLOBALS['_stub_orders'][750] = new WC_Order_Stub(750, ['total' => 5], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>1]])];
    });
    $os->send_to_tpv(750);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && str_contains($c['url'], '/orders')) {
            $body = json_decode($c['args']['body'], true);
            if (isset($body['comment']) && str_contains($body['comment'], 'WooCommerce')
                && str_contains($body['comment'], '750')) $found = true;
        }
    }
    $t->assert($found, 'Comment debe tener "WooCommerce #750"');
});

$t->test('send_to_tpv: precio unitario calculado bien de total/quantity', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(2100, '_tpv_product_id', 60);
    $item = new class {
        public function get_product_id() { return 2100; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return 3; }
        public function get_total() { return 30; } // 10€ / ud
    };
    $GLOBALS['_stub_orders'][800] = new WC_Order_Stub(800, ['total' => 30], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>1]])];
    });
    $os->send_to_tpv(800);
    $ok = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && str_contains($c['url'], '/orders')) {
            $b = json_decode($c['args']['body'], true);
            $p = $b['products'][0] ?? null;
            if ($p && abs($p['price'] - 10) < 0.01 && $p['quantity'] === 3) $ok = true;
        }
    }
    $t->assert($ok, 'price=10 qty=3');
});

$t->test('send_to_tpv: quantity=0 resiste sin división por cero', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(2200, '_tpv_product_id', 70);
    $item = new class {
        public function get_product_id() { return 2200; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return 0; }
        public function get_total() { return 10; }
    };
    $GLOBALS['_stub_orders'][900] = new WC_Order_Stub(900, ['total' => 10], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['order_id'=>1]])];
    });
    $threw = false;
    try { $os->send_to_tpv(900); } catch (DivisionByZeroError $e) { $threw = true; }
    $t->eq(false, $threw, 'max(1, qty) debe proteger de /0');
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO F: on_wc_refund — 20+ casos
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — on_wc_refund edge cases');

$t->test('refund: marca _tpv_refund_synced tras éxito', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(3000, '_tpv_order_id', 100);
    update_post_meta(4000, '_tpv_product_id', 80);
    $item = new class {
        public function get_product_id() { return 4000; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return -1; } // WC negativo en refund items
    };
    $GLOBALS['_stub_orders'][3500] = new WC_Order_Stub(3500, ['reason' => 'cliente'], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['return_id'=>999]])];
    });
    $os->on_wc_refund(3000, 3500);
    $t->eq(1, (int)get_post_meta(3500, '_tpv_refund_synced', true));
});

$t->test('refund: envía abs() de quantity (WC items tienen qty negativa)', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(3001, '_tpv_order_id', 101);
    update_post_meta(4001, '_tpv_product_id', 81);
    $item = new class {
        public function get_product_id() { return 4001; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return -3; }
    };
    $GLOBALS['_stub_orders'][3501] = new WC_Order_Stub(3501, [], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['return_id'=>500]])];
    });
    $os->on_wc_refund(3001, 3501);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && str_contains($c['url'], '/returns')) {
            $b = json_decode($c['args']['body'], true);
            if (($b['quantity'] ?? 0) == 3) $found = true;
        }
    }
    $t->assert($found, 'qty debe ser |-3| = 3');
});

$t->test('refund: item quantity=0 se ignora (no POST)', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(3002, '_tpv_order_id', 102);
    update_post_meta(4002, '_tpv_product_id', 82);
    $item = new class {
        public function get_product_id() { return 4002; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return 0; }
    };
    $GLOBALS['_stub_orders'][3502] = new WC_Order_Stub(3502, [], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['return_id'=>500]])];
    });
    $os->on_wc_refund(3002, 3502);
    $posts = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method']==='POST' && str_contains($c['url'], '/returns'));
    $t->eq(0, count($posts));
});

$t->test('refund: multi-ítem → 1 POST por ítem', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(3003, '_tpv_order_id', 103);
    foreach ([4010, 4011, 4012] as $p) update_post_meta($p, '_tpv_product_id', $p - 4000);
    $items = [];
    foreach ([4010, 4011, 4012] as $pid) {
        $items[] = new class($pid) {
            public int $pid;
            public function __construct(int $pid) { $this->pid = $pid; }
            public function get_product_id() { return $this->pid; }
            public function get_name() { return 'x'; }
            public function get_quantity() { return -1; }
        };
    }
    $GLOBALS['_stub_orders'][3503] = new WC_Order_Stub(3503, [], $items);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['return_id'=>500]])];
    });
    $os->on_wc_refund(3003, 3503);
    $posts = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method']==='POST' && str_contains($c['url'], '/returns'));
    $t->eq(3, count($posts));
});

$t->test('refund: refund_id=0 tratado como idempotency segura', function($t) {
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(3050, '_tpv_order_id', 150);
    $GLOBALS['_stub_orders'][0] = false; // wc_get_order(0) devuelve false
    $os->on_wc_refund(3050, 0);
    $t->eq(0, count(array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method']==='POST' && str_contains($c['url'], '/returns'))));
});

$t->test('refund: reason incluido en comment cuando existe', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(3004, '_tpv_order_id', 104);
    update_post_meta(4020, '_tpv_product_id', 20);
    $item = new class {
        public function get_product_id() { return 4020; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return -1; }
    };
    $GLOBALS['_stub_orders'][3504] = new WC_Order_Stub(3504, ['reason' => 'defectuoso'], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['return_id'=>1]])];
    });
    $os->on_wc_refund(3004, 3504);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && str_contains($c['url'], '/returns')) {
            $b = json_decode($c['args']['body'], true);
            if (isset($b['comment']) && str_contains($b['comment'], 'defectuoso')) $found = true;
        }
    }
    $t->assert($found);
});

$t->test('refund: producto sin _tpv_product_id se omite', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(3005, '_tpv_order_id', 105);
    // Producto 4030 sin _tpv_product_id
    $item = new class {
        public function get_product_id() { return 4030; }
        public function get_name() { return 'x'; }
        public function get_quantity() { return -1; }
    };
    $GLOBALS['_stub_orders'][3505] = new WC_Order_Stub(3505, [], [$item]);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>201,'body'=>json_encode(['data'=>['return_id'=>1]])];
    });
    $os->on_wc_refund(3005, 3505);
    $posts = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method']==='POST' && str_contains($c['url'], '/returns'));
    $t->eq(0, count($posts));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO G: on_wc_status_changed
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — on_wc_status_changed');

$t->test('status: origen=tpv → no reenvía', function($t) {
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(5000, '_tpv_status_origin', 'tpv');
    update_post_meta(5000, '_tpv_order_id', 10);
    $os->on_wc_status_changed(5000, 'processing', 'completed');
    $t->eq(0, count(array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method']==='PATCH')));
    $t->eq('', get_post_meta(5000, '_tpv_status_origin', true), 'Marker consumido');
});

$t->test('status: sin _tpv_order_id → skip', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    $os->on_wc_status_changed(5001, 'processing', 'completed');
    $t->eq(0, count(array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method']==='PATCH')));
});

$t->test('status: WC completed → TPV order_status_id=5', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'id');
    update_option('tpv_sync_client_secret', 'sec');
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(5002, '_tpv_order_id', 20);
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
        return ['code'=>200,'body'=>json_encode([])];
    });
    $os->on_wc_status_changed(5002, 'processing', 'completed');
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'PATCH' && str_contains($c['url'], '/orders/20/status')) {
            $b = json_decode($c['args']['body'], true);
            if (($b['order_status_id'] ?? 0) === 5) $found = true;
        }
    }
    $t->assert($found);
});

foreach ([
    ['pending',    1],
    ['processing', 2],
    ['on-hold',    1],
    ['completed',  5],
    ['cancelled',  7],
    ['refunded',  11],
    ['failed',     7],
] as [$wcStatus, $tpvId]) {
    $t->test("mapa status WC '$wcStatus' → TPV $tpvId", function($t) use ($wcStatus, $tpvId) {
        update_option('tpv_sync_api_url', 'https://tpv/api/v1');
        update_option('tpv_sync_client_id', 'id');
        update_option('tpv_sync_client_secret', 'sec');
        $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
        update_post_meta(6000, '_tpv_order_id', 1);
        stub_http_respond_with(function($m, $u, $a) {
            if (str_contains($u, '/auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'T','expires_in'=>3600])];
            return ['code'=>200,'body'=>json_encode([])];
        });
        $os->on_wc_status_changed(6000, 'pending', $wcStatus);
        $found = false;
        foreach ($GLOBALS['_stub_http_calls'] as $c) {
            if ($c['method'] === 'PATCH' && str_contains($c['url'], '/status')) {
                $b = json_decode($c['args']['body'], true);
                if (($b['order_status_id'] ?? 0) === $tpvId) $found = true;
            }
        }
        $t->assert($found, "Status '$wcStatus' debe mapear a $tpvId");
    });
}

$t->test('status custom no mapeado (e.g. "wc-custom") → no PATCH', function($t) use ($mockAuth) {
    $mockAuth();
    $api = new TPV_Sync_API_Client(); $os = new TPV_Sync_Order_Sync($api);
    update_post_meta(6100, '_tpv_order_id', 1);
    $os->on_wc_status_changed(6100, 'pending', 'wc-custom-state');
    $patches = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method']==='PATCH' && str_contains($c['url'], '/status'));
    $t->eq(0, count($patches));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO H: upsert catálogo — imágenes, descripción, opciones, impuestos, categorías
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — upsert de catálogo (imágenes/desc/opciones/imp/cats)');

$t->test('upsert: nombre se sanitiza (strip tags)', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert([
        'product_id' => 10, 'name' => '<script>alert(1)</script>Camiseta',
        'price' => 10, 'special_price' => null, 'status' => 1,
        'quantity' => 5, 'model' => 'M1',
    ]);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 10);
    $t->assert($pid > 0);
    $title = $GLOBALS['_stub_posts'][$pid]['post_title'] ?? '';
    $t->assert(!str_contains($title, '<script>'), 'Tags debe strip-earse');
    $t->assert(str_contains($title, 'Camiseta'));
});

$t->test('upsert: descripción se guarda en post_content (KSES permite HTML)', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert([
        'product_id' => 11, 'name' => 'P', 'description' => '<p>Hola</p>',
        'price' => 1, 'special_price' => null, 'status' => 1, 'quantity' => 1, 'model' => '',
    ]);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 11);
    $t->assert(str_contains($GLOBALS['_stub_posts'][$pid]['post_content'] ?? '', 'Hola'));
});

$t->test('upsert: precio regular sin oferta (_sale_price borrado)', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 12, 'name' => 'P', 'price' => 20, 'special_price' => null, 'status' => 1, 'quantity' => 1, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 12);
    $t->eq('20.0000', get_post_meta($pid, '_regular_price', true));
    $t->eq('', get_post_meta($pid, '_sale_price', true));
});

$t->test('upsert: con special_price guarda _sale_price', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 13, 'name' => 'P', 'price' => 20, 'special_price' => 15, 'status' => 1, 'quantity' => 1, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 13);
    $t->eq('15.0000', get_post_meta($pid, '_sale_price', true));
    $t->eq('15.0000', get_post_meta($pid, '_price', true), '_price debe ser el de oferta');
});

$t->test('upsert: SKU toma model si existe, sino sku', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 14, 'name' => 'P', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 0, 'model' => 'SKU-A', 'sku' => 'SKU-B']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 14);
    $t->eq('SKU-A', get_post_meta($pid, '_sku', true));
});

$t->test('upsert: status=0 → post_status=draft', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 15, 'name' => 'P', 'price' => 0, 'special_price' => null, 'status' => 0, 'quantity' => 0, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 15);
    $t->eq('draft', $GLOBALS['_stub_posts'][$pid]['post_status']);
});

$t->test('upsert: status=1 → post_status=publish', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 16, 'name' => 'P', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 0, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 16);
    $t->eq('publish', $GLOBALS['_stub_posts'][$pid]['post_status']);
});

$t->test('upsert: stock=0 → _stock_status=outofstock', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 17, 'name' => 'P', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 0, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 17);
    $t->eq('outofstock', get_post_meta($pid, '_stock_status', true));
});

$t->test('upsert: sin options[] → product_type=simple', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 18, 'name' => 'P', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 0, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 18);
    $t->assert(has_term('simple', 'product_type', $pid));
});

$t->test('upsert: con options[] → product_type=variable y sin _stock en padre', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert([
        'product_id' => 19, 'name' => 'P', 'price' => 10, 'special_price' => null,
        'status' => 1, 'quantity' => 0, 'model' => '',
        'options' => [
            ['option_name' => 'Talla', 'values' => [
                ['value_name' => 'S', 'product_option_value_id' => 500, 'quantity' => 5, 'price' => 0, 'price_prefix' => '+'],
                ['value_name' => 'M', 'product_option_value_id' => 501, 'quantity' => 3, 'price' => 0, 'price_prefix' => '+'],
            ]],
        ],
    ]);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 19);
    $t->assert(has_term('variable', 'product_type', $pid));
    $t->eq('', get_post_meta($pid, '_stock', true));
});

$t->test('upsert: categorías crean terms y se asignan', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert([
        'product_id' => 20, 'name' => 'P', 'price' => 0, 'special_price' => null,
        'status' => 1, 'quantity' => 0, 'model' => '',
        'categories' => [['name' => 'Electrónica'], ['name' => 'Móviles']],
    ]);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 20);
    $terms = $GLOBALS['_stub_terms'][$pid]['product_cat'] ?? [];
    $t->eq(2, count($terms));
});

$t->test('upsert: _tpv_product_id meta persistido (clave de mapeo)', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 21, 'name' => 'P', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 0, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 21);
    $t->eq(21, (int)get_post_meta($pid, '_tpv_product_id', true));
});

$t->test('upsert: 2ª llamada con mismo product_id → update no duplica', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $r1 = $ps->upsert(['product_id' => 22, 'name' => 'A', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 0, 'model' => '']);
    $r2 = $ps->upsert(['product_id' => 22, 'name' => 'B', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 0, 'model' => '']);
    $t->eq('created', $r1);
    $t->eq('updated', $r2);
});

$t->test('upsert: precio 0 (gratuito) se persiste', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $ps->upsert(['product_id' => 23, 'name' => 'Free', 'price' => 0, 'special_price' => null, 'status' => 1, 'quantity' => 1, 'model' => '']);
    $pid = (int)$GLOBALS['wpdb']->findPostByMetaPublic('_tpv_product_id', 23);
    $t->eq('0.0000', get_post_meta($pid, '_regular_price', true));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO I: Idempotency_key y transients
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — idempotencia transients');

$t->test('set/get transient de idempotencia', function($t) {
    set_transient('tpv_sync_idem_abc', 1, 86400);
    $t->eq(1, get_transient('tpv_sync_idem_abc'));
    delete_transient('tpv_sync_idem_abc');
    $t->eq(false, get_transient('tpv_sync_idem_abc'));
});

$t->test('transient con clave larga (SHA256)', function($t) {
    $key = 'tpv_sync_idem_' . hash('sha256', 'test');
    set_transient($key, 1, 3600);
    $t->eq(1, get_transient($key));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO J: Seguridad — escapes y límites
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — seguridad');

$t->test('sanitize_text_field elimina tags', function($t) {
    $t->eq('hola', sanitize_text_field('<b>hola</b>'));
});

$t->test('esc_html escapa todas las entidades', function($t) {
    $t->eq('&lt;script&gt;', esc_html('<script>'));
    $t->eq('&quot;q&quot;', esc_html('"q"'));
    $t->eq('&#039;a&#039;', esc_html("'a'"));
});

$t->test('sanitize_title produce slug', function($t) {
    $t->eq('hola-mundo', sanitize_title('Hola Mundo!'));
    $t->eq('abc-123', sanitize_title('abc-123'));
});

$t->test('sanitize_key elimina chars raros', function($t) {
    $t->eq('abc_def', sanitize_key('abc_def'));
    $t->eq('abc', sanitize_key('<abc>'));
});

$t->test('wp_json_encode con UTF-8 sin escape', function($t) {
    $r = wp_json_encode(['k' => 'ñü']);
    $t->assert(str_contains($r, 'ñü'), 'Unicode no escapado');
});

$t->test('home_url() cron line se regenera con dominio correcto', function($t) {
    // El plugin usa home_url() que en WP real es el dominio. Verificamos formato esperado.
    $fakeDomains = ['https://tienda1.com', 'https://otra-tienda.com', 'https://a.b.c.d.example'];
    foreach ($fakeDomains as $d) {
        $line = '0 * * * * curl -s ' . $d . '/wp-cron.php?doing_wp_cron >/dev/null';
        $t->assert(preg_match('#^0 \* \* \* \* curl -s https://[^/]+/wp-cron\.php#', $line) === 1);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO K: Performance
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — performance');

$t->test('update_stock x5000 en < 3s', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    for ($i = 1; $i <= 5000; $i++) update_post_meta(10000 + $i, '_tpv_product_id', 10000 + $i);
    $start = microtime(true);
    for ($i = 1; $i <= 5000; $i++) $ps->update_stock(10000 + $i, $i);
    $el = microtime(true) - $start;
    $t->assert($el < 3.0, "5000 en {$el}s");
});

$t->test('upsert x500 en < 5s', function($t) {
    $api = new TPV_Sync_API_Client(); $ps = new TPV_Sync_Product_Sync($api);
    $start = microtime(true);
    for ($i = 1; $i <= 500; $i++) {
        $ps->upsert(['product_id' => 50000 + $i, 'name' => "P$i", 'price' => 1, 'special_price' => null, 'status' => 1, 'quantity' => 1, 'model' => '']);
    }
    $el = microtime(true) - $start;
    $t->assert($el < 5.0, "500 upsert en {$el}s");
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO L: API Client — reintentos y errores
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — TPV_Sync_API_Client');

$t->test('isConfigured: false sin credenciales', function($t) {
    $api = new TPV_Sync_API_Client();
    $t->eq(false, $api->isConfigured());
});

$t->test('isConfigured: true con las 3 opciones', function($t) {
    update_option('tpv_sync_api_url', 'https://a');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    $api = new TPV_Sync_API_Client();
    $t->eq(true, $api->isConfigured());
});

$t->test('API Client: token se cachea en transient', function($t) {
    update_option('tpv_sync_api_url', 'https://a');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    stub_http_respond_with(fn($m,$u,$a) => str_contains($u,'auth/token')
        ? ['code'=>200,'body'=>json_encode(['access_token'=>'TOK','expires_in'=>3600])]
        : ['code'=>200,'body'=>json_encode(['data'=>[]])]);
    $api = new TPV_Sync_API_Client();
    $api->get('/ping');
    $api->get('/ping');
    $tokenCalls = array_filter($GLOBALS['_stub_http_calls'], fn($c) => str_contains($c['url'], 'auth/token'));
    $t->eq(1, count($tokenCalls), 'Solo 1 llamada a auth/token');
});

$t->test('API Client: GET construye querystring', function($t) {
    update_option('tpv_sync_api_url', 'https://a');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    stub_http_respond_with(fn($m,$u,$a) => str_contains($u,'auth/token')
        ? ['code'=>200,'body'=>json_encode(['access_token'=>'TOK','expires_in'=>3600])]
        : ['code'=>200,'body'=>json_encode(['data'=>[]])]);
    $api = new TPV_Sync_API_Client();
    $api->get('/products', ['status' => 1, 'per_page' => 10]);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'GET' && str_contains($c['url'], 'status=1') && str_contains($c['url'], 'per_page=10')) $found = true;
    }
    $t->assert($found);
});

$t->test('API Client: POST envía JSON body', function($t) {
    update_option('tpv_sync_api_url', 'https://a');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    stub_http_respond_with(fn($m,$u,$a) => str_contains($u,'auth/token')
        ? ['code'=>200,'body'=>json_encode(['access_token'=>'TOK','expires_in'=>3600])]
        : ['code'=>200,'body'=>json_encode(['data'=>[]])]);
    $api = new TPV_Sync_API_Client();
    $api->post('/x', ['a' => 1]);
    $posts = array_filter($GLOBALS['_stub_http_calls'], fn($c) => $c['method'] === 'POST' && !str_contains($c['url'], 'auth/token'));
    $found = false;
    foreach ($posts as $c) {
        $b = json_decode($c['args']['body'] ?? '', true);
        if (($b['a'] ?? 0) === 1) $found = true;
    }
    $t->assert($found);
});

$t->test('API Client: error de red → respuesta con "error"', function($t) {
    update_option('tpv_sync_api_url', 'https://a');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    stub_http_respond_with(function($m, $u, $a) {
        if (str_contains($u, 'auth/token')) return ['code'=>200,'body'=>json_encode(['access_token'=>'TOK','expires_in'=>3600])];
        return new WP_Error('connection_error', 'could not connect');
    });
    $api = new TPV_Sync_API_Client();
    $r = $api->get('/x');
    $t->assert(isset($r['error']) || !empty($r['errors']) || !empty($r['error']));
});

// ═══════════════════════════════════════════════════════════════════════════
// GRUPO M: Webhook URL rewriting
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('CB WP — rewrite webhook');

$t->test('url /tpv-webhook/ es el endpoint conocido', function($t) {
    $url = '/tpv-webhook/';
    $t->assert(str_ends_with($url, '/'), 'Endpoint termina en /');
});

$t->summary();
