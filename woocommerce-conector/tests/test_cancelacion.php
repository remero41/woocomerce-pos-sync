<?php
declare(strict_types=1);
/**
 * Cancelación / reembolso total hechos en la tienda → el TPV deshace la venta.
 *
 * La API del TPV no deja «cancelar» (estado 7) un pedido cobrado: la matriz
 * de transiciones lo prohíbe para el actor api (shared/pedido/transiciones.php)
 * y el estado 2 (en proceso) ni existe. El plugin mandaba los dos, recibía
 * 422 y registraba «ok»: la venta seguía viva en el TPV con el stock restado
 * (reproducido en proceso contra tpv200, 07-10-2026).
 */
require_once __DIR__ . '/test_devoluciones.php';

final class ApiCancelacionFalsa extends TPV_Sync_API_Client
{
    public array $devueltas = [];    // clave => body
    public array $fallan = [];        // opids que la API rechaza
    public bool $caida = false;
    private int $sig = 500;
    public function __construct(public array $lineas = [], public array $hechas = []) {}
    public function get(string $path, array $params = []): array
    {
        if ($this->caida) return TPV_Sync_API_Client::decide(502, []);
        if (preg_match('#^/orders/(\d+)/returns$#', $path)) return TPV_Sync_API_Client::decide(200, ['data' => $this->hechas]);
        if (preg_match('#^/orders/(\d+)$#', $path)) return TPV_Sync_API_Client::decide(200, ['data' => ['products' => $this->lineas]]);
        return TPV_Sync_API_Client::decide(404, []);
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if (in_array((int) ($body['order_product_id'] ?? 0), $this->fallan, true)) {
            return TPV_Sync_API_Client::decide(409, ['errors' => [['error' => 'conflict', 'message' => 'carrera']]]);
        }
        $this->devueltas[$idempotencyKey] = $body;
        return TPV_Sync_API_Client::decide(201, ['data' => ['return_id' => ++$this->sig]]);
    }
    public function patch(string $path, array $body = []): array { return TPV_Sync_API_Client::decide(200, ['data' => $body]); }
}

