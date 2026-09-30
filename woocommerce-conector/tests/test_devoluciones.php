<?php
declare(strict_types=1);
/**
 * DEVOLUCIONES DE LA TIENDA HACIA EL TPV — spec SPEC_pedidos_woo_tpv.md, F3.
 *
 * Verificado en código (30-09-2026):
 *  P9  desde el 22-08 (api_tpv bb2b474) la API rechaza `return_status_id`
 *      distinto de 3 (422 invalid_return_status) y el plugin mandaba 1:
 *      NINGÚN reembolso de Woo llegaba al TPV;
 *  P5  un reintento reenviaba la devolución ENTERA, protegida solo por una
 *      idempotencia que caduca a las 24 h (la cola llega a ~29 h);
 *  P6  una línea sin enlace se saltaba y aun así se marcaba sincronizada;
 *  P7  dos tallas del mismo producto compartían clave: la 2.ª se perdía;
 *  P8  sin `order_product_id`, con dos líneas del mismo producto la API no
 *      sabe qué talla vuelve (422) o la atribuye al producto.
 *
 * La API falsa valida como la real (ReturnController::validateReturnBody).
 */

require_once __DIR__ . '/test_pedidos_sin_enlace.php';

final class ItemDevolucionFalso
{
    public function __construct(private int $id, private int $productId, private int $variacion,
                                private float $qty, private string $nombre = 'Línea') {}
    public function get_id() { return $this->id; }
    public function get_product_id() { return $this->productId; }
    public function get_variation_id() { return $this->variacion; }
    public function get_quantity() { return -abs($this->qty); }   // Woo: negativa
    public function get_name() { return $this->nombre; }
}

final class ReembolsoWooFalso
{
    public array $notas = [];
    public function __construct(private int $id, private array $items) {}
    public function get_id() { return $this->id; }
    public function get_items($t = 'line_item') { return $this->items; }
    public function get_reason() { return 'Talla pequeña'; }
    public function add_order_note($n) { $this->notas[] = $n; }
}

/** API: el pedido del TPV con sus líneas y POST /orders/{id}/returns validado. */
final class ApiDevolucionesFalsa extends TPV_Sync_API_Client
{
    public array $devoluciones = [];       // [clave => body]
    public array $fallanClaves = [];       // claves que la API rechaza
    public bool $altaFalla = false;         // POST /products rechazado
    private int $siguiente = 300;
    /** @param array $lineas líneas del pedido del TPV (forma de GET /orders/{id}) */
    public function __construct(public array $lineas = [], public array $catalogo = []) {}
    public function get(string $path, array $params = []): array
    {
        if (preg_match('#^/orders/(\d+)$#', $path)) { return ['data' => ['products' => $this->lineas]]; }
        return $path === '/products' ? ['data' => $this->catalogo, 'meta' => ['cursor' => null]] : [];
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if ($path === '/products') {
            return $this->altaFalla ? ['errors' => [['error' => 'validation_error', 'message' => 'price']]]
                                    : ['data' => ['product_id' => 888]];
        }
        if (isset($body['return_status_id']) && (int) $body['return_status_id'] !== 3) {
            return ['errors' => [['error' => 'validation_error', 'field' => 'return_status_id',
                'message' => 'invalid_return_status']]];
        }
        $delProducto = array_filter($this->lineas, fn ($l) => (int) $l['product_id'] === (int) $body['product_id']);
        if (count($delProducto) > 1 && empty($body['order_product_id'])) {
            return ['errors' => [['error' => 'validation_error', 'field' => 'order_product_id',
                'message' => 'order_product_id is required']]];
        }
        if (in_array($idempotencyKey, $this->fallanClaves, true)) {
            return ['errors' => [['error' => 'server_error', 'message' => 'caído']]];
        }
        $this->devoluciones[$idempotencyKey] = $body;
        return ['data' => ['return_id' => ++$this->siguiente]];
    }
}

function linea_tpv(int $opid, int $productId, int $pov = 0, float $qty = 1): array
{
    return ['order_product_id' => $opid, 'product_id' => $productId, 'quantity' => $qty,
            'options' => $pov ? [['product_option_value_id' => $pov]] : []];
}

/** Pedido 1 de Woo ya enviado al TPV como pedido 700. */
function devoluciones_tienda(): void
{
    pedidos_tienda();
    $GLOBALS['__wp_meta'][1] = ['_tpv_order_id' => 700];
}

