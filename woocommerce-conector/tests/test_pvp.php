<?php
declare(strict_types=1);
/**
 * EL MISMO PRECIO FINAL EN LA WEB Y EN LA CAJA (SPEC_pvp_tienda_caja, fase 1).
 *
 * lulubeauty (30-09-2026): su web enseñaba «9,92 € PVP IVA incl.» y el TPV
 * cobraba 12,00 €. El conector calculaba el precio sin IVA con la
 * configuración de impuestos de WooCommerce (wc_get_price_excluding_tax) y,
 * con cualquier configuración distinta de la ideal, el TPV cobraba de más.
 *
 * Regla nueva: el conector manda lo que Woo COBRA a un cliente de España (el
 * PVP) y el TPV calcula el precio sin IVA con su clase de impuesto. Solo si la
 * API lo anuncia (`/health` → capabilities: price_gross_write): contra una API
 * vieja, el PVP se guardaría como precio sin IVA y el TPV cobraría un 21 % más.
 */

require_once __DIR__ . '/test_volcado_bulk.php';   // WP en memoria (get_post, metas, WpdbFalso…)
require_once dirname(__DIR__) . '/includes/class-precio-pvp.php';
require_once __DIR__ . '/test_stock_tpv_a_woo.php';   // tpv_producto() + stubs de upsert

// ─── HTTP en memoria: registra cada petición y contesta según la ruta ─────────
$GLOBALS['__http'] = ['peticiones' => [], 'capacidades' => ['price_gross_write'], 'health' => 200];
if (!function_exists('get_locale')) { function get_locale() { return 'es_ES'; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0) { return json_encode($v, $f); } }
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return false; } }
function pvp_http(string $metodo, string $url, array $args): array
{
    $GLOBALS['__http']['peticiones'][] = ['metodo' => $metodo, 'url' => $url,
        'headers' => $args['headers'] ?? [], 'body' => json_decode((string) ($args['body'] ?? ''), true)];
    $ruta = (string) parse_url($url, PHP_URL_PATH);
    if (str_ends_with($ruta, '/auth/token')) { return ['code' => 200, 'body' => ['access_token' => 't', 'expires_in' => 3600]]; }
    if (str_ends_with($ruta, '/health')) {
        return ['code' => $GLOBALS['__http']['health'], 'body' => ['status' => 'ok', 'capabilities' => $GLOBALS['__http']['capacidades']]];
    }
    if (str_ends_with($ruta, '/products/bulk')) {
        $items = json_decode((string) ($args['body'] ?? ''), true)['items'] ?? [];
        $res = [];
        foreach ($items as $i => $it) { $res[] = ['index' => $i, 'status' => 200, 'body' => ['product_id' => 900, 'action' => 'updated'], 'error' => null]; }
        return ['code' => 200, 'body' => ['results' => $res, 'summary' => ['total' => count($res), 'ok' => count($res), 'failed' => 0]]];
    }
    if (preg_match('#/products/(\d+)$#', $ruta, $m)) { return ['code' => 200, 'body' => ['data' => ['product_id' => (int) $m[1]]]]; }
    if (str_ends_with($ruta, '/products') && $metodo === 'GET') {
        // Catálogo del TPV (fase 2: los precios con IVA para comparar con la web).
        if (!empty($GLOBALS['__http']['catalogoFalla'])) { return ['code' => 503, 'body' => ['error' => 'caido']]; }
        return ['code' => 200, 'body' => ['data' => $GLOBALS['__http']['catalogo'] ?? [], 'meta' => ['cursor' => null]]];
    }
    if (str_ends_with($ruta, '/products')) { return ['code' => 201, 'body' => ['data' => ['product_id' => 501]]]; }
    return ['code' => 200, 'body' => ['results' => []]];
}
if (!function_exists('wp_remote_post'))    { function wp_remote_post($u, $a = [])    { return pvp_http('POST', $u, $a); } }
if (!function_exists('wp_remote_get'))     { function wp_remote_get($u, $a = [])     { return pvp_http('GET', $u, $a); } }
if (!function_exists('wp_remote_request')) { function wp_remote_request($u, $a = []) { return pvp_http($a['method'] ?? 'GET', $u, $a); } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return json_encode($r['body'] ?? []); } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return $r['code'] ?? 0; } }
if (!function_exists('wp_remote_retrieve_header')) { function wp_remote_retrieve_header($r, $h) { return ''; } }

