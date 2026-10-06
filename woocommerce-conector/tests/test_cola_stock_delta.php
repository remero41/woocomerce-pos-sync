<?php
declare(strict_types=1);
/**
 * H2 — La cola reintenta un cambio de stock de Woo con delta 0.
 *
 * push_wc_stock_change() encola 'stock.push' con delta=0 y absolute_target=N
 * cuando no pudo leer el stock del TPV ("delta lo recalculamos al procesar la
 * queue"). La cola tiene que mandar al TPV el ajuste que deja el stock en N,
 * no un PATCH con quantity_change=0 que no cambia nada y se da por hecho.
 *
 * Se ejecuta la cola REAL (class-queue.php) con un cliente API simulado que
 * registra lo que se le pide y contesta como la API de verdad.
 */

require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-queue.php';

final class ColaApiEspia extends TPV_Sync_API_Client
{
    public array $llamadas = [];
    public float $stockTpv = 10.0;
    public function __construct() {}
    public function get(string $path, array $params = []): array
    {
        $this->llamadas[] = ['GET', $path, $params];
        if (preg_match('#^/products/(\d+)/stock$#', $path)) {
            return TPV_Sync_API_Client::decide(200, ['data' => ['product_id' => 7, 'quantity' => $this->stockTpv]]);
        }
        return TPV_Sync_API_Client::decide(404, []);
    }
    public function patch(string $path, array $body = []): array
    {
        $this->llamadas[] = ['PATCH', $path, $body];
        if (preg_match('#^/products/(\d+)/stock$#', $path)) {
            $this->stockTpv += (float) ($body['quantity_change'] ?? 0);
            return TPV_Sync_API_Client::decide(201, ['data' => ['quantity_after' => $this->stockTpv]]);
        }
        return TPV_Sync_API_Client::decide(404, []);
    }
}

function run_cola_stock_delta_tests(WooTestRunner $t): void
{
    $t->suite('Cola: stock.push encolado sin delta');

    $t->test('la cola deja el stock del TPV en el absoluto que tenía Woo', function (WooTestRunner $t) {
        $api = new ColaApiEspia();
        $api->stockTpv = 10.0;
        $cola = new TPV_Sync_Queue($api, new ColaProductosNulo(), new ColaPedidosNulo());
        // Lo que encola push_wc_stock_change() cuando el TPV no contestó:
        $payload = ['tpv_product_id' => 7, 'absolute_target' => 3.0, 'delta' => 0,
                    'reason' => 'ajuste_manual', 'comment' => 'WC product #99 (reintento)'];
        $m = new ReflectionMethod(TPV_Sync_Queue::class, 'execute');
        $m->setAccessible(true);
        $ok = $m->invoke($cola, 'stock.push', $payload);
        $t->assert($ok === true, 'la operación se da por aplicada');
        $t->assertEquals(3.0, $api->stockTpv, 'el TPV debe quedar en 3 (lo que Woo tenía), no en ' . $api->stockTpv);
        $patches = array_values(array_filter($api->llamadas, fn ($l) => $l[0] === 'PATCH'));
        $t->assert(count($patches) === 1, 'un solo PATCH de stock');
        $t->assert(abs((float) ($patches[0][2]['quantity_change'] ?? 0) - (-7.0)) < 0.0001,
            'quantity_change debe ser -7, fue ' . json_encode($patches[0][2]['quantity_change'] ?? null));
    });

    $t->test('si el TPV ya está en el absoluto no se manda nada', function (WooTestRunner $t) {
        $api = new ColaApiEspia();
        $api->stockTpv = 3.0;
        $cola = new TPV_Sync_Queue($api, new ColaProductosNulo(), new ColaPedidosNulo());
        $m = new ReflectionMethod(TPV_Sync_Queue::class, 'execute');
        $m->setAccessible(true);
        $ok = $m->invoke($cola, 'stock.push', ['tpv_product_id' => 7, 'absolute_target' => 3.0, 'delta' => 0, 'reason' => 'ajuste_manual']);
        $t->assert($ok === true, 'aplicada');
        $patches = array_filter($api->llamadas, fn ($l) => $l[0] === 'PATCH');
        $t->assert(count($patches) === 0, 'sin PATCH cuando no hay diferencia');
    });

    $t->test('sin saber el stock del TPV no se calcula nada: null, nunca 0', function (WooTestRunner $t) {
        $t->assertEquals(null, TPV_Sync_Queue::deltaHaciaAbsoluto(TPV_Sync_API_Client::decide(502, []), 3.0), 'TPV caído');
        $t->assertEquals(null, TPV_Sync_Queue::deltaHaciaAbsoluto(TPV_Sync_API_Client::decide(200, ['data' => []]), 3.0),
            'respuesta sin cantidad: no se asume 0 (daría un delta enorme)');
        $t->assertEquals(-7.0, TPV_Sync_Queue::deltaHaciaAbsoluto(TPV_Sync_API_Client::decide(200, ['total' => ['quantity' => 10]]), 3.0), 'formato total.quantity');
    });

    $t->test('un delta explícito (sin absoluto) se sigue mandando tal cual', function (WooTestRunner $t) {
        $api = new ColaApiEspia();
        $api->stockTpv = 10.0;
        $cola = new TPV_Sync_Queue($api, new ColaProductosNulo(), new ColaPedidosNulo());
        $m = new ReflectionMethod(TPV_Sync_Queue::class, 'execute');
        $m->setAccessible(true);
        $m->invoke($cola, 'stock.push', ['tpv_product_id' => 7, 'delta' => -2, 'reason' => 'venta']);
        $t->assertEquals(8.0, $api->stockTpv, 'delta -2 aplicado');
    });
}

// Dobles mínimos de los colaboradores que la cola no usa en stock.push.
if (!class_exists('TPV_Sync_Product_Sync')) {
    class TPV_Sync_Product_Sync { public function __construct() {} }
}
if (!class_exists('TPV_Sync_Order_Sync')) {
    class TPV_Sync_Order_Sync { public function __construct() {} }
}
final class ColaProductosNulo extends TPV_Sync_Product_Sync { public function __construct() {} }
final class ColaPedidosNulo extends TPV_Sync_Order_Sync { public function __construct() {} }
