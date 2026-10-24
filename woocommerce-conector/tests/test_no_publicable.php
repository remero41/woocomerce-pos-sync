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
 *   - su borrador no vuelve al TPV como «oculto» (status 0 lo escondería en la caja);
 *   - una foto que trajo la caja (`_tpv_image_url`) y deja de venir sale de la
 *     galería y de la destacada; una subida a mano en Woo no se toca. Ningún
 *     adjunto se borra (wp_delete_attachment es de B4, R44).
 *
 * Marcar en el TPV no avisa hoy a Woo (no hay product.updated): el conector lo
 * ve en el siguiente camino que lea el producto, y todos tienen que reaccionar.
 */

require_once __DIR__ . '/test_venta_a_peso.php';

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

    $t->test('su borrador no vuelve al TPV como «oculto» al editarlo en Woo', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $api = new ApiNoPublicableFalsa([502 => tpv_producto(502, ['no_publicable' => true])]);
        $sync = new TPV_Sync_Product_Sync($api);
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $sync->push_wc_product_to_tpv(8310);

        $parche = $api->patches['/products/502'] ?? null;
        $t->assert(is_array($parche), 'no se envió la edición al TPV');
        $t->assert(!array_key_exists('status', (array) $parche), 'status 0 lo escondería en la caja');

        // Un borrador puesto a mano sí viaja como siempre.
        $GLOBALS['__wp_posts'][8306]->post_status = 'draft';
        $sync->push_wc_product_to_tpv(8306);
        $t->assertEquals(0, $api->patches['/products/900']['status'] ?? 'ausente');
    });

    $t->test('el envío en bloque lo manda por la ruta que no toca el estado', function ($t) {
        np_tienda();
        $GLOBALS['__wp_meta'][8310] += ['_tpv_product_id' => 502];
        $api = new ApiNoPublicableFalsa([502 => tpv_producto(502, ['no_publicable' => true])]);
        $sync = new TPV_Sync_Product_Sync($api);
        $sync->upsert(tpv_producto(502, ['no_publicable' => true]));
        $sync->push_wc_products_bulk([8310]);

        $t->assertEquals([], $api->itemsBulk, '/products/bulk escribe siempre status');
        $t->assert(isset($api->patches['/products/502']) && !array_key_exists('status', $api->patches['/products/502']),
            'va por PATCH sin status');
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
