<?php
declare(strict_types=1);
/**
 * «NO PUBLICABLE» EN EL TPV: LA TIENDA ONLINE NO LO ENSEÑA (plan B3, D19, Tarea 14).
 *
 * El TPV marca como no publicable lo que trae fotos o fichas con licencia de un
 * mayorista. La API (Tarea 13) lo dice en cada producto con `no_publicable` y no
 * sirve las fotos marcadas (ni las de ruta rara): `image` a null y fuera de `images`.
 * Es una barrera legal, así que el conector la trata como la venta a peso:
 *
 *   - no crea en Woo un producto no publicable; si ya estaba, lo pasa a BORRADOR
 *     sin borrarlo ni desenlazarlo, y apunta el estado que tenía;
 *   - cuando el TPV lo desmarca, el mismo upsert le devuelve ESE estado (un
 *     producto privado vuelve a privado, no a publicado);
 *   - el volcado por lotes, el repaso nocturno de stock y «Reconciliar catálogos»
 *     (Reconciler::decidir) no lo crean ni lo corrigen, pero si lo ven publicado
 *     lo retiran (la simulación de «Reconciliar» lo avisa);
 *   - mientras está marcado, nada de su catálogo vuelve de Woo a la caja (edición,
 *     volcado, papelera, borrado, tallas, fotos, categorías, reset): la copia de Woo
 *     está congelada. El stock sí viaja;
 *   - una foto que la caja trajo para ESE producto y deja de venir (por ruta, no por
 *     URL: un cambio de dominio no es otra foto) sale de la galería y de la destacada;
 *     una subida a mano en Woo, o una de la caja elegida a mano para otro producto,
 *     no se toca. Ningún adjunto se borra (wp_delete_attachment es de B4, R44).
 *
 * Marcar en el TPV no avisa hoy a Woo (no hay product.updated): el conector lo
 * ve en el siguiente camino que lea el producto, y todos tienen que reaccionar.
 */

require_once __DIR__ . '/test_venta_a_peso.php';
require_once __DIR__ . '/test_autocuracion.php';
require_once __DIR__ . '/test_pvp_cuadre.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';
require_once __DIR__ . '/fixtures/api_categorias_falsa.php';

// ─── WordPress en memoria: lo que tocan la destacada y la galería ───────────
if (!function_exists('get_attached_media')) {
    function get_attached_media($tipo, $postId) {
        return array_map(static fn ($id) => (object) ['ID' => $id], $GLOBALS['__wp_adjuntos'][(int) $postId] ?? []);
    }
}
if (!function_exists('set_post_thumbnail')) {
    function set_post_thumbnail($postId, $attId) { $GLOBALS['__wp_thumbs'][(int) $postId] = (int) $attId; return true; }
}
if (!function_exists('delete_post_thumbnail')) {
    function delete_post_thumbnail($postId) { unset($GLOBALS['__wp_thumbs'][(int) $postId]); return true; }
}
if (!function_exists('get_attached_file')) { function get_attached_file($id) { return ''; } }
// Con las funciones de medios ya definidas, sync_images no carga wp-admin/includes
// (que aquí no existe). Ningún test descarga una foto: si alguno lo intenta, falla.
if (!function_exists('media_handle_sideload')) {
    function media_handle_sideload($f, $postId) { throw new RuntimeException('no se esperaba subir una foto'); }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($n = '') { throw new RuntimeException('no se esperaba descargar una foto'); }
}
if (!function_exists('wp_generate_attachment_metadata')) { function wp_generate_attachment_metadata($id, $f) { return []; } }

const NP_IMG = 'https://tpv.test/image/catalog/prov/';

/** API que registra lo que se le pide y responde con el catálogo dado. */
final class ApiNoPublicableFalsa extends TPV_Sync_API_Client
{
    public array $llamadas = [];
    public array $patches = [];
    public array $itemsBulk = [];
    public function __construct(private array $productos = []) {}
    public function get(string $path, array $params = []): array
    {
        $this->llamadas[] = "GET $path";
        if ($path === '/products') {
            return ['data' => array_values($this->productos), 'meta' => ['cursor' => null]];
        }
        if (preg_match('#^/products/(\d+)$#', $path, $m)) {
            return ['data' => $this->productos[(int) $m[1]] ?? []];
        }
        if (preg_match('#^/products/(\d+)/stock$#', $path, $m)) {
            return ['data' => ['quantity' => (float) ($this->productos[(int) $m[1]]['quantity'] ?? 0)]];
        }
        if ($path === '/health') {
            return ['data' => ['capabilities' => ['variants_in_place']]];
        }
        return ['data' => []];
    }
    public function delete(string $path): array
    {
        $this->llamadas[] = "DELETE $path";
        return ['data' => []];
    }
    public function patch(string $path, array $body = []): array
    {
        $this->llamadas[] = "PATCH $path";
        $this->patches[$path] = $body;
        return ['data' => ['product_id' => (int) basename($path)]];
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $this->llamadas[] = "POST $path";
        if ($path === '/products/bulk') {
            $this->itemsBulk = array_merge($this->itemsBulk, $body['items'] ?? []);
            return self::decide(200, ['results' => [], 'summary' => ['total' => 0, 'ok' => 0, 'failed' => 0]]);
        }
        return ['data' => ['product_id' => 777]];
    }
    public function batch(array $ops): array
    {
        $r = [];
        foreach ($ops as $i => $op) {
            $this->llamadas[] = $op['method'] . ' ' . $op['path'];
            $id = (int) basename((string) $op['path']);
            $r[] = ['index' => $i, 'status' => 200, 'body' => ['data' => $this->productos[$id] ?? []]];
        }
        return ['results' => $r];
    }
}

