<?php
declare(strict_types=1);
/**
 * LA WEB Y LA CAJA COBRAN LO MISMO, SIN QUE NADIE PULSE NADA
 * (SPEC_pvp_tienda_caja, fase 2: P4 + D3).
 *
 * lulubeauty (30-09-2026): tras la fase 1, 476 de 498 productos cuadraban y 8
 * seguían cobrando un 21 % más en la caja. Decisiones del usuario:
 *  - la referencia es lo que la web COBRA a un cliente de España;
 *  - la corrección es automática, sin botón («el cliente no sabe y la puede
 *    liar»), y sin freno de cambios masivos: si la tienda configura mal sus
 *    impuestos, la caja cobra lo que cobre su web;
 *  - una promoción puesta solo en caja no es un descuadre (fase 1: «nunca la
 *    tuvo → no se dice nada»).
 *
 * La comparación va dentro de la autocuración, que ya recorre el catálogo con
 * cursor, candado y presupuesto de tiempo. Se ejecuta autocurar() de verdad,
 * con el cliente HTTP real contra una API en memoria.
 */

require_once __DIR__ . '/test_autocuracion.php';   // WpdbAutocura, add_option, tpv_sync_module_catalog
require_once __DIR__ . '/test_pvp.php';            // HTTP en memoria, WC_Tax, ProductoPvpFalso

/** Producto variable: no se compara (el TPV no tiene precio por talla que casar). */
final class ProductoVariablePvpFalso
{
    public function __construct(private int $id) {}
    public function get_id() { return $this->id; }
    public function get_sku() { return 'VAR-' . $this->id; }
    public function get_regular_price() { return '10'; }
    public function get_sale_price() { return ''; }
    public function is_on_sale() { return false; }
    public function get_tax_status() { return 'taxable'; }
    public function get_tax_class() { return ''; }
    public function is_type($t) { return $t === 'variable'; }
    public function get_gallery_image_ids() { return []; }
}

/**
 * Tienda: Woo con [impuestos, precios con IVA, tarifa ES], productos
 * [postId => producto] enlazados a TPV 900+i, y el catálogo del TPV tal y
 * como lo devuelve la API pedido con IVA: [tpvId => [precio, rebaja|null]].
 */
function cuadre_tienda(array $woo, array $productos, array $tpv, array $opciones = [],
                       array $capacidades = ['price_gross_write']): void
{
    [$impuestos, $conIva, $tarifa] = $woo;
    $GLOBALS['wpdb'] = new WpdbAutocura();
    $GLOBALS['__wp_meta'] = $GLOBALS['__wp_thumbs'] = $GLOBALS['__wp_posts'] = $GLOBALS['__wc_products'] = [];
    $GLOBALS['__wp_options'] = $opciones + [
        'woocommerce_calc_taxes'         => $impuestos,
        'woocommerce_prices_include_tax' => $conIva,
        'tpv_sync_module_catalog'        => 1,
        'tpv_sync_principal'             => 'wc',
        'tpv_sync_api_url'               => 'https://tpv.test/api/v1',
        'tpv_sync_client_id'             => 'wc_x',
        'tpv_sync_client_secret'         => 's',
    ];
    $GLOBALS['__woo_tasas'] = $tarifa === null ? [] : ['' => [['rate' => (string) $tarifa]]];
    $filas = [];
    foreach ($tpv as $tpvId => [$precio, $rebaja]) {
        $filas[] = ['product_id' => $tpvId, 'price' => $precio, 'special_price' => $rebaja];
    }
    $GLOBALS['__http'] = ['peticiones' => [], 'capacidades' => $capacidades, 'health' => 200, 'catalogo' => $filas];
    $i = 0;
    foreach ($productos as $postId => $p) {
        $GLOBALS['__wp_posts'][$postId] = (object) ['ID' => $postId, 'post_type' => 'product',
            'post_title' => "P$postId", 'post_content' => '', 'post_status' => 'publish'];
        $GLOBALS['__wc_products'][$postId] = $p;
        $GLOBALS['__wp_meta'][$postId] = ['_tpv_product_id' => 900 + $i++];
    }
}

