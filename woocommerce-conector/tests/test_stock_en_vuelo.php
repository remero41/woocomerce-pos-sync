<?php
declare(strict_types=1);
/**
 * EL REPASO DE STOCK NO DEVUELVE UNIDADES QUE YA SE VENDIERON EN LA WEB.
 *
 * El repaso pone en Woo el stock del TPV. Pero un pedido web que Woo ya restó
 * y que aún no ha llegado al TPV (una transferencia «en espera», que no se
 * manda hasta cobrarla; un pedido retenido por un producto sin enlace) no
 * está en ese número: copiarlo tal cual devolvía esas unidades a la tienda y
 * se podían vender dos veces. Desde el repaso nocturno (16-10-2026) esto pasa
 * cada noche, así que el stock que se escribe en Woo es el del TPV MENOS lo
 * que está «en vuelo».
 *
 * En vuelo = restado en Woo y sin `_tpv_order_id`, en espera / procesando /
 * completado, y posterior al primer pedido que llegó al TPV (o retenido). Lo
 * anterior a conectar la tienda ya estaba descontado cuando el TPV tomó el
 * stock de Woo: contarlo restaría dos veces.
 */

require_once __DIR__ . '/test_stock_tpv_a_woo.php';
require_once dirname(__DIR__) . '/includes/class-order-sync.php';
require_once dirname(__DIR__) . '/includes/class-en-vuelo.php';

/** Pedido de Woo con lo que mira el cálculo de unidades en vuelo. */
final class PedidoEnVueloFalso
{
    public function __construct(
        private int $id,
        private array $lineas,              // [[producto, variacion, cantidad], ...]
        private array $meta = [],
        private bool $reducido = true,
        private string $estado = 'on-hold'
    ) {}
    public function get_id() { return $this->id; }
    public function get_status() { return $this->estado; }
    public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    public function get_order_stock_reduced() { return $this->reducido; }
    public function get_items($tipo = 'line_item')
    {
        return array_map(static fn ($l) => new class ($l) {
            public function __construct(private array $l) {}
            public function get_product_id() { return $this->l[0]; }
            public function get_variation_id() { return $this->l[1]; }
            public function get_quantity() { return $this->l[2]; }
        }, $this->lineas);
    }
}

