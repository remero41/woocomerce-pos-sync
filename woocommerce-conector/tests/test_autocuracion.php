<?php
declare(strict_types=1);
/**
 * AUTOCURACIÓN: el enlace y las imágenes se reparan solos (lulubeauty, 29-09-2026).
 *
 * Tras el volcado del 28-09, 917 de 1.023 productos del TPV seguían sin foto
 * y los simples sin `_tpv_product_id` en Woo (sin él, los pedidos de Woo con
 * esos productos se descartan). Nada lo reparaba solo:
 *
 *  - el cron semanal solo corrige STOCK, y solo de productos ya enlazados;
 *  - la cola solo reintenta STOCK;
 *  - una imagen fallida solo se reintenta si alguien vuelve a guardar ESE
 *    producto en Woo;
 *  - «Reconciliar» enlaza, pero es un botón.
 *
 * Arreglarlo exigía entrar al WordPress de cada tienda. autocurar() recorre
 * el catálogo por tandas (cursor) y, por producto:
 *
 *   1. sin enlace -> lo busca en el TPV (external_id, luego model, luego sku)
 *      y SOLO enlaza: no sobrescribe nada ni da de alta;
 *   2. con enlace -> sube las imágenes que falten (las ya enviadas no se
 *      repiten: `_tpv_images_sent`).
 *
 * Se ejecuta autocurar() de verdad, con WordPress y la API en memoria.
 */

require_once __DIR__ . '/test_volcado_bulk.php'; // ProductoWooFalso + stubs de WP

if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('add_option')) {
    function add_option($k, $v = '', $d = '', $autoload = 'yes') {
        if (array_key_exists($k, $GLOBALS['__wp_options'] ?? [])) { return false; }
        $GLOBALS['__wp_options'][$k] = $v;
        return true;
    }
}
if (!function_exists('tpv_sync_module_catalog')) {
    // Como en woocommerce-conector.php (no se carga en los tests).
    function tpv_sync_module_catalog(): bool { return (bool) get_option('tpv_sync_module_catalog', 1); }
}
if (!function_exists('delete_option')) {
    function delete_option($k) { unset($GLOBALS['__wp_options'][$k]); return true; }
}

/** $wpdb en memoria: lo justo para las consultas de autocurar(). */
final class WpdbAutocura
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public array $log = [];
    public function insert($tabla, $fila) { $this->log[] = $fila; return 1; }
    public function prepare($sql, ...$args) { return ['sql' => $sql, 'args' => $args]; }

    /** Productos (publish/draft) con ID > cursor, ascendentes, limitados. */
    public function get_col($q)
    {
        [$cursor, $limite] = $q['args'];
        $ids = [];
        foreach ($GLOBALS['__wp_posts'] as $id => $p) {
            if ($p->post_type === 'product' && in_array($p->post_status, ['publish', 'draft'], true) && $id > $cursor) {
                $ids[] = $id;
            }
        }
        sort($ids);
        return array_map('strval', array_slice($ids, 0, $limite));
    }

    public int $consultasEnlaces = 0;

    /** Enlaces existentes: post_id + meta_value de `_tpv_product_id`. */
    public function get_results($sql, $salida = null)
    {
        $this->consultasEnlaces++;
        $filas = [];
        foreach ($GLOBALS['__wp_meta'] as $postId => $metas) {
            if (!empty($metas['_tpv_product_id'])) {
                $filas[] = ['post_id' => (string) $postId, 'meta_value' => (string) $metas['_tpv_product_id']];
            }
        }
        return $filas;
    }

    public function get_var($q)
    {
        if (!is_array($q)) { return null; }
        [$clave, $valor] = preg_match("/meta_key = '([a-z_]+)'/", $q['sql'], $m)
            ? [$m[1], $q['args'][0] ?? null]
            : [$q['args'][0] ?? '', $q['args'][1] ?? null];
        foreach ($GLOBALS['__wp_meta'] as $postId => $metas) {
            if ((string) ($metas[$clave] ?? '') === (string) $valor) { return $postId; }
        }
        return null;
    }
}

