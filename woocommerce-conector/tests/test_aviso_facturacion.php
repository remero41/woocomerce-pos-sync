<?php
declare(strict_types=1);
/**
 * EL TPV FACTURA LO ONLINE (spec F5.2, decisión del usuario 30-09-2026).
 *
 * Cada venta pagada en la tienda entra en el TPV, que le asigna factura de su
 * serie y la registra en Hacienda (VeriFactu). Si la tienda emite además sus
 * propias facturas (un plugin de facturas de WooCommerce), la misma venta
 * tendría dos. El panel tiene que decirlo donde se decide qué se sincroniza.
 */

function run_aviso_facturacion_tests(WooTestRunner $t): void
{
    $t->suite('Panel: quién factura las ventas online');

    $t->test('el aviso dice que factura el TPV y que la tienda no debe facturar', function ($t) {
        $aviso = TPV_Sync_Admin::avisoFacturacion();
        $t->assert(str_contains($aviso, 'factura'), $aviso);
        $t->assert(str_contains($aviso, 'TPV'), $aviso);
        $t->assert(str_contains($aviso, 'No emitas facturas'), 'sin la instrucción, la comerciante no sabe qué hacer');
    });

    $t->test('el panel lo muestra en «Qué se sincroniza»', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        $t->assert((bool) preg_match('/Qué se sincroniza.{0,3000}self::avisoFacturacion\(\)/s', $src),
            'un aviso que nadie pinta no avisa a nadie');
    });
}
