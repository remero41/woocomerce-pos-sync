<?php
declare(strict_types=1);
/**
 * VENTA DE UNA VARIACIÓN (módulo Catálogo sin Pedidos): descuenta de SU talla.
 *
 * Antes deduct_stock_from_wc_order() mandaba la venta al producto PADRE
 * (get_product_id()): en el TPV bajaba el stock general y no la talla, y el
 * siguiente recálculo (general = suma de tallas) deshacía el descuento. La
 * talla vendida seguía con el mismo stock (16-11-2026). La API del TPV ahora
 * contesta 409 stock_from_variants a una venta sin talla en un producto con
 * tallas y acepta `product_option_value_id`.
 */

require_once __DIR__ . '/test_venta_a_peso.php';

final class ItemVariacionFalso
{
    public function __construct(private int $productId, private int $variationId, private float $qty) {}
    public function get_product_id() { return $this->productId; }
    public function get_variation_id() { return $this->variationId; }
    public function get_quantity() { return $this->qty; }
    public function get_total() { return 0.0; }
    public function get_total_tax() { return 0.0; }
    public function get_name() { return 'Camiseta - M'; }
}

/** API que apunta cada PATCH con su cuerpo. */
final class ApiVentaVariacionFalsa extends TPV_Sync_API_Client
{
    public array $patches = [];
    public function __construct() {}
    public function patch(string $path, array $body = []): array { $this->patches[] = [$path, $body]; return ['data' => []]; }
    public function get(string $path, array $params = []): array { return ['data' => []]; }
}

function run_venta_variacion_stock_tests(WooTestRunner $t): void
{
    $t->suite('Venta de una variación: descuenta de su talla');

    $t->test('la venta de una variación manda la talla (product_option_value_id) al TPV', function ($t) {
        $GLOBALS['__wp_meta'][7100] = ['_tpv_product_id' => 610];
        $GLOBALS['__wp_meta'][7101] = ['_tpv_option_value_id' => 3301];
        $GLOBALS['__wp_meta'][5151] = [];
        $GLOBALS['__wc_orders'][5151] = new PedidoWooFalso(5151, [new ItemVariacionFalso(7100, 7101, 2)]);
        $api = new ApiVentaVariacionFalsa();
        (new TPV_Sync_Product_Sync($api))->deduct_stock_from_wc_order(5151);
        $t->assertEquals([['/products/610/stock', [
            'quantity_change'         => -2,
            'reason'                  => 'venta',
            'comment'                 => 'Venta WooCommerce #5151',
            'product_option_value_id' => 3301,
        ]]], $api->patches);
    });

    $t->test('una variación sin enlace a su talla no descuenta del padre', function ($t) {
        $GLOBALS['__wp_meta'][7200] = ['_tpv_product_id' => 620];
        $GLOBALS['__wp_meta'][7201] = [];
        $GLOBALS['__wp_meta'][5252] = [];
        $GLOBALS['__wc_orders'][5252] = new PedidoWooFalso(5252, [new ItemVariacionFalso(7200, 7201, 1)]);
        $api = new ApiVentaVariacionFalsa();
        (new TPV_Sync_Product_Sync($api))->deduct_stock_from_wc_order(5252);
        $t->assertEquals([], $api->patches, 'el general del padre es la suma de tallas: descontarlo no sirve');
    });

    $reconciliar = function (bool $variable): ApiReconciliarFalsa {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        if ($variable) {
            $GLOBALS['__wc_products'][8310] = new class {
                public function get_id() { return 8310; }
                public function get_sku() { return 'R3UW28310'; }
                public function get_regular_price() { return ''; }
                public function is_type($t) { return $t === 'variable'; }
                public function get_manage_stock() { return true; }
                public function get_stock_quantity() { return 5.0; }
                public function get_gallery_image_ids() { return []; }
            };
        }
        $GLOBALS['wpdb'] = new class extends stdClass {
            public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta';
            public function insert($t, $f) { return 1; }
            public function get_results($q, $f = null) { return []; }
            public function get_col($q) { return []; }
            public function prepare($sql, ...$a) { return (new WpdbFalso())->prepare($sql, ...$a); }
            public function get_var($q) { return (new WpdbFalso())->get_var($q); }
        };
        if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
        $api = new ApiReconciliarFalsa([
            502 => tpv_producto(502, ['subtract' => true, 'quantity' => 9, 'name' => 'Producto 8310', 'price' => 3.12, 'sku' => 'R3UW28310']),
        ]);
        (new TPV_Sync_Product_Sync($api))->reconcileBidirectional(false, ['stock' => 'woo', 'catalogo' => 'woo']);
        return $api;
    };

    $t->test('«Reconciliar» (manda Woo) no empuja el stock del padre de un variable', function ($t) use ($reconciliar) {
        $t->assertEquals([], $reconciliar(true)->patches,
            'el general del TPV es la suma de tallas: la API respondería 409 stock_from_variants');
    });

    $t->test('«Reconciliar» (manda Woo) sigue empujando el stock de un simple', function ($t) use ($reconciliar) {
        $t->assertEquals(['/products/502' => ['quantity' => 5.0]], $reconciliar(false)->patches);
    });

    $t->test('un producto simple sigue descontando sin talla', function ($t) {
        $GLOBALS['__wp_meta'][7300] = ['_tpv_product_id' => 630];
        $GLOBALS['__wp_meta'][5353] = [];
        $GLOBALS['__wc_orders'][5353] = new PedidoWooFalso(5353, [new ItemVariacionFalso(7300, 0, 3)]);
        $api = new ApiVentaVariacionFalsa();
        (new TPV_Sync_Product_Sync($api))->deduct_stock_from_wc_order(5353);
        $t->assertEquals([['/products/630/stock', [
            'quantity_change' => -3,
            'reason'          => 'venta',
            'comment'         => 'Venta WooCommerce #5353',
        ]]], $api->patches);
    });
}
