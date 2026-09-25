<?php
declare(strict_types=1);
/**
 * QUIEN MANDA al reconciliar.
 *
 * El caso real (pineapplemoda, 25-09-2026): se volcó Woo -> TPV y el catálogo
 * llegó mal (SKU con prefijo tecnico, variantes sin precio). Al ir a arreglarlo
 * aparece la pregunta de verdad: si ahora se reconcilia, ¿quien gana?
 *
 * Lo que habia era peor que "no poder elegir": habia DOS reconciliadores con el
 * dueño CABLEADO, y uno de ellos se disparaba SOLO desde el panel.
 *
 *   reconcile()               -> solo stock, "TPV gana siempre" (:649)
 *   reconcileBidirectional()  -> decide por fecha de modificacion (:2223)
 *   class-admin.php:3385      -> lo llama sin que nadie lo pida
 *
 * Con el catálogo del TPV mal, ese disparo automatico puede machacar Woo, que
 * es justo la fuente buena. De ahi el orden: esto va ANTES de arreglar los
 * datos, porque cambiar identificadores sin control de dueño duplica catalogos.
 *
 * El patron que se fija aqui es el de los conectores que funcionan (revisado en
 * prestashop-pos-sync y en el estandar ERP<->tienda): el dueño NO es por
 * plataforma sino POR DOMINIO, porque los dominios tienen dueños naturales
 * distintos:
 *
 *   stock    -> manda quien vende fisicamente (el TPV). Es el unico dato que se
 *               decrementa por un hecho del mundo real.
 *   catalogo -> manda quien lo mantiene. En pineapplemoda, Woo.
 *
 * Un interruptor unico "manda Woo" aplicado tambien al stock borraria el stock
 * real de la tienda. Por eso los tests de abajo insisten en que los dos
 * dominios se deciden por separado.
 *
 * Todo lo que se prueba aqui es PURO: sin WordPress, sin red y sin base de
 * datos. La decision vive en una funcion que recibe dos fotos (la de Woo y la
 * del TPV) y devuelve que habria que hacer. Asi se puede probar el caso de
 * 2.510 productos sin tener 2.510 productos.
 */

require_once dirname(__DIR__) . '/includes/class-reconciler.php';

