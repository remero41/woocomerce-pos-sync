<?php
declare(strict_types=1);
/**
 * UN PEDIDO NUNCA LLEGA A MEDIAS (lulubeauty, 29/30-09-2026) — spec
 * SPEC_pedidos_woo_tpv.md, F1 + F2.
 *
 * send_to_tpv() descartaba con `continue` cada línea cuyo producto no tenía
 * `_tpv_product_id`: el pedido llegaba al TPV SIN esa línea (total y stock
 * mal) o, si no quedaba ninguna, se saltaba para siempre («Sin productos
 * mapeados al TPV»), sin cola ni reintento. Tras el volcado del 28-09 eso
 * eran todos los simples.
 *
 * Decisiones del usuario:
 *  - todo lo que se vende en la tienda existe en el TPV: una línea sin enlace
 *    se asegura en el momento (enlazar si ya existe; crear si no), en
 *    cualquier modo;
 *  - si aun así no se puede, el pedido se RETIENE con aviso: nunca a medias;
 *  - la cola no se multiplica: reencolar lo mismo devuelve la fila existente.
 *
 * Se ejecuta send_to_tpv() de verdad, con WordPress, Woo y la API en memoria.
 */

require_once __DIR__ . '/test_volcado_bulk.php';   // tienda 8306/8308/8310 + stubs WP
require_once dirname(__DIR__) . '/includes/class-order-sync.php';
require_once dirname(__DIR__) . '/includes/class-queue.php';

if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0) { return json_encode($v, $f); } }
if (!function_exists('get_user_meta')) { function get_user_meta($u, $k = '', $s = false) { return ''; } }
if (!function_exists('current_time')) { function current_time($t, $gmt = 0) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('WC')) { function WC() { return (object) ['countries' => null]; } }
if (!class_exists('TPV_Sync')) {
    /** El singleton del plugin: solo lo que usa la cola desde send_to_tpv(). */
    final class TPV_Sync
    {
        public static ?self $inst = null;
        public $queue;
        public static function instance(): self { return self::$inst ??= new self(); }
    }
}

final class ItemPedidoFalso
{
    public function __construct(private int $productId, private float $qty, private float $total,
                                private float $tax = 0.0, private string $nombre = 'Línea') {}
    public function get_product_id() { return $this->productId; }
    public function get_variation_id() { return 0; }
    public function get_quantity() { return $this->qty; }
    public function get_total() { return $this->total; }
    public function get_total_tax() { return $this->tax; }
    public function get_name() { return $this->nombre; }
}

final class PedidoWooFalso
{
    public array $notas = [];
    public function __construct(private int $id, private array $items, private string $estado = 'processing') {}
    public function get_id() { return $this->id; }
    public function get_status() { return $this->estado; }
    public function get_items($tipo = 'line_item') { return $tipo === 'coupon' ? [] : $this->items; }
    public function get_payment_method_title() { return 'Tarjeta'; }
    public function get_total() { return 0.0; }
    public function get_billing_first_name() { return 'Ana'; }
    public function get_billing_last_name() { return 'Pérez'; }
    public function get_billing_email() { return 'ana@test'; }
    public function get_billing_phone() { return ''; }
    public function get_customer_id() { return 0; }
    public function add_order_note($n) { $this->notas[] = $n; }
    public function update_status($s, $n = '') { $this->notas[] = "[$s] $n"; }
}
if (!function_exists('wc_get_order')) {
    function wc_get_order($id) { return $GLOBALS['__wc_orders'][(int) $id] ?? null; }
}

/** API del TPV: catálogo, altas de producto, PATCH y pedidos. */
final class ApiPedidosFalsa extends TPV_Sync_API_Client
{
    public array $pedidos = [];
    public array $altas = [];
    public array $patches = [];
    public bool $altaFalla = false;
    public bool $pedidoFalla = false;
    private int $siguiente = 776;
    public function __construct(public array $catalogo = []) {}
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
        if ($path === '/products') {
            $this->altas[] = $body;
            if ($this->altaFalla) {
                return ['error' => 'validation_error', 'errors' => [['field' => 'price', 'message' => 'price must be >= 0']]];
            }
            return ['data' => ['product_id' => ++$this->siguiente]];
        }
        if ($path === '/orders') {
            if ($this->pedidoFalla) {
                return ['errors' => [['error' => 'server_error', 'message' => 'caído']]];
            }
            $this->pedidos[] = $body;
            return ['data' => ['order_id' => 5000 + count($this->pedidos)]];
        }
        return ['results' => []];
    }
}