function run_stock_en_vuelo_tests(WooTestRunner $t): void
{
    $t->suite('Stock en vuelo: lo vendido en la web que el TPV aún no tiene');

    // ── Qué pedidos cuentan ───────────────────────────────────────────────
    $t->test('una transferencia en espera posterior a la conexión cuenta', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([new PedidoEnVueloFalso(120, [[8310, 0, 2]])], 100);
        $t->assertEquals([8310 => 2.0], $u);
    });

    $t->test('la talla cuenta en la variación, no en el padre', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([new PedidoEnVueloFalso(120, [[8300, 8301, 1], [8300, 8302, 3]])], 100);
        $t->assertEquals([8301 => 1.0, 8302 => 3.0], $u);
    });

    $t->test('varios pedidos del mismo artículo se suman', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([
            new PedidoEnVueloFalso(120, [[8310, 0, 2]]),
            new PedidoEnVueloFalso(121, [[8310, 0, 1]], [], true, 'processing'),
        ], 100);
        $t->assertEquals([8310 => 3.0], $u);
    });

    $t->test('un pedido que ya está en el TPV no cuenta (el TPV ya lo restó)', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([new PedidoEnVueloFalso(120, [[8310, 0, 2]], ['_tpv_order_id' => '5001'])], 100);
        $t->assertEquals([], $u);
    });

    $t->test('un pedido que Woo no restó (o ya repuso) no cuenta', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([new PedidoEnVueloFalso(120, [[8310, 0, 2]], [], false)], 100);
        $t->assertEquals([], $u);
    });

    $t->test('un pedido anterior a la conexión no cuenta (ya estaba en el stock volcado)', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([new PedidoEnVueloFalso(90, [[8310, 0, 2]], [], true, 'completed')], 100);
        $t->assertEquals([], $u);
    });

    $t->test('un pedido retenido cuenta aunque sea anterior al primero enviado', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([
            new PedidoEnVueloFalso(90, [[8310, 0, 2]], ['_tpv_order_pendiente' => '{"faltan":[8310]}'], true, 'processing'),
        ], 100);
        $t->assertEquals([8310 => 2.0], $u);
    });

    $t->test('sin ningún pedido enviado todavía, solo cuentan los retenidos', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([
            new PedidoEnVueloFalso(120, [[8310, 0, 2]]),
            new PedidoEnVueloFalso(121, [[8308, 0, 1]], ['_tpv_order_pendiente' => '{}'], true, 'processing'),
        ], 0);
        $t->assertEquals([8308 => 1.0], $u);
    });

    $t->test('el pedido en el límite (el primero enviado) no cuenta', function ($t) {
        $u = TPV_Sync_En_Vuelo::dePedidos([new PedidoEnVueloFalso(100, [[8310, 0, 2]])], 100);
        $t->assertEquals([], $u);
    });

    // ── El repaso escribe TPV − en vuelo ──────────────────────────────────
    $t->test('el repaso deja en Woo el stock del TPV menos lo vendido en la web', function ($t) {
        volcado_tienda();
        // El TPV tiene 6. Woo vendió 2 por transferencia (en espera) ⇒ Woo tiene 4.
        $GLOBALS['__wp_meta'][8306] = ['_tpv_product_id' => 900, '_manage_stock' => 'yes', '_stock' => 4];
        $api = new ApiReconcileFalsa([900 => tpv_producto(900, ['subtract' => true, 'quantity' => 6])]);
        $sync = (new TPV_Sync_Product_Sync($api))->conStockEnVuelo(fn () => [8306 => 2.0]);
        $stats = $sync->reconcile(100);

        $t->assertEquals(4.0, (float) stock_meta(8306, '_stock'), 'devolver las 2 unidades permitiría venderlas dos veces');
        $t->assertEquals(0, $stats['fixed'] ?? -1, 'no hay nada que corregir');
    });

    $t->test('si de verdad difiere, corrige a TPV − en vuelo', function ($t) {
        volcado_tienda();
        // TPV 6, en vuelo 2 ⇒ Woo debe tener 4; tiene 9 (una venta de caja que no llegó).
        $GLOBALS['__wp_meta'][8306] = ['_tpv_product_id' => 900, '_manage_stock' => 'yes', '_stock' => 9];
        $api = new ApiReconcileFalsa([900 => tpv_producto(900, ['subtract' => true, 'quantity' => 6])]);
        $sync = (new TPV_Sync_Product_Sync($api))->conStockEnVuelo(fn () => [8306 => 2.0]);
        $stats = $sync->reconcile(100);

        $t->assertEquals(4.0, (float) stock_meta(8306, '_stock'));
        $t->assertEquals(1, $stats['fixed'] ?? -1);
    });

    $t->test('las tallas también descuentan lo que está en vuelo', function ($t) {
        volcado_tienda();
        $wpdbAntes = $GLOBALS['wpdb'];
        $GLOBALS['__wp_meta'][8306] = ['_tpv_product_id' => 900, '_manage_stock' => 'yes'];
        $GLOBALS['__wp_meta'][8401] = ['_tpv_option_value_id' => 71, '_manage_stock' => 'yes', '_stock' => 9];
        $GLOBALS['wpdb'] = new class extends stdClass {
            public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta';
            public function insert($t, $f) { return 1; }
            public function prepare($sql, ...$a) { return (new WpdbFalso())->prepare($sql, ...$a); }
            public function get_var($q) {
                $esLaTalla = is_array($q) && str_contains((string) $q['sql'], "'_tpv_option_value_id'") && in_array(71, $q['args'], true);
                return $esLaTalla ? 8401 : (new WpdbFalso())->get_var($q);
            }
            public function get_col($q) { return []; }
            public function get_results($q, $f = null) { return []; }
        };
        if (!function_exists('wp_get_post_parent_id')) { function wp_get_post_parent_id($id) { return 0; } }
        $api = new ApiReconcileFalsa([900 => tpv_producto(900, ['subtract' => true, 'quantity' => 5,
            'options' => [['values' => [['product_option_value_id' => 71, 'quantity' => 5]]]]])]);
        $sync = (new TPV_Sync_Product_Sync($api))->conStockEnVuelo(fn () => [8401 => 1.0]);
        $sync->reconcile(100);

        $t->assertEquals(4.0, (float) stock_meta(8401, '_stock'), 'TPV 5 − 1 en vuelo');
        $GLOBALS['wpdb'] = $wpdbAntes;
    });

    // ── «Reconciliar catálogos» (el botón del panel) ─────────────────────
    $wpdbReconciliar = static function () {
        $GLOBALS['wpdb'] = new class extends stdClass {
            public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta';
            public function insert($t, $f) { return 1; }
            public function get_results($q, $f = null) { return []; }
            public function get_col($q) { return []; }
            public function prepare($sql, ...$a) { return (new WpdbFalso())->prepare($sql, ...$a); }
            public function get_var($q) { return (new WpdbFalso())->get_var($q); }
        };
        if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
    };

    $t->test('botón con stock del TPV: lo vendido en la web no es una diferencia', function ($t) use ($wpdbReconciliar) {
        volcado_tienda();
        $antes = $GLOBALS['wpdb'];
        $wpdbReconciliar();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 4];
        $api = new ApiReconciliarFalsa([502 => tpv_producto(502, ['subtract' => true, 'quantity' => 6,
            'name' => 'Producto 8310', 'price' => 3.12, 'sku' => 'R3UW28310'])]);
        $sync = (new TPV_Sync_Product_Sync($api))->conStockEnVuelo(fn () => [8310 => 2.0]);

        $sim = $sync->reconcileBidirectional(true, ['stock' => 'tpv', 'catalogo' => 'ninguno']);
        $t->assertEquals(0, $sim['divergentes'] ?? -1, 'Woo 4 + 2 en vuelo = TPV 6');
        $sync->reconcileBidirectional(false, ['stock' => 'tpv', 'catalogo' => 'ninguno']);
        $t->assertEquals(4, (int) stock_meta(8310, '_stock'), 'no devuelve las 2 unidades vendidas');
        $GLOBALS['wpdb'] = $antes;
    });

    $t->test('botón con stock del TPV que sí difiere: TPV − en vuelo', function ($t) use ($wpdbReconciliar) {
        volcado_tienda();
        $antes = $GLOBALS['wpdb'];
        $wpdbReconciliar();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 9];
        $api = new ApiReconciliarFalsa([502 => tpv_producto(502, ['subtract' => true, 'quantity' => 6,
            'name' => 'Producto 8310', 'price' => 3.12, 'sku' => 'R3UW28310'])]);
        $sync = (new TPV_Sync_Product_Sync($api))->conStockEnVuelo(fn () => [8310 => 2.0]);
        $sync->reconcileBidirectional(false, ['stock' => 'tpv', 'catalogo' => 'ninguno']);
        $t->assertEquals(4, (int) stock_meta(8310, '_stock'));
        $GLOBALS['wpdb'] = $antes;
    });

    $t->test('botón con stock de Woo: sube Woo + en vuelo (el TPV los restará al llegar)', function ($t) use ($wpdbReconciliar) {
        volcado_tienda();
        $antes = $GLOBALS['wpdb'];
        $wpdbReconciliar();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 4];
        $api = new ApiReconciliarFalsa([502 => tpv_producto(502, ['subtract' => true, 'quantity' => 3,
            'name' => 'Producto 8310', 'price' => 3.12, 'sku' => 'R3UW28310'])]);
        $sync = (new TPV_Sync_Product_Sync($api))->conStockEnVuelo(fn () => [8310 => 2.0]);
        $sync->reconcileBidirectional(false, ['stock' => 'woo', 'catalogo' => 'ninguno']);
        $t->assertEquals(6.0, $api->patches['/products/502']['quantity'] ?? 'ausente',
            'subir 4 dejaría el TPV en 2 cuando le lleguen los 2 pedidos');
        $GLOBALS['wpdb'] = $antes;
    });
}