function run_cancelacion_tests(WooTestRunner $t): void
{
    $t->suite('Cancelación en la tienda → devolución total en el TPV');

    $t->test('qué estado de Woo hace qué en el TPV', function ($t) {
        $t->assertEquals(5, TPV_Sync_Order_Sync::accionPorEstadoWc('completed'), 'entregado: 1→5 lo permite la API');
        foreach (['cancelled', 'refunded', 'failed'] as $e) {
            $t->assertEquals('devolver', TPV_Sync_Order_Sync::accionPorEstadoWc($e), "$e deshace la venta");
        }
        foreach (['processing', 'on-hold', 'pending', 'checkout-draft'] as $e) {
            $t->assertEquals(null, TPV_Sync_Order_Sync::accionPorEstadoWc($e), "$e: el TPV no gestiona el pedido");
        }
    });

    $t->test('pendientes = pedido − devoluciones por línea (y sin línea), sin descuentos', function ($t) {
        $lineas = [linea_tpv(10, 100, 0, 3) + ['name' => 'A'], linea_tpv(11, 100, 0, 1) + ['name' => 'A bis'],
                   linea_tpv(12, 200, 0, 2) + ['name' => 'B'], linea_tpv(13, 999, 0, 1) + ['model' => 'DISCOUNT', 'name' => 'Cupón']];
        $hechas = [['product_id' => 100, 'quantity' => 2, 'comment' => '10|Reembolso WC #5'],
                   ['product_id' => 200, 'quantity' => 1, 'comment' => 'vieja sin línea'],
                   ['product_id' => 200, 'quantity' => 1, 'comment' => '12|cancelada', 'return_status_id' => 4]];
        $p = TPV_Sync_Order_Sync::pendientesDeDevolver($lineas, $hechas);
        $t->assertEquals([10 => 1.0, 11 => 1.0, 12 => 1.0], array_map(fn ($x) => $x['quantity'], $p),
            'A: 3−2; A bis: 1; B: 2−1 (la cancelada no cuenta); el cupón no');
        $t->assertEquals(100, $p[10]['product_id']);
    });

    $t->test('todo devuelto ⇒ nada pendiente', function ($t) {
        $p = TPV_Sync_Order_Sync::pendientesDeDevolver([linea_tpv(10, 100, 0, 2)], [['product_id' => 100, 'quantity' => 2, 'comment' => '10|x']]);
        $t->assertEquals([], $p);
    });

    $t->test('cancelar en Woo devuelve en el TPV lo que queda, línea a línea, con clave propia', function ($t) {
        devoluciones_tienda();   // pedido Woo 1 ↔ TPV 700
        $api = new ApiCancelacionFalsa([linea_tpv(10, 100, 0, 2) + ['name' => 'A'], linea_tpv(12, 200, 55, 1) + ['name' => 'B talla M']],
                                       [['product_id' => 100, 'quantity' => 1, 'comment' => '10|Reembolso WC #9']]);
        $s = pedidos_sync_con($api);
        $s->on_wc_status_changed(1, 'processing', 'cancelled');
        $t->assertEquals(['wc-cancel-1-10', 'wc-cancel-1-12'], array_keys($api->devueltas), 'una devolución por línea pendiente');
        $t->assertEquals(1.0, $api->devueltas['wc-cancel-1-10']['quantity'], 'A: quedaba 1 de 2');
        $t->assertEquals(12, $api->devueltas['wc-cancel-1-12']['order_product_id'], 'la talla vuelve a SU línea');
        $t->assert(str_contains((string) $api->devueltas['wc-cancel-1-10']['comment'], 'cancelled'), 'el motivo viaja');
        $t->assertEquals(true, $api->devueltas['wc-cancel-1-10']['restock'] ?? null, 'la cancelación repone stock siempre');
        $t->assertEquals(0, count($GLOBALS['wpdb']->cola), 'sin cola: todo entró');
    });

    $t->test('si una línea falla, va a la cola y al reintentar solo manda la que falta', function ($t) {
        devoluciones_tienda();
        $api = new ApiCancelacionFalsa([linea_tpv(10, 100, 0, 1) + ['name' => 'A'], linea_tpv(12, 200, 0, 1) + ['name' => 'B']]);
        $api->fallan = [12];
        $s = pedidos_sync_con($api);
        $s->on_wc_status_changed(1, 'processing', 'refunded');
        $t->assertEquals(['wc-cancel-1-10'], array_keys($api->devueltas), 'la 12 falló');
        $t->assertEquals(['order.cancel'], array_map(fn ($f) => $f->operation, array_values($GLOBALS['wpdb']->cola)), 'encolada');
        // El TPV ya tiene la 10 devuelta; la cola reintenta.
        $api->hechas = [['product_id' => 100, 'quantity' => 1, 'comment' => '10|x']];
        $api->fallan = [];
        TPV_Sync::instance()->queue->process(5);
        $t->assertEquals(['wc-cancel-1-10', 'wc-cancel-1-12'], array_keys($api->devueltas), 'solo mandó la que faltaba');
        $t->assertEquals(['done'], array_map(fn ($f) => $f->status, array_values($GLOBALS['wpdb']->cola)), 'cola hecha');
    });

    $t->test('con el TPV caído no se inventa nada: a la cola', function ($t) {
        devoluciones_tienda();
        $api = new ApiCancelacionFalsa([linea_tpv(10, 100, 0, 1)]); $api->caida = true;
        $s = pedidos_sync_con($api);
        $s->on_wc_status_changed(1, 'processing', 'cancelled');
        $t->assertEquals([], $api->devueltas);
        $t->assertEquals(['order.cancel'], array_map(fn ($f) => $f->operation, array_values($GLOBALS['wpdb']->cola)), 'encolada');
    });

    $t->test('«completed» va como estado 5 y un rechazo de la API se registra como error, no como ok', function ($t) {
        devoluciones_tienda();
        $api = new class extends TPV_Sync_API_Client {
            public array $patches = [];
            public function __construct() {}
            public function patch(string $path, array $body = []): array { $this->patches[$path] = $body; return TPV_Sync_API_Client::decide(422, ['errors' => [['message' => 'Transición rechazada']]]); }
        };
        $s = pedidos_sync_con($api);
        $GLOBALS['wpdb']->log = [];
        $s->on_wc_status_changed(1, 'processing', 'completed');
        $t->assertEquals(['/orders/700/status' => ['order_status_id' => 5, 'comment' => 'Estado actualizado desde WooCommerce: completed']], $api->patches);
        $ultimo = end($GLOBALS['wpdb']->log);
        $t->assertEquals('error', $ultimo['status'] ?? '', 'el rechazo se ve');
        $s->on_wc_status_changed(1, 'pending', 'processing');
        $t->assertEquals(1, count($api->patches), '«processing» no manda nada: el TPV no tiene ese estado');
    });
}
