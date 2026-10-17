<?php
declare(strict_types=1);
/**
 * EL CÓDIGO DE UNA TALLA ES SOLO EL SUYO, Y BORRARLA EN WOO LA BORRA EN LA CAJA.
 *
 * lulubeauty (17-10-2026): `$variation->get_sku()` hereda el SKU del padre
 * cuando la variación no tiene uno propio; la caja recibía la referencia del
 * producto en todas las tallas y perdía sus EAN. El comportamiento completo
 * se prueba contra WordPress real en tests/integracion/test_tallas_codigo_propio.php;
 * aquí, lo que se puede vigilar sin WordPress: el contexto 'edit' en las dos
 * rutas y el hook del borrado de variaciones enganchado de verdad.
 */

function run_tallas_codigo_propio_tests(WooTestRunner $t): void
{
    $t->suite('Tallas: código propio y borrado explícito');

    $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

    $t->test('las dos rutas leen el SKU PROPIO de la variación (get_sku(\'edit\'))', function ($t) use ($src) {
        $t->assertEquals(2, preg_match_all('/\$v->get_sku\(\s*[\x27"]edit[\x27"]\s*\)/', $src),
            'sin el contexto, Woo devuelve el SKU del padre y la caja pierde los EAN de las tallas');
        $t->assertEquals(0, preg_match_all('/\$v->get_sku\(\s*\)/', $src),
            'ninguna ruta debe quedarse con el heredado');
    });

    $t->test('el número de la variación solo va como barcode_si_vacio cuando la API trata las tallas en sitio', function ($t) use ($src) {
        $t->assertEquals(2, preg_match_all('/mejorCodigo\s*\([^;]*\$this->tallasEnSitio \? 0 : \(int\)\s*\$v->get_id\(\)/s', $src),
            'con la API nueva el número no puede viajar como código: pisaría el EAN de la caja');
        $t->assert(str_contains($src, "tieneCapacidad('variants_in_place')"), 'se consulta la capacidad');
    });

    $t->test('el borrado de una variación en Woo está enganchado a before_delete_post', function ($t) {
        $salida = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/fixtures/cargar_plugin_hooks.php') . ' 2>/dev/null');
        $r = json_decode((string) $salida, true);
        $cbs = $r['acciones']['before_delete_post'] ?? [];
        $t->assert(in_array('TPV_Sync_Product_Sync::push_wc_variation_delete_to_tpv', $cbs, true),
            'before_delete_post=' . json_encode($cbs));
        $t->assert(in_array('TPV_Sync_Product_Sync::push_wc_delete_to_tpv', $cbs, true), 'el del producto sigue');
    });
}