// ─── WooCommerce fiscal en memoria ────────────────────────────────────────────
if (!class_exists('WC_Tax')) {
    final class WC_Tax
    {
        /** Tarifas de la dirección base de la tienda, por clase de impuesto. */
        public static function get_base_tax_rates($clase = '') { return $GLOBALS['__woo_tasas'][(string) $clase] ?? []; }
    }
}
if (!function_exists('wc_get_price_decimals')) { function wc_get_price_decimals() { return 2; } }
if (!function_exists('wc_get_price_excluding_tax')) {
    /** Como Woo: solo quita IVA si los precios se introducen con impuestos y hay tarifa. */
    function wc_get_price_excluding_tax($p, $args = []) {
        $precio = (float) ($args['price'] ?? 0);
        if (get_option('woocommerce_prices_include_tax') !== 'yes') { return $precio; }
        $pct = 0.0; foreach (WC_Tax::get_base_tax_rates($p->get_tax_class()) as $r) { $pct += (float) $r['rate']; }
        return $precio / (1 + $pct / 100);
    }
}

final class ProductoPvpFalso
{
    public function __construct(private int $id, private string $precio, private string $rebaja = '',
                                private string $estadoIva = 'taxable', private string $clase = '') {}
    public function get_id() { return $this->id; }
    public function get_sku() { return 'PVP-' . $this->id; }
    public function get_regular_price() { return $this->precio; }
    public function get_sale_price() { return $this->rebaja; }
    public function is_on_sale() { return $this->rebaja !== ''; }
    public function get_tax_status() { return $this->estadoIva; }
    public function get_tax_class() { return $this->clase; }
    public function is_type($t) { return $t === 'simple'; }
    public function get_manage_stock() { return false; }
    public function get_stock_quantity() { return null; }
    public function get_gallery_image_ids() { return []; }
}

/**
 * Tienda con Woo configurado como se indique y un producto enlazado (TPV 900).
 * $woo: [impuestos 'yes'|'no', precios con IVA 'yes'|'no', tarifa ES %|null]
 */
function pvp_tienda(array $woo, ProductoPvpFalso $producto, array $capacidades = ['price_gross_write'], array $metas = []): void
{
    volcado_tienda();
    [$impuestos, $conIva, $tarifa] = $woo;
    $GLOBALS['__wp_options'] = [
        'woocommerce_calc_taxes'           => $impuestos,
        'woocommerce_prices_include_tax'   => $conIva,
        'tpv_sync_api_url'                 => 'https://tpv.test/api/v1',
        'tpv_sync_client_id'               => 'wc_x',
        'tpv_sync_client_secret'           => 's',
    ];
    $GLOBALS['__woo_tasas'] = $tarifa === null ? [] : ['' => [['rate' => (string) $tarifa]]];
    $GLOBALS['__http'] = ['peticiones' => [], 'capacidades' => $capacidades, 'health' => 200];
    $id = $producto->get_id();
    $GLOBALS['__wp_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product', 'post_title' => "P$id",
        'post_content' => '', 'post_status' => 'publish'];
    $GLOBALS['__wc_products'][$id] = $producto;
    $GLOBALS['__wp_meta'][$id] = $metas + ['_tpv_product_id' => 900];
}

function pvp_ultima(string $metodo, string $trozoRuta): ?array
{
    foreach (array_reverse($GLOBALS['__http']['peticiones']) as $p) {
        if ($p['metodo'] === $metodo && str_contains($p['url'], $trozoRuta)) { return $p; }
    }
    return null;
}

