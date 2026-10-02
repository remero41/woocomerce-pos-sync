<?php
declare(strict_types=1);
/**
 * VENTA A PESO: lo que el TPV vende por kg NO se publica en Woo (decisión del 02-10).
 *
 * El TPV vende a peso con la báscula: precio por kg/lb/100 g y stock con decimales
 * (4,25 kg). Woo guarda unidades enteras y su precio sería «por unidad». La API lo
 * avisa con `sold_by_weight` y el plugin:
 *
 *   - no lo crea en Woo; si ya estaba publicado, lo pasa a BORRADOR (no lo borra);
 *   - si el TPV lo desmarca, el upsert normal lo vuelve a publicar;
 *   - no copia su stock decimal a Woo (webhook stock.adjusted, cron semanal) ni
 *     empuja el stock entero de Woo al TPV (la API respondería 409);
 *   - «Reconciliar catálogos» no lo toca (la regla está en Reconciler::decidir).
 */

require_once __DIR__ . '/test_stock_tpv_a_woo.php';
require_once __DIR__ . '/test_pedidos_sin_enlace.php';
require_once dirname(__DIR__) . '/includes/class-reconciler.php';

if (!class_exists('WC_Product')) {
    class WC_Product
    {
        public function __construct(private int $id, private ?float $stock) {}
        public function get_id() { return $this->id; }
        public function get_stock_quantity() { return $this->stock; }
        public function is_type($t) { return $t === 'simple'; }
        public function get_parent_id() { return 0; }
    }
}

/** API que registra TODO lo que se le pide (para ver que no se escribe stock). */
final class ApiPesoFalsa extends TPV_Sync_API_Client
{
    public array $llamadas = [];
    public function __construct(private array $productos = []) {}
    public function get(string $path, array $params = []): array
    {
        $this->llamadas[] = "GET $path";
        if ($path === '/products') { return ['data' => array_values($this->productos), 'meta' => ['cursor' => null]]; }
        $id = (int) basename(dirname($path) === '/products' ? $path : dirname($path));
        return ['data' => $this->productos[$id] ?? []];
    }
    public function patch(string $path, array $body = []): array { $this->llamadas[] = "PATCH $path"; return ['data' => []]; }
    public function batch(array $ops): array
    {
        $r = [];
        foreach ($ops as $i => $op) {
            $id = (int) basename((string) $op['path']);
            $r[] = ['index' => $i, 'status' => 200, 'body' => ['data' => $this->productos[$id] ?? []]];
        }
        return ['results' => $r];
    }
}

function estado_post(int $id): string { return (string) ($GLOBALS['__wp_posts'][$id]->post_status ?? ''); }

