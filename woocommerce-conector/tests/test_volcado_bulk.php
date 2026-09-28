<?php
declare(strict_types=1);
/**
 * EL VOLCADO EN BLOQUE, EJECUTADO DE VERDAD (lulubeauty, 28-09-2026).
 *
 * Primer volcado Woo→TPV de 847 productos. Los simples van por
 * POST /products/bulk (push_wc_products_bulk). Tres fallos en ese camino:
 *
 *  1. NO SE GUARDABA EL ENLACE. Desde el 03-07-2026 (api_tpv b72f13f) la API
 *     responde con el sobre uniforme BulkResult:
 *
 *         {"results":[{"index":0,"status":201,
 *                      "body":{"product_id":501,"action":"created"},
 *                      "error":null}], "summary":{...}}
 *
 *     y el plugin seguía leyendo `data.results[].product_id` ⇒ ningún
 *     producto simple recibía `_tpv_product_id` en Woo. Consecuencia: los
 *     pedidos de Woo con esos productos se saltaban («Sin productos mapeados
 *     al TPV») y no llegaban nunca al TPV.
 *
 *  2. SIN IMÁGENES. Solo la ruta singular y las altas con variantes llamaban
 *     a push_images_to_tpv; el bloque no.
 *
 *  3. STOCK A 0. El bloque no mandaba `quantity` ni `subtract`: todo llegaba
 *     como «cuento unidades y tengo 0».
 *
 * Aquí no se prueba una copia de la lógica: se ejecuta push_wc_products_bulk()
 * con WordPress y la API simulados en memoria.
 */

require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-identificadores.php';
require_once dirname(__DIR__) . '/includes/class-stock.php';
require_once dirname(__DIR__) . '/includes/class-product-sync.php';

// ─── WordPress en memoria (solo lo que toca el volcado) ─────────────────────

if (!function_exists('get_post')) {
    function get_post($id) { return $GLOBALS['__wp_posts'][(int) $id] ?? null; }
}
if (!function_exists('wc_get_product')) {
    function wc_get_product($id) { return $GLOBALS['__wc_products'][(int) $id] ?? null; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key = '', $single = false) {
        return $GLOBALS['__wp_meta'][(int) $id][$key] ?? '';
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $value) {
        $GLOBALS['__wp_meta'][(int) $id][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($id, $key) { unset($GLOBALS['__wp_meta'][(int) $id][$key]); return true; }
}
if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id($id) { return $GLOBALS['__wp_thumbs'][(int) $id] ?? 0; }
}
if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($id) { return 'https://tienda.test/img/' . (int) $id . '.jpg'; }
}

final class ProductoWooFalso
{
    public function __construct(
        private int $id,
        private string $sku,
        private string $precio,
        private bool $gestiona,
        private ?float $stock,
        private array $galeria = []
    ) {}
    public function get_id() { return $this->id; }
    public function get_sku() { return $this->sku; }
    public function get_regular_price() { return $this->precio; }
    public function is_type($t) { return $t === 'simple'; }
    public function get_manage_stock() { return $this->gestiona; }
    public function get_stock_quantity() { return $this->gestiona ? $this->stock : null; }
    public function get_gallery_image_ids() { return $this->galeria; }
}

final class WpdbFalso
{
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public array $log = [];
    public function insert($tabla, $fila) { $this->log[] = $fila; return 1; }

    public function prepare($sql, ...$args) { return ['sql' => $sql, 'args' => $args]; }

    /**
     * Solo entiende la búsqueda «post cuyo meta X vale Y», que es la única
     * que hacen find_wc_post() y update_variant_stock().
     */
    public function get_var($q)
    {
        if (!is_array($q)) { return null; }
        if (preg_match("/meta_key = '([a-z_]+)'/", $q['sql'], $m)) {
            [$clave, $valor] = [$m[1], $q['args'][0] ?? null];
        } else {
            [$clave, $valor] = [$q['args'][0] ?? '', $q['args'][1] ?? null];
        }
        foreach ($GLOBALS['__wp_meta'] as $postId => $metas) {
            if ((string) ($metas[$clave] ?? '') === (string) $valor) { return $postId; }
        }
        return null;
    }
}

/**
 * API falsa que responde EXACTAMENTE con el sobre de BulkResult
 * (api_tpv/api/v1/classes/BulkResult.php::toArray), pasado por decide()
 * como hace parse() en producción.
 */
final class ApiVolcadoFalsa extends TPV_Sync_API_Client
{
    public array $itemsBulk = [];
    public array $opsBatch  = [];
    /** @var array<string,int> model => product_id que ya existe en el TPV */
    public array $existentes = [];
    private int $siguienteId = 500;

    public function __construct(array $existentes = []) { $this->existentes = $existentes; }

    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if ($path === '/products/bulk') {
            $this->itemsBulk = $body['items'] ?? [];
            $results = [];
            foreach ($this->itemsBulk as $i => $item) {
                $m = (string) ($item['model'] ?? '');
                if (isset($this->existentes[$m])) {
                    $results[] = ['index' => $i, 'status' => 200,
                        'body' => ['product_id' => $this->existentes[$m], 'action' => 'updated'],
                        'error' => null];
                } else {
                    $results[] = ['index' => $i, 'status' => 201,
                        'body' => ['product_id' => ++$this->siguienteId, 'action' => 'created'],
                        'error' => null];
                }
            }
            return self::decide(200, [
                'results' => $results,
                'summary' => ['total' => count($results), 'ok' => count($results), 'failed' => 0],
            ]);
        }
        if ($path === '/batch') {
            $ops = $body['operations'] ?? [];
            $this->opsBatch = array_merge($this->opsBatch, $ops);
            $results = [];
            foreach ($ops as $i => $op) {
                $results[] = ['index' => $i, 'status' => 201,
                    'body' => ['data' => ['image' => 'catalog/x' . $i . '.jpg']], 'error' => null];
            }
            return self::decide(200, ['results' => $results]);
        }
        return self::decide(404, ['error' => 'not_found']);
    }
}