/** La tienda del volcado, con $wpdb capaz de las consultas del volcado y la reconciliación. */
function np_tienda(): void
{
    volcado_tienda();
    $GLOBALS['__wp_adjuntos'] = [];
    $GLOBALS['__wp_inserts'] = [];
    $GLOBALS['__wp_options']['tpv_sync_principal'] = '';
    $GLOBALS['wpdb'] = new class extends stdClass {
        public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta';
        public function insert($t, $f) { return 1; }
        public function get_results($q, $f = null) { return []; }
        public function get_col($q) { return []; }
        public function prepare($sql, ...$a) { return (new WpdbFalso())->prepare($sql, ...$a); }
        public function get_var($q) { return (new WpdbFalso())->get_var($q); }
    };
    if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
}

/** $wpdb de np_tienda que además apunta lo que se escribe en el Registro (tpv_sync_log). */
function np_registro(): object
{
    $GLOBALS['wpdb'] = new class extends stdClass {
        public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta';
        public array $filas = [];
        public function insert($t, $f) { $this->filas[] = $f; return 1; }
        public function get_results($q, $f = null) { return []; }
        public function get_col($q) { return []; }
        public function prepare($sql, ...$a) { return (new WpdbFalso())->prepare($sql, ...$a); }
        public function get_var($q) { return (new WpdbFalso())->get_var($q); }
    };
    return $GLOBALS['wpdb'];
}

function np_sync(array $productos = []): TPV_Sync_Product_Sync
{
    return (new TPV_Sync_Product_Sync(new ApiNoPublicableFalsa($productos)))->conStockEnVuelo(fn () => []);
}

/** 8310 enlazado al 502 con fotos de la caja (81 destacada, 82 y 83 en galería) y una subida en Woo (90). */
function np_tienda_con_fotos(): void
{
    np_tienda();
    $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_product_image_gallery' => '82,90,83'];
    $GLOBALS['__wp_meta'][81] = ['_tpv_image_url' => NP_IMG . 'principal.jpg'];
    $GLOBALS['__wp_meta'][82] = ['_tpv_image_url' => NP_IMG . 'g1.jpg'];
    $GLOBALS['__wp_meta'][83] = ['_tpv_image_url' => NP_IMG . 'g2.jpg'];
    $GLOBALS['__wp_meta'][90] = [];
    $GLOBALS['__wp_thumbs'][8310] = 81;
    $GLOBALS['__wp_adjuntos'][8310] = [81, 82, 83, 90];
}

/**
 * 8310 enlazado al 502. Su galería apunta a la 82 (X): la trajo la caja para OTRO post
 * (8999) con la misma ruta que la 84 (Y), la propia de 8310. Destacada 81, propia.
 */
function np_fotos_compartidas(): void
{
    np_tienda();
    $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_product_image_gallery' => '82'];
    $GLOBALS['__wp_meta'][82] = ['_tpv_image_url' => NP_IMG . 'g1.jpg'];
    $GLOBALS['__wp_meta'][81] = ['_tpv_image_url' => NP_IMG . 'principal.jpg'];
    $GLOBALS['__wp_meta'][84] = ['_tpv_image_url' => NP_IMG . 'g1.jpg'];
    $GLOBALS['__wp_thumbs'][8310] = 81;
    $GLOBALS['__wp_adjuntos'][8310] = [81, 84];
    $GLOBALS['__wp_adjuntos'][8999] = [82];
}

/** Lo que manda GET /products/{id} en `images`: principal primero, sin las marcadas. */
function np_imagenes(array $fotos, bool $conPrincipal = true): array
{
    $out = [];
    foreach (array_values($fotos) as $i => $nombre) {
        $out[] = ['url' => NP_IMG . $nombre, 'is_main' => $conPrincipal && $i === 0, 'sort_order' => $i];
    }
    return $out;
}

function np_meta(int $postId, string $clave)
{
    return $GLOBALS['__wp_meta'][$postId][$clave] ?? null;
}

/** msgid => msgstr de un .po (sin la cabecera). */
function np_po(string $fichero): array
{
    preg_match_all('/^msgid "(.*)"\nmsgstr "(.*)"$/m', (string) file_get_contents($fichero), $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $par) {
        if ($par[1] !== '') { $out[stripcslashes($par[1])] = stripcslashes($par[2]); }
    }
    return $out;
}

/** msgid => msgstr de un .mo compilado (formato GNU gettext). */
function np_mo(string $fichero): array
{
    $d = (string) file_get_contents($fichero);
    $f = unpack('V', substr($d, 0, 4))[1] === 0x950412de ? 'V' : 'N';
    $n = unpack($f, substr($d, 8, 4))[1];
    $oIds = unpack($f, substr($d, 12, 4))[1];
    $oTrad = unpack($f, substr($d, 16, 4))[1];
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        [, $li, $pi] = unpack($f . '2', substr($d, $oIds + 8 * $i, 8));
        [, $lt, $pt] = unpack($f . '2', substr($d, $oTrad + 8 * $i, 8));
        $out[substr($d, $pi, $li)] = substr($d, $pt, $lt);
    }
    return $out;
}