/** API del TPV en memoria: catálogo para el índice y /batch de imágenes. */
final class ApiAutocuraFalsa extends TPV_Sync_API_Client
{
    public int $gets = 0;
    public array $opsBatch = [];
    public array $posts = [];
    /** @var int status que devuelve cada operación de imagen */
    public int $statusImagen = 201;

    /** @param array $catalogo filas {product_id, model, sku, external_id?} */
    public function __construct(public array $catalogo = []) { parent::__construct(); }

    public function get(string $path, array $params = []): array
    {
        $this->gets++;
        if ($path === '/products') {
            return ['data' => $this->catalogo, 'meta' => ['cursor' => null]];
        }
        return ['error' => 'not_found'];
    }

    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $this->posts[] = $path;
        if ($path === '/batch') {
            $ops = $body['operations'] ?? [];
            $this->opsBatch = array_merge($this->opsBatch, $ops);
            $res = [];
            foreach ($ops as $i => $op) {
                $ok = $this->statusImagen < 300;
                $res[] = ['index' => $i, 'status' => $this->statusImagen,
                    'body' => $ok ? ['data' => ['image' => "catalog/api/x$i.jpg"]] : null,
                    'error' => $ok ? null : ['errors' => [['error' => 'signature_invalid', 'message' => 'no']]]];
            }
            return self::decide(200, ['results' => $res]);
        }
        return self::decide(404, ['error' => 'not_found']);
    }
}

/**
 * Tienda de prueba. Cada producto: [sku, thumb, galería, enlace previo].
 * Por defecto: catálogo mandado por Woo, módulo activo y conector configurado.
 */
function autocura_tienda(array $productos, array $opciones = []): void
{
    $GLOBALS['wpdb'] = new WpdbAutocura();
    $GLOBALS['__wp_meta'] = $GLOBALS['__wp_thumbs'] = $GLOBALS['__wp_posts'] = $GLOBALS['__wc_products'] = [];
    $GLOBALS['__wp_options'] = $opciones + [
        'tpv_sync_module_catalog' => 1,
        'tpv_sync_principal'      => 'wc',
        'tpv_sync_api_url'        => 'https://tpv.test/api/v1',
        'tpv_sync_client_id'      => 'wc_x',
        'tpv_sync_client_secret'  => 's',
    ];
    foreach ($productos as $id => [$sku, $thumb, $gal, $enlace]) {
        $GLOBALS['__wp_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product',
            'post_title' => "P$id", 'post_content' => '', 'post_status' => 'publish'];
        $GLOBALS['__wc_products'][$id] = new ProductoWooFalso($id, $sku, '1', false, null, $gal);
        $GLOBALS['__wp_meta'][$id] = $enlace ? ['_tpv_product_id' => $enlace] : [];
        if ($thumb) { $GLOBALS['__wp_thumbs'][$id] = $thumb; }
    }
}

function autocura_enlace(int $postId): int
{
    return (int) get_post_meta($postId, '_tpv_product_id', true);
}

/** API para el guardado singular con catálogo en el TPV. */
final class ApiGuardadoFalsa extends TPV_Sync_API_Client
{
    public array $altas = [];
    public array $patches = [];
    public function __construct(private array $catalogo) {}
    public function get(string $path, array $params = []): array
    {
        return $path === '/products' ? ['data' => $this->catalogo, 'meta' => ['cursor' => null]] : [];
    }
    public function patch(string $path, array $body = []): array
    {
        $this->patches[$path] = $body;
        return ['data' => ['product_id' => (int) basename($path)]];
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if ($path === '/products') { $this->altas[] = $body; return ['data' => ['product_id' => 777]]; }
        return ['results' => []];
    }
}