function run_reconcile_dueno_tests(WooTestRunner $t): void
{
    // Dos fotos del MISMO producto que discrepan en todo lo que importa:
    // el nombre y el precio son del catálogo, la cantidad es del stock.
    $wc = [
        'external_id' => '48853',
        'name'        => 'Cinturón fajín',
        'price'       => 24.14,
        'sku'         => '',
        'quantity'    => 7,
    ];
    $tpv = [
        'tpv_id'      => 1331,
        'external_id' => '48853',   // api_external_mapping: el post_id de WP
        'name'        => 'Cinturon fajin (viejo)',
        'price'       => 0.0,        // el sintoma real: el padre quedo a 0
        'sku'         => '__WC__48853', // y el sku con el prefijo tecnico
        'quantity'    => 3,
    ];

    $t->suite('Reconciliar: quien manda se elige, y se elige por dominio');

    // ── El default no puede ser una sorpresa ─────────────────────────────
    $t->test('sin configurar nada, manda el TPV en stock y Woo en catalogo', function ($t) {
        $p = TPV_Sync_Reconciler::politicaPorDefecto();
        $t->assertEquals('tpv', $p['stock'],
            'el stock lo manda quien vende fisicamente');
        $t->assertEquals('woo', $p['catalogo'],
            'el catalogo lo manda quien lo mantiene');
    });

    // ── Catalogo = Woo: el fix del caso real ─────────────────────────────
    $t->test('con catalogo=woo el nombre y el precio de Woo suben al TPV', function ($t) use ($wc, $tpv) {
        $d = TPV_Sync_Reconciler::decidir($wc, $tpv, ['stock' => 'tpv', 'catalogo' => 'woo']);

        $t->assertEquals('push', $d['catalogo']['accion'],
            'Woo manda en catalogo: los datos buenos tienen que subir al TPV');
        $t->assert(in_array('name', $d['catalogo']['campos'], true),
            'el nombre de Woo debe viajar');
        $t->assert(in_array('price', $d['catalogo']['campos'], true),
            'el precio de Woo debe viajar: es el sintoma del producto a 0');
    });

    // ── La separacion de dominios es el corazon de todo ──────────────────
    $t->test('mandar en catalogo NO da derecho a tocar el stock', function ($t) use ($wc, $tpv) {
        $d = TPV_Sync_Reconciler::decidir($wc, $tpv, ['stock' => 'tpv', 'catalogo' => 'woo']);

        // Este es EL test. Si el catalogo arrastra la cantidad, "manda Woo"
        // borra el stock real de la tienda: 7 (lo que Woo cree) pisando 3 (lo
        // que de verdad queda tras vender en el mostrador).
        $t->assert(!in_array('quantity', $d['catalogo']['campos'], true),
            'el catalogo NUNCA puede arrastrar la cantidad: borraria el stock real vendido en caja');
        $t->assertEquals('pull', $d['stock']['accion'],
            'el stock sigue bajando del TPV aunque el catalogo lo mande Woo');
    });

    $t->test('los dos dominios se deciden por separado, no con un interruptor', function ($t) use ($wc, $tpv) {
        // La combinacion cruzada: catalogo del TPV pero stock de Woo. Es rara,
        // pero si el diseño es honesto tiene que poder expresarse.
        $d = TPV_Sync_Reconciler::decidir($wc, $tpv, ['stock' => 'woo', 'catalogo' => 'tpv']);
        $t->assertEquals('push', $d['stock']['accion'],   'stock=woo -> sube a TPV');
        $t->assertEquals('pull', $d['catalogo']['accion'], 'catalogo=tpv -> baja a Woo');
    });

    // ── Modo auditoria: mirar sin tocar ──────────────────────────────────
    $t->test('con los dos dominios en ninguno no se toca nada', function ($t) use ($wc, $tpv) {
        $d = TPV_Sync_Reconciler::decidir($wc, $tpv, ['stock' => 'ninguno', 'catalogo' => 'ninguno']);
        $t->assertEquals('nada', $d['stock']['accion'],    'nadie manda -> no se escribe');
        $t->assertEquals('nada', $d['catalogo']['accion'], 'nadie manda -> no se escribe');
        // Pero seguir informando de que discrepan: es el modo auditoria.
        $t->assert($d['discrepa'] === true,
            'aunque no se toque, hay que poder ver que los dos lados no coinciden');
    });

    $t->test('si los dos lados coinciden no se propone ninguna escritura', function ($t) {
        $igual = ['external_id' => '1', 'name' => 'X', 'price' => 10.0, 'sku' => 'A', 'quantity' => 5];
        $mismo = ['tpv_id' => 9, 'name' => 'X', 'price' => 10.0, 'sku' => 'A', 'quantity' => 5];
        $d = TPV_Sync_Reconciler::decidir($igual, $mismo, ['stock' => 'tpv', 'catalogo' => 'woo']);

        $t->assertEquals('nada', $d['catalogo']['accion'], 'nada que cambiar en catalogo');
        $t->assertEquals('nada', $d['stock']['accion'],    'nada que cambiar en stock');
        $t->assert($d['discrepa'] === false, 'no discrepan');
    });

    // ── La regla dura del usuario: no afectar a Woo ──────────────────────
    // Un producto que falta en un lado es el caso donde mas facil es perder
    // catalogo: la tentacion es "si no esta en el que manda, sobra". Ninguna
    // de las CUATRO combinaciones puede acabar en un borrado.
    //
    // (Las cuatro, y no una: probando solo catalogo=tpv sobrevivia un mutante
    // que ponia 'delete' en la rama de catalogo=woo — justo la politica del
    // caso real de pineapplemoda.)
    $t->test('falta en Woo y manda el TPV: se puede crear, nunca borrar', function ($t) {
        $soloTpv = ['tpv_id' => 5, 'name' => 'Solo TPV', 'price' => 1.0, 'sku' => '', 'quantity' => 2];
        $d = TPV_Sync_Reconciler::decidir(null, $soloTpv, ['stock' => 'tpv', 'catalogo' => 'tpv']);
        $t->assert($d['catalogo']['accion'] !== 'delete',
            'reconciliar no borra en Woo: Woo es la fuente buena del catalogo');
        $t->assertEquals('pull', $d['catalogo']['accion'], 'el que manda lo baja al otro lado');
    });

    $t->test('falta en Woo y manda WOO: se deja quieto, no se borra del TPV', function ($t) {
        // La politica del caso real. Hay algo en el TPV que Woo no tiene:
        // borrarlo del TPV por "no estar en el que manda" es como se pierde
        // el catalogo de un cliente en un solo clic.
        $soloTpv = ['tpv_id' => 5, 'name' => 'Solo TPV', 'price' => 1.0, 'sku' => '', 'quantity' => 2];
        $d = TPV_Sync_Reconciler::decidir(null, $soloTpv, ['stock' => 'tpv', 'catalogo' => 'woo']);
        $t->assert($d['catalogo']['accion'] !== 'delete',
            'que Woo mande NO autoriza a borrar del TPV lo que Woo no tiene');
        $t->assertEquals('nada', $d['catalogo']['accion'],
            'ante la duda no se toca: que lo decida una persona');
    });

    $t->test('falta en el TPV y manda Woo: se sube, nunca se borra', function ($t) use ($wc) {
        $d = TPV_Sync_Reconciler::decidir($wc, null, ['stock' => 'tpv', 'catalogo' => 'woo']);
        $t->assert($d['catalogo']['accion'] !== 'delete', 'jamas un borrado');
        $t->assertEquals('push', $d['catalogo']['accion'], 'Woo manda: sube al TPV');
    });

    $t->test('falta en el TPV y manda el TPV: no se borra de Woo', function ($t) use ($wc) {
        $d = TPV_Sync_Reconciler::decidir($wc, null, ['stock' => 'tpv', 'catalogo' => 'tpv']);
        $t->assert($d['catalogo']['accion'] !== 'delete',
            'ni siquiera con catalogo=tpv se borra un producto de Woo');
        $t->assertEquals('nada', $d['catalogo']['accion'], 'se deja quieto');
    });

    // ── Nada se aplica a ciegas sobre 2.510 productos ────────────────────
    $t->suite('Reconciliar: simular antes de escribir');

    $t->test('el plan de una simulacion no contiene ninguna escritura', function ($t) use ($wc, $tpv) {
        $plan = TPV_Sync_Reconciler::planificar(
            [$wc], [$tpv], ['stock' => 'tpv', 'catalogo' => 'woo'], true // dryRun
        );

        $t->assert($plan['dry_run'] === true, 'el plan tiene que declararse como simulacion');
        $t->assertEquals(0, count($plan['operaciones']),
            'una simulacion NO emite operaciones: solo cuenta y enseña');
        $t->assert($plan['total_cambios'] > 0,
            'pero si tiene que decir cuantos productos cambiarian');
    });

    $t->test('la simulacion enseña ejemplos concretos, no solo un numero', function ($t) use ($wc, $tpv) {
        $plan = TPV_Sync_Reconciler::planificar([$wc], [$tpv],
            ['stock' => 'tpv', 'catalogo' => 'woo'], true);

        $t->assert(!empty($plan['ejemplos']),
            'un "voy a cambiar 2510 productos" sin ejemplos no es revisable por nadie');
        $ej = $plan['ejemplos'][0];
        $t->assert(isset($ej['motivo']) && $ej['motivo'] !== '',
            'cada ejemplo dice POR QUE cambia');
    });

    $t->test('la simulacion no enseña 2510 filas, enseña una muestra', function ($t) {
        // El caso real: el catalogo entero discrepando.
        $wcs = $tpvs = [];
        for ($i = 1; $i <= 2510; $i++) {
            $wcs[]  = ['external_id' => (string)$i, 'name' => "P$i",
                       'price' => 10.0, 'sku' => '', 'quantity' => 1];
            $tpvs[] = ['tpv_id' => $i, 'external_id' => (string)$i, 'name' => "P$i viejo",
                       'price' => 0.0, 'sku' => '__WC__' . $i, 'quantity' => 1];
        }
        $plan = TPV_Sync_Reconciler::planificar($wcs, $tpvs,
            ['stock' => 'tpv', 'catalogo' => 'woo'], true);

        $t->assertEquals(2510, $plan['total_cambios'], 'el recuento es del catalogo entero');
        $t->assert(count($plan['ejemplos']) <= 10,
            'la muestra se queda en 10: 2510 filas no las lee nadie');
    });

    $t->test('sin simulacion si se emiten las operaciones', function ($t) use ($wc, $tpv) {
        $plan = TPV_Sync_Reconciler::planificar([$wc], [$tpv],
            ['stock' => 'tpv', 'catalogo' => 'woo'], false);

        $t->assert($plan['dry_run'] === false, 'esto ya no es una simulacion');
        $t->assert(count($plan['operaciones']) > 0,
            'al aplicar de verdad tienen que salir operaciones');
    });

    // ── El emparejamiento no puede depender del SKU ──────────────────────
    $t->test('los productos se emparejan por el id externo, no por el sku', function ($t) use ($wc, $tpv) {
        // Es lo que permite arreglar el sku sin duplicar el catalogo: el
        // vinculo vive en api_external_mapping (client_external_id = post_id),
        // no en el sku. Aqui los sku son DISTINTOS a proposito ('' vs
        // '__WC__48853') y aun asi tienen que reconocerse como el mismo.
        $plan = TPV_Sync_Reconciler::planificar([$wc], [$tpv],
            ['stock' => 'tpv', 'catalogo' => 'woo'], true);

        $t->assertEquals(1, $plan['total_cambios'],
            'con sku distintos siguen siendo UN producto, no dos');
        $t->assertEquals(0, $plan['solo_en_woo'],
            'si se empareja por sku, este saldria como huerfano y se duplicaria');
        $t->assertEquals(0, $plan['solo_en_tpv'], 'idem por el otro lado');
    });

    // ── El disparo automatico que podia machacar Woo ─────────────────────
    $t->suite('Reconciliar: nunca se dispara solo');

    $t->test('el panel ya no lanza una reconciliacion sin que se pida', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');

        // Antes: ajax_check_sync() llamaba a reconcileBidirectional() "silenciosa".
        // Con el catalogo del TPV mal, eso baja los datos malos a Woo sin avisar.
        $t->assert(!preg_match('/reconcileBidirectional\s*\(\s*\)\s*;/', $src),
            'la reconciliacion automatica y silenciosa tiene que desaparecer: '
            . 'puede machacar Woo con datos malos del TPV sin que nadie lo pida');
    });
}
