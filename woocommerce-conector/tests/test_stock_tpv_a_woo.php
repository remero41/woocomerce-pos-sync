<?php
declare(strict_types=1);
/**
 * TPV → WOO: EL STOCK NO PUEDE INVENTAR UN «AGOTADO».
 *
 * Tras el volcado de lulubeauty (28-09-2026) el TPV tenía los 847 productos a
 * stock 0. Tres caminos lo bajaban a Woo y dejaban la tienda «Agotado» aunque
 * Woo no gestionase stock de esos productos:
 *
 *   - upsert() (product.updated, ofertas, variantes, CSV importado en el TPV)
 *     forzaba `_manage_stock=yes` + `_stock` = el del TPV.
 *   - update_stock() (stock.adjusted, p. ej. una venta de caja: 0 → -1)
 *     escribía `_stock_status=outofstock`.
 *   - el cron semanal reconcile() copiaba el stock del TPV a Woo sin mirar si
 *     alguno de los dos lo cuenta.
 *
 * Se ejecuta el código real con WordPress en memoria (ver test_volcado_bulk).
 */

require_once __DIR__ . '/test_volcado_bulk.php';

if (!function_exists('wp_strip_all_tags'))  { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('wp_kses_post'))       { function wp_kses_post($s) { return (string) $s; } }
if (!function_exists('wp_update_post')) {
    // Aplica el estado al post en memoria: la venta a peso pasa a borrador y vuelve a publicar.
    function wp_update_post($d) {
        $id = (int) ($d['ID'] ?? 0);
        if (isset($d['post_status'], $GLOBALS['__wp_posts'][$id])) { $GLOBALS['__wp_posts'][$id]->post_status = $d['post_status']; }
        return $id;
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($d) { $GLOBALS['__wp_inserts'][] = $d; return 9000 + count($GLOBALS['__wp_meta']); }
}
if (!function_exists('is_wp_error'))        { function is_wp_error($x) { return false; } }
if (!function_exists('wc_format_decimal'))  { function wc_format_decimal($n) { return (string) $n; } }
if (!function_exists('wp_set_object_terms')) { function wp_set_object_terms($id, $t, $tax) { return true; } }
if (!function_exists('wc_delete_product_transients')) { function wc_delete_product_transients($id) {} }
if (!function_exists('clean_post_cache'))   { function clean_post_cache($id) {} }
if (!function_exists('has_term'))           { function has_term($t, $tax, $id) { return false; } }
if (!function_exists('get_the_title'))      { function get_the_title($id) { return "Producto $id"; } }

/** Producto del TPV tal y como lo devuelve GET /products/{id}. */
function tpv_producto(int $id, array $extra): array
{
    return $extra + [
        'product_id' => $id, 'name' => "TPV $id", 'model' => "M$id", 'sku' => '',
        'price' => 3.78, 'special_price' => null, 'quantity' => 0, 'status' => 1,
        'tax_class_id' => 0, 'description' => '',
    ];
}

/** API falsa para el cron: lista y detalle de productos. */
final class ApiReconcileFalsa extends TPV_Sync_API_Client
{
    public function __construct(private array $productos) {}

    public function get(string $path, array $params = []): array
    {
        return ['data' => array_values($this->productos), 'meta' => ['cursor' => null]];
    }

    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $results = [];
        foreach (($body['operations'] ?? []) as $i => $op) {
            $id = (int) basename((string) $op['path']);
            $results[] = ['index' => $i, 'status' => 200,
                'body' => ['data' => $this->productos[$id] ?? []], 'error' => null];
        }
        return ['results' => $results];
    }
}

/** API falsa para la ruta singular (alta/edición de un producto en Woo). */
final class ApiSingularFalsa extends TPV_Sync_API_Client
{
    public array $posts = [];
    public array $patches = [];
    public function __construct() {}
    public function get(string $path, array $params = []): array { return ['data' => [], 'meta' => []]; }
    public function patch(string $path, array $body = []): array
    {
        $this->patches[$path] = $body;
        return ['data' => ['product_id' => (int) basename($path)]];
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if ($path === '/products') {
            $this->posts[] = $body;
            return ['data' => ['product_id' => 777]];
        }
        return ['results' => []];
    }
}