function run_autocuracion_tests(WooTestRunner $t): void
{
    $t->suite('Autocuración — guardar en Woo usa la misma regla');

    $t->test('guardar un producto sin enlace lo casa por external_id, sin duplicarlo', function ($t) {
        volcado_tienda();   // 8310 sin enlace, SKU R3UW28310
        $api = new ApiGuardadoFalsa([
            ['product_id' => 950, 'model' => 'SKU-ANTIGUO', 'sku' => 'SKU-ANTIGUO', 'external_id' => '8310'],
        ]);
        (new TPV_Sync_Product_Sync($api))->push_wc_product_to_tpv(8310);
        $t->assertEquals([], $api->altas, 'el SKU cambió en Woo: sin external_id se daba de alta OTRO producto');
        $t->assert(isset($api->patches['/products/950']), 'actualiza el que ya era suyo');
        $t->assertEquals(950, (int) get_post_meta(8310, '_tpv_product_id', true));
    });

    $t->suite('Autocuración — resolverEnlace (la regla, pura)');

    $idx = ['by_external' => ['10' => 501], 'by_model' => ['M-10' => 502, 'M-11' => 503], 'by_sku' => ['S-12' => 504]];
    $nadie = fn (int $tpv) => 0;

    $t->test('external_id manda sobre el model', function ($t) use ($idx, $nadie) {
        $t->assertEquals(501, TPV_Sync_Product_Sync::resolverEnlace(10, 'M-10', '', $idx, $nadie),
            'el volcado registró este post: es la pareja exacta aunque el SKU haya cambiado');
    });
    $t->test('sin external_id: model, y si no, sku', function ($t) use ($idx, $nadie) {
        $t->assertEquals(503, TPV_Sync_Product_Sync::resolverEnlace(11, 'M-11', 'S-12', $idx, $nadie));
        $t->assertEquals(504, TPV_Sync_Product_Sync::resolverEnlace(12, 'nada', 'S-12', $idx, $nadie));
        $t->assertEquals(0, TPV_Sync_Product_Sync::resolverEnlace(13, 'nada', 'nada', $idx, $nadie));
    });
    $t->test('claves vacías no casan con nada', function ($t) use ($nadie) {
        $idxVacio = ['by_external' => [], 'by_model' => ['' => 9], 'by_sku' => ['' => 9]];
        $t->assertEquals(0, TPV_Sync_Product_Sync::resolverEnlace(1, '', '', $idxVacio, $nadie));
    });
    $t->test('nunca roba un producto del TPV enlazado a OTRO post', function ($t) use ($idx) {
        $ocupado = fn (int $tpv) => $tpv === 501 ? 77 : 0;
        $t->assertEquals(502, TPV_Sync_Product_Sync::resolverEnlace(10, 'M-10', '', $idx, $ocupado),
            'el 501 ya es del post 77: se prueba el siguiente candidato');
        $todoOcupado = fn (int $tpv) => 77;
        $t->assertEquals(0, TPV_Sync_Product_Sync::resolverEnlace(10, 'M-10', '', $idx, $todoOcupado));
    });
    $t->test('si ya es de ESTE post, vale', function ($t) use ($idx) {
        $mio = fn (int $tpv) => $tpv === 501 ? 10 : 0;
        $t->assertEquals(501, TPV_Sync_Product_Sync::resolverEnlace(10, 'M-10', '', $idx, $mio));
    });

    $t->suite('Autocuración — autocurar() de verdad');

    $t->test('enlaza los productos que perdieron el enlace', function ($t) {
        autocura_tienda([8308 => ['MS2428308', 0, [], 0], 8310 => ['R3UW28310', 0, [], 0]]);
        $api = new ApiAutocuraFalsa([
            ['product_id' => 900, 'model' => 'OTRO', 'sku' => '', 'external_id' => '8308'],
            ['product_id' => 901, 'model' => 'R3UW28310', 'sku' => 'R3UW28310'],
        ]);
        $r = (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals(900, autocura_enlace(8308), 'por external_id');
        $t->assertEquals(901, autocura_enlace(8310), 'por model');
        $t->assertEquals(2, $r['enlazados'] ?? -1);
        $t->assertEquals([], array_values(array_diff($api->posts, ['/batch'])),
            'solo enlaza: ni altas ni PATCH que sobrescriban el TPV');
    });

    $t->test('sube las imágenes pendientes y no repite las ya enviadas', function ($t) {
        autocura_tienda([8308 => ['A', 70, [71, 72], 900]]);
        update_post_meta(8308, '_tpv_images_sent', ['https://tienda.test/img/70.jpg' => 'catalog/api/v.jpg']);
        $api = new ApiAutocuraFalsa();
        $r = (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $urls = array_map(fn ($o) => $o['body']['image_url'], $api->opsBatch);
        $t->assertEquals(['https://tienda.test/img/71.jpg', 'https://tienda.test/img/72.jpg'], $urls,
            'la destacada ya estaba: solo la galería');
        $t->assertEquals(0, $api->gets, 'todo enlazado: no hace falta cargar el catálogo del TPV');
        $t->assertEquals(1, $r['con_imagenes'] ?? -1);

        // Segunda pasada: ya no queda nada que subir.
        update_option('tpv_sync_autocura_cursor', 0);
        $api2 = new ApiAutocuraFalsa();
        (new TPV_Sync_Product_Sync($api2))->autocurar(25, 60.0);
        $t->assertEquals([], $api2->opsBatch, 'idempotente: la segunda vuelta no duplica');
    });

    $t->test('una imagen que falla se reintenta en la siguiente vuelta', function ($t) {
        autocura_tienda([8308 => ['A', 70, [], 900]]);
        $api = new ApiAutocuraFalsa();
        $api->statusImagen = 401;
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        update_option('tpv_sync_autocura_cursor', 0);
        $api2 = new ApiAutocuraFalsa();
        (new TPV_Sync_Product_Sync($api2))->autocurar(25, 60.0);
        $t->assertEquals(1, count($api2->opsBatch), 'no se da por subida: vuelve a intentarlo');
    });

    $t->test('enlaza y sube las imágenes en la misma pasada', function ($t) {
        autocura_tienda([8308 => ['A', 70, [], 0]]);
        $api = new ApiAutocuraFalsa([['product_id' => 900, 'model' => 'A', 'sku' => 'A']]);
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals('/products/900/images', $api->opsBatch[0]['path'] ?? '');
    });

    $t->test('no roba un producto del TPV que ya es de otro post', function ($t) {
        // 8400 y 8401 comparten SKU en Woo; 8400 ya es el dueño del 900.
        autocura_tienda([8400 => ['DUP', 0, [], 900], 8401 => ['DUP', 0, [], 0]]);
        $api = new ApiAutocuraFalsa([['product_id' => 900, 'model' => 'DUP', 'sku' => 'DUP']]);
        $r = (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals(0, autocura_enlace(8401), 'dos posts enlazados al mismo producto mezclarían pedidos y stock');
        $t->assertEquals(1, $r['sin_pareja'] ?? -1);
        $t->assertEquals(0, $r['enlazados'] ?? -1, 'ni cuenta como enlazado');
        $t->assert(!array_key_exists('_tpv_product_id', $GLOBALS['__wp_meta'][8401]), 'ni deja un enlace a 0 escrito');
    });

    $t->test('dentro de la misma tanda, un producto del TPV se enlaza una sola vez', function ($t) {
        autocura_tienda([8400 => ['DUP', 0, [], 0], 8401 => ['DUP', 0, [], 0]]);
        $api = new ApiAutocuraFalsa([['product_id' => 900, 'model' => 'DUP', 'sku' => 'DUP']]);
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals(900, autocura_enlace(8400));
        $t->assertEquals(0, autocura_enlace(8401));
    });

    $t->test('recorre por tandas con cursor y vuelve a empezar al acabar', function ($t) {
        autocura_tienda([1 => ['A', 0, [], 0], 2 => ['B', 0, [], 0], 3 => ['C', 0, [], 0]]);
        $cat = [['product_id' => 11, 'model' => 'A', 'sku' => ''], ['product_id' => 12, 'model' => 'B', 'sku' => ''],
                ['product_id' => 13, 'model' => 'C', 'sku' => '']];
        $s = new TPV_Sync_Product_Sync(new ApiAutocuraFalsa($cat));
        $s->autocurar(2, 60.0);
        $t->assertEquals([11, 12, 0], [autocura_enlace(1), autocura_enlace(2), autocura_enlace(3)], 'primera tanda: 2');
        $t->assertEquals(1, $GLOBALS['wpdb']->consultasEnlaces,
            'los enlaces existentes se leen UNA vez por pasada, no una por producto');
        $t->assertEquals(2, (int) get_option('tpv_sync_autocura_cursor'));
        $s->autocurar(2, 60.0);
        $t->assertEquals(13, autocura_enlace(3), 'segunda tanda: el resto');
        $r = $s->autocurar(2, 60.0);
        $t->assertEquals(true, $r['vuelta_completa'] ?? null, 'al final vuelve a empezar');
        $t->assertEquals(0, (int) get_option('tpv_sync_autocura_cursor'));
    });

    $t->test('una pasada recorre por TIEMPO, no por número: 300 productos sin nada pendiente, de una vez', function ($t) {
        // lulubeauty (30-09): el cron solo corre cuando alguien visita la web.
        // Con 25 productos por pasada, 3 visitas en 3 horas avanzaron 75 de
        // ~850. Revisar un producto que no tiene nada pendiente cuesta
        // milisegundos: lo que limita una pasada es el tiempo, no el número.
        $tienda = [];
        for ($id = 1; $id <= 300; $id++) { $tienda[$id] = ['S' . $id, 0, [], 1000 + $id]; }
        autocura_tienda($tienda);
        $api = new ApiAutocuraFalsa();
        $r = (new TPV_Sync_Product_Sync($api))->autocurar();
        $t->assertEquals(300, $r['revisados'] ?? -1, 'antes: 25 por visita');
        $t->assertEquals(0, $api->gets + count($api->posts), 'y sin gastar ni una llamada a la API');
    });

    $t->test('sin presupuesto de tiempo: deja el cursor donde se quedó', function ($t) {
        autocura_tienda([1 => ['A', 70, [], 11], 2 => ['B', 71, [], 12]]);
        $api = new ApiAutocuraFalsa();
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 0.0);
        $t->assertEquals(1, count($api->opsBatch), 'procesa al menos uno para avanzar siempre');
        $t->assertEquals(1, (int) get_option('tpv_sync_autocura_cursor'), 'el 2 queda para la siguiente');
    });

    $t->test('catálogo mandado por el TPV: enlaza, pero NO empuja imágenes de Woo', function ($t) {
        autocura_tienda([8308 => ['A', 70, [], 0]], ['tpv_sync_principal' => 'tpv']);
        $api = new ApiAutocuraFalsa([['product_id' => 900, 'model' => 'A', 'sku' => 'A']]);
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals(900, autocura_enlace(8308), 'el enlace sirve en cualquier modo (pedidos)');
        $t->assertEquals([], $api->opsBatch, 'las imágenes van del TPV a Woo: empujarlas haría eco');
    });

    $t->test('catálogo apagado o conector sin configurar: no toca nada', function ($t) {
        autocura_tienda([8308 => ['A', 70, [], 0]], ['tpv_sync_module_catalog' => 0]);
        $api = new ApiAutocuraFalsa([['product_id' => 900, 'model' => 'A', 'sku' => 'A']]);
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals(0, autocura_enlace(8308));
        $t->assertEquals(0, $api->gets + count($api->posts));

        autocura_tienda([8308 => ['A', 70, [], 0]], ['tpv_sync_client_secret' => '']);
        $api = new ApiAutocuraFalsa([['product_id' => 900, 'model' => 'A', 'sku' => 'A']]);
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals(0, $api->gets + count($api->posts), 'sin credenciales no hay a quién preguntar');
    });

    $t->test('dos pasadas a la vez: la segunda no hace nada (candado)', function ($t) {
        autocura_tienda([8308 => ['A', 70, [], 900]]);
        add_option('tpv_sync_autocura_candado', (string) time());
        $api = new ApiAutocuraFalsa();
        $r = (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals([], $api->opsBatch, 'subiría las mismas imágenes dos veces');
        $t->assertEquals(true, $r['ocupado'] ?? null);
    });

    $t->test('candado huérfano (proceso muerto) se recupera', function ($t) {
        autocura_tienda([8308 => ['A', 70, [], 900]]);
        add_option('tpv_sync_autocura_candado', (string) (time() - 3600));
        $api = new ApiAutocuraFalsa();
        (new TPV_Sync_Product_Sync($api))->autocurar(25, 60.0);
        $t->assertEquals(1, count($api->opsBatch), 'si no, un corte a mitad la apagaba para siempre');
        $t->assertEquals(false, get_option('tpv_sync_autocura_candado', false), 'y lo suelta al acabar');
    });
}
