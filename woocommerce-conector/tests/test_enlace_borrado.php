<?php
declare(strict_types=1);
/**
 * ENLACE A UN PRODUCTO BORRADO EN EL TPV (lulubeauty, 30-09-2026).
 *
 * La comerciante limpió duplicados desde el panel del TPV (product_del_multi)
 * y 7 productos publicados en Woo se quedaron con `_tpv_product_id` apuntando
 * a productos que ya no existen. asegurarEnTpv() se fía de cualquier enlace
 * > 0, así que un pedido con uno de ellos lo rechazaba la API ENTERO
 * (`not_found:product_id:N`, 404) y la cola lo reintentaba igual para siempre:
 * la venta no llegaba nunca al TPV. Solo el guardado en Woo se curaba
 * (PATCH 404 ⇒ recrea).
 *
 * Regla del usuario: todo lo vendible en la tienda existe en el TPV y un
 * pedido nunca llega a medias. Un enlace roto se quita, el producto se vuelve
 * a asegurar (reenlazar si el TPV tiene su gemelo; alta si no) y el pedido se
 * reenvía. Una talla borrada va a nombre del padre, como una sin mapear.
 *
 * Se ejecuta send_to_tpv() de verdad, con WordPress, Woo y la API en memoria.
 */

require_once __DIR__ . '/test_pedidos_sin_enlace.php';

/** Línea de pedido de una talla concreta (get_variation_id > 0). */
final class ItemTallaFalso
{
    public function __construct(private int $productId, private int $variationId) {}
    public function get_product_id() { return $this->productId; }
    public function get_variation_id() { return $this->variationId; }
    public function get_quantity() { return 1.0; }
    public function get_total() { return 10.0; }
    public function get_total_tax() { return 2.1; }
    public function get_name() { return "Talla {$this->variationId}"; }
}

/**
 * La API del TPV con productos y tallas BORRADOS: no salen en el catálogo y
 * POST /orders los rechaza como lo hace OrderController (la 1ª línea que falla
 * aborta el pedido entero), con el cuerpo problem+json que negocia el cliente.
 */
class ApiConBorrados extends TPV_Sync_API_Client
{
    public array $pedidos = [];
    public array $altas = [];
    public int $intentosPedido = 0;
    public bool $formatoLegacy = false;
    public bool $rechazaTodo = false;
    private int $siguiente = 776;
    public function __construct(public array $catalogo = [], public array $borrados = [],
                                public array $tallasBorradas = []) {}
    public function get(string $path, array $params = []): array
    {
        return $path === '/products' ? ['data' => $this->catalogo, 'meta' => ['cursor' => null]] : [];
    }
    public function patch(string $path, array $body = []): array
    {
        return ['data' => ['product_id' => (int) basename($path)]];
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if ($path === '/products') {
            $this->altas[] = $body;
            return ['data' => ['product_id' => ++$this->siguiente]];
        }
        if ($path !== '/orders') {
            return ['results' => []];
        }
        $this->intentosPedido++;
        foreach ($body['products'] ?? [] as $linea) {
            $pid = (int) $linea['product_id'];
            if ($this->rechazaTodo || in_array($pid, $this->borrados, true)) {
                return $this->noEncontrado("not_found:product_id:$pid");
            }
            foreach ($linea['options'] ?? [] as $o) {
                $pov = (int) $o['product_option_value_id'];
                if (in_array($pov, $this->tallasBorradas, true)) {
                    return $this->noEncontrado("not_found:product_option_value_id:$pov");
                }
            }
        }
        $this->pedidos[] = $body;
        return self::decide(201, ['data' => ['order_id' => 5000 + count($this->pedidos)]]);
    }
    private function noEncontrado(string $codigo): array
    {
        if ($this->formatoLegacy) {
            return self::decide(404, ['errors' => [['error' => $codigo, 'message' => 'Recurso no encontrado.']]]);
        }
        return self::decide(404, [
            'type' => "https://errors.catinfog.com/$codigo", 'title' => 'Recurso no encontrado.',
            'status' => 404, 'code' => $codigo,
        ]);
    }
}

function borrados_sync(ApiConBorrados $api): TPV_Sync_Order_Sync
{
    $productos = new TPV_Sync_Product_Sync($api);
    $pedidos   = new TPV_Sync_Order_Sync($api, $productos);
    TPV_Sync::instance()->queue = new TPV_Sync_Queue($api, $productos, $pedidos);
    return $pedidos;
}

