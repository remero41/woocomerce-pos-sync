<?php
declare(strict_types=1);

/**
 * Stubs mínimos de WordPress/WooCommerce para ejecutar tests del plugin
 * tpv-sync fuera de un WP bootstrap real. Simula el comportamiento que usa
 * el plugin: options, post_meta, transients, logger, cron, etc.
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('DAY_IN_SECONDS'))  define('DAY_IN_SECONDS', 86400);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

// ─── Estado global en memoria ────────────────────────────────────────────────
$GLOBALS['_stub_options']    = [];
$GLOBALS['_stub_transients'] = [];
$GLOBALS['_stub_postmeta']   = [];   // [post_id][meta_key] = value
$GLOBALS['_stub_posts']      = [];   // [post_id] => row
$GLOBALS['_stub_terms']      = [];
$GLOBALS['_stub_scheduled']  = [];
$GLOBALS['_stub_log']        = [];   // filas "tpv_sync_log"
$GLOBALS['_stub_hooks']      = [];
$GLOBALS['_stub_http_calls'] = [];   // registro de llamadas salientes

// ─── Options ─────────────────────────────────────────────────────────────────
function get_option($k, $d = false) { return $GLOBALS['_stub_options'][$k] ?? $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['_stub_options'][$k] = $v; return true; }
function add_option($k, $v) {
    if (!array_key_exists($k, $GLOBALS['_stub_options'])) $GLOBALS['_stub_options'][$k] = $v;
    return true;
}
function delete_option($k) { unset($GLOBALS['_stub_options'][$k]); return true; }

// ─── Transients ──────────────────────────────────────────────────────────────
function get_transient($k) { return $GLOBALS['_stub_transients'][$k]['v'] ?? false; }
function set_transient($k, $v, $ttl = 0) {
    $GLOBALS['_stub_transients'][$k] = ['v' => $v, 'exp' => time() + $ttl];
    return true;
}
function delete_transient($k) { unset($GLOBALS['_stub_transients'][$k]); return true; }

// ─── Post meta ───────────────────────────────────────────────────────────────
function get_post_meta($post_id, $key = '', $single = false) {
    if ($key === '') return $GLOBALS['_stub_postmeta'][$post_id] ?? [];
    $v = $GLOBALS['_stub_postmeta'][$post_id][$key] ?? '';
    return $single ? $v : ($v === '' ? [] : [$v]);
}
function update_post_meta($post_id, $key, $val) {
    $GLOBALS['_stub_postmeta'][$post_id][$key] = $val;
    return true;
}
function delete_post_meta($post_id, $key) {
    unset($GLOBALS['_stub_postmeta'][$post_id][$key]);
    return true;
}

// ─── Posts ───────────────────────────────────────────────────────────────────
function wp_insert_post($data) {
    $id = $data['ID'] ?? (max(array_keys($GLOBALS['_stub_posts'] ?: [0])) + 1);
    $GLOBALS['_stub_posts'][$id] = array_merge($GLOBALS['_stub_posts'][$id] ?? [], $data, ['ID' => $id]);
    return $id;
}
function wp_update_post($data) { return wp_insert_post($data); }
function get_posts($args = []) {
    $type     = $args['post_type']      ?? 'post';
    $statuses = (array)($args['post_status'] ?? ['publish']);
    $fields   = $args['fields']         ?? '';
    $limit    = (int)($args['posts_per_page'] ?? -1);
    $order    = strtoupper($args['order'] ?? 'DESC');

    $ids = [];
    foreach ($GLOBALS['_stub_posts'] as $pid => $p) {
        $pType   = is_object($p) ? ($p->post_type   ?? '') : ($p['post_type']   ?? '');
        $pStatus = is_object($p) ? ($p->post_status ?? '') : ($p['post_status'] ?? '');
        if ($pType !== $type) continue;
        if (!in_array($pStatus, $statuses, true)) continue;
        $ids[] = (int)$pid;
    }
    sort($ids);
    if ($order === 'DESC') $ids = array_reverse($ids);
    if ($limit > 0) $ids = array_slice($ids, 0, $limit);

    if ($fields === 'ids') return $ids;
    $rows = [];
    foreach ($ids as $id) $rows[] = $GLOBALS['_stub_posts'][$id];
    return $rows;
}
function wp_get_post_parent_id($post_id) { return (int)($GLOBALS['_stub_posts'][$post_id]['post_parent'] ?? 0); }
function wp_delete_post($id, $force = false) { unset($GLOBALS['_stub_posts'][$id]); return true; }
function clean_post_cache($id) { return true; }
function get_attached_media($type, $post_id) { return []; }
function set_post_thumbnail($post_id, $thumb_id) { return true; }
function has_post_thumbnail($post_id) { return false; }
function media_handle_sideload($file, $post_id) { return new WP_Error('no-media', 'stub'); }
function get_current_user_id() { return 1; }
if (!function_exists('get_locale'))           { function get_locale() { return $GLOBALS['_stub_locale'] ?? 'es_ES'; } }
if (!function_exists('wp_parse_url'))         { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('sanitize_text_field'))  { function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; } }
if (!function_exists('nocache_headers'))      { function nocache_headers() {} }
if (!function_exists('status_header'))        { function status_header($c) { $GLOBALS['_stub_http_status'] = (int)$c; } }

// Stubs user/term/WP helpers
if (!function_exists('sanitize_email')) {
    function sanitize_email($s) { return is_string($s) ? trim($s) : ''; }
}
if (!function_exists('sanitize_user')) {
    function sanitize_user($s, $strict = false) { return preg_replace('/[^a-z0-9_\-.]/i', '', (string)$s); }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        foreach ($GLOBALS['_stub_users'] ?? [] as $u) {
            if ($field === 'email' && ($u->user_email ?? '') === $value) return $u;
            if ($field === 'id' && ($u->ID ?? 0) === (int)$value) return $u;
        }
        return false;
    }
}
if (!function_exists('username_exists')) {
    function username_exists($login) {
        foreach ($GLOBALS['_stub_users'] ?? [] as $u) {
            if (($u->user_login ?? '') === $login) return $u->ID ?? 0;
        }
        return false;
    }
}
if (!function_exists('wp_insert_user')) {
    function wp_insert_user($data) {
        $id = (int)(max(array_keys($GLOBALS['_stub_users'] ?? [0])) + 1);
        $u = (object)(array_merge(['ID' => $id], $data));
        $GLOBALS['_stub_users'][$id] = $u;
        return $id;
    }
}
if (!function_exists('wp_update_user')) {
    function wp_update_user($data) {
        $id = $data['ID'] ?? 0;
        if (!$id || !isset($GLOBALS['_stub_users'][$id])) return new WP_Error('no_user', 'user not found');
        foreach ($data as $k => $v) $GLOBALS['_stub_users'][$id]->$k = $v;
        return $id;
    }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($uid, $key, $val) {
        $GLOBALS['_stub_usermeta'][$uid][$key] = $val;
        return true;
    }
}
if (!function_exists('delete_user_meta')) {
    function delete_user_meta($uid, $key) { unset($GLOBALS['_stub_usermeta'][$uid][$key]); return true; }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12, $special = true) { return bin2hex(random_bytes(max(1, (int)($len/2)))); }
}
if (!function_exists('wp_update_term')) {
    function wp_update_term($id, $tax, $args = []) { return ['term_id' => $id]; }
}
if (!function_exists('update_term_meta')) {
    function update_term_meta($id, $k, $v) { $GLOBALS['_stub_termmeta'][$id][$k] = $v; return true; }
}
if (!function_exists('wp_delete_term')) {
    function wp_delete_term($id, $tax) { return true; }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time();
    }
}

// ─── Taxonomies ──────────────────────────────────────────────────────────────
function wp_set_object_terms($id, $terms, $tax) { $GLOBALS['_stub_terms'][$id][$tax] = (array)$terms; return true; }
function has_term($term, $tax, $post_id) {
    $t = $GLOBALS['_stub_terms'][$post_id][$tax] ?? [];
    return in_array($term, $t, true);
}
function get_term_by($f, $v, $tax) { return false; }
function wp_insert_term($name, $tax) { return ['term_id' => crc32($tax . $name)]; }
function taxonomy_exists($tax) { return true; }
function register_taxonomy($tax, $obj, $args = []) { return true; }

// ─── Sanitización ────────────────────────────────────────────────────────────
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function sanitize_title($s) { return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$s)), '-'); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string)$s)); }
function wp_strip_all_tags($s) { return strip_tags((string)$s); }
function wp_kses_post($s) { return (string)$s; }
function esc_url_raw($s) { return (string)$s; }
function esc_url($s) { return (string)$s; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_html__($s, $d = '') { return $s; }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function __($s, $d = '') { return $s; }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }

// ─── Cron / hooks ────────────────────────────────────────────────────────────
function add_action($h, $cb, $pri = 10, $args = 1) { $GLOBALS['_stub_hooks'][$h][] = $cb; }
if (!function_exists('add_filter')) {
    function add_filter($h, $cb, $pri = 10, $args = 1) { $GLOBALS['_stub_filters'][$h][] = $cb; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($h, $value) {
        foreach ($GLOBALS['_stub_filters'][$h] ?? [] as $cb) $value = $cb($value);
        return $value;
    }
}
function do_action($h, ...$args) {
    foreach ($GLOBALS['_stub_hooks'][$h] ?? [] as $cb) { $cb(...$args); }
}
function wp_next_scheduled($h) { return $GLOBALS['_stub_scheduled'][$h] ?? false; }
function wp_schedule_event($ts, $freq, $h) { $GLOBALS['_stub_scheduled'][$h] = $ts; return true; }
function wp_schedule_single_event($ts, $h) { return true; }
function wp_clear_scheduled_hook($h) { unset($GLOBALS['_stub_scheduled'][$h]); return true; }

// ─── WC: transients y producto ───────────────────────────────────────────────
function wc_delete_product_transients($id) { return true; }
function wc_format_decimal($v) { return number_format((float)$v, 4, '.', ''); }
function wc_create_refund($args) {
    $id = (int)(mt_rand(100000, 999999));
    $GLOBALS['_stub_posts'][$id] = ['ID' => $id, 'post_type' => 'shop_order_refund'] + $args;
    return new WC_Order_Stub($id, $args);
}

class WC_Product {
    public int $id; public string $type; public $stock; public int $parent;
    public string $name;
    public string $sku = '';
    public $regular_price = null;
    public $price = null;
    public function __construct(int $id = 0, string $type = 'simple', $stock = 0, int $parent = 0, string $name = 'prod') {
        $this->id = $id; $this->type = $type; $this->stock = $stock; $this->parent = $parent; $this->name = $name;
    }
    public function get_id(): int { return $this->id; }
    public function get_type(): string { return $this->type; }
    public function is_type($t): bool { return $this->type === $t; }
    public function get_stock_quantity() { return $this->stock; }  // puede ser null
    public function get_parent_id(): int { return $this->parent; }
    public function get_name(): string { return $this->name; }
    public function get_sku(): string { return $this->sku; }
    public function set_sku($s): void { $this->sku = (string)$s; }
    public function get_regular_price() { return $this->regular_price; }
    public function save() { return $this->id; }
}

class WC_Product_Stub extends WC_Product {}

// wc_get_product stub: devuelve WC_Product_Stub si hay _stub_posts[id], si no null
if (!function_exists('wc_get_product')) {
    function wc_get_product($postId) {
        if (!isset($GLOBALS['_stub_posts'][$postId])) return null;
        $p = new WC_Product_Stub((int)$postId, 'simple', 0);
        // rellenar sku y precio desde meta si existen
        $sku = get_post_meta($postId, '_sku', true);
        if ($sku) $p->sku = (string)$sku;
        $rp = get_post_meta($postId, '_regular_price', true);
        if ($rp !== '') $p->regular_price = $rp;
        return $p;
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($s) {
        $s = strtolower((string)$s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }
}
if (!function_exists('get_post')) {
    function get_post($postId) {
        return $GLOBALS['_stub_posts'][$postId] ?? null;
    }
}

class WC_Order_Stub {
    public int $id;
    public array $data;
    public array $items;
    public function __construct(int $id, array $data = [], array $items = []) {
        $this->id = $id; $this->data = $data; $this->items = $items;
    }
    public function get_id(): int { return $this->id; }
    // En WC real, get_items($type) filtra por tipo: 'line_item'|'coupon'|'fee'...
    // Convención del stub: items con key 'type' filtrable, o array simple si no
    // se pide filtro (compat con tests previos que pasan items sin type).
    public function get_items($type = 'line_item') {
        if ($type === null || $type === '' || $type === false) return $this->items;
        // Si los items tienen type propio, filtramos; si no, comportamiento legacy:
        // devolver todos cuando pidan 'line_item' y vacío para otros tipos.
        $typed = [];
        $hasTyped = false;
        foreach ($this->items as $i) {
            if (is_object($i) && method_exists($i, 'get_type')) {
                $hasTyped = true;
                if ($i->get_type() === $type) $typed[] = $i;
            }
        }
        if ($hasTyped) return $typed;
        return $type === 'line_item' ? $this->items : [];
    }
    public function get_total() { return $this->data['total'] ?? 0; }
    public function get_billing_first_name() { return $this->data['first_name'] ?? ''; }
    public function get_billing_last_name() { return $this->data['last_name'] ?? ''; }
    public function get_billing_email() { return $this->data['email'] ?? ''; }
    public function get_billing_phone() { return $this->data['phone'] ?? ''; }
    public function get_payment_method_title() { return $this->data['pm'] ?? 'online'; }
    public function get_reason() { return $this->data['reason'] ?? ''; }
    public function add_order_note($n) { return true; }
    public function update_status($s, $note = '') { $this->data['status'] = $s; }
    public function get_customer_id(): int { return (int)($this->data['customer_id'] ?? 0); }
    public function get_billing_address_1(): string { return $this->data['billing_address_1'] ?? ''; }
    public function get_billing_address_2(): string { return $this->data['billing_address_2'] ?? ''; }
    public function get_billing_city(): string { return $this->data['billing_city'] ?? ''; }
    public function get_billing_postcode(): string { return $this->data['billing_postcode'] ?? ''; }
    public function get_billing_country(): string { return $this->data['billing_country'] ?? ''; }
    public function get_billing_state(): string { return $this->data['billing_state'] ?? ''; }
    public function get_billing_company(): string { return $this->data['billing_company'] ?? ''; }
    public function get_shipping_address_1(): string { return $this->data['shipping_address_1'] ?? ''; }
    public function get_shipping_address_2(): string { return $this->data['shipping_address_2'] ?? ''; }
    public function get_shipping_city(): string { return $this->data['shipping_city'] ?? ''; }
    public function get_shipping_postcode(): string { return $this->data['shipping_postcode'] ?? ''; }
    public function get_shipping_country(): string { return $this->data['shipping_country'] ?? ''; }
    public function get_shipping_state(): string { return $this->data['shipping_state'] ?? ''; }
    public function get_status(): string { return $this->data['status'] ?? 'pending'; }
    public function get_meta($k, $single = true) { return $this->data['meta'][$k] ?? ''; }
}

class WC_Product_Variable {
    public static function sync($id) { return true; }
}

function wc_get_order($id) {
    if (isset($GLOBALS['_stub_orders'][$id])) return $GLOBALS['_stub_orders'][$id];
    return false;
}
// (WC_Product ya definido arriba)

// ─── wpdb stub (solo lo que usa el plugin) ───────────────────────────────────
class WPDB_Stub {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';
    public array $inserts = [];
    public function prepare($sql, ...$args) {
        // WP real envuelve %s entre comillas simples
        $sql = str_replace('%s', "'%s'", $sql);
        return vsprintf($sql, $args);
    }
    public function get_var($sql) {
        // devuelve el post_id asociado a un _tpv_product_id o _tpv_option_value_id
        if (preg_match("/meta_key\s*=\s*'?_tpv_product_id'?\s*AND\s*meta_value\s*=\s*'?(\d+)'?/i", $sql, $m)) {
            return $this->findPostByMeta('_tpv_product_id', (int)$m[1]);
        }
        if (preg_match("/meta_key\s*=\s*'?_tpv_option_value_id'?\s*AND\s*meta_value\s*=\s*'?(\d+)'?/i", $sql, $m)) {
            return $this->findPostByMeta('_tpv_option_value_id', (int)$m[1]);
        }
        if (preg_match("/meta_key\s*=\s*'?_tpv_order_id'?\s*AND\s*meta_value\s*=\s*'?(\d+)'?/i", $sql, $m)) {
            return $this->findPostByMeta('_tpv_order_id', (int)$m[1]);
        }
        return null;
    }
    private function findPostByMeta($key, $val) {
        foreach ($GLOBALS['_stub_postmeta'] as $pid => $metas) {
            if (isset($metas[$key]) && (int)$metas[$key] === (int)$val) return $pid;
        }
        return null;
    }
    // Para tests externos
    public function findPostByMetaPublic($key, $val) { return $this->findPostByMeta($key, $val); }
    public function insert($table, $row) { $this->inserts[] = ['table' => $table, 'row' => $row]; return 1; }
    public function query($sql) { return 1; }
    public function get_results($sql) { return []; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();

// ─── HTTP (wp_remote_*) — mockeable ──────────────────────────────────────────
function wp_remote_get($url, $args = [])    { return _mock_http('GET', $url, $args); }
function wp_remote_post($url, $args = [])   { return _mock_http('POST', $url, $args); }
function wp_remote_request($url, $args = []) { return _mock_http($args['method'] ?? 'GET', $url, $args); }
function wp_remote_retrieve_body($r) { return is_wp_error($r) ? '' : ($r['body'] ?? ''); }
function wp_remote_retrieve_response_code($r) { return is_wp_error($r) ? 0 : ($r['code'] ?? 200); }
function wp_remote_retrieve_header($r, $h) { return $r['headers'][$h] ?? ''; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_tempnam($url) { return tempnam(sys_get_temp_dir(), 'tpv'); }

class WP_Error {
    public string $code; public string $message;
    public function __construct(string $c, string $m = '') { $this->code = $c; $this->message = $m; }
    public function get_error_message() { return $this->message; }
}

function _mock_http($method, $url, $args) {
    $GLOBALS['_stub_http_calls'][] = compact('method', 'url', 'args');
    // Por defecto devuelve éxito — los tests que quieran inspeccionar
    // se leen $_stub_http_calls.
    $responder = $GLOBALS['_stub_http_responder'] ?? null;
    if ($responder) return $responder($method, $url, $args);
    return ['code' => 200, 'body' => json_encode(['data' => []]), 'headers' => []];
}

function stub_http_respond_with(callable $fn) { $GLOBALS['_stub_http_responder'] = $fn; }
function stub_reset() {
    $GLOBALS['_stub_options']    = [];
    $GLOBALS['_stub_transients'] = [];
    $GLOBALS['_stub_postmeta']   = [];
    $GLOBALS['_stub_posts']      = [];
    $GLOBALS['_stub_terms']      = [];
    $GLOBALS['_stub_termmeta']   = [];
    $GLOBALS['_stub_users']      = [];
    $GLOBALS['_stub_usermeta']   = [];
    $GLOBALS['_stub_scheduled']  = [];
    $GLOBALS['_stub_log']        = [];
    $GLOBALS['_stub_hooks']      = [];
    $GLOBALS['_stub_http_calls'] = [];
    $GLOBALS['_stub_http_responder'] = null;
    $GLOBALS['_stub_orders']     = [];
    $GLOBALS['wpdb']             = new WPDB_Stub();
    $GLOBALS['tpv_sync_skip_wc_stock_push'] = false;
}

// Logger usado por class-api-client → wpdb->insert
// (ya cubierto por WPDB_Stub::insert)

// ─── Autoload stubs para clases del plugin ───────────────────────────────────
// El test carga directamente los archivos del plugin.