/** $wpdb con postmeta de pedidos pendientes y la tabla de la cola. */
final class WpdbPedidos
{
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';
    public array $log = [];
    public array $cola = [];
    public int $insert_id = 0;
    public int $rows_affected = 0;
    public function prepare($sql, ...$args) { return ['sql' => $sql, 'args' => $args]; }
    public function insert($tabla, $fila)
    {
        if (str_ends_with($tabla, 'tpv_sync_queue')) {
            $this->insert_id = count($this->cola) + 1;
            $this->cola[$this->insert_id] = (object) (['id' => $this->insert_id] + $fila);
            return 1;
        }
        $this->log[] = $fila;
        return 1;
    }
    public function update($tabla, $datos, $donde)
    {
        foreach ($datos as $k => $v) { $this->cola[$donde['id']]->$k = $v; }
        return 1;
    }
    public function get_var($q)
    {
        if (is_array($q) && str_contains($q['sql'], 'tpv_sync_queue')) {
            [$estado, $op, $payload] = $q['args'];
            foreach ($this->cola as $f) {
                if ($f->status === $estado && $f->operation === $op && $f->payload === $payload) { return $f->id; }
            }
            return null;
        }
        // find_wc_post(): post cuyo meta X vale Y.
        [$clave, $valor] = preg_match("/meta_key = '([a-z_]+)'/", $q['sql'], $m)
            ? [$m[1], $q['args'][0] ?? null] : [$q['args'][0] ?? '', $q['args'][1] ?? null];
        foreach ($GLOBALS['__wp_meta'] as $postId => $metas) {
            if ((string) ($metas[$clave] ?? '') === (string) $valor) { return $postId; }
        }
        return null;
    }
    public function get_results($q, $salida = null)
    {
        // La cola: todo lo pendiente (el reloj no importa aquí).
        return array_values(array_filter($this->cola, fn ($f) => $f->status === 'pending'));
    }
    /** Posts con el meta pendiente de la consulta, ID > cursor, ascendentes. */
    public function get_col($q)
    {
        if (str_contains($q['sql'], 'tpv_sync_log')) {
            // Pedidos registrados con ese evento, estado y mensaje.
            [$evento, $estado, $mensaje] = $q['args'];
            $ids = [];
            foreach ($this->log as $f) {
                if (($f['event_type'] ?? '') === $evento && ($f['status'] ?? '') === $estado
                    && ($f['message'] ?? '') === $mensaje) {
                    $ids[] = (string) $f['resource_id'];
                }
            }
            return array_values(array_unique($ids));
        }
        [$cursor, $limite] = $q['args'];
        preg_match("/meta_key = '([a-z_]+)'/", $q['sql'], $m);
        $ids = [];
        foreach ($GLOBALS['__wp_meta'] as $id => $metas) {
            if (isset($metas[$m[1]]) && $id > $cursor) { $ids[] = $id; }
        }
        sort($ids);
        return array_map('strval', array_slice($ids, 0, $limite));
    }
}

/** Tienda del volcado + pedidos. 8306 enlazado (900); 8308 y 8310 sin enlace. */
function pedidos_tienda(array $opciones = []): void
{
    volcado_tienda();
    $GLOBALS['wpdb'] = new WpdbPedidos();
    $GLOBALS['__wp_options'] = $opciones + ['tpv_sync_principal' => 'wc'];
    $GLOBALS['__wc_orders'] = [];
    TPV_Sync::$inst = null;
}

function pedidos_sync(ApiPedidosFalsa $api): TPV_Sync_Order_Sync
{
    $productos = new TPV_Sync_Product_Sync($api);
    $pedidos   = new TPV_Sync_Order_Sync($api, $productos);
    TPV_Sync::instance()->queue = new TPV_Sync_Queue($api, $productos, $pedidos);
    return $pedidos;
}

function pedido(int $id, array $productIds): PedidoWooFalso
{
    $items = array_map(fn ($pid) => new ItemPedidoFalso($pid, 1, 10.0, 2.1, "Producto $pid"), $productIds);
    return $GLOBALS['__wc_orders'][$id] = new PedidoWooFalso($id, $items);
}

