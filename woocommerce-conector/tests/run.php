<?php
declare(strict_types=1);
/**
 * Runner de tests del conector WooCommerce.
 *
 * El plugin no tenia NINGUN test hasta la auditoria de 2026-08-26. Se replica el
 * estilo del runner de la API (api/v1/tests/run_tests.php) a proposito: una sola
 * forma de ejecutar tests en todo el parque, sin composer ni PHPUnit.
 *
 * Los tests NO cargan WordPress. Cubren las piezas puras (la decision de si una
 * respuesta HTTP fue bien) con la respuesta simulada; lo que necesita de WP se
 * stubea abajo, en el minimo imprescindible.
 *
 * Uso: php tests/run.php
 */

// ─── Stubs de WordPress ──────────────────────────────────────────────────────
// Solo lo que exigen los ficheros bajo prueba al ser incluidos. Si un test
// necesita mas WP que esto, probablemente esta probando WP y no el plugin.
define('ABSPATH', __DIR__);

if (!function_exists('get_option')) {
    function get_option($k, $default = false) { return $GLOBALS['__wp_options'][$k] ?? $default; }
}
if (!function_exists('update_option')) {
    function update_option($k, $v, $autoload = null) { $GLOBALS['__wp_options'][$k] = $v; return true; }
}
if (!function_exists('delete_transient')) { function delete_transient($k) { return true; } }
if (!function_exists('get_transient'))    { function get_transient($k) { return false; } }
if (!function_exists('set_transient'))    { function set_transient($k, $v, $t = 0) { return true; } }
if (!function_exists('add_action'))      { function add_action($t, $f, $p = 10, $a = 1) { return true; } }
if (!function_exists('add_filter'))      { function add_filter($t, $f, $p = 10, $a = 1) { return true; } }
if (!function_exists('apply_filters'))    { function apply_filters($tag, $value, ...$a) { return $value; } }
if (!function_exists('__'))               { function __($t, $d = null) { return $t; } }

// ─── Mini framework ──────────────────────────────────────────────────────────

final class WooTestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private string $suite = '';

    public function suite(string $name): void
    {
        $this->suite = $name;
        echo "\n\033[1;34m══ $name ══\033[0m\n";
    }

    public function test(string $name, callable $fn): void
    {
        $before = $this->failed;
        try {
            $fn($this);
        } catch (Throwable $e) {
            $this->failed++;
            $this->failures[] = "[{$this->suite}] $name: EXCEPCION " . $e->getMessage();
        }
        if ($this->failed === $before) {
            echo "  \033[32m✓\033[0m $name\n";
        } else {
            echo "  \033[31m✗\033[0m $name\n";
        }
    }

    public function assert(bool $cond, string $msg): void
    {
        if ($cond) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = "[{$this->suite}] $msg";
        }
    }

    /**
     * Igualdad estricta. El mensaje por defecto muestra esperado vs obtenido:
     * sin eso, un fallo de umbral solo dice "fallo" y hay que ir a leer el
     * test para saber que salio.
     */
    public function assertEquals($expected, $actual, string $msg = ''): void
    {
        $ok = $expected === $actual;
        if ($msg === '') {
            $msg = sprintf('esperado %s, obtenido %s',
                var_export($expected, true), var_export($actual, true));
        } elseif (!$ok) {
            $msg .= sprintf(' (esperado %s, obtenido %s)',
                var_export($expected, true), var_export($actual, true));
        }
        $this->assert($ok, $msg);
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\n\033[1m══ RESULTADO: {$this->passed}/{$total} pasaron";
        if ($this->failed > 0) {
            echo " · \033[31m{$this->failed} fallaron\033[0m\033[1m";
        }
        echo " ══\033[0m\n";
        if ($this->failures) {
            echo "\n\033[31mFallos:\033[0m\n";
            foreach ($this->failures as $f) { echo "  • $f\n"; }
        }
        return $this->failed === 0 ? 0 : 1;
    }
}

$t = new WooTestRunner();

require_once __DIR__ . '/test_api_client_parse.php';
require_once __DIR__ . '/test_queue_success.php';
require_once __DIR__ . '/test_auth_401_diagnostico.php';
require_once __DIR__ . '/test_sync_health_panel.php';
require_once __DIR__ . '/test_imagenes_batch.php';
require_once __DIR__ . '/test_actualizador.php';
require_once dirname(__DIR__) . '/includes/class-order-sync.php';
require_once dirname(__DIR__) . '/includes/class-admin.php';

run_api_client_parse_tests($t);
run_queue_success_tests($t);
run_auth_401_diagnostico_tests($t);
run_sync_health_panel_tests($t);
run_imagenes_batch_tests($t);
run_contador_honesto_tests($t);
run_bulk_variantes_tests($t);
run_conteo_coherente_tests($t);
run_actualizador_tests($t);
run_variantes_stock_tests($t);
run_resuscripcion_tests($t);
run_lista_unica_tests($t);
run_endpoint_webhook_tests($t);
run_firma_webhook_tests($t);
run_cache_actualizador_tests($t);

exit($t->summary());
