<?php
declare(strict_types=1);
/**
 * Arranque de los tests de INTEGRACIÓN: WordPress + WooCommerce de verdad.
 *
 * Los tests de tests/ ejecutan el plugin con WordPress en memoria. Eso no ve
 * lo que hace WooCommerce por dentro: el estado de stock de un padre variable
 * sale de la tabla `wc_product_meta_lookup`, no de los metas, y guardar el
 * padre dispara `woocommerce_update_product`. Ningún stub lo reproducía y por
 * eso lulubeauty (17-10-2026) tenía productos «Sin existencias» con tallas en
 * stock y cada venta de caja rebotaba a la caja como edición del producto.
 *
 * Qué hace:
 *   - Carga el WordPress de WP_ROOT (por defecto el tpv85 local, WC 10.6.2).
 *   - Quita el conector instalado de los plugins activos y carga en su lugar
 *     el de ESTE árbol (el que se está probando).
 *   - Las opciones del conector viven en memoria (no toca las de la tienda).
 *   - Ninguna petición sale a la red: `pre_http_request` las apunta en
 *     $GLOBALS['__http'] y responde como respondería el TPV.
 *
 * Crea productos reales en la base de datos de WP_ROOT y los borra al acabar.
 * Uso: php tests/integracion/run.php [/ruta/al/wordpress]
 */

$wpRoot = $argv[1] ?? (getenv('WP_ROOT') ?: '/var/www/html/tpv85/public_html');
if (!is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "No hay WordPress en $wpRoot\n");
    exit(2);
}

define('WP_USE_THEMES', false);
define('TPV_SYNC_INTEGRACION', true);

$GLOBALS['__plugin_bajo_prueba'] = realpath(__DIR__ . '/../../woocommerce-conector.php');
$GLOBALS['__http'] = [];
// Lo que el TPV devuelve en GET /products/{id}; los tests lo rellenan.
$GLOBALS['__tpv_productos'] = [];
// Lo que el TPV anuncia en GET /health; los tests lo cambian para simular una API vieja.
$GLOBALS['__tpv_capacidades'] = ['price_gross_write', 'categories_write', 'variants_in_place'];

/** Opciones del conector, en memoria. */
$GLOBALS['__opciones_conector'] = [
    'tpv_sync_api_url'        => 'https://tpv.prueba.invalid/api/v1',
    'tpv_sync_client_id'      => 'wc_prueba',
    'tpv_sync_client_secret'  => 'secreto',
    'tpv_sync_module_catalog' => 1,
    'tpv_sync_principal'      => '',
];

function integracion_filtro(string $hook, callable $fn, int $prio = 10, int $args = 1): void
{
    $GLOBALS['wp_filter'][$hook][$prio][] = ['function' => $fn, 'accepted_args' => $args];
}

// Antes de cargar WP solo se pueden dejar filtros en $wp_filter (WP_Hook los adopta).
$GLOBALS['wp_filter'] = [];

integracion_filtro('option_active_plugins', static function ($activos) {
    return array_values(array_filter((array) $activos,
        static fn ($p) => !str_starts_with((string) $p, 'woocommerce-conector/')));
});

integracion_filtro('plugins_loaded', static function () {
    require_once $GLOBALS['__plugin_bajo_prueba'];
}, -1000);

foreach (array_keys($GLOBALS['__opciones_conector']) as $clave) {
    integracion_filtro("pre_option_$clave", static function ($pre, $opcion) {
        return $GLOBALS['__opciones_conector'][$opcion] ?? $pre;
    }, 10, 2);
}
// La versión ya «instalada»: si no, el plugin programa su repaso en la tienda.
integracion_filtro('pre_option_tpv_sync_version_instalada', static function ($pre) {
    return defined('TPV_SYNC_VERSION') ? TPV_SYNC_VERSION : $pre;
});
integracion_filtro('pre_transient_tpv_sync_token', static function () {
    return ['token' => 'token-prueba', 'exp' => time() + 3600];
});
// Las capacidades no se recuerdan entre tests: cada uno ve las de $GLOBALS.
integracion_filtro('pre_transient_tpv_sync_api_capacidades', static fn () => false);
integracion_filtro('pre_set_transient_tpv_sync_api_capacidades', static fn () => false);

/**
 * JPEG de verdad para una foto del TPV (media_handle_sideload valida que lo sea).
 * $GLOBALS['__tpv_imagenes'][url] = 'clave' fuerza el contenido: dos URLs con la
 * misma clave son la MISMA foto con nombres distintos (lo que hace la API al
 * guardar cada subida con un nombre aleatorio).
 */
