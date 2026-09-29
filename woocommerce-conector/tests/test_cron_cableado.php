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