function reembolso(int $id, array $items): ReembolsoWooFalso
{
    return $GLOBALS['__wc_orders'][$id] = new ReembolsoWooFalso($id, $items);
}

function devolver(ApiDevolucionesFalsa $api, int $refundId): TPV_Sync_Order_Sync
{
    $s = pedidos_sync_con($api);
    $s->on_wc_refund(1, $refundId);
    return $s;
}

function pedidos_sync_con(TPV_Sync_API_Client $api): TPV_Sync_Order_Sync
{
    $productos = new TPV_Sync_Product_Sync($api);
    $pedidos   = new TPV_Sync_Order_Sync($api, $productos);
    TPV_Sync::instance()->queue = new TPV_Sync_Queue($api, $productos, $pedidos);
    return $pedidos;
}

function run_devoluciones_tests(WooTestRunner $t): void
{
    $t->suite('Devoluciones — llegan, enteras y una sola vez (F3)');

    $t->test('P9 un reembolso de Woo LLEGA al TPV (antes: 422 invalid_return_status)', function ($t) {
        devoluciones_tienda();
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900)]);
        reembolso(50, [new ItemDevolucionFalso(501, 8306, 0, 1)]);
        devolver($api, 50);
        $t->assertEquals(1, count($api->devoluciones), 'la API rechazaba el return_status_id=1 del plugin');
        $t->assertEquals(1, (int) get_post_meta(50, '_tpv_refund_synced', true));
    });

    $t->test('T9 dos tallas del mismo producto: dos devoluciones, cada una a SU línea', function ($t) {
        devoluciones_tienda();
        $GLOBALS['__wp_meta'][8501] = ['_tpv_option_value_id' => 41];   // talla S
        $GLOBALS['__wp_meta'][8502] = ['_tpv_option_value_id' => 42];   // talla M
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900, 41), linea_tpv(72, 900, 42)]);
        reembolso(50, [new ItemDevolucionFalso(501, 8306, 8501, 1), new ItemDevolucionFalso(502, 8306, 8502, 1)]);
        devolver($api, 50);
        $t->assertEquals(2, count($api->devoluciones), 'con la clave por producto la 2.ª se perdía');
        $opids = array_map(fn ($b) => $b['order_product_id'] ?? null, array_values($api->devoluciones));
        sort($opids);
        $t->assertEquals([71, 72], $opids, 'cada talla vuelve a su línea (su stock)');
    });

    $t->test('T8 falla una línea: el reintento manda SOLO la que faltaba', function ($t) {
        devoluciones_tienda();
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900), linea_tpv(73, 777)]);
        $GLOBALS['__wp_meta'][8308]['_tpv_product_id'] = 777;
        reembolso(50, [new ItemDevolucionFalso(501, 8306, 0, 1), new ItemDevolucionFalso(502, 8308, 0, 1)]);
        $api->fallanClaves = ['wc-refund-50-502'];
        $s = devolver($api, 50);
        $t->assertEquals('', get_post_meta(50, '_tpv_refund_synced', true), 'no está entera');
        $api->fallanClaves = [];
        $antes = $api->devoluciones;
        $api->devoluciones = [];
        $s->on_wc_refund(1, 50);
        $t->assertEquals(['wc-refund-50-502'], array_keys($api->devoluciones),
            'reenviar la 501 pasadas 24 h (idempotencia caducada) la devolvería dos veces');
        $t->assertEquals(1, (int) get_post_meta(50, '_tpv_refund_synced', true));
        $t->assertEquals(['wc-refund-50-501'], array_keys($antes));
    });

    $t->test('T10 línea que el TPV no acepta: NO se marca sincronizada; queda pendiente con nota', function ($t) {
        devoluciones_tienda();
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900)]);
        $api->altaFalla = true;
        $r = reembolso(50, [new ItemDevolucionFalso(501, 8306, 0, 1), new ItemDevolucionFalso(502, 8310, 0, 1)]);
        devolver($api, 50);
        $t->assertEquals('', get_post_meta(50, '_tpv_refund_synced', true), 'antes se daba por hecha');
        $t->assert(get_post_meta(50, '_tpv_refund_pendiente', true) !== '', 'pendiente: puede arreglarse');
        $t->assertEquals(1, count($r->notas), 'la comerciante lo ve');
        $t->assertEquals(['wc-refund-50-501'], array_keys($api->devoluciones), 'la línea buena sí entra');
    });

    $t->test('línea cuyo producto NO está en el pedido del TPV (llegó a medias antes): nota, sin reintento eterno', function ($t) {
        devoluciones_tienda();
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900)]);
        $GLOBALS['__wp_meta'][8310]['_tpv_product_id'] = 888;   // enlazado, pero no vendido en el 700
        $r = reembolso(50, [new ItemDevolucionFalso(502, 8310, 0, 1)]);
        devolver($api, 50);
        $t->assertEquals('', get_post_meta(50, '_tpv_refund_synced', true), 'no se ha devuelto nada');
        $t->assertEquals('', get_post_meta(50, '_tpv_refund_pendiente', true), 'esperar no lo arregla');
        $t->assertEquals(1, count($r->notas));
        $t->assert(str_contains($r->notas[0] ?? '', 'a mano'), 'pide revisarlo a mano');
    });

    $t->test('reembolso de un pedido aún retenido: espera, y sale cuando sale el pedido', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wp_meta'][1] = ['_tpv_order_pendiente' => '{}'];
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900)]);
        reembolso(50, [new ItemDevolucionFalso(501, 8306, 0, 1)]);
        $s = devolver($api, 50);
        $t->assertEquals([], $api->devoluciones, 'no hay pedido en el TPV todavía');
        $t->assert(get_post_meta(50, '_tpv_refund_pendiente', true) !== '', 'antes: «skip» y se perdía');
        // El pedido se envía por fin.
        $GLOBALS['__wp_meta'][1] = ['_tpv_order_id' => 700];
        $r = $s->reintentarPendientes(10);
        $t->assertEquals(1, count($api->devoluciones), 'el mismo cron de los pedidos las reintenta');
        $t->assertEquals(1, $r['devoluciones'] ?? -1);
        $t->assertEquals('', get_post_meta(50, '_tpv_refund_pendiente', true));
    });

    $t->test('las devoluciones retenidas se recorren por tandas y el cursor vuelve a empezar', function ($t) {
        pedidos_tienda();
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900)]);
        $api->altaFalla = true;
        $s = pedidos_sync_con($api);
        // 51 y 52 siguen atascadas (su producto no se puede dar de alta); la 53 ya puede salir.
        foreach ([51 => 8310, 52 => 8310, 53 => 8306] as $rid => $pid) {
            reembolso($rid, [new ItemDevolucionFalso($rid * 10, $pid, 0, 1)]);
            $GLOBALS['__wp_meta'][$rid] = ['_tpv_refund_pendiente' => json_encode(['pedido' => 1])];
        }
        $GLOBALS['__wp_meta'][1] = ['_tpv_order_id' => 700];
        $s->reintentarDevolucionesPendientes(2);
        $t->assertEquals(0, count($api->devoluciones), 'primera tanda: 51 y 52, atascadas');
        $s->reintentarDevolucionesPendientes(2);
        $t->assertEquals(1, count($api->devoluciones), 'segunda tanda: la 53 no queda tapada');
        $s->reintentarDevolucionesPendientes(2);
        $t->assertEquals(0, (int) get_option('tpv_sync_devoluciones_cursor'), 'al acabar vuelve a empezar');
    });

    $t->test('venta anterior al conector (sin pedido en el TPV ni retenido): se omite', function ($t) {
        pedidos_tienda();
        $api = new ApiDevolucionesFalsa();
        reembolso(50, [new ItemDevolucionFalso(501, 8306, 0, 1)]);
        devolver($api, 50);
        $t->assertEquals([], $api->devoluciones);
        $t->assertEquals('', get_post_meta(50, '_tpv_refund_pendiente', true),
            'no hay nada que devolver en el TPV: esperar sería para siempre');
    });

    $t->test('dos reintentos sin línea: UNA sola nota', function ($t) {
        devoluciones_tienda();
        $api = new ApiDevolucionesFalsa([linea_tpv(71, 900)]);
        $api->altaFalla = true;
        $r = reembolso(50, [new ItemDevolucionFalso(502, 8310, 0, 1)]);
        $s = devolver($api, 50);
        $s->on_wc_refund(1, 50);
        $t->assertEquals(1, count($r->notas));
    });
}
