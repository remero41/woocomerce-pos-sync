<?php
declare(strict_types=1);
/**
 * RECUPERAR LOS PEDIDOS QUE EL PLUGIN YA DESCARTÓ — spec F4.
 *
 * Hasta F1, un pedido sin ninguna línea enlazada se registraba como
 * `skip` «Sin productos mapeados al TPV» y no se volvía a intentar. Decisión
 * del usuario (30-09-2026): recuperarlos AUTOMÁTICAMENTE; si alguno se
 * metió a mano en el TPV y se duplica, lo asume la comerciante.
 *
 * Solo esos: los pagados (en proceso / completados) que siguen sin pedido en
 * el TPV. Una vez por instalación.
 */

require_once __DIR__ . '/test_pedidos_sin_enlace.php';

function descartado(int $orderId, string $mensaje = 'Sin productos mapeados al TPV'): void
{
    $GLOBALS['wpdb']->log[] = ['event_type' => 'order_sync', 'resource' => 'order',
        'resource_id' => $orderId, 'status' => 'skip', 'message' => $mensaje];
}

function run_recuperar_pedidos_tests(WooTestRunner $t): void
{
    $t->suite('Recuperar los pedidos descartados (F4)');

    $t->test('los pedidos pagados que se descartaron llegan solos al TPV', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, [new ItemPedidoFalso(8306, 1, 10.0)], 'processing');
        $GLOBALS['__wc_orders'][2] = new PedidoWooFalso(2, [new ItemPedidoFalso(8306, 1, 10.0)], 'completed');
        descartado(1);
        descartado(2);
        $r = pedidos_sync($api)->reintentarPendientes(10);
        $t->assertEquals(2, count($api->pedidos), 'antes se quedaban fuera para siempre');
        $t->assertEquals(2, $r['enviados'] ?? -1);
    });

    $t->test('solo los pagados: un cancelado o pendiente de pago no se recupera', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, [new ItemPedidoFalso(8306, 1, 10.0)], 'cancelled');
        $GLOBALS['__wc_orders'][2] = new PedidoWooFalso(2, [new ItemPedidoFalso(8306, 1, 10.0)], 'pending');
        descartado(1);
        descartado(2);
        pedidos_sync($api)->reintentarPendientes(10);
        $t->assertEquals([], $api->pedidos, 'no hubo venta que registrar');
    });

    $t->test('solo los descartados por falta de enlace; y nunca uno que ya está en el TPV', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, [new ItemPedidoFalso(8306, 1, 10.0)]);
        $GLOBALS['__wc_orders'][2] = new PedidoWooFalso(2, [new ItemPedidoFalso(8306, 1, 10.0)]);
        descartado(1, 'Pedido sin líneas de producto');
        descartado(2);
        $GLOBALS['__wp_meta'][2] = ['_tpv_order_id' => 6000];   // ya llegó por otro camino
        pedidos_sync($api)->reintentarPendientes(10);
        $t->assertEquals([], $api->pedidos, 'recuperar el 2 lo duplicaría en el TPV');
        $t->assertEquals('', get_post_meta(2, '_tpv_order_pendiente', true),
            'marcado como pendiente se reintentaría cada 5 minutos para siempre');
        $t->assertEquals([], $GLOBALS['__wc_orders'][2]->notas, 'ni una nota «Recuperado» falsa');
    });

    $t->test('un pedido que ya estaba retenido no recibe otra nota', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $api->altaFalla = true;
        $p = pedido(1, [8308]);
        $s = pedidos_sync($api);
        $s->send_to_tpv(1);               // retenido: 1 nota
        descartado(1);                    // (y figura en el registro)
        $s->reintentarPendientes(10);
        $t->assertEquals(1, count($p->notas), implode(' | ', $p->notas));
    });

    $t->test('se hace UNA vez por instalación', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $s = pedidos_sync($api);
        $s->reintentarPendientes(10);                       // primera pasada: nada que recuperar
        $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, [new ItemPedidoFalso(8306, 1, 10.0)]);
        descartado(1);                                      // un «skip» posterior (no debería haberlo)
        $s->reintentarPendientes(10);
        $t->assertEquals([], $api->pedidos, 'no se repasa el registro en cada pasada');
    });

    $t->test('el pedido recuperado lleva una nota que lo explica', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $p = $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, [new ItemPedidoFalso(8306, 1, 10.0)]);
        descartado(1);
        pedidos_sync($api)->reintentarPendientes(10);
        $t->assert(count(array_filter($p->notas, fn ($n) => str_contains($n, 'Recuperado'))) === 1,
            'la comerciante tiene que poder ver por qué llega ahora: ' . implode(' | ', $p->notas));
    });
}