function integracion_jpeg(string $url): string
{
    $clave = $GLOBALS['__tpv_imagenes'][$url] ?? $url;
    $h = md5($clave);
    $im = imagecreatetruecolor(8, 8);
    imagefill($im, 0, 0, imagecolorallocate($im, hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))));
    imagesetpixel($im, 1, 1, imagecolorallocate($im, hexdec(substr($h, 6, 2)), hexdec(substr($h, 8, 2)), hexdec(substr($h, 10, 2))));
    ob_start();
    imagejpeg($im, null, 100);
    return (string) ob_get_clean();
}
$GLOBALS['__tpv_imagenes'] = [];

integracion_filtro('pre_http_request', static function ($pre, $args, $url) {
    $metodo = strtoupper((string) ($args['method'] ?? 'GET'));
    if ($metodo === 'GET' && preg_match('#^https://tpv\.prueba\.invalid/image/#', (string) $url)) {
        $GLOBALS['__http'][] = ['metodo' => 'GET', 'ruta' => (string) $url, 'cuerpo' => null];
        return [
            'headers'  => ['content-type' => 'image/jpeg'],
            'body'     => integracion_jpeg((string) $url),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }
    $ruta   = (string) parse_url((string) $url, PHP_URL_PATH);
    $ruta   = preg_replace('#^/api/v1#', '', $ruta);
    $cuerpo = isset($args['body']) && is_string($args['body']) ? json_decode($args['body'], true) : null;
    $GLOBALS['__http'][] = ['metodo' => $metodo, 'ruta' => $ruta, 'cuerpo' => $cuerpo];

    $respuesta = ['data' => []];
    if ($metodo === 'GET' && $ruta === '/health') {
        $respuesta = ['data' => ['status' => 'ok', 'capabilities' => $GLOBALS['__tpv_capacidades']]];
    } elseif ($metodo === 'DELETE' && preg_match('#^/products/(\d+)/variants/(\d+)$#', $ruta)) {
        $respuesta = null;   // 204
    } elseif ($metodo === 'GET' && preg_match('#^/products/(\d+)$#', $ruta, $m)) {
        // __tpv_sin_detalle: ids cuyo detalle no llega (el producto sigue en el listado).
        $respuesta = ['data' => in_array((int) $m[1], $GLOBALS['__tpv_sin_detalle'] ?? [], true)
            ? [] : ($GLOBALS['__tpv_productos'][(int) $m[1]] ?? [])];
    } elseif ($metodo === 'GET' && $ruta === '/products') {
        // El listado de la API NO es el detalle: formatProduct (api_tpv
        // ProductController) solo da estos campos. Sin descripción, tallas,
        // imágenes ni categorías. Un listado falso «completo» escondió que
        // reconciliar vaciaba descripciones en Woo (lulubeauty, 18-10-2026).
        $campos = ['product_id', 'name', 'model', 'sku', 'price', 'special_price', 'quantity',
            'subtract', 'status', 'image', 'tax_class_id', 'date_modified', 'sold_by_weight'];
        $respuesta = ['data' => array_map(
            static fn ($p) => array_intersect_key($p, array_flip($campos)),
            array_values($GLOBALS['__tpv_productos'])
        ), 'meta' => ['cursor' => null]];
    } elseif ($metodo === 'PATCH' && preg_match('#^/products/(\d+)#', $ruta, $m)) {
        $respuesta = ['data' => ['product_id' => (int) $m[1]]];
    } elseif ($ruta === '/batch') {
        // Solo lecturas en lote (el repaso): GET /products/{id}.
        $resultados = [];
        foreach ((array) ($cuerpo['operations'] ?? $cuerpo['requests'] ?? []) as $i => $op) {
            $id = preg_match('#^/products/(\d+)$#', (string) ($op['path'] ?? ''), $m) ? (int) $m[1] : 0;
            $datos = $GLOBALS['__tpv_productos'][$id] ?? null;
            $resultados[] = ['index' => $i, 'status' => $datos ? 200 : 404,
                'body' => ['data' => $datos], 'error' => null];
        }
        $respuesta = ['results' => $resultados];
    }
    return [
        'headers'  => [],
        'body'     => $respuesta === null ? '' : wp_json_encode($respuesta),
        'response' => $respuesta === null ? ['code' => 204, 'message' => 'No Content'] : ['code' => 200, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
}, 10, 3);

require $wpRoot . '/wp-load.php';

if (!class_exists('TPV_Sync') || !function_exists('WC')) {
    fwrite(STDERR, "No se cargó el conector bajo prueba o WooCommerce\n");
    exit(2);
}
if (realpath(TPV_SYNC_DIR . 'woocommerce-conector.php') !== $GLOBALS['__plugin_bajo_prueba']) {
    fwrite(STDERR, 'Se cargó otro conector: ' . TPV_SYNC_DIR . "\n");
    exit(2);
}