function cuadre_pasada(): array
{
    return (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->autocurar(500, 20.0);
}

/** Una vuelta entera: la pasada que recorre y la que la cierra. */
function cuadre_vuelta(): array
{
    $s = cuadre_pasada();
    cuadre_pasada();
    return $s;
}

/** Que la última revisión de precios quede atrás (se hace cada 6 h). */
function cuadre_pasan_horas(int $horas = 7): void
{
    $g = get_option('tpv_sync_pvp_cuadre', []);
    $g['ultima']['fecha'] = gmdate('Y-m-d H:i:s', time() - $horas * 3600);
    update_option('tpv_sync_pvp_cuadre', $g);
}

function cuadre_patches(): array
{
    return array_values(array_filter($GLOBALS['__http']['peticiones'],
        fn ($p) => $p['metodo'] === 'PATCH' && str_contains($p['url'], '/products/')));
}

function run_pvp_cuadre_tests(WooTestRunner $t): void
{
    $t->suite('PVP fase 2 — ¿cuadran la web y la caja? (regla pura)');

    $casos = [
        // web precio, web rebaja, TPV precio, TPV rebaja, ¿cuadra?
        ['mismo precio',                                      9.92, null, 9.92, null, true],
        ['un céntimo de diferencia',                          9.92, null, 9.93, null, false],
        ['la caja cobra un 21 % más',                         9.92, null, 12.00, null, false],
        ['el TPV guarda la base con 4 decimales',             14.99, null, 14.989964, null, true],
        ['rebaja de la web que la caja no tiene',             20.00, 15.00, 20.00, null, false],
        ['rebaja igual en los dos',                           20.00, 15.00, 20.00, 15.00, true],
        ['rebaja distinta',                                   20.00, 15.00, 20.00, 16.00, false],
        ['promoción puesta solo en caja: no es un descuadre', 20.00, null, 20.00, 12.00, true],
        ['precio normal distinto aunque la rebaja cuadre',    21.00, 15.00, 20.00, 15.00, false],
    ];
    foreach ($casos as [$nombre, $wp, $wr, $tp, $tr, $esperado]) {
        $t->test($nombre, function ($t) use ($wp, $wr, $tp, $tr, $esperado) {
            $t->assertEquals($esperado, TPV_Sync_Precio_Pvp::cuadra($wp, $wr, $tp, $tr));
        });
    }

    $t->suite('PVP fase 2 — la autocuración corrige la caja');

    $t->test('la caja cobra un 21 % más: se corrige con el PVP de la web (lulubeauty)', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        $s = cuadre_pasada();
        $p = cuadre_patches();
        $t->assertEquals(1, count($p), 'antes: nada, la caja seguía cobrando 12,00 €');
        $t->assert(str_ends_with($p[0]['url'] ?? '', '/products/900'), 'al producto enlazado');
        $t->assertEquals(9.92, $p[0]['body']['price'] ?? null, 'el PVP que cobra la web');
        $t->assertEquals('gross', $p[0]['headers']['X-Price-Input'] ?? null, 'y dicho como PVP: el TPV calcula la base');
        $t->assertEquals(1, $s['pvp_corregidos'] ?? null);
    });

    $t->test('lo que ya cuadra no se toca', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [9.92, null]]);
        cuadre_pasada();
        $t->assertEquals([], cuadre_patches());
    });

    $t->test('los precios del TPV se leen CON IVA aunque Woo pida sin IVA para lo suyo', function ($t) {
        // Precios introducidos sin IVA: el cliente pide `net` en todo lo demás.
        cuadre_tienda(['yes', 'no', 21], [7071 => new ProductoPvpFalso(7071, '10.00')], [900 => [12.10, null]]);
        cuadre_pasada();
        $gets = array_values(array_filter($GLOBALS['__http']['peticiones'],
            fn ($p) => $p['metodo'] === 'GET' && str_ends_with((string) parse_url($p['url'], PHP_URL_PATH), '/products')));
        $t->assert($gets !== [], 'lee el catálogo del TPV');
        $t->assertEquals('gross', $gets[0]['headers']['X-Price-Format'] ?? null,
            'con `net` compararía la base del TPV con el PVP de la web: todo «descuadrado»');
        $t->assertEquals([], cuadre_patches(), '10,00 + 21 % = 12,10: cuadra');
    });

    $t->test('rebaja de la web que la caja no aplica: se manda la rebaja', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [2797 => new ProductoPvpFalso(2797, '32.05', '25.64')], [900 => [32.05, null]]);
        cuadre_pasada();
        $p = cuadre_patches();
        $t->assertEquals(25.64, $p[0]['body']['special_price'] ?? null);
    });

    $t->test('rebaja igual en la web y en la caja: no se toca', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [2797 => new ProductoPvpFalso(2797, '32.05', '25.64')], [900 => [32.05, 25.64]]);
        cuadre_pasada();
        $t->assertEquals([], cuadre_patches());
    });

    $t->test('una fila del TPV sin precio no se toma por 0 €', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], []);
        $GLOBALS['__http']['catalogo'] = [['product_id' => 900, 'special_price' => null]];
        cuadre_pasada();
        $t->assertEquals([], cuadre_patches());
    });

    $t->test('promoción puesta solo en caja: no se corrige ni se borra', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '20.00')], [900 => [20.00, 12.00]]);
        cuadre_pasada();
        $t->assertEquals([], cuadre_patches());
    });

    $t->suite('PVP fase 2 — cuándo NO se corrige');

    $t->test('API sin precios con IVA: ni se lee el catálogo ni se toca nada', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]], [], []);
        cuadre_pasada();
        $t->assertEquals([], cuadre_patches(), 'una API vieja guardaría el PVP como base: +21 %');
        $gets = array_filter($GLOBALS['__http']['peticiones'],
            fn ($p) => $p['metodo'] === 'GET' && str_ends_with((string) parse_url($p['url'], PHP_URL_PATH), '/products'));
        $t->assertEquals(0, count($gets));
    });

    $t->test('catálogo mandado por el TPV: no se corrige desde la web', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]],
            ['tpv_sync_principal' => 'tpv']);
        $s = cuadre_pasada();
        $t->assertEquals([], cuadre_patches());
        $t->assertEquals(0, $s['pvp_atascados'] ?? -1, 'el panel no puede decir «no se ha podido igualar»');
        $gets = array_filter($GLOBALS['__http']['peticiones'],
            fn ($p) => $p['metodo'] === 'GET' && str_ends_with((string) parse_url($p['url'], PHP_URL_PATH), '/products'));
        $t->assertEquals(0, count($gets), 'ni se lee el catálogo para nada');
    });

    $t->test('el TPV no contesta el catálogo: no hay con qué comparar y no se toca nada', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        $GLOBALS['__http']['catalogoFalla'] = true;
        cuadre_pasada();
        $t->assertEquals([], cuadre_patches());
    });

    $t->test('un producto que el TPV ya no tiene no se «corrige»', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [555 => [12.00, null]]);
        $s = cuadre_pasada();
        $t->assertEquals([], cuadre_patches(), 'eso lo resuelve el pedido (2.10.0), no un PATCH a un 404');
        $t->assertEquals(0, $s['pvp_corregidos'] ?? -1);
    });

    $t->test('los productos variables no se comparan', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [8474 => new ProductoVariablePvpFalso(8474)], [900 => [18.40, null]]);
        cuadre_pasada();
        $t->assertEquals([], cuadre_patches());
    });

    $t->suite('PVP fase 2 — sin bucles');

    $t->test('si tras corregir sigue sin cuadrar, no se repite en cada pasada', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        cuadre_vuelta();
        cuadre_pasan_horas();
        $s = cuadre_vuelta();   // el TPV (en memoria) sigue devolviendo 12,00
        $t->assertEquals(1, count(cuadre_patches()), 'un PATCH por cambio de la web, no uno cada 5 minutos');
        $t->assertEquals(1, $s['pvp_atascados'] ?? null, 'y se cuenta como «no se pudo cuadrar»');
    });

    $t->test('si la web cambia de precio, se vuelve a intentar', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        cuadre_vuelta();
        $GLOBALS['__wc_products'][7071] = new ProductoPvpFalso(7071, '9.50');
        cuadre_pasan_horas();
        cuadre_vuelta();
        $t->assertEquals(2, count(cuadre_patches()));
    });

    $t->test('cuando ya cuadra, se olvida la corrección anterior', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        cuadre_vuelta();
        $GLOBALS['__http']['catalogo'] = [['product_id' => 900, 'price' => 9.92, 'special_price' => null]];
        cuadre_pasan_horas();
        cuadre_vuelta();
        $t->assertEquals('', get_post_meta(7071, '_tpv_pvp_corregido', true),
            'si mañana vuelve a descuadrar con esos mismos precios, se corrige otra vez');
    });

    $t->suite('PVP fase 2 — cada 6 horas, no cada 5 minutos');

    $t->test('antes de 6 h no se vuelve a leer el catálogo del TPV', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [9.92, null]]);
        cuadre_vuelta();
        $GLOBALS['__http']['peticiones'] = [];
        cuadre_pasan_horas(5);
        cuadre_vuelta();
        $t->assertEquals([], array_values(array_filter($GLOBALS['__http']['peticiones'],
            fn ($p) => str_contains($p['url'], '/products') || str_contains($p['url'], '/health'))),
            'leer los precios cuesta el catálogo entero del TPV');
    });

    $t->test('pasadas 6 h, se revisa otra vez', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [9.92, null]]);
        cuadre_vuelta();
        $GLOBALS['__http']['catalogo'] = [['product_id' => 900, 'price' => 12.00, 'special_price' => null]];
        cuadre_pasan_horas(6);
        cuadre_vuelta();
        $t->assertEquals(1, count(cuadre_patches()));
    });

    $t->test('una vuelta sin revisión no borra lo que enseña el panel', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        cuadre_vuelta();
        cuadre_vuelta();   // sin revisión: no han pasado 6 h
        $t->assertEquals(1, get_option('tpv_sync_pvp_cuadre')['ultima']['corregidos'] ?? null);
    });

    $t->suite('PVP fase 2 — lo que ve el panel');

    $t->test('al cerrar la vuelta queda el resumen: corregidos y los que no cuadran, con ejemplos', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [
            7071 => new ProductoPvpFalso(7071, '9.92'),
            7072 => new ProductoPvpFalso(7072, '5.00'),
            7073 => new ProductoPvpFalso(7073, '8.00'),
        ], [900 => [12.00, null], 901 => [5.00, null], 902 => [9.68, null]]);
        cuadre_vuelta();                 // corrige 7071 y 7073
        cuadre_pasan_horas();
        cuadre_vuelta();                 // el TPV en memoria no cambia: los dos quedan atascados
        $r = get_option('tpv_sync_pvp_cuadre')['ultima'] ?? [];
        $t->assertEquals(3, $r['revisados'] ?? null);
        $t->assertEquals(0, $r['corregidos'] ?? null);
        $t->assertEquals(2, $r['atascados'] ?? null);
        $t->assertEquals(['PVP-7071', 'PVP-7073'], array_column($r['ejemplos'] ?? [], 'sku'));
        $t->assertEquals(['web' => 9.92, 'caja' => 12.0], array_intersect_key($r['ejemplos'][0] ?? [], ['web' => 1, 'caja' => 1]));
        $t->assert(!empty($r['fecha']), 'con fecha: «en la última revisión»');
    });

    $t->test('la primera vuelta cuenta lo corregido', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        cuadre_vuelta();
        $r = get_option('tpv_sync_pvp_cuadre')['ultima'] ?? [];
        $t->assertEquals(1, $r['corregidos'] ?? null);
        $t->assertEquals(0, $r['atascados'] ?? null);
    });

    $t->test('clase de impuesto sin tarifa para España: se cuenta para avisar a la tienda', function ($t) {
        cuadre_tienda(['yes', 'no', 21], [
            7071 => new ProductoPvpFalso(7071, '10.00', '', 'taxable', 'reducido'),
            7072 => new ProductoPvpFalso(7072, '10.00', '', 'taxable', 'reducido'),
            7073 => new ProductoPvpFalso(7073, '10.00'),
            7074 => new ProductoPvpFalso(7074, '10.00', '', 'none', 'reducido'),
        ], [900 => [10.00, null], 901 => [10.00, null], 902 => [12.10, null], 903 => [10.00, null]]);
        cuadre_vuelta();
        $r = get_option('tpv_sync_pvp_cuadre')['ultima'] ?? [];
        $t->assertEquals(['reducido' => 2], $r['sin_iva'] ?? null,
            'Woo no suma IVA a esos productos; el exento (7074) no cuenta');
    });

    $t->test('con los impuestos de Woo desactivados no hay nada que avisar', function ($t) {
        cuadre_tienda(['no', 'no', null], [7071 => new ProductoPvpFalso(7071, '10.00', '', 'taxable', 'reducido')],
            [900 => [10.00, null]]);
        cuadre_vuelta();
        $t->assertEquals([], get_option('tpv_sync_pvp_cuadre')['ultima']['sin_iva'] ?? null);
    });
}