/** Monta la tienda del caso real: 3 productos simples. */
function volcado_tienda(): void
{
    $GLOBALS['wpdb'] = new WpdbFalso();
    $GLOBALS['__wp_meta'] = [8308 => [], 8310 => [], 8306 => []];
    $GLOBALS['__wp_thumbs'] = [];
    $GLOBALS['__wp_posts'] = $GLOBALS['__wc_products'] = [];

    $alta = function (int $id, string $sku, bool $gestiona, ?float $stock, int $thumb, array $gal) {
        $GLOBALS['__wp_posts'][$id] = (object) [
            'ID' => $id, 'post_type' => 'product', 'post_title' => "Producto $id",
            'post_content' => '', 'post_status' => 'publish',
        ];
        $GLOBALS['__wc_products'][$id] = new ProductoWooFalso($id, $sku, '3.12', $gestiona, $stock, $gal);
        if ($thumb) { $GLOBALS['__wp_thumbs'][$id] = $thumb; }
    };
    // 8308: la fresa de la captura. Woo NO gestiona stock. Foto + 1 de galería.
    $alta(8308, 'MS2428308', false, null, 70, [71]);
    // 8310: Woo SÍ gestiona, 5 unidades, sin fotos.
    $alta(8310, 'R3UW28310', true, 5.0, 0, []);
    // 8306: Woo SÍ gestiona, 8 unidades, ya estaba en el TPV (id 900).
    $alta(8306, '2RKA78306', true, 8.0, 80, []);
    $GLOBALS['__wp_meta'][8306]['_tpv_product_id'] = 900;
}

function run_volcado_bulk_tests(WooTestRunner $t): void
{
    $t->suite('Volcado en bloque: enlace, imágenes y stock');

    $t->test('guarda _tpv_product_id leyendo el sobre real de la API', function ($t) {
        volcado_tienda();
        $api = new ApiVolcadoFalsa(['2RKA78306' => 900]);
        $r = (new TPV_Sync_Product_Sync($api))->push_wc_products_bulk([8308, 8310, 8306]);

        $t->assertEquals(501, (int) get_post_meta(8308, '_tpv_product_id', true),
            'sin el enlace, los pedidos de Woo de este producto no llegan al TPV');
        $t->assertEquals(502, (int) get_post_meta(8310, '_tpv_product_id', true));
        $t->assertEquals(900, (int) get_post_meta(8306, '_tpv_product_id', true));
        $t->assertEquals(3, $r['sent'] ?? -1, 'los 3 cuentan como enviados, no como omitidos');
        $t->assertEquals(2, $r['created'] ?? -1, 'dos altas');
        $t->assertEquals(1, $r['updated'] ?? -1, 'una actualización');
    });

    $t->test('sube las imágenes de los productos del bloque', function ($t) {
        volcado_tienda();
        $api = new ApiVolcadoFalsa(['2RKA78306' => 900]);
        (new TPV_Sync_Product_Sync($api))->push_wc_products_bulk([8308, 8310, 8306]);

        $rutas = array_map(fn($o) => $o['path'], $api->opsBatch);
        $t->assertEquals(2, count(array_keys($rutas, '/products/501/images', true)),
            'la fresa 8308 tiene destacada + 1 de galería');
        $t->assertEquals(1, count(array_keys($rutas, '/products/900/images', true)),
            'también las del producto que ya existía');
        $t->assertEquals(0, count(array_keys($rutas, '/products/502/images', true)),
            'un producto sin fotos no genera operaciones');
        $enviadas = get_post_meta(8308, '_tpv_images_sent', true);
        $t->assert(is_array($enviadas)
            && isset($enviadas['https://tienda.test/img/70.jpg'], $enviadas['https://tienda.test/img/71.jpg']),
            'se recuerdan las subidas para no duplicarlas al relanzar');
    });

    $t->test('manda subtract/quantity según gestione Woo el stock', function ($t) {
        volcado_tienda();
        $api = new ApiVolcadoFalsa(['2RKA78306' => 900]);
        (new TPV_Sync_Product_Sync($api))->push_wc_products_bulk([8308, 8310, 8306]);

        $porModel = [];
        foreach ($api->itemsBulk as $it) { $porModel[$it['model']] = $it; }

        $fresa = $porModel['MS2428308'] ?? [];
        $t->assertEquals(0, $fresa['subtract'] ?? 'ausente', 'Woo no cuenta ⇒ el TPV no resta');
        $t->assert(!array_key_exists('quantity', $fresa), 'y no llega como «0 unidades»');

        $nuevo = $porModel['R3UW28310'] ?? [];
        $t->assertEquals(1, $nuevo['subtract'] ?? 'ausente');
        $t->assertEquals(5.0, $nuevo['quantity'] ?? 'ausente', 'alta: el TPV nace con el stock de Woo');

        // Ya existía en el TPV: el plugin manda igualmente el stock de Woo
        // como stock INICIAL. Quien decide no aplicarlo a un producto que ya
        // existe es la API (bulk solo usa quantity al crear; ver api_tpv,
        // test_bulk_stock). El plugin no puede saberlo con certeza antes de
        // mandar: el enlace local puede faltar aunque el TPV ya lo tenga.
        $viejo = $porModel['2RKA78306'] ?? [];
        $t->assertEquals(1, $viejo['subtract'] ?? 'ausente');
        $t->assertEquals(8.0, $viejo['quantity'] ?? 'ausente');
    });
}
