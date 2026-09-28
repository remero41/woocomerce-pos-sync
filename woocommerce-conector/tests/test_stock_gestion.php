<?php
declare(strict_types=1);
/**
 * «GESTIONAR STOCK» DE WOO ↔ «RESTAR STOCK» DEL TPV.
 *
 * Caso real (lulubeauty, 28-09-2026): primer volcado Woo→TPV de 847 productos.
 * En Woo casi todo el catálogo tiene la gestión de inventario APAGADA («Hay
 * existencias», sin número). En el TPV llegaron todos con stock 0 y
 * `subtract=1` (el valor por defecto de la API), es decir: «cuento unidades y
 * no tengo ninguna».
 *
 * Eso dejaba tres minas:
 *
 *   - Vender en caja bajaba el stock a -1 y avisaba a Woo, que ponía el
 *     producto «Agotado».
 *   - Cualquier product.updated del TPV entraba por upsert(), que forzaba
 *     `_manage_stock=yes` con el stock del TPV (0) ⇒ «Agotado».
 *   - El cron semanal de stock ponía a 0 en Woo lo que Woo sí contaba.
 *
 * El TPV ya tiene el concepto: `subtract=0` = «este producto no descuenta
 * stock». El bridge del TPV no emite stock.adjusted por él. Solo faltaba
 * traducirlo en las dos direcciones. Aquí vive esa traducción, pura.
 */

require_once dirname(__DIR__) . '/includes/class-stock.php';

function run_stock_gestion_tests(WooTestRunner $t): void
{
    $t->suite('Stock: gestionar stock (Woo) ↔ restar stock (TPV)');

    // ── Woo → TPV ─────────────────────────────────────────────────────────
    $t->test('Woo sin gestion de stock ⇒ TPV subtract=0 y SIN cantidad', function ($t) {
        $c = TPV_Sync_Stock::haciaTpv(false, null, true);
        $t->assertEquals(['subtract' => 0], $c,
            'un producto que Woo no cuenta no puede llegar al TPV como «0 unidades»');
    });

    $t->test('Woo sin gestion con un _stock viejo tampoco manda cantidad', function ($t) {
        // Woo conserva el número de cuando sí se gestionaba. No significa nada.
        $c = TPV_Sync_Stock::haciaTpv(false, 7.0, true);
        $t->assert(!array_key_exists('quantity', $c), 'el _stock huérfano no viaja');
    });

    $t->test('Woo con gestion, ALTA ⇒ subtract=1 y la cantidad de Woo', function ($t) {
        $c = TPV_Sync_Stock::haciaTpv(true, 5.0, true);
        $t->assertEquals(['subtract' => 1, 'quantity' => 5.0], $c);
    });

    $t->test('Woo con gestion, cantidad null en alta ⇒ 0', function ($t) {
        $c = TPV_Sync_Stock::haciaTpv(true, null, true);
        $t->assertEquals(0.0, $c['quantity'] ?? 'ausente');
    });

    $t->test('Woo con gestion, producto YA en el TPV ⇒ no se manda cantidad', function ($t) {
        // Re-lanzar el volcado no puede pisar el stock que el TPV lleva
        // contado (ventas de caja incluidas). El stock se mueve por sus
        // propios caminos, auditados.
        $c = TPV_Sync_Stock::haciaTpv(true, 5.0, false);
        $t->assertEquals(['subtract' => 1], $c);
    });

    // ── TPV → Woo ─────────────────────────────────────────────────────────
    $t->test('TPV subtract=false ⇒ Woo sin gestion y «hay existencias»', function ($t) {
        $m = TPV_Sync_Stock::haciaWoo(['subtract' => false, 'quantity' => 0], true);
        $t->assertEquals(['_manage_stock' => 'no', '_stock_status' => 'instock'], $m);
    });

    $t->test('TPV subtract=true con 3 ⇒ Woo gestiona 3 unidades', function ($t) {
        $m = TPV_Sync_Stock::haciaWoo(['subtract' => true, 'quantity' => 3], false);
        $t->assertEquals(['_manage_stock' => 'yes', '_stock' => 3, '_stock_status' => 'instock'], $m);
    });

    $t->test('TPV subtract=true con 0 ⇒ agotado (aquí SÍ es verdad)', function ($t) {
        $m = TPV_Sync_Stock::haciaWoo(['subtract' => true, 'quantity' => 0], true);
        $t->assertEquals('outofstock', $m['_stock_status'] ?? '');
    });

    $t->test('TPV subtract=true con -1 ⇒ agotado', function ($t) {
        $m = TPV_Sync_Stock::haciaWoo(['subtract' => true, 'quantity' => -1], true);
        $t->assertEquals('outofstock', $m['_stock_status'] ?? '');
    });

    // API anterior al campo `subtract`: no se sabe si el TPV cuenta.
    $t->test('sin subtract (API vieja) y Woo sin gestion ⇒ NO se toca nada', function ($t) {
        $m = TPV_Sync_Stock::haciaWoo(['quantity' => 0], false);
        $t->assertEquals([], $m,
            'sin saber si el TPV cuenta, forzar la gestión deja la tienda «Agotado»');
    });

    $t->test('sin subtract (API vieja) y Woo con gestion ⇒ se copia el stock', function ($t) {
        $m = TPV_Sync_Stock::haciaWoo(['quantity' => 4], true);
        $t->assertEquals(['_manage_stock' => 'yes', '_stock' => 4, '_stock_status' => 'instock'], $m);
    });

    // ── ¿Hay stock que sincronizar? (eventos, cron, reconciliar) ─────────
    $t->test('se sincroniza solo si Woo gestiona y el TPV cuenta', function ($t) {
        $t->assertEquals(true,  TPV_Sync_Stock::seCuenta(true,  ['subtract' => true]));
        $t->assertEquals(false, TPV_Sync_Stock::seCuenta(false, ['subtract' => true]),
            'Woo no gestiona ⇒ el número no significa nada');
        $t->assertEquals(false, TPV_Sync_Stock::seCuenta(true,  ['subtract' => false]),
            'el TPV no descuenta ⇒ su 0 no es un agotado');
        $t->assertEquals(true,  TPV_Sync_Stock::seCuenta(true,  []),
            'API vieja: decide Woo');
    });
}