function pvp_guardar(int $id): ?array
{
    (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_product_to_tpv($id);
    return pvp_ultima('PATCH', '/products/900');
}

function run_pvp_tests(WooTestRunner $t): void
{
    $t->suite('PVP — la regla, pura');

    $casos = [
        // impuestos, precios con IVA, gravado, % base, precio → PVP que cobra Woo a España
        ['impuestos desactivados',                 false, false, true,  21.0, 9.92, 9.92],
        ['precios con IVA',                        true,  true,  true,  21.0, 9.92, 9.92],
        ['precios con IVA sin tarifa',             true,  true,  true,  0.0,  9.92, 9.92],
        ['precios SIN IVA, tarifa 21 %',           true,  false, true,  21.0, 9.92, 12.00],
        ['precios SIN IVA y sin tarifa',           true,  false, true,  0.0,  9.92, 9.92],
        ['producto no gravado',                    true,  false, false, 21.0, 9.92, 9.92],
        ['precios SIN IVA, tarifa 10 %',           true,  false, true,  10.0, 10.00, 11.00],
    ];
    foreach ($casos as [$nombre, $imp, $conIva, $grav, $pct, $precio, $pvp]) {
        $t->test("PVP: $nombre", function ($t) use ($imp, $conIva, $grav, $pct, $precio, $pvp) {
            $t->assertEquals($pvp, TPV_Sync_Precio_Pvp::deWoo($precio, $imp, $conIva, $grav, $pct, 2));
        });
    }

    $t->suite('PVP — el cliente solo manda precios con IVA si la API lo anuncia');

    $t->test('API nueva: las escrituras llevan X-Price-Input: gross; las lecturas no', function ($t) {
        pvp_tienda(['yes', 'yes', 21], new ProductoPvpFalso(10, '9.92'));
        $api = new TPV_Sync_API_Client();
        $api->patch('/products/900', ['price' => 1]);
        $api->get('/products/900');
        $t->assertEquals('gross', pvp_ultima('PATCH', '/products/900')['headers']['X-Price-Input'] ?? null);
        $t->assert(!isset(pvp_ultima('GET', '/products/900')['headers']['X-Price-Input']), 'leer no es escribir');
    });

    $t->test('API vieja (sin la capacidad): NI la cabecera NI el PVP', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92'), []);
        $p = pvp_guardar(10);
        $t->assert(!isset($p['headers']['X-Price-Input']), 'una API vieja guardaría el PVP como precio sin IVA');
        $t->assertEquals(9.92, (float) ($p['body']['price'] ?? -1), 'se manda lo de siempre');
    });

    $t->test('si /health falla, se trata como API vieja', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92'));
        $GLOBALS['__http']['health'] = 500;
        $p = pvp_guardar(10);
        $t->assert(!isset($p['headers']['X-Price-Input']), 'ante la duda, el camino de siempre');
    });

    $t->test('la capacidad se pregunta una vez, no en cada petición', function ($t) {
        pvp_tienda(['yes', 'yes', 21], new ProductoPvpFalso(10, '9.92'));
        $api = new TPV_Sync_API_Client();
        $api->patch('/products/900', ['price' => 1]);
        $api->patch('/products/900', ['price' => 2]);
        $n = count(array_filter($GLOBALS['__http']['peticiones'], fn ($p) => str_contains($p['url'], '/health')));
        $t->assertEquals(1, $n);
    });

    $t->suite('PVP — guardar un producto manda lo que Woo cobra');

    $t->test('lulubeauty antes de arreglar Woo (precios SIN IVA, tarifa 21 %): viaja 12,00, no 9,92', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92'));
        $p = pvp_guardar(10);
        $t->assertEquals(12.00, (float) ($p['body']['price'] ?? -1), 'es lo que Woo cobra al pagar');
        $t->assertEquals('gross', $p['headers']['X-Price-Input'] ?? null);
    });

    $t->test('lulubeauty después (precios CON IVA): viaja 9,92 y el TPV cobrará 9,92', function ($t) {
        pvp_tienda(['yes', 'yes', 21], new ProductoPvpFalso(10, '9.92'));
        $t->assertEquals(9.92, (float) (pvp_guardar(10)['body']['price'] ?? -1));
    });

    $t->test('alta de un producto nuevo (POST): también en PVP y con la cabecera', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92'));
        unset($GLOBALS['__wp_meta'][10]['_tpv_product_id']);
        (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_product_to_tpv(10);
        $p = pvp_ultima('POST', '/products');
        $t->assertEquals(12.00, (float) ($p['body']['price'] ?? -1));
        $t->assertEquals('gross', $p['headers']['X-Price-Input'] ?? null, 'el alta va por post(), no por patch()');
    });

    $t->test('precios CON IVA pero sin tarifa: viaja 9,92 (antes el TPV cobraba 12,00)', function ($t) {
        pvp_tienda(['yes', 'yes', null], new ProductoPvpFalso(10, '9.92'));
        $t->assertEquals(9.92, (float) (pvp_guardar(10)['body']['price'] ?? -1));
    });

    $t->test('producto exento (no gravado) con precios SIN IVA: viaja el precio, no +21 %', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92', '', 'none'));
        $t->assertEquals(9.92, (float) (pvp_guardar(10)['body']['price'] ?? -1), 'Woo no le suma IVA a un exento');
    });

    $t->test('impuestos desactivados en Woo: viaja el precio tal cual', function ($t) {
        pvp_tienda(['no', 'no', 21], new ProductoPvpFalso(10, '9.92'));
        $t->assertEquals(9.92, (float) (pvp_guardar(10)['body']['price'] ?? -1));
    });

    $t->suite('PVP — las rebajas de la tienda llegan al TPV (D1)');

    $t->test('producto rebajado: la rebaja viaja como special_price, en PVP', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92', '6.20'));
        $p = pvp_guardar(10);
        $t->assertEquals(7.50, (float) ($p['body']['special_price'] ?? -1), '6,20 sin IVA = 7,50 lo que se cobra');
        $t->assertEquals('1', (string) get_post_meta(10, '_tpv_rebaja_enviada', true));
    });

    $t->test('volcado en bloque: la rebaja también viaja', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92', '6.20'));
        (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->push_wc_products_bulk([10]);
        $item = pvp_ultima('POST', '/products/bulk')['body']['items'][0] ?? [];
        $t->assertEquals(12.00, (float) ($item['price'] ?? -1), 'precio en PVP');
        $t->assertEquals(7.50, (float) ($item['special_price'] ?? -1), 'y la rebaja: antes el volcado no la mandaba');
    });

    $t->test('se acabó la rebaja en la tienda: se quita en el TPV', function ($t) {
        pvp_tienda(['yes', 'yes', 21], new ProductoPvpFalso(10, '9.92'), ['price_gross_write'], ['_tpv_rebaja_enviada' => '1']);
        $p = pvp_guardar(10);
        $t->assert(array_key_exists('special_price', $p['body'] ?? []) && $p['body']['special_price'] === null,
            'si no, la tienda física seguiría rebajando');
        $t->assertEquals('', (string) get_post_meta(10, '_tpv_rebaja_enviada', true));
    });

    $t->test('nunca hubo rebaja: no se toca la del TPV (promoción de caja)', function ($t) {
        pvp_tienda(['yes', 'yes', 21], new ProductoPvpFalso(10, '9.92'));
        $p = pvp_guardar(10);
        $t->assert(!array_key_exists('special_price', $p['body'] ?? []), 'mandar null borraría una promoción puesta en caja');
    });

    $t->test('API vieja: tampoco se mandan rebajas (no sabría en qué unidad vienen)', function ($t) {
        pvp_tienda(['yes', 'no', 21], new ProductoPvpFalso(10, '9.92', '6.20'), []);
        $p = pvp_guardar(10);
        $t->assert(!array_key_exists('special_price', $p['body'] ?? []), 'rebaja sin IVA en una API que no sabe convertir');
    });

    $t->suite('PVP — una actualización desde el TPV no borra la rebaja de la tienda');

    $t->test('manda Woo: el TPV sin precio especial NO borra la rebaja de Woo', function ($t) {
        volcado_tienda();
        $GLOBALS['__wp_options'] = ['tpv_sync_principal' => 'wc'];
        $GLOBALS['__wp_meta'][8308] += ['_tpv_product_id' => 501, '_sale_price' => '6.20', '_price' => '6.20'];
        (new TPV_Sync_Product_Sync(new ApiVolcadoFalsa()))->upsert(tpv_producto(501, ['price' => 9.92]));
        $t->assertEquals('6.20', (string) get_post_meta(8308, '_sale_price', true),
            'antes: cualquier edición en el TPV borraba la rebaja de la web');
        $t->assertEquals(6.2, (float) get_post_meta(8308, '_price', true), 'y la web sigue cobrando la rebaja');
    });

    $t->test('manda el TPV: su «sin rebaja» sí se aplica en la tienda', function ($t) {
        volcado_tienda();
        $GLOBALS['__wp_options'] = ['tpv_sync_principal' => 'tpv'];
        $GLOBALS['__wp_meta'][8308] += ['_tpv_product_id' => 501, '_sale_price' => '6.20', '_price' => '6.20'];
        (new TPV_Sync_Product_Sync(new ApiVolcadoFalsa()))->upsert(tpv_producto(501, ['price' => 9.92]));
        $t->assertEquals('', (string) get_post_meta(8308, '_sale_price', true));
        $t->assertEquals(9.92, (float) get_post_meta(8308, '_price', true));
    });
}
