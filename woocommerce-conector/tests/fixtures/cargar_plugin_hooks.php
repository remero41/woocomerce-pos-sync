<?php
/**
 * Carga el plugin entero con los stubs mínimos de WordPress y vuelca, en JSON,
 * qué callbacks registra en cada hook: {hook: ["Clase::metodo", "closure", ...]}.
 *
 * Lo usa test_pedido_estado_manual.php para reproducir, hook a hook, lo que
 * WooCommerce dispara al cambiar de estado un pedido y ver si el plugin lo oye.
 * Va en subproceso porque define funciones globales de WP que chocarían con
 * los stubs de run.php.
 */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
// Las tablas propias ya «existen»: si no, el plugin tira de dbDelta (wp-admin).
$GLOBALS['__opt'] = [
    'tpv_sync_idem_table_v1'     => '1',
    'tpv_sync_dlq_table_v1'      => '1',
    'tpv_sync_version_instalada' => 'x',
];
$GLOBALS['__acciones'] = [];

$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wp_';
    public function __call($n, $a) { return null; }
};

function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'https://t/'; }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function get_option($k, $d = false) { return $GLOBALS['__opt'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opt'][$k] = $v; return true; }
function add_option($k, $v = '', $d = '', $a = 'yes') { $GLOBALS['__opt'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opt'][$k]); return true; }
function add_action($h, $f, $p = 10, $a = 1) { $GLOBALS['__acciones'][$h][] = $f; return true; }
function add_filter($h, $f, $p = 10, $a = 1) { return true; }
function apply_filters($h, $v, ...$a) { return $v; }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function register_uninstall_hook($f, $cb) {}
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($t, $rec, $h) { return true; }
function wp_clear_scheduled_hook($h) { return 0; }
function is_admin() { return false; }
function __($t, $d = null) { return $t; }
function get_query_var($v) { return ''; }
function flush_rewrite_rules() {}
// Lo que toca plugins_loaded al enganchar (TPV_Sync::instance() y lo demás).
function load_plugin_textdomain($d, $x = false, $p = '') { return true; }
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function delete_transient($k) { return true; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function is_wp_error($x) { return false; }
function current_user_can($c) { return false; }
function add_shortcode($t, $cb) {}
function register_rest_route($ns, $r, $a = []) { return true; }
function add_rewrite_rule($r, $q, $a = 'bottom') {}
function wp_schedule_single_event($t, $h, $a = []) { return true; }
function wp_unschedule_event($t, $h, $a = []) { return true; }
function sanitize_text_field($s) { return (string) $s; }
function esc_html($s) { return (string) $s; }
function esc_attr($s) { return (string) $s; }
function admin_url($p = '') { return 'https://t/wp-admin/' . $p; }
function home_url($p = '') { return 'https://t' . $p; }
function site_url($p = '') { return 'https://t' . $p; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }

// Sin WooCommerce el plugin no engancha nada (plugins_loaded: class_exists('WooCommerce')).
class WooCommerce {}

require dirname(__DIR__, 2) . '/woocommerce-conector.php';

// Lo que WordPress hace después: plugins_loaded, donde el plugin engancha todo.
foreach ($GLOBALS['__acciones']['plugins_loaded'] ?? [] as $cb) {
    try { $cb(); } catch (Throwable $e) {
        // Se avisa por stderr: un stub que falte deja el JSON vacío y el test
        // en rojo sin decir por qué.
        fwrite(STDERR, 'plugins_loaded: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    }
}

$nombre = static function ($f): string {
    if ($f instanceof Closure) return 'closure';
    if (is_array($f)) return (is_object($f[0]) ? get_class($f[0]) : (string) $f[0]) . '::' . $f[1];
    return (string) $f;
};
$salida = [];
foreach ($GLOBALS['__acciones'] as $hook => $cbs) {
    $salida[$hook] = array_map($nombre, $cbs);
}
echo json_encode(['acciones' => $salida]);
