<?php
declare(strict_types=1);
/**
 * De lo que el comerciante ya eligio, a la politica por dominio.
 *
 * El plugin YA tenia una decision de "quien manda": la option
 * `tpv_sync_principal`, que pone el asistente al conectar y que vale
 *
 *     ''     -> sin decidir (modo legacy: manda WooCommerce)
 *     'wc'   -> manda WooCommerce
 *     'tpv'  -> manda el TPV
 *
 * No hay que inventar un ajuste paralelo: seria un segundo sitio donde decir
 * lo mismo, y los dos acabarian discrepando. La politica por dominio se
 * DERIVA de ese ajuste.
 *
 * Y la traduccion no es "lo mismo para todo", porque ese ajuste habla del
 * CATALOGO. Lo dice el propio codigo que ya existia, en el push de productos
 * (class-product-sync.php:1181):
 *
 *     "El stock es bidireccional siempre — pero el stock no pasa por aqui"
 *
 * O sea: `tpv_sync_principal` nunca goberno el stock. Traducirlo como "manda
 * en todo" le daria a Woo un poder sobre el stock que hoy no tiene, y en un
 * catalogo de 2.510 productos eso es subir el stock que Woo cree por encima
 * del que de verdad queda tras vender en caja.
 *
 * De ahi el mapeo:
 *
 *     catalogo <- tpv_sync_principal ('' se trata como 'wc', el legacy)
 *     stock    <- el TPV, siempre, salvo que se pida otra cosa a proposito
 */

require_once dirname(__DIR__) . '/includes/class-reconciler.php';

function run_politica_desde_ajustes_tests(WooTestRunner $t): void
{
    $t->suite('La politica sale del ajuste que ya existe, no de uno nuevo');

    $t->test('principal=wc -> el catalogo lo manda Woo', function ($t) {
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('wc');
        $t->assertEquals('woo', $p['catalogo'], 'el comerciante dijo que manda su tienda');
    });

    $t->test('principal=tpv -> el catalogo lo manda el TPV', function ($t) {
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('tpv');
        $t->assertEquals('tpv', $p['catalogo']);
    });

    $t->test('sin decidir se comporta como el modo legacy: manda Woo', function ($t) {
        // Importante que NO cambie de significado: hay instalaciones vivas con
        // la option vacia, y el push de productos ya las trata como "manda WC".
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('');
        $t->assertEquals('woo', $p['catalogo'],
            'cambiar esto le daria la vuelta al comportamiento de las instalaciones antiguas');
    });

    $t->test('un valor raro no deja el catalogo sin dueño ni inventa uno', function ($t) {
        // Defensa: si la option se corrompe, se cae al legacy conocido.
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('cualquier-cosa');
        $t->assertEquals('woo', $p['catalogo'], 'se cae al legacy, no a un estado nuevo');
    });

    // ── Lo que el ajuste NO decide ───────────────────────────────────────
    $t->test('el stock lo sigue mandando el TPV aunque el catalogo lo mande Woo', function ($t) {
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('wc');
        $t->assertEquals('tpv', $p['stock'],
            '`tpv_sync_principal` nunca goberno el stock: el propio push lo dice');
    });

    $t->test('con principal=tpv manda el TPV en los dos, que es coherente', function ($t) {
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('tpv');
        $t->assertEquals('tpv', $p['stock']);
        $t->assertEquals('tpv', $p['catalogo']);
    });

    // ── Se puede afinar sin tocar el ajuste global ───────────────────────
    $t->test('se puede pedir una politica distinta para una pasada concreta', function ($t) {
        // El caso de pineapplemoda: el catalogo del TPV llego mal y hay que
        // reconciliar con Woo mandando, aunque el ajuste global diga otra cosa.
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('tpv', ['catalogo' => 'woo']);
        $t->assertEquals('woo', $p['catalogo'], 'la peticion concreta manda sobre el ajuste');
        $t->assertEquals('tpv', $p['stock'], 'y lo que no se pide, no se cambia');
    });

    $t->test('se puede pedir el modo auditoria sin escribir nada', function ($t) {
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('wc',
            ['catalogo' => 'ninguno', 'stock' => 'ninguno']);
        $t->assertEquals('ninguno', $p['catalogo']);
        $t->assertEquals('ninguno', $p['stock']);
    });

    $t->test('un dueño invalido en la peticion se ignora, no rompe la pasada', function ($t) {
        $p = TPV_Sync_Reconciler::politicaDesdePrincipal('wc', ['catalogo' => 'marte']);
        $t->assertEquals('woo', $p['catalogo'],
            'un valor que no existe cae al que tocaba, no deja el dominio en un estado raro');
    });
}