function run_pedidos_sin_enlace_tests(WooTestRunner $t): void
{
    $t->suite('Pedidos — nunca a medias (F1)');

    $t->test('T2 línea sin enlace que el TPV conoce (external_id): se enlaza y el pedido sale entero', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa([['product_id' => 950, 'model' => 'OTRO', 'sku' => '', 'external_id' => '8308']]);
        pedido(1, [8306, 8308]);
        pedidos_sync($api)->send_to_tpv(1);
        $ids = array_column($api->pedidos[0]['products'] ?? [], 'product_id');
        $t->assertEquals([900, 950], $ids, 'antes llegaba solo el 900');
        $t->assertEquals(950, (int) get_post_meta(8308, '_tpv_product_id', true), 'y queda enlazado');
        $t->assertEquals([], $api->altas, 'ya existía: no se da de alta');
        $t->assertEquals([], $api->patches, 'solo se enlaza: no se sobrescribe el TPV');
    });

    $t->test('T12 línea cuyo producto no está en el TPV: se crea y el pedido sale entero', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        pedido(1, [8306, 8308]);
        pedidos_sync($api)->send_to_tpv(1);
        $t->assertEquals(1, count($api->altas), 'alta del producto que faltaba');
        $t->assertEquals([900, 777], array_column($api->pedidos[0]['products'] ?? [], 'product_id'));
    });

    $t->test('T13 manda el TPV: una línea que casa con el TPV SOLO se enlaza', function ($t) {
        pedidos_tienda(['tpv_sync_principal' => 'tpv']);
        $api = new ApiPedidosFalsa([['product_id' => 951, 'model' => 'MS2428308', 'sku' => 'MS2428308']]);
        pedido(1, [8308]);
        pedidos_sync($api)->send_to_tpv(1);
        $t->assertEquals([], $api->patches, 'un PATCH pisaría el catálogo que manda el TPV');
        $t->assertEquals([951], array_column($api->pedidos[0]['products'] ?? [], 'product_id'));
    });

    $t->test('T1/T15 el TPV rechaza el producto: NO se manda nada y el pedido queda retenido', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $api->altaFalla = true;
        $p = pedido(1, [8306, 8308]);
        pedidos_sync($api)->send_to_tpv(1);
        $t->assertEquals([], $api->pedidos, 'antes salía con la línea del 900 sola');
        $t->assert(get_post_meta(1, '_tpv_order_pendiente', true) !== '', 'marcado como pendiente');
        $t->assertEquals(1, count($p->notas), 'la comerciante lo ve en el pedido');
        $t->assert(str_contains($p->notas[0] ?? '', 'Producto 8308'), 'y sabe qué producto falta');
    });

    $t->test('T5 ninguna línea enlazable: retenido, no descartado en silencio', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $api->altaFalla = true;
        pedido(1, [8308, 8310]);
        pedidos_sync($api)->send_to_tpv(1);
        $t->assertEquals([], $api->pedidos);
        $t->assert(get_post_meta(1, '_tpv_order_pendiente', true) !== '', 'antes: «skip» y nunca más');
    });

    $t->test('un pedido SIN líneas de producto no se retiene: no hay nada que mandar', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $GLOBALS['__wc_orders'][1] = new PedidoWooFalso(1, []);
        pedidos_sync($api)->send_to_tpv(1);
        $t->assertEquals([], $api->pedidos);
        $t->assertEquals('', get_post_meta(1, '_tpv_order_pendiente', true),
            'retenerlo lo reintentaría cada 5 minutos para siempre');
    });

    $t->test('T4 varios intentos fallidos: UNA sola nota', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $api->altaFalla = true;
        $p = pedido(1, [8308]);
        $s = pedidos_sync($api);
        $s->send_to_tpv(1);
        $s->send_to_tpv(1);
        $t->assertEquals(1, count($p->notas), 'una nota por intento llenaría el pedido');
    });

    $t->test('T3 el reintento lo envía cuando ya se puede, y deja de estar pendiente', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $api->altaFalla = true;
        pedido(1, [8306, 8308]);
        $s = pedidos_sync($api);
        $s->send_to_tpv(1);
        $api->altaFalla = false;          // se corrigió el producto en la tienda
        $r = $s->reintentarPendientes(10);
        $t->assertEquals(1, count($api->pedidos), 'sale en la siguiente pasada');
        $t->assertEquals(2, count($api->pedidos[0]['products'] ?? []), 'entero');
        $t->assertEquals('', get_post_meta(1, '_tpv_order_pendiente', true), 'ya no está pendiente');
        $t->assertEquals(1, $r['enviados'] ?? -1);
    });

    $t->test('el reintento recorre todos los pendientes por tandas, aunque los primeros sigan atascados', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $api->altaFalla = true;
        $s = pedidos_sync($api);
        foreach ([1, 2, 3] as $id) { pedido($id, [8308]); $s->send_to_tpv($id); }
        // El 3 ya se puede mandar; el 1 y el 2 siguen atascados.
        $GLOBALS['__wc_orders'][3] = new PedidoWooFalso(3, [new ItemPedidoFalso(8306, 1, 10.0)]);
        $s->reintentarPendientes(2);
        $t->assertEquals([], $api->pedidos, 'primera tanda: el 1 y el 2');
        $s->reintentarPendientes(2);
        $t->assertEquals(1, count($api->pedidos), 'segunda tanda: llega al 3');
        $s->reintentarPendientes(2);
        $t->assertEquals(0, (int) get_option('tpv_sync_pedidos_cursor'), 'al acabar vuelve a empezar');
    });

    $t->suite('Pedidos — manda el TPV: nada vendible se queda fuera (F1.1b)');

    $t->test('T14 guardar en Woo un producto que el TPV no tiene lo crea en el TPV', function ($t) {
        pedidos_tienda(['tpv_sync_principal' => 'tpv']);
        $api = new ApiPedidosFalsa();
        (new TPV_Sync_Product_Sync($api))->push_wc_product_to_tpv(8308);
        $t->assertEquals(1, count($api->altas), 'antes era una «isla» y se ignoraba');
        $t->assertEquals(777, (int) get_post_meta(8308, '_tpv_product_id', true));
    });

    $t->test('una VARIACIÓN nunca se da de alta suelta en el TPV', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wp_posts'][8400] = (object) ['ID' => 8400, 'post_type' => 'product_variation',
            'post_title' => 'Talla M', 'post_content' => '', 'post_status' => 'publish'];
        $GLOBALS['__wc_products'][8400] = new class {
            public function get_id() { return 8400; }
            public function get_sku() { return 'VAR-M'; }
            public function is_type($t) { return $t === 'variation'; }
        };
        // En el TPV hay un producto cuyo model coincide con el SKU de la talla.
        $api = new ApiPedidosFalsa([['product_id' => 960, 'model' => 'VAR-M', 'sku' => 'VAR-M']]);
        $id = (new TPV_Sync_Product_Sync($api))->asegurarEnTpv(8400);
        $t->assertEquals(0, $id);
        $t->assertEquals('', get_post_meta(8400, '_tpv_product_id', true),
            'enlazar la talla a otro producto del TPV mezclaría ventas y stock');
        $t->assertEquals([], $api->altas, 'se vende a nombre del padre: darla de alta duplicaría el catálogo');
    });

    $t->suite('Cola — no se multiplica (F2)');

    $t->test('T6 encolar dos veces lo mismo deja UNA fila', function ($t) {
        pedidos_tienda();
        $q = new TPV_Sync_Queue(new ApiPedidosFalsa(), new TPV_Sync_Product_Sync(new ApiPedidosFalsa()),
            new TPV_Sync_Order_Sync(new ApiPedidosFalsa(), new TPV_Sync_Product_Sync(new ApiPedidosFalsa())));
        $a = $q->enqueue('order.send', ['wc_order_id' => 7], 'x');
        $b = $q->enqueue('order.send', ['wc_order_id' => 7], 'y');
        $t->assertEquals($a, $b, 'devuelve la que ya estaba');
        $t->assertEquals(1, count($GLOBALS['wpdb']->cola));
    });

    $t->test('T7 un reintento de la cola que falla no crea filas nuevas', function ($t) {
        pedidos_tienda();
        $api = new ApiPedidosFalsa();
        $api->pedidoFalla = true;
        pedido(1, [8306]);
        $s = pedidos_sync($api);
        $s->send_to_tpv(1);               // falla ⇒ 1 fila
        TPV_Sync::instance()->queue->process(20);
        TPV_Sync::instance()->queue->process(20);
        $t->assertEquals(1, count($GLOBALS['wpdb']->cola), 'antes nacía una fila por cada reintento');
        $t->assertEquals(2, (int) ($GLOBALS['wpdb']->cola[1]->attempts ?? 0), 'y cuenta los intentos: puede abandonar');
    });
}