function run_enlace_borrado_tests(WooTestRunner $t): void
{
    $t->suite('Pedidos — enlace a un producto borrado en el TPV');

    // 8306 está enlazado al 900 (volcado_tienda); el TPV lo ha borrado.

    $t->test('el producto enlazado ya no existe: se vuelve a dar de alta y el pedido sale entero', function ($t) {
        pedidos_tienda();
        $api = new ApiConBorrados([], [900]);
        $p = pedido(1, [8306]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals(1, count($api->altas), 'todo lo vendible existe en el TPV');
        $t->assertEquals([777], array_column($api->pedidos[0]['products'] ?? [], 'product_id'),
            'antes la API lo rechazaba entero y la cola repetía lo mismo para siempre');
        $t->assertEquals(777, (int) get_post_meta(8306, '_tpv_product_id', true), 'el enlace queda arreglado');
        $t->assertEquals([], $GLOBALS['wpdb']->cola, 'no queda nada en la cola');
        $t->assertEquals(1, count(array_filter($p->notas, fn ($n) => str_contains($n, 'Registrado en TPV'))));
    });

    $t->test('si el TPV tiene su gemelo (mismo SKU), se reenlaza sin dar de alta', function ($t) {
        pedidos_tienda();
        $api = new ApiConBorrados([['product_id' => 960, 'model' => '2RKA78306', 'sku' => '2RKA78306']], [900]);
        pedido(1, [8306]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals([], $api->altas, 'un alta duplicaría el que la comerciante se quedó');
        $t->assertEquals([960], array_column($api->pedidos[0]['products'] ?? [], 'product_id'));
        $t->assertEquals(960, (int) get_post_meta(8306, '_tpv_product_id', true));
    });

    $t->test('solo se toca la línea rota: la otra conserva su enlace', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wp_meta'][8308]['_tpv_product_id'] = 950;
        $api = new ApiConBorrados([['product_id' => 950, 'model' => 'MS2428308', 'sku' => 'MS2428308']], [900]);
        pedido(1, [8306, 8308]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals(950, (int) get_post_meta(8308, '_tpv_product_id', true));
        $t->assertEquals([777, 950], array_column($api->pedidos[0]['products'] ?? [], 'product_id'));
    });

    $t->test('dos productos borrados en el mismo pedido: se rehacen los dos', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wp_meta'][8310]['_tpv_product_id'] = 901;
        $api = new ApiConBorrados([], [900, 901]);
        pedido(1, [8306, 8310]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals(2, count($api->altas));
        $t->assertEquals(1, count($api->pedidos), 'la API avisa de uno cada vez: no basta un solo reintento');
        $t->assertEquals([], $GLOBALS['wpdb']->cola);
    });

    $t->test('talla borrada en el TPV: la línea va a nombre del padre y el pedido entra', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wp_meta'][8401] = ['_tpv_option_value_id' => 55];
        $api = new ApiConBorrados([['product_id' => 900, 'model' => '2RKA78306', 'sku' => '2RKA78306']], [], [55]);
        $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, [new ItemTallaFalso(8306, 8401)]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals(1, count($api->pedidos), 'antes: rechazado entero por la talla');
        $t->assertEquals(['product_id' => 900], array_intersect_key($api->pedidos[0]['products'][0] ?? [],
            ['product_id' => 1, 'options' => 1]), 'sin la talla, como una variación sin mapear');
        $t->assertEquals('', get_post_meta(8401, '_tpv_option_value_id', true), 'el enlace roto no se reutiliza');
        $t->assertEquals(900, (int) get_post_meta(8306, '_tpv_product_id', true), 'el padre sigue enlazado');
    });

    $t->test('talla borrada junto a otra viva: solo pierde el enlace la borrada', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wp_meta'][8401] = ['_tpv_option_value_id' => 55];
        $GLOBALS['__wp_meta'][8402] = ['_tpv_option_value_id' => 56];
        $api = new ApiConBorrados([['product_id' => 900, 'model' => '2RKA78306', 'sku' => '2RKA78306']], [], [55]);
        $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, [new ItemTallaFalso(8306, 8401), new ItemTallaFalso(8306, 8402)]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals(56, (int) get_post_meta(8402, '_tpv_option_value_id', true),
            'la talla que el TPV sí tiene sigue atribuyendo su venta');
        $t->assertEquals([['product_option_value_id' => 56]], $api->pedidos[0]['products'][1]['options'] ?? null);
    });

    $t->test('el formato de error clásico (errors[0].error) también se reconoce', function ($t) {
        pedidos_tienda();
        $api = new ApiConBorrados([], [900]);
        $api->formatoLegacy = true;
        pedido(1, [8306]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals([777], array_column($api->pedidos[0]['products'] ?? [], 'product_id'));
    });

    $t->test('si el TPV lo sigue rechazando, no hay bucle: se para y va a la cola una vez', function ($t) {
        pedidos_tienda();
        $api = new ApiConBorrados();
        $api->rechazaTodo = true;
        $p = pedido(1, [8306]);
        borrados_sync($api)->send_to_tpv(1);
        $t->assertEquals(2, $api->intentosPedido, 'un reenvío por enlace arreglado, no más');
        $t->assertEquals(1, count($GLOBALS['wpdb']->cola), 'el error normal: a la cola con backoff');
        $t->assertEquals(1, count(array_filter($p->notas, fn ($n) => str_contains($n, 'Error al registrar'))));
    });

    $t->test('un 404 de un producto que no está en el pedido no desenlaza nada', function ($t) {
        pedidos_tienda();
        pedido(1, [8306]);
        // La API culpa a un id que ninguna línea lleva: no hay enlace que quitar.
        $s = borrados_sync(new class ([], []) extends ApiConBorrados {
            public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
            {
                if ($path === '/orders') {
                    $this->intentosPedido++;
                    return self::decide(404, ['code' => 'not_found:product_id:12345', 'status' => 404]);
                }
                return parent::post($path, $body, $idempotencyKey);
            }
        });
        $s->send_to_tpv(1);
        $t->assertEquals(900, (int) get_post_meta(8306, '_tpv_product_id', true));
        $t->assertEquals(1, count($GLOBALS['wpdb']->cola), 'error normal, sin reenvío');
    });
}
