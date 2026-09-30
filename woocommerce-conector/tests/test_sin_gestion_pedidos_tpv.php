<?php
declare(strict_types=1);
/**
 * EL TPV NO GESTIONA LOS PEDIDOS DE LA TIENDA (decisión del usuario, 30-09-2026).
 *
 * El amo de un pedido online es la tienda. El TPV recibe la venta (y lo que la
 * corrige desde la tienda), pero no la gestiona. El interruptor «Pedidos» del
 * panel decía «Se crean en el TPV cuando se pagan», y era falso: los pedidos
 * se envían SIEMPRE. Lo que hacía de verdad era suscribir la tienda a eventos
 * del TPV que la GESTIONABAN desde fuera:
 *  - return.created: una devolución hecha en el TPV creaba un reembolso en Woo;
 *  - order.status_changed: cambiar el estado en el TPV lo cambiaba en Woo
 *    (la API ni siquiera lo emite: código muerto).
 * Se quita el interruptor y esa mitad TPV→Woo. Woo→TPV sigue intacto.
 */

require_once __DIR__ . '/test_pedidos_sin_enlace.php';
require_once dirname(__DIR__) . '/includes/class-webhook-handler.php';

if (!function_exists('tpv_sync_module_orders')) {
    // Como en woocommerce-conector.php antes de quitar el interruptor: si el
    // código viejo la llama, que se comporte como en una tienda que lo encendió.
    function tpv_sync_module_orders(): bool { return (bool) get_option('tpv_sync_module_orders', 0); }
}
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return false; } }
if (!function_exists('wc_create_refund')) {
    function wc_create_refund($args) { $GLOBALS['__reembolsos_creados'][] = $args; return (object) ['id' => 1]; }
}

/** Tienda con el interruptor «Pedidos» ENCENDIDO y el pedido 1 de Woo = pedido 700 del TPV. */
function sin_gestion_evento(string $tipo, array $campos = [], int $recurso = 700): PedidoWooFalso
{
    pedidos_tienda();
    $GLOBALS['__wp_options']['tpv_sync_module_orders'] = 1;
    $p = pedido(1, [8306]);
    $GLOBALS['__wp_meta'][1] = ['_tpv_order_id' => 700];
    $GLOBALS['__reembolsos_creados'] = [];
    $api = new ApiPedidosFalsa();
    $wh = new TPV_Sync_Webhook(new TPV_Sync_Product_Sync($api), pedidos_sync($api), $api);
    $m = new ReflectionMethod($wh, 'dispatch');
    $m->setAccessible(true);
    $m->invoke($wh, ['event_type' => $tipo, 'resource_id' => $recurso, 'changed_fields' => $campos]);
    return $p;
}

function run_sin_gestion_pedidos_tpv_tests(WooTestRunner $t): void
{
    $t->suite('El TPV no gestiona los pedidos de la tienda');

    $t->test('la tienda ya no se suscribe a pedidos ni devoluciones del TPV', function ($t) {
        $ev = TPV_Sync_Admin::eventosSuscritos(true);
        foreach (['order.created', 'order.payment_changed', 'return.created', 'return.deleted'] as $e) {
            $t->assert(!in_array($e, $ev, true), "sobra $e");
        }
        $t->assert(in_array('stock.adjusted', $ev, true), 'el catálogo sigue');
        $t->assert(in_array('customer.created', $ev, true), 'los clientes siguen');
    });

    $t->test('una devolución hecha en el TPV NO crea un reembolso en Woo', function ($t) {
        sin_gestion_evento('return.created', ['order_id' => 700, 'product_id' => 999, 'quantity' => 1, 'total' => 10.0]);
        $t->assertEquals([], $GLOBALS['__reembolsos_creados'] ?? [],
            'las tiendas ya suscritas lo seguirán recibiendo: el receptor tiene que ignorarlo');
    });

    $t->test('un cambio de estado en el TPV no toca el pedido de Woo', function ($t) {
        $p = sin_gestion_evento('order.status_changed', ['order_status_id' => 7]);
        $t->assertEquals([], $p->notas, 'cancelar en el TPV cancelaba en Woo');
        $t->assert(!method_exists('TPV_Sync_Order_Sync', 'update_wc_status'), 'código muerto fuera');
    });

    $t->test('Woo → TPV sigue: cambiar el estado en Woo se propaga al TPV', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wp_meta'][1] = ['_tpv_order_id' => 700];
        $api = new ApiPedidosFalsa();
        pedidos_sync($api)->on_wc_status_changed(1, 'processing', 'cancelled');
        $t->assertEquals(7, (int) ($api->patches['/orders/700/status']['order_status_id'] ?? 0),
            'la cancelación en la tienda tiene que llegar al TPV (stock y caja)');
    });

    $t->test('el panel ya no tiene el interruptor «Pedidos» y dice lo que pasa de verdad', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        $t->assert(!str_contains($src, 'name="tpv_sync_module_orders"'), 'el interruptor mentía');
        $t->assert(str_contains($src, 'Los pedidos se gestionan aquí, en WooCommerce: el TPV no los cambia.'),
            'la tienda tiene que saber que los pedidos van siempre y se gestionan en ella');
    });
}