function run_no_publicable_tests(WooTestRunner $t): void
{
    $t->suite('«No publicable» en el TPV: Woo no lo enseña');

    $t->test('test_no_publicable_no_se_crea', function ($t) {
        np_tienda();
        $r = np_sync()->upsert(tpv_producto(777, ['no_publicable' => true, 'quantity' => 3]));

        $t->assertEquals([], $GLOBALS['__wp_inserts'], 'se creó en Woo un producto no publicable');
        $t->assertEquals('no_publicable', $r);
    });

    $t->test('test_no_publicable_que_ya_existia_pasa_a_borrador_y_conserva_el_enlace', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $r = np_sync()->upsert(tpv_producto(502, ['no_publicable' => true, 'name' => 'Ficha del mayorista',
                                                  'description' => '<p>texto licenciado</p>']));

        $t->assertEquals('no_publicable', $r);
        $t->assertEquals('draft', estado_post(8310), 'sigue publicado un producto que el TPV no deja publicar');
        $t->assertEquals(502, np_meta(8310, '_tpv_product_id'), 'el enlace se conserva');
        $t->assertEquals('1', np_meta(8310, '_tpv_no_publicable'));
        $t->assertEquals('publish', np_meta(8310, '_tpv_no_publicable_estado'), 'el estado de antes queda apuntado');
        $t->assertEquals([], $GLOBALS['__wp_inserts'], 'ni se crea otro');
        $t->assertEquals('Producto 8310', $GLOBALS['__wp_posts'][8310]->post_title, 'no se copia la ficha del TPV');
    });

    $t->test('test_al_desmarcar_vuelve_a_publicarse', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $t->assertEquals('draft', estado_post(8310), 'preparación: marcado ⇒ borrador');
        $r = $sync->upsert(tpv_producto(502, ['no_publicable' => false, 'quantity' => 3, 'subtract' => true]));

        $t->assertEquals('updated', $r);
        $t->assertEquals('publish', estado_post(8310));
        $t->assertEquals(null, np_meta(8310, '_tpv_no_publicable'), 'la marca se quita');
        $t->assertEquals(null, np_meta(8310, '_tpv_no_publicable_estado'));
        $t->assertEquals(3, np_meta(8310, '_stock'), 'vuelve a llevar los datos del TPV');
    });

    $t->test('al desmarcar vuelve al estado que tenía (privado), no a publicado', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $GLOBALS['__wp_posts'][8310]->post_status = 'private';
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $t->assertEquals('draft', estado_post(8310));
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));   // otro aviso con la marca
        $t->assertEquals('private', np_meta(8310, '_tpv_no_publicable_estado'),
            'un segundo aviso no pisa el estado de antes con su propio borrador');
        $sync->upsert(tpv_producto(502, ['no_publicable' => false]));
        $t->assertEquals('private', estado_post(8310), 'publicaría algo que el comerciante tenía en privado');
    });

    $t->test('al desmarcar un producto oculto en el TPV, se queda en borrador', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $t->assertEquals('draft', estado_post(8310), 'preparación: marcado ⇒ borrador');
        $sync->upsert(tpv_producto(502, ['no_publicable' => false, 'status' => 0]));
        $t->assertEquals('draft', estado_post(8310));
    });

    $t->test('lo que el comerciante mandó a la papelera mientras estaba marcado no sale de ella', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $GLOBALS['__wp_posts'][8310]->post_status = 'trash';
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $t->assertEquals('trash', estado_post(8310), 'marcar no saca de la papelera');
        $sync->upsert(tpv_producto(502, ['no_publicable' => false]));
        $t->assertEquals('trash', estado_post(8310), 'desmarcar tampoco');
    });

    $t->test('API vieja (sin no_publicable) ⇒ todo como siempre', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $r = np_sync()->upsert(tpv_producto(502, ['quantity' => 2]));
        $t->assertEquals('updated', $r);
        $t->assertEquals('publish', estado_post(8310));
    });

    $t->test('un aviso sin el campo no quita una marca ya vista («no lo sé»)', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $r = $sync->upsert(tpv_producto(502, ['quantity' => 2]));
        $t->assertEquals('no_publicable', $r);
        $t->assertEquals('draft', estado_post(8310), 'una respuesta sin el campo lo publicaría');
    });

    $t->test('test_reconciliar_no_crea_ni_corrige', function ($t) {
        $wc  = ['name' => 'A', 'price' => 1.0, 'sku' => 'X', 'quantity' => 4];
        $tpv = ['name' => 'B', 'price' => 2.0, 'sku' => 'X', 'quantity' => 3, 'no_publicable' => true];
        foreach ([['catalogo' => 'tpv', 'stock' => 'tpv'], ['catalogo' => 'woo', 'stock' => 'woo']] as $pol) {
            $d = TPV_Sync_Reconciler::decidir($wc, $tpv, $pol);
            $t->assertEquals('nada', $d['catalogo']['accion'], 'corregiría el catálogo de un no publicable');
            $t->assertEquals('nada', $d['stock']['accion']);
            $t->assertEquals('no publicable en el TPV', $d['catalogo']['motivo']);
        }
        $d2 = TPV_Sync_Reconciler::decidir(null, $tpv, ['catalogo' => 'tpv', 'stock' => 'tpv']);
        $t->assertEquals('nada', $d2['catalogo']['accion'], 'lo crearía en Woo');
        $d3 = TPV_Sync_Reconciler::decidir($wc, ['no_publicable' => false] + $tpv, ['catalogo' => 'tpv', 'stock' => 'tpv']);
        $t->assertEquals('pull', $d3['catalogo']['accion'], 'sin la marca, la regla de siempre');

        // De punta a punta: «Reconciliar catálogos» con el TPV al mando.
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $api = new ApiNoPublicableFalsa([
            502 => tpv_producto(502, ['no_publicable' => true, 'name' => 'Otro nombre', 'quantity' => 1, 'subtract' => true]),
            777 => tpv_producto(777, ['no_publicable' => true]),
        ]);
        $sync = (new TPV_Sync_Product_Sync($api))->conStockEnVuelo(fn () => []);

        $sim = $sync->reconcileBidirectional(true, ['catalogo' => 'tpv', 'stock' => 'tpv']);
        $t->assertEquals('publish', estado_post(8310), 'la simulación no escribe');
        $t->assertEquals(1, $sim['divergentes'] ?? -1, 'la simulación avisa de que pasará a borrador');
        $t->assertEquals(0, $sim['synced'] ?? -1, 'ni cuenta como «se traerá» el que solo está en el TPV');

        $sync->reconcileBidirectional(false, ['catalogo' => 'tpv', 'stock' => 'tpv']);
        $t->assertEquals([], $GLOBALS['__wp_inserts'], 'creó en Woo un no publicable');
        $t->assertEquals('draft', estado_post(8310), 'Woo lo sigue enseñando');
        $t->assertEquals('Producto 8310', $GLOBALS['__wp_posts'][8310]->post_title, 'corrigió el catálogo');
        $t->assertEquals(5, np_meta(8310, '_stock'), 'corrigió el stock');
        $t->assertEquals([], $api->patches, 'escribió en el TPV');
    });

    $t->test('test_volcado_por_lotes_lo_salta', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $sync = np_sync([
            502 => tpv_producto(502, ['no_publicable' => true]),
            777 => tpv_producto(777, ['no_publicable' => true]),
            778 => tpv_producto(778, ['no_publicable' => false]),
        ]);
        $GLOBALS['__wp_options']['tpv_sync_pull_ids_cache'] = json_encode([502, 777, 778]);
        $GLOBALS['__wp_options']['tpv_sync_pull_ids_cache_ts'] = time();
        $stats = $sync->import_all();

        $t->assertEquals(1, count($GLOBALS['__wp_inserts']), 'solo se crea el publicable');
        $t->assertEquals('TPV 778', $GLOBALS['__wp_inserts'][0]['post_title'] ?? null);
        $t->assertEquals(2, $stats['no_publicable'] ?? 0, 'los dos no publicables se saltan');
        $t->assertEquals('draft', estado_post(8310), 'el que ya estaba pasa a borrador');
    });

    $t->test('el repaso nocturno de stock lo retira y no le corrige el stock', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
        $stats = np_sync([502 => tpv_producto(502, ['no_publicable' => true, 'subtract' => true, 'quantity' => 3])])
            ->reconcile(100);
        $t->assertEquals('draft', estado_post(8310), 'el repaso lo ve marcado y Woo lo sigue enseñando');
        $t->assertEquals(5, np_meta(8310, '_stock'));
        $t->assertEquals(0, $stats['fixed'] ?? -1);
        $t->assertEquals(1, $stats['skipped'] ?? -1);
    });

    $t->test('el repaso lo retira aunque además se venda a peso', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        np_sync([502 => tpv_producto(502, ['no_publicable' => true, 'sold_by_weight' => true])])->reconcile(100);
        $t->assertEquals('draft', estado_post(8310));
    });

    $t->test('«Reconciliar» que enlaza por código un producto marcado lo retira', function ($t) {
        np_tienda();
        // 8308 está en Woo sin enlace y con el mismo código que el 777 del TPV.
        $GLOBALS['wpdb'] = new class extends stdClass {
            public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta';
            public function insert($t, $f) { return 1; }
            public function get_results($q, $f = null) {
                return str_contains((string) $q, 'pm_sku') ? [['post_id' => 8308, 'post_modified' => '', 'sku' => 'M777', 'gtin' => '']] : [];
            }
            public function get_col($q) { return []; }
            public function prepare($sql, ...$a) { return (new WpdbFalso())->prepare($sql, ...$a); }
            public function get_var($q) { return (new WpdbFalso())->get_var($q); }
        };
        $sync = np_sync([777 => tpv_producto(777, ['no_publicable' => true])]);
        $sync->reconcileBidirectional(false, ['catalogo' => 'tpv', 'stock' => 'tpv']);
        $t->assertEquals(777, np_meta(8308, '_tpv_product_id'), 'preparación: se enlaza');
        $t->assertEquals('draft', estado_post(8308), 'enlazado y publicado');
    });

    $t->test('editar en Woo un marcado no manda nada de su ficha a la caja; el stock sí', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes'];
        $GLOBALS['__wp_posts'][8310]->post_title = 'Nombre de antes de la marca';
        $api = new ApiNoPublicableFalsa([502 => tpv_producto(502, ['no_publicable' => true, 'quantity' => 5])]);
        $sync = new TPV_Sync_Product_Sync($api);
        $sync->upsert(tpv_producto(502, ['no_publicable' => true, 'name' => 'Nombre actual en la caja']));
        $api->llamadas = [];

        $t->assertEquals(false, $sync->push_wc_product_to_tpv(8310));
        $t->assertEquals([], $api->llamadas, 'la copia congelada de Woo volvería a la caja');

        $sync->push_wc_stock_change(new WC_Product(8310, 4.0), ['stock_quantity']);
        $t->assertEquals(-1.0, $api->patches['/products/502/stock']['quantity_change'] ?? 'ausente',
            'el stock sigue viajando');

        // Un borrador puesto a mano, sin marca, viaja como siempre.
        $GLOBALS['__wp_posts'][8306]->post_status = 'draft';
        $sync->push_wc_product_to_tpv(8306);
        $t->assertEquals(0, $api->patches['/products/900']['status'] ?? 'ausente');
    });

    $t->test('el volcado Woo → TPV del asistente lo omite', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_tpv_no_publicable' => '1'];
        $api = new ApiNoPublicableFalsa();
        $stats = (new TPV_Sync_Product_Sync($api))->push_wc_products_bulk([8310, 8306]);

        $t->assert(!in_array('R3UW28310', array_column($api->itemsBulk, 'sku'), true), '/products/bulk lleva el marcado');
        $t->assertEquals(['2RKA78306'], array_column($api->itemsBulk, 'sku'), 'el que no está marcado sí va');
        $t->assert(!isset($api->patches['/products/502']), 'ni por la ruta singular');
        $t->assertEquals(0, ($stats['errors'] ?? 0) + ($stats['fallback'] ?? 0), 'ni cuenta como error: es un omitido');
    });

    $t->test('papelera, restaurar, borrar y borrar una talla en Woo no tocan la caja', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_tpv_no_publicable' => '1'];
        $GLOBALS['__wp_posts'][8320] = (object) ['ID' => 8320, 'post_type' => 'product_variation', 'post_parent' => 8310];
        $GLOBALS['__wp_meta'][8320] = ['_tpv_option_value_id' => 33];
        $api = new ApiNoPublicableFalsa();
        $sync = new TPV_Sync_Product_Sync($api);
        $sync->push_wc_trash_to_tpv(8310);
        $sync->push_wc_untrash_to_tpv(8310);
        $sync->push_wc_delete_to_tpv(8310);
        $sync->push_wc_variation_delete_to_tpv(8320);
        $t->assertEquals([], $api->llamadas, 'la caja oculta, borra o pierde la talla de un marcado');

        // Sin la marca, todo como siempre.
        unset($GLOBALS['__wp_meta'][8310]['_tpv_no_publicable']);
        $sync->push_wc_trash_to_tpv(8310);
        $sync->push_wc_variation_delete_to_tpv(8320);
        $t->assertEquals(0, $api->patches['/products/502']['status'] ?? 'ausente');
        $t->assert(in_array('DELETE /products/502/variants/33', $api->llamadas, true), 'la talla se borra en la caja');
    });

    $t->test('el autocurado no le sube fotos ni le cuadra el precio', function ($t) {
        autocura_tienda([8400 => ['M777', 70, [], 777]]);
        $api = new ApiAutocuraFalsa([['product_id' => 777, 'model' => 'M777', 'sku' => '']]);
        $GLOBALS['__wp_meta'][8400]['_tpv_no_publicable'] = '1';
        $stats = (new TPV_Sync_Product_Sync($api))->autocurar();
        $t->assertEquals([], $api->opsBatch, 'la foto de la web viajaría a la caja');
        $t->assertEquals(1, $stats['revisados'] ?? 0, 'preparación: lo revisa');

        // Sin la marca, la foto de la web sí sube (comprobación de que el test mira algo).
        autocura_tienda([8400 => ['M777', 70, [], 777]]);
        $api = new ApiAutocuraFalsa([['product_id' => 777, 'model' => 'M777', 'sku' => '']]);
        (new TPV_Sync_Product_Sync($api))->autocurar();
        $t->assert($api->opsBatch !== [], 'preparación: sin marca la foto sube');
    });

    $t->test('la revisión de precios del autocurado no lo toca ni lo da por atascado', function ($t) {
        cuadre_tienda(['yes', 'yes', 21], [7071 => new ProductoPvpFalso(7071, '9.92')], [900 => [12.00, null]]);
        $GLOBALS['__wp_meta'][7071]['_tpv_no_publicable'] = '1';
        $s = cuadre_pasada();
        $t->assertEquals([], cuadre_patches(), 'el precio congelado de Woo volvería a la caja');
        $t->assertEquals(0, $s['pvp_atascados'] ?? -1, 'el panel diría «no se ha podido igualar»');
    });

    $t->test('la unión de categorías no le junta categorías en ningún sentido', function ($t) {
        foreach ([TPV_Sync_Categorias_Sync::MARCA, TPV_Sync_Categorias_Sync::CURSOR] as $k) {
            unset($GLOBALS['__wp_options'][$k]);
        }
        $GLOBALS['__wp_meta'][8310] = ['_tpv_product_id' => 502, '_tpv_no_publicable' => '1'];
        $api = new ApiCategoriasFalsa();
        $api->productos = [502 => [1]];
        $s = new TPV_Sync_Categorias_Sync($api, new TerminosEnMemoria(),
            fn (int $desde, int $lote): array => $desde < 8310 ? [['post_id' => 8310, 'tpv_id' => 502]] : []);
        $s->unirProductos(10);
        $t->assertEquals([], $api->llamadas, 'sus categorías de Woo viajarían a la caja');
        $t->assertEquals([1], $api->productos[502]);
    });

    $t->test('ningún camino de catálogo Woo → TPV queda sin la guarda', function ($t) {
        $ps = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');
        $cuerpo = function (string $src, string $firma): string {
            $i = strpos($src, $firma);
            if ($i === false) return '';
            $j = strpos($src, "\n    }\n", $i);
            return substr($src, $i, $j === false ? null : $j - $i);
        };
        foreach (['public function push_wc_product_to_tpv(', 'public function push_wc_products_bulk(',
                  'public function push_wc_trash_to_tpv(', 'public function push_wc_untrash_to_tpv(',
                  'public function push_wc_delete_to_tpv(', 'public function push_wc_variation_delete_to_tpv(',
                  'private function push_images_to_tpv('] as $f) {
            $t->assert(str_contains($cuerpo($ps, $f), 'esNoPublicable('), "sin guarda: $f");
        }
        $cat = (string) file_get_contents(dirname(__DIR__) . '/includes/class-categorias-sync.php');
        $t->assert(str_contains($cuerpo($cat, 'public function unirProductos('), 'esNoPublicable('), 'sin guarda: unirProductos');
        $adm = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        $t->assert(str_contains($cuerpo($adm, 'public function ajax_reset_sync('), 'esNoPublicable('),
            'sin guarda: el reset que borra en el TPV');
    });

    $t->test('R1: desmarcar no devuelve a la papelera lo que el comerciante sacó de ella', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $GLOBALS['__wp_posts'][8310]->post_status = 'trash';
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));          // en la papelera: no se mueve ni se apunta
        $t->assertEquals(null, np_meta(8310, '_tpv_no_publicable_estado'));
        $GLOBALS['__wp_posts'][8310]->post_status = 'draft';                  // lo restaura (WordPress lo deja en borrador)
        $sync->upsert(tpv_producto(502, ['no_publicable' => false, 'status' => 1]));
        $t->assertEquals('publish', estado_post(8310), 'vuelve a la papelera');
    });

    foreach (['private', 'pending'] as $aMano) {
        $t->test("R2: un «{$aMano}» puesto a mano durante la marca sobrevive al repaso y al desmarcado", function ($t) use ($aMano) {
            np_tienda();
            $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_manage_stock' => 'yes', '_stock' => 5];
            $sync = np_sync([502 => tpv_producto(502, ['no_publicable' => true, 'subtract' => true, 'quantity' => 5])]);
            $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
            $GLOBALS['__wp_posts'][8310]->post_status = $aMano;
            $sync->reconcile(100);                                             // aún marcado: vuelve a borrador
            $t->assertEquals('draft', estado_post(8310));
            $t->assertEquals($aMano, np_meta(8310, '_tpv_no_publicable_estado'), 'manda la última decisión');
            $sync->upsert(tpv_producto(502, ['no_publicable' => false]));
            $t->assertEquals($aMano, estado_post(8310), 'sale publicado');
        });
    }

    $t->test('un estado apuntado que no se puede restaurar no se aplica', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_tpv_no_publicable' => '1',
                                        '_tpv_no_publicable_estado' => 'trash'];   // de una versión anterior
        $GLOBALS['__wp_posts'][8310]->post_status = 'draft';
        np_sync()->upsert(tpv_producto(502, ['no_publicable' => false]));
        $t->assertEquals('publish', estado_post(8310), 'la papelera no se restaura nunca');
    });

    $t->test('test_imagen_que_trajo_la_caja_y_ya_no_viene_se_quita_de_la_galeria', function ($t) {
        np_tienda_con_fotos();
        $sync = np_sync();

        // g2 marcada en el TPV: deja de venir.
        $sync->upsert(tpv_producto(502, ['images' => np_imagenes(['principal.jpg', 'g1.jpg'])]));
        $t->assertEquals('82,90', np_meta(8310, '_product_image_gallery'), 'g2 sigue en la galería');
        $t->assertEquals(81, $GLOBALS['__wp_thumbs'][8310] ?? 0);

        // La principal marcada: `image` a null y fuera de `images`.
        $sync->upsert(tpv_producto(502, ['image' => null, 'images' => np_imagenes(['g1.jpg'], false)]));
        $t->assertEquals(0, $GLOBALS['__wp_thumbs'][8310] ?? 0, 'la principal marcada sigue de destacada');
        $t->assertEquals('82,90', np_meta(8310, '_product_image_gallery'));

        // Todas marcadas (o con ruta rara): `images` vacío.
        $sync->upsert(tpv_producto(502, ['image' => null, 'images' => []]));
        $t->assertEquals('90', np_meta(8310, '_product_image_gallery'), 'quedan fotos de la caja con `images` vacío');

        $t->assertEquals(NP_IMG . 'g2.jpg', np_meta(83, '_tpv_image_url'), 'el adjunto no se borra (B4)');
    });

    $t->test('test_imagen_subida_en_woo_no_se_toca', function ($t) {
        np_tienda_con_fotos();
        $GLOBALS['__wp_thumbs'][8310] = 90;                         // la destacada la subió el comerciante
        $GLOBALS['__wp_meta'][8310]['_product_image_gallery'] = '82,91';
        $GLOBALS['__wp_meta'][91] = [];
        $GLOBALS['__wp_adjuntos'][8310][] = 91;

        np_sync()->upsert(tpv_producto(502, ['image' => null, 'images' => []]));
        $t->assertEquals(90, $GLOBALS['__wp_thumbs'][8310] ?? 0, 'se quitó una destacada subida en Woo');
        $t->assertEquals('91', np_meta(8310, '_product_image_gallery'), 'se quitó una foto subida en Woo');
    });

    $t->test('una foto de la caja que sigue viniendo se queda aunque cambie de sitio', function ($t) {
        np_tienda_con_fotos();
        // La principal pasa a ser g1 y la vieja principal va a la galería.
        np_sync()->upsert(tpv_producto(502, ['images' => np_imagenes(['g1.jpg', 'principal.jpg', 'g2.jpg'])]));
        $t->assertEquals(82, $GLOBALS['__wp_thumbs'][8310] ?? 0);
        $t->assertEquals('81,83,90', np_meta(8310, '_product_image_gallery'));
    });

    $t->test('R4: la caja cambia de dominio: las fotos que siguen viniendo se quedan sin descargar nada', function ($t) {
        np_tienda_con_fotos();
        $otra = 'https://caja-nueva.example/image/catalog/prov/';
        $imgs = [
            ['url' => $otra . 'principal.jpg', 'is_main' => true,  'sort_order' => 0],
            ['url' => $otra . 'g1.jpg',        'is_main' => false, 'sort_order' => 1],
        ];
        np_sync()->upsert(tpv_producto(502, ['images' => $imgs]));   // wp_tempnam lanza si intenta descargar
        $t->assertEquals(81, $GLOBALS['__wp_thumbs'][8310] ?? 0);
        $t->assertEquals('82,90', np_meta(8310, '_product_image_gallery'), 'g1 sigue; g2 (marcada) sale');
    });

    $t->test('foto marcada que deja de venir + otra que falla por la red ⇒ la marcada sale y la que sigue viniendo se queda', function ($t) {
        np_tienda_con_fotos();
        $otra = 'https://caja-nueva.example/image/catalog/prov/';
        $GLOBALS['__http']['catalogoFalla'] = true;    // el doble HTTP contesta 503 a las rutas «…/products»
        $imgs = [
            ['url' => $otra . 'principal.jpg',   'is_main' => true,  'sort_order' => 0],
            ['url' => $otra . 'g1.jpg',          'is_main' => false, 'sort_order' => 1],   // sigue viniendo (otro dominio)
            ['url' => $otra . 'nueva/products',  'is_main' => false, 'sort_order' => 2],   // nueva: la descarga falla
        ];                                                                                 // g2, marcada: ya no viene
        try {
            np_sync()->upsert(tpv_producto(502, ['images' => $imgs]));
        } finally {
            unset($GLOBALS['__http']['catalogoFalla']);
        }
        $t->assertEquals('82,90', np_meta(8310, '_product_image_gallery'), 'la marcada sigue publicada por un 503 de otra');
        $t->assertEquals(81, $GLOBALS['__wp_thumbs'][8310] ?? 0);
        $t->assertEquals(NP_IMG . 'g1.jpg', np_meta(82, '_tpv_image_url'), 'la que sigue viniendo conserva su adjunto');
    });

    $t->test('cambio de dominio con la misma ruta y una descarga fallida ⇒ no se quita nada', function ($t) {
        np_tienda_con_fotos();
        $otra = 'https://caja-nueva.example/image/catalog/prov/';
        $GLOBALS['__http']['catalogoFalla'] = true;
        $imgs = [
            ['url' => $otra . 'principal.jpg',  'is_main' => true,  'sort_order' => 0],
            ['url' => $otra . 'g1.jpg',         'is_main' => false, 'sort_order' => 1],
            ['url' => $otra . 'g2.jpg',         'is_main' => false, 'sort_order' => 2],
            ['url' => $otra . 'nueva/products', 'is_main' => false, 'sort_order' => 3],
        ];
        try {
            np_sync()->upsert(tpv_producto(502, ['images' => $imgs]));
        } finally {
            unset($GLOBALS['__http']['catalogoFalla']);
        }
        $t->assertEquals(81, $GLOBALS['__wp_thumbs'][8310] ?? 0, 'se quitó la destacada');
        $t->assertEquals('82,83,90', np_meta(8310, '_product_image_gallery'), 'se quitó una foto que sigue viniendo');
    });

    $t->test('una destacada de la caja cuya ruta sigue viniendo (ahora en la galería) se queda', function ($t) {
        np_tienda_con_fotos();
        np_sync()->upsert(tpv_producto(502, ['image' => null,
            'images' => np_imagenes(['principal.jpg', 'g1.jpg', 'g2.jpg'], false)]));
        $t->assertEquals(81, $GLOBALS['__wp_thumbs'][8310] ?? 0, 'se quitó una foto que la caja sigue mandando');
    });

    $t->test('la principal cambia de ruta y su descarga falla ⇒ la destacada vieja sale igual (la barrera manda)', function ($t) {
        np_tienda_con_fotos();
        $GLOBALS['__http']['catalogoFalla'] = true;
        try {
            np_sync()->upsert(tpv_producto(502, ['images' => [
                ['url' => NP_IMG . 'nueva-principal/products', 'is_main' => true, 'sort_order' => 0]]]));
        } finally {
            unset($GLOBALS['__http']['catalogoFalla']);
        }
        $t->assertEquals(0, $GLOBALS['__wp_thumbs'][8310] ?? 0, 'una principal que ya no viene sigue de destacada');
        $t->assertEquals('90', np_meta(8310, '_product_image_gallery'));
    });

    // Una descarga sin Content-Type (o con uno que no es imagen) es una descarga fallida:
    // antes, strtolower(strtok('', ';')) lanzaba un TypeError que cortaba el aviso antes
    // de quitar la foto marcada.
    $tipos = [
        'sin Content-Type'          => [],
        'con un tipo que no es imagen' => ['content-type' => 'text/html; charset=UTF-8'],
    ];
    foreach ($tipos as $caso => $cabeceras) {
        $t->test("foto nueva {$caso} + una marcada que deja de venir ⇒ sin excepción, la marcada sale y la nueva no se adjunta", function ($t) use ($cabeceras) {
            np_tienda_con_fotos();
            $registro = np_registro();
            $nueva = NP_IMG . 'nueva.jpg';
            $GLOBALS['__http']['respuestas'][$nueva] = ['code' => 200, 'body' => '<html>no es una foto</html>', 'headers' => $cabeceras];
            try {
                $r = np_sync()->upsert(tpv_producto(502, ['images' => [
                    ['url' => NP_IMG . 'principal.jpg', 'is_main' => true,  'sort_order' => 0],
                    ['url' => NP_IMG . 'g1.jpg',        'is_main' => false, 'sort_order' => 1],
                    ['url' => $nueva,                   'is_main' => false, 'sort_order' => 2],
                ]]));   // g2, marcada: ya no viene. wp_tempnam y media_handle_sideload lanzan si se usan.
            } finally {
                unset($GLOBALS['__http']['respuestas'][$nueva]);
            }
            $t->assertEquals('updated', $r);
            $t->assertEquals('82,90', np_meta(8310, '_product_image_gallery'), 'la marcada sigue publicada');
            $t->assertEquals(81, $GLOBALS['__wp_thumbs'][8310] ?? 0);
            $avisos = array_filter($registro->filas, fn ($f) => ($f['status'] ?? '') === 'warn'
                && str_contains((string) ($f['message'] ?? ''), 'nueva.jpg'));
            $t->assertEquals(1, count($avisos), 'la descarga fallida no queda en el Registro');
        });
    }

    // ── Fotos compartidas por ruta con otro post (ronda 2) ──────────────────────────
    // La base buscaba el adjunto de una URL ya importada con un LIMIT 1 global (en MySQL,
    // el más antiguo): la galería o la destacada de un producto podía apuntar al adjunto
    // X de otro post (otro producto, el de antes de un reset, un duplicado) aunque el
    // producto tuviera su propio adjunto Y con la misma ruta.

    $t->test('A: sin marca, la galería que apunta al adjunto de otro post no sale con la foto repetida', function ($t) {
        np_fotos_compartidas();
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['images' => np_imagenes(['principal.jpg', 'g1.jpg'])]));
        $t->assertEquals(81, $GLOBALS['__wp_thumbs'][8310] ?? 0);
        $t->assertEquals('82', np_meta(8310, '_product_image_gallery'), 'la misma foto dos veces');
        $sync->upsert(tpv_producto(502, ['images' => np_imagenes(['principal.jpg', 'g1.jpg'])]));
        $t->assertEquals('82', np_meta(8310, '_product_image_gallery'), 'segundo aviso');
    });

    $t->test('B: una foto marcada sale de la galería aunque el adjunto sea de otro post', function ($t) {
        np_fotos_compartidas();
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['images' => np_imagenes(['principal.jpg'])]));   // g1 marcada
        $t->assertEquals('', (string) np_meta(8310, '_product_image_gallery'), 'la foto marcada sigue en la galería');
        $sync->upsert(tpv_producto(502, ['images' => np_imagenes(['principal.jpg'])]));
        $t->assertEquals('', (string) np_meta(8310, '_product_image_gallery'), 'segundo aviso');
        $t->assertEquals(NP_IMG . 'g1.jpg', np_meta(82, '_tpv_image_url'), 'el adjunto no se borra (B4)');
    });

    $t->test('B2: marcada después de un aviso sin marca: sale igual', function ($t) {
        np_fotos_compartidas();
        $sync = np_sync();
        $sync->upsert(tpv_producto(502, ['images' => np_imagenes(['principal.jpg', 'g1.jpg'])]));   // sin marca
        $sync->upsert(tpv_producto(502, ['images' => np_imagenes(['principal.jpg'])]));             // g1 marcada
        $t->assertEquals('', (string) np_meta(8310, '_product_image_gallery'), 'la foto marcada sigue en la galería');
    });

    $t->test('D: la destacada es el adjunto de otro post y la principal se marca ⇒ la destacada sale', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502, '_product_image_gallery' => '82'];
        $GLOBALS['__wp_meta'][80] = ['_tpv_image_url' => NP_IMG . 'principal.jpg'];   // X, de otro post
        $GLOBALS['__wp_meta'][81] = ['_tpv_image_url' => NP_IMG . 'principal.jpg'];   // Y, de 8310
        $GLOBALS['__wp_meta'][82] = ['_tpv_image_url' => NP_IMG . 'g1.jpg'];
        $GLOBALS['__wp_thumbs'][8310] = 80;
        $GLOBALS['__wp_adjuntos'][8310] = [81, 82];
        np_sync()->upsert(tpv_producto(502, ['image' => null, 'images' => np_imagenes(['g1.jpg'], false)]));
        $t->assertEquals(0, $GLOBALS['__wp_thumbs'][8310] ?? 0, 'la principal marcada sigue de destacada');
        $t->assertEquals('82', np_meta(8310, '_product_image_gallery'));
    });

    $t->test('C: con «manda el TPV», publicar a mano un marcado se deshace al guardar, sin llamar a la caja', function ($t) {
        np_tienda();
        $GLOBALS['__wp_options']['tpv_sync_principal'] = 'tpv';
        try {
            $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
            $api = new ApiNoPublicableFalsa([502 => tpv_producto(502, ['no_publicable' => true])]);
            $sync = new TPV_Sync_Product_Sync($api);
            $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
            $GLOBALS['__wp_posts'][8310]->post_status = 'publish';   // el comerciante lo publica a mano
            $api->llamadas = [];
            $t->assertEquals(false, $sync->push_wc_product_to_tpv(8310));   // woocommerce_update_product
            $t->assertEquals('draft', estado_post(8310), 'publicado a mano y sigue publicado');
            $t->assertEquals([], $api->llamadas, 'llamó a la caja');
            $t->assertEquals('publish', np_meta(8310, '_tpv_no_publicable_estado'), 'manda la última decisión');
        } finally {
            $GLOBALS['__wp_options']['tpv_sync_principal'] = '';
        }
    });

    $t->test('C: en el modo por defecto, también; y un marcado sin enlace no gana un enlace «0»', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8308] += ['_tpv_no_publicable' => '1'];   // marcado y sin enlace (tras un reset)
        $api = new ApiNoPublicableFalsa();
        (new TPV_Sync_Product_Sync($api))->push_wc_product_to_tpv(8308);
        $t->assertEquals('draft', estado_post(8308));
        $t->assertEquals(null, np_meta(8308, '_tpv_product_id'), 'se inventó un enlace');
        $t->assertEquals([], $api->llamadas);
    });

    $t->test('R7: una foto de la caja elegida a mano para otro producto se queda en su galería', function ($t) {
        np_tienda_con_fotos();
        $GLOBALS['__wp_meta'][8306]['_product_image_gallery'] = '82';   // la 82 la trajo la caja para el 502
        $GLOBALS['__wp_adjuntos'][8306] = [];
        np_sync()->upsert(tpv_producto(900, ['images' => []]));
        $t->assertEquals('82', np_meta(8306, '_product_image_gallery'));

        // Lo que la caja pone en un producto sí queda apuntado como suyo, y sale al dejar de venir.
        $sync = np_sync();
        $sync->upsert(tpv_producto(900, ['images' => np_imagenes(['g1.jpg'], false)]));
        $t->assertEquals(['catalog/prov/g1.jpg'], np_meta(8306, '_tpv_fotos_caja'), 'la ruta queda apuntada como suya');
        $sync->upsert(tpv_producto(900, ['images' => []]));
        $t->assertEquals('', np_meta(8306, '_product_image_gallery'), 'la marcada sigue en el producto al que la caja la puso');
    });

    $t->test('el motivo «no publicable» está traducido con la misma clave en los tres idiomas', function ($t) {
        $dir = dirname(__DIR__) . '/languages/';
        $claves = ['no publicable en el TPV', 'no publicable en el TPV: pasa a borrador'];
        $referencia = null;
        foreach (['es_ES', 'en_US', 'fr_FR'] as $idioma) {
            $po = np_po($dir . "tpv-sync-$idioma.po");
            $mo = np_mo($dir . "tpv-sync-$idioma.mo");
            foreach ($claves as $c) {
                $t->assert(($po[$c] ?? '') !== '', "$idioma.po sin traducir: «{$c}»");
                $t->assertEquals($po[$c] ?? null, $mo[$c] ?? null, "$idioma.mo sin recompilar: «{$c}»");
            }
            $ids = array_keys($po);
            sort($ids);
            $referencia ??= $ids;
            $t->assertEquals($referencia, $ids, "$idioma.po no tiene las mismas claves que es_ES");
        }
    });
}
