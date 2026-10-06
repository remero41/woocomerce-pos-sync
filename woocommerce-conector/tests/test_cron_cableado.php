<?php
declare(strict_types=1);
/**
 * EL CRON ESTÁ ENCHUFADO, no solo escrito (autocuración, 29-09-2026).
 *
 * Un evento de cron vive en tres listas del fichero principal: el
 * add_action que lo atiende, el wp_schedule_event que lo programa (con un
 * intervalo que tiene que existir en cron_schedules) y el
 * wp_clear_scheduled_hook de la desactivación. Si una se desincroniza, la
 * suite sigue en verde y el trabajo no se ejecuta nunca, o se queda
 * programado tras desactivar el plugin.
 *
 * Se carga woocommerce-conector.php de verdad, en un proceso aparte, con un
 * WordPress que graba lo que el plugin registra (fixtures/cargar_plugin_cron.php).
 */

function run_cron_cableado_tests(WooTestRunner $t): void
{
    $t->suite('Cron — cada evento programado tiene quien lo atienda');

    $salida = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr '
        . escapeshellarg(__DIR__ . '/fixtures/cargar_plugin_cron.php') . ' 2>/dev/null');
    $r = json_decode((string) $salida, true);

    $t->test('el fichero principal se carga y deja constancia', function ($t) use ($r, $salida) {
        $t->assert(is_array($r), 'no se pudo cargar el plugin: ' . substr((string) $salida, 0, 300));
    });
    if (!is_array($r)) { return; }

    $nucleo = ['hourly', 'twicedaily', 'daily', 'weekly'];

    $t->test('la autocuración está programada cada 5 minutos', function ($t) use ($r) {
        $t->assertEquals('tpv_sync_cada_5_min', $r['programados']['tpv_sync_autocurar'] ?? null);
    });

    $t->test('los pedidos retenidos se reintentan cada 5 minutos', function ($t) use ($r) {
        $t->assertEquals('tpv_sync_cada_5_min', $r['programados']['tpv_sync_pedidos_pendientes'] ?? null,
            'un pedido retenido que nadie reintenta es un pedido perdido');
    });

    $t->test('el plugin se registra para actualizarse solo (y solo él)', function ($t) use ($r) {
        $t->assertEquals(true, $r['auto_update']['nuestro'] ?? null,
            'sin el filtro enganchado, la versión nueva solo se ofrece y la tienda se queda atrás');
        $t->assertEquals(false, $r['auto_update']['ajeno'] ?? null, 'los plugins ajenos, como estaban');
    });

    $t->test('todo evento programado tiene su add_action', function ($t) use ($r) {
        $huerfanos = array_diff(array_keys($r['programados']), $r['acciones']);
        $t->assertEquals([], array_values($huerfanos), 'programado y sin nadie que lo ejecute');
    });

    $t->test('todo intervalo usado existe', function ($t) use ($r, $nucleo) {
        $faltan = array_diff(array_values($r['programados']), array_merge($nucleo, $r['intervalos']));
        $t->assertEquals([], array_values($faltan), 'WordPress no programa un intervalo que no conoce');
    });

    $t->test('al desactivar se limpian todos', function ($t) use ($r) {
        $quedan = array_diff(array_keys($r['programados']), $r['limpiados']);
        $t->assertEquals([], array_values($quedan), 'seguiría disparándose con el plugin desactivado');
    });
}

function run_repaso_stock_csv_tests(WooTestRunner $t): void
{
    $t->suite('Tras un CSV en el TPV: repaso de stock por páginas, no volcado');
    $src = (string) file_get_contents(dirname(__DIR__) . '/woocommerce-conector.php');
    $wh  = (string) file_get_contents(dirname(__DIR__) . '/includes/class-webhook-handler.php');
    $t->test('csv.imported programa el repaso de stock, no import_all()', function ($t) use ($wh) {
        $t->assert(str_contains($wh, "'tpv_sync_reconcile_stock_total', ['']"), 'programa el repaso desde el principio');
        $t->assert(!preg_match("/case 'csv\\.imported':[^}]*'tpv_sync_import_all'/s", $wh), 'el aviso no programa el volcado');
    });
    $t->test('cada página programa la siguiente con su cursor y nunca va sin límite', function ($t) use ($src) {
        $t->assert(str_contains($src, 'reconcile(100, $cursor'), 'páginas de 100');
        $t->assert(!str_contains($src, 'reconcile(0)'), 'sin pasada entera');
        $t->assert(str_contains($src, "wp_schedule_single_event(time() + 10, 'tpv_sync_reconcile_stock_total', [(string) \$stats['next_cursor']])"), 'encadena con el cursor');
    });
}
