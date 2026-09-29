<?php
/**
 * Carga woocommerce-conector.php con un WordPress mínimo que GRABA lo que el
 * plugin registra (acciones, filtros, eventos de cron, limpieza al
 * desactivar) y lo imprime en JSON. Lo usa test_cron_cableado.php en un
 * proceso aparte: el fichero principal define clases y constantes que no
 * pueden convivir con el runner.
 */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
$GLOBALS['__opt'] = ['tpv_sync_idem_table_v1' => '1', 'tpv_sync_version_instalada' => 'x'];
$GLOBALS['__rec'] = ['acciones' => [], 'filtros' => [], 'programados' => [], 'limpiados' => []];

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
function add_action($h, $f, $p = 10, $a = 1) { $GLOBALS['__rec']['acciones'][$h][] = $f; return true; }
function add_filter($h, $f, $p = 10, $a = 1) { $GLOBALS['__rec']['filtros'][$h][] = $f; return true; }
function apply_filters($h, $v, ...$a) { return $v; }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) { $GLOBALS['__desactivar'] = $cb; }
function register_uninstall_hook($f, $cb) {}
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($t, $rec, $h) { $GLOBALS['__rec']['programados'][$h] = $rec; return true; }
function wp_clear_scheduled_hook($h) { $GLOBALS['__rec']['limpiados'][] = $h; return 0; }
function is_admin() { return false; }
function __($t, $d = null) { return $t; }
function get_query_var($v) { return ''; }
function flush_rewrite_rules() {}


require dirname(__DIR__, 2) . '/woocommerce-conector.php';

// Lo que WordPress hace después: plugins_loaded y el filtro de intervalos.
foreach ($GLOBALS['__rec']['acciones']['plugins_loaded'] ?? [] as $cb) {
    try { $cb(); } catch (Throwable $e) { /* solo interesa lo que programa */ }
}
$intervalos = [];
foreach ($GLOBALS['__rec']['filtros']['cron_schedules'] ?? [] as $cb) { $intervalos = $cb($intervalos); }

// Desactivación: solo interesa qué limpia; la API de verdad no está.
try { ($GLOBALS['__desactivar'] ?? fn () => null)(); } catch (Throwable $e) {}

echo json_encode([
    'acciones'   => array_keys($GLOBALS['__rec']['acciones']),
    'programados'=> $GLOBALS['__rec']['programados'],
    'intervalos' => array_keys($intervalos),
    'limpiados'  => $GLOBALS['__rec']['limpiados'],
]);
