<?php
declare(strict_types=1);
/**
 * EL GTIN RANCIO (spec varios códigos de barras 2026-10-18, auditoría del cambio de ★).
 *
 * El ★ de un producto en el TPV pasa de un EAN (8411300838279) a una referencia (CAM-BAS). El
 * conector escribía el SKU nuevo pero dejaba en Woo el GTIN viejo, y como paraProducto() prefiere
 * el GTIN, el siguiente guardado en Woo devolvía al TPV el EAN viejo y revertía el cambio en
 * silencio. Ahora se borra el GTIN que escribió el propio conector con el ★ anterior; uno que el
 * comerciante puso a mano se respeta.
 */

require_once dirname(__DIR__) . '/includes/class-identificadores.php';

function run_gtin_rancio_tests(WooTestRunner $t): void
{
    $t->suite('GTIN rancio: cambiar el ★ del TPV no deja el EAN viejo en Woo');

    $t->test('★ con pinta de GTIN → es el GTIN', function ($t) {
        $t->assertEquals('8411300838279', TPV_Sync_Identificadores::gtinTrasModelo('8411300838279', '', ''), 'EAN-13');
        $t->assertEquals('12345678', TPV_Sync_Identificadores::gtinTrasModelo('12345678', '87654321', '87654321'), 'otro GTIN lo sustituye');
    });

    $t->test('★ pasa a referencia: el GTIN que escribimos con el ★ anterior se borra', function ($t) {
        $t->assertEquals('', TPV_Sync_Identificadores::gtinTrasModelo('CAM-BAS', '8411300838279', '8411300838279'), 'GTIN rancio → borrar');
        $gtin = TPV_Sync_Identificadores::gtinTrasModelo('CAM-BAS', '8411300838279', '8411300838279');
        $m = TPV_Sync_Identificadores::paraProducto($gtin ?? '8411300838279', 'CAM-BAS', 1);
        $t->assertEquals('CAM-BAS', $m['model'], 'y el siguiente guardado manda el ★ nuevo, no el viejo');
    });

    $t->test('un GTIN puesto a mano en Woo se respeta', function ($t) {
        $t->assertEquals(null, TPV_Sync_Identificadores::gtinTrasModelo('CAM-BAS', '8400000000017', '8411300838279'), 'no lo escribimos nosotros');
        $t->assertEquals(null, TPV_Sync_Identificadores::gtinTrasModelo('CAM-BAS', '8400000000017', ''), 'instalación de antes (sin _tpv_model)');
        $t->assertEquals(null, TPV_Sync_Identificadores::gtinTrasModelo('CAM-BAS', '', 'CAM-VIEJA'), 'sin GTIN no hay nada que borrar');
    });
}