function run_venta_a_peso_tests(WooTestRunner $t): void
{
    $t->suite('Venta a peso: no se publica en Woo');
    $sync = fn(array $p = []) => new TPV_Sync_Product_Sync(new ApiPesoFalsa($p));

    $t->test('producto a peso YA publicado ⇒ pasa a borrador, no se borra', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $r = $sync()->upsert(tpv_producto(502, ['sold_by_weight' => true, 'quantity' => 4.25, 'subtract' => true]));

        $t->assertEquals('draft', estado_post(8310), 'sigue publicado un producto que se vende por kg');
        $t->assertEquals('a_peso', $r);
        $t->assertEquals(5, $GLOBALS['__wp_meta'][8310]['_stock'] ?? null, 'el 4,25 kg no se copia a Woo');
        $t->assertEquals(502, $GLOBALS['__wp_meta'][8310]['_tpv_product_id'] ?? null, 'el enlace se conserva');
    });

    $t->test('producto a peso que Woo no tiene ⇒ no se crea', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_inserts'] = [];
        $r = $sync()->upsert(tpv_producto(777, ['sold_by_weight' => true, 'quantity' => 4.25]));

        $t->assertEquals([], $GLOBALS['__wp_inserts'], 'se creó en Woo un producto a peso');
        $t->assertEquals('a_peso', $r);
    });

    $t->test('el TPV lo desmarca ⇒ vuelve a publicarse', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $sync()->upsert(tpv_producto(502, ['sold_by_weight' => true]));
        $sync()->upsert(tpv_producto(502, ['sold_by_weight' => false, 'quantity' => 3, 'subtract' => true]));

        $t->assertEquals('publish', estado_post(8310));
        $t->assertEquals(3, $GLOBALS['__wp_meta'][8310]['_stock'] ?? null, 'vuelve a llevar el stock del TPV');
        $sync()->update_stock(502, 2.0);
        $t->assertEquals(2.0, $GLOBALS['__wp_meta'][8310]['_stock'] ?? null, 'y los ajustes de stock vuelven a llegar');
    });

    $t->test('API vieja (sin sold_by_weight) ⇒ todo como siempre', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes'];
        $r = $sync()->upsert(tpv_producto(502, ['quantity' => 2, 'subtract' => true]));
        $t->assertEquals('publish', estado_post(8310));
        $t->assertEquals('updated', $r);
    });

    $t->test('stock.adjusted de un producto a peso no se copia a Woo', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $sync()->upsert(tpv_producto(502, ['sold_by_weight' => true]));
        $sync()->update_stock(502, 3.75);
        $t->assertEquals(5, $GLOBALS['__wp_meta'][8310]['_stock'] ?? null);
    });

    $t->test('editar el stock en Woo de un producto a peso no lo empuja al TPV', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $sync()->upsert(tpv_producto(502, ['sold_by_weight' => true]));
        $api = new ApiPesoFalsa([502 => tpv_producto(502, ['quantity' => 4.25])]);
        (new TPV_Sync_Product_Sync($api))->push_wc_stock_change(new WC_Product(8310, 4.0), ['stock_quantity']);
        $t->assertEquals([], $api->llamadas, 'pisaría 4,25 kg con 4');
    });

    $t->test('una venta en Woo de un producto a peso no descuenta en el TPV', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $GLOBALS['__wp_meta'][8306] += ['_tpv_product_id' => 900];
        $sync()->upsert(tpv_producto(502, ['sold_by_weight' => true]));
        $GLOBALS['__wp_meta'][4242] = [];
        $GLOBALS['__wc_orders'][4242] = new PedidoWooFalso(4242, [
            new ItemPedidoFalso(8310, 1, 3.0), new ItemPedidoFalso(8306, 2, 6.0),
        ]);
        $api = new ApiPesoFalsa();
        (new TPV_Sync_Product_Sync($api))->deduct_stock_from_wc_order(4242);
        $t->assertEquals(['PATCH /products/900/stock'], $api->llamadas,
            'solo el producto por unidades descuenta; el de peso lo ajusta la tienda en el TPV');
    });

    $t->test('decidir(): un producto a peso no se reconcilia en ningún sentido', function ($t) {
        $pol = ['catalogo' => 'woo', 'stock' => 'woo'];
        $wc  = ['name' => 'A', 'price' => 1.0, 'sku' => 'X', 'quantity' => 4];
        $tpv = ['name' => 'B', 'price' => 2.0, 'sku' => 'X', 'quantity' => 4.25, 'sold_by_weight' => true];
        $d = TPV_Sync_Reconciler::decidir($wc, $tpv, $pol);
        $t->assertEquals('nada', $d['stock']['accion']);
        $t->assertEquals('nada', $d['catalogo']['accion']);
        $d2 = TPV_Sync_Reconciler::decidir(null, $tpv, ['catalogo' => 'tpv', 'stock' => 'tpv']);
        $t->assertEquals('nada', $d2['catalogo']['accion'], 'crearía en Woo un producto a peso');
        $d3 = TPV_Sync_Reconciler::decidir($wc, ['sold_by_weight' => false] + $tpv, $pol);
        $t->assertEquals('push', $d3['stock']['accion'], 'sin peso la regla de siempre');
    });

    $t->test('«Reconciliar catálogos» (manda Woo) no empuja el stock de un producto a peso', function ($t) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
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
            502 => tpv_producto(502, ['sold_by_weight' => true, 'subtract' => true, 'quantity' => 4.25,
                                      'name' => 'Producto 8310', 'price' => 3.12, 'sku' => 'R3UW28310']),
        ]);
        (new TPV_Sync_Product_Sync($api))->reconcileBidirectional(false, ['stock' => 'woo', 'catalogo' => 'woo']);
        $t->assertEquals([], $api->patches, 'el 5 entero de Woo pisaría los 4,25 kg del TPV');
    });

    $t->test('cron semanal: el producto a peso se salta, no se «corrige»', function ($t) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $api = new ApiPesoFalsa([502 => tpv_producto(502, ['sold_by_weight' => true, 'subtract' => true, 'quantity' => 4.25])]);
        $stats = (new TPV_Sync_Product_Sync($api))->reconcile(100);
        $t->assertEquals(5, $GLOBALS['__wp_meta'][8310]['_stock'] ?? null);
        $t->assertEquals(0, $stats['fixed'] ?? -1);
    });
}
