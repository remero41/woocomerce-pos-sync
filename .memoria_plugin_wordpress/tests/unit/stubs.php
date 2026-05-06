<?php
declare(strict_types=1);

/**
 * Stubs in-memory compartidos por todos los tests unitarios.
 *
 * Se asegura que las clases existen UNA sola vez (class_exists guard).
 * Los tests individuales pueden require esto en su setUpBeforeClass.
 */

if (!defined('ABSPATH'))         define('ABSPATH', '/tmp/');
if (!defined('DAY_IN_SECONDS'))  define('DAY_IN_SECONDS', 86400);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

// AUTH_KEYs si no están (para SecretsTest)
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $c) {
    if (!defined($c)) define($c, "test-key-$c");
}

// Storage global para transients/options/posts
$GLOBALS['_test_transients'] ??= [];
$GLOBALS['_test_options']    ??= [];
$GLOBALS['_test_postmeta']   ??= [];

// ── Funciones WP mínimas (cada una con su propio guard) ──
if (!function_exists('get_transient')) {
    function get_transient(string $k) {
        $v = $GLOBALS['_test_transients'][$k] ?? false;
        return $v === false ? false : $v['v'];
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $k, $v, int $ttl = 0): bool {
        $GLOBALS['_test_transients'][$k] = ['v' => $v, 'exp' => time() + $ttl];
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $k): bool {
        unset($GLOBALS['_test_transients'][$k]);
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $k, $d = false) {
        return $GLOBALS['_test_options'][$k] ?? $d;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $k, $v, $a = null): bool {
        $GLOBALS['_test_options'][$k] = $v;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $k): bool {
        unset($GLOBALS['_test_options'][$k]);
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action(...$a): bool { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter(...$a): bool { return true; }
}
if (!function_exists('do_action')) {
    function do_action(...$a): void {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($t, $v, ...$a) { return $v; }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(...$a): bool { return true; }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(...$a): bool { return true; }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(...$a) { return false; }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(...$a): void {}
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, int $o = 0) { return json_encode($d, $o); }
}
if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql', bool $gmt = false) { return gmdate('Y-m-d H:i:s'); }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $id, string $key, bool $single = false) {
        $v = $GLOBALS['_test_postmeta'][$id][$key] ?? '';
        return $single ? $v : ($v === '' ? [] : [$v]);
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta(int $id, string $key, $val): bool {
        $GLOBALS['_test_postmeta'][$id][$key] = $val;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $id, string $key): bool {
        unset($GLOBALS['_test_postmeta'][$id][$key]);
        return true;
    }
}

// ── Clases del plugin con métodos mínimos ──
// Usar trait-like conditional. Si el test real del plugin ya está cargado,
// NO redeclarar (class_exists guard).
if (!class_exists('TPV_Sync_API_Client')) {
    class TPV_Sync_API_Client {
        public $patchResponse = ['data' => ['ok' => true]];
        public $getResponse   = [];
        public $postResponse  = ['data' => []];
        public array $calls = [];
        public function patch(string $path, array $body): array {
            $this->calls[] = ['patch', $path, $body];
            return $this->patchResponse;
        }
        public function get(string $path, array $params = []): array {
            $this->calls[] = ['get', $path, $params];
            return $this->getResponse;
        }
        public function post(string $path, array $body = [], ?string $idemKey = null): array {
            $this->calls[] = ['post', $path, $body];
            return $this->postResponse;
        }
    }
}

if (!class_exists('TPV_Sync_Product_Sync')) {
    class TPV_Sync_Product_Sync {
        const TPV_ID_META = '_tpv_product_id';
        public function push_wc_product_to_tpv($postOrId): bool { return true; }
        public function update_stock(int $tpvId, float $qty): void {}
        public function update_variant_stock(int $povId, float $qty): void {}
        public function update_from_tpv(int $tpvId): void {}
        public function delete_product(int $tpvId): void {}
    }
}

if (!class_exists('TPV_Sync_Order_Sync')) {
    class TPV_Sync_Order_Sync {
        const TPV_ORDER_META = '_tpv_order_id';
        public function send_to_tpv(int $id): void {}
        public function on_wc_refund(int $wcId, int $refundId): void {}
        public function update_wc_status(int $tpvOrderId, int $tpvStatusId): void {}
    }
}