/** API falsa para «Reconciliar catálogos». */
final class ApiReconciliarFalsa extends TPV_Sync_API_Client
{
    public array $patches = [];
    public function __construct(private array $productos) {}
    public function getAll(string $path, array $params = []): array { return array_values($this->productos); }
    public function patch(string $path, array $body = []): array
    {
        $this->patches[$path] = $body;
        return ['data' => []];
    }
}

function stock_meta(int $postId, string $clave)
{
    return $GLOBALS['__wp_meta'][$postId][$clave] ?? null;
}

function run_stock_tpv_a_woo_tests(WooTestRunner $t): void
{
    $t->suite('TPV → Woo: el stock no inventa un «Agotado»');

    $sync = fn() => new TPV_Sync_Product_Sync(new ApiVolcadoFalsa());

    // ── upsert ────────────────────────────────────────────────────────────
    $t->test('upsert con subtract=false ⇒ Woo sin gestion y con existencias', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8308] += ['_tpv_product_id' => 501, '_manage_stock' => 'no',
                                        '_stock_status' => 'instock'];
        $sync()->upsert(tpv_producto(501, ['subtract' => false, 'quantity' => 0]));

        $t->assertEquals('no', stock_meta(8308, '_manage_stock'));
        $t->assertEquals('instock', stock_meta(8308, '_stock_status'),
            'el 0 de un producto que el TPV no cuenta no es un agotado');
    });

    $t->test('upsert sin subtract (API vieja) respeta a Woo si no gestiona', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8308] += ['_tpv_product_id' => 501, '_manage_stock' => 'no',
                                        '_stock_status' => 'instock'];
        $sync()->upsert(tpv_producto(501, ['quantity' => 0]));

        $t->assertEquals('no', stock_meta(8308, '_manage_stock'),
            'forzar la gestión con un 0 dejaba la tienda «Agotado»');
        $t->assertEquals('instock', stock_meta(8308, '_stock_status'));
    });

    $t->test('upsert con subtract=true copia el stock del TPV', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes'];
        $sync()->upsert(tpv_producto(502, ['subtract' => true, 'quantity' => 3]));

        $t->assertEquals('yes', stock_meta(8310, '_manage_stock'));
        $t->assertEquals(3, stock_meta(8310, '_stock'));
        $t->assertEquals('instock', stock_meta(8310, '_stock_status'));
    });

    // ── update_stock (evento stock.adjusted) ──────────────────────────────
    $t->test('una venta de caja (0 → -1) no agota un producto que Woo no cuenta', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8308] += ['_tpv_product_id' => 501, '_manage_stock' => 'no',
                                        '_stock_status' => 'instock'];
        $sync()->update_stock(501, -1.0);

        $t->assertEquals('instock', stock_meta(8308, '_stock_status'));
        $t->assertEquals(null, stock_meta(8308, '_stock'), 'ni se le escribe un número');
    });

    $t->test('en un producto que Woo sí cuenta, el evento se aplica', function ($t) use ($sync) {
        volcado_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes',
                                        '_stock' => 5];
        $sync()->update_stock(502, 4.0);

        $t->assertEquals(4.0, stock_meta(8310, '_stock'));
    });

    // ── ruta singular: crear/editar un producto en Woo ────────────────────
    $t->test('alta en Woo sin gestion ⇒ subtract=0 y sin cantidad', function ($t) {
        volcado_tienda();
        $api = new ApiSingularFalsa();
        (new TPV_Sync_Product_Sync($api))->push_wc_product_to_tpv(8308);

        $body = $api->posts[0] ?? [];
        $t->assertEquals(0, $body['subtract'] ?? 'ausente');
        $t->assert(!array_key_exists('quantity', $body), 'no nace como «0 unidades»');
    });

    $t->test('alta en Woo con gestion ⇒ subtract=1 y su cantidad', function ($t) {
        volcado_tienda();
        $api = new ApiSingularFalsa();
        (new TPV_Sync_Product_Sync($api))->push_wc_product_to_tpv(8310);

        $body = $api->posts[0] ?? [];
        $t->assertEquals(1, $body['subtract'] ?? 'ausente');
        $t->assertEquals(5.0, $body['quantity'] ?? 'ausente');
    });

    $t->test('editar en Woo manda subtract pero NUNCA la cantidad', function ($t) {
        volcado_tienda();   // 8306 ya enlazado con el 900
        $api = new ApiSingularFalsa();
        (new TPV_Sync_Product_Sync($api))->push_wc_product_to_tpv(8306);

        $body = $api->patches['/products/900'] ?? [];
        $t->assertEquals(1, $body['subtract'] ?? 'ausente');
        $t->assert(!array_key_exists('quantity', $body),
            'el PATCH con quantity pisaría el stock vendido en caja');
    });

    // ── «Reconciliar catálogos» con el stock mandado por Woo ──────────────
    // Es la herramienta para cargar el stock inicial tras el volcado.
    $t->test('reconciliar (stock=woo) solo sube stock de lo que los dos cuentan', function ($t) {
        volcado_tienda();
        // Woo no gestiona, pero guarda un _stock=7 de cuando sí lo hacía.
        $GLOBALS['__wp_meta'][8308] += ['_tpv_product_id' => 501, '_manage_stock' => 'no', '_stock' => 7];
        // Woo gestiona 5; el TPV cuenta y tiene 0 (el volcado no lo cargó).
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

        // Catálogo idéntico en los dos lados: solo se mide el stock.
        $api = new ApiReconciliarFalsa([
            501 => tpv_producto(501, ['subtract' => false, 'quantity' => 0,
                                      'name' => 'Producto 8308', 'price' => 3.12, 'sku' => 'MS2428308']),
            502 => tpv_producto(502, ['subtract' => true,  'quantity' => 0,
                                      'name' => 'Producto 8310', 'price' => 3.12, 'sku' => 'R3UW28310']),
        ]);
        $sync = new TPV_Sync_Product_Sync($api);

        $sim = $sync->reconcileBidirectional(true, ['stock' => 'woo', 'catalogo' => 'ninguno']);
        $t->assertEquals(1, $sim['divergentes'] ?? -1,
            'la simulación no cuenta como diferencia el _stock huérfano');

        $sync->reconcileBidirectional(false, ['stock' => 'woo', 'catalogo' => 'ninguno']);
        $t->assertEquals(5.0, $api->patches['/products/502']['quantity'] ?? 'ausente',
            'el stock que Woo sí cuenta se carga en el TPV');
        $t->assert(!isset($api->patches['/products/501']),
            'el 7 huérfano no viaja a un producto que el TPV no resta');
    });

    // ── cron semanal ──────────────────────────────────────────────────────
    $t->test('el cron solo corrige stock donde los DOS lados cuentan', function ($t) {
        volcado_tienda();
        // Woo cuenta 5, el TPV no resta (0 sin significado) ⇒ no se toca.
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        // Woo no cuenta ⇒ no se toca aunque el TPV diga 0.
        $GLOBALS['__wp_meta'][8308] += ['_tpv_product_id' => 501, '_manage_stock' => 'no',
                                        '_stock_status' => 'instock'];
        // Los dos cuentan y difieren ⇒ manda el TPV (dueño del stock).
        $GLOBALS['__wp_meta'][8306]  = ['_tpv_product_id' => 900, '_manage_stock' => 'yes', '_stock' => 8];

        $api = new ApiReconcileFalsa([
            502 => tpv_producto(502, ['subtract' => false, 'quantity' => 0]),
            501 => tpv_producto(501, ['subtract' => true,  'quantity' => 0]),
            900 => tpv_producto(900, ['subtract' => true,  'quantity' => 6]),
        ]);
        $stats = (new TPV_Sync_Product_Sync($api))->reconcile(100);

        $t->assertEquals(5, stock_meta(8310, '_stock'), 'el TPV no cuenta: su 0 no pisa el 5 de Woo');
        $t->assertEquals('instock', stock_meta(8308, '_stock_status'), 'Woo no cuenta: no se agota');
        $t->assertEquals(6.0, (float) stock_meta(8306, '_stock'), 'los dos cuentan: corrige al TPV');
        $t->assertEquals(1, $stats['fixed'] ?? -1, 'una sola corrección');
    });
}
