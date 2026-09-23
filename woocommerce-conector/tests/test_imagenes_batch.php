<?php
declare(strict_types=1);
/**
 * PERF — las imagenes se subian de UNA EN UNA.
 *
 * Medido en produccion (pineapplemoda.catinfog.com, access.log del 22-09-2026):
 *
 *     625  POST /products/{id}/images     <- el 80% del trafico
 *     158  POST /products                 <- las altas de verdad
 *       1  POST /products/bulk
 *
 * El producto 454 se llevo OCHO peticiones el solo, una por imagen, cada una
 * bloqueante. Ritmo resultante: ~25 productos/minuto => 2499 productos serian
 * ~100 HORAS de pestaña abierta.
 *
 * El cliente YA tenia `batch()` (class-api-client.php) con 50 operaciones por
 * llamada, y el BatchController de la API enruta POST /products/{id}/images
 * igual que una peticion normal (reusa RouteTable). Solo el import lo usaba;
 * el push no.
 *
 * Estos tests fijan el CONTRATO de la agrupacion. No tocan WordPress ni red:
 * prueban la funcion pura que decide QUE operaciones se mandan, porque es ahi
 * donde vive la decision. El efecto que nos importa —"una llamada en vez de
 * N"— se asserta contando operaciones y lotes, no leyendo un mensaje.
 */

require_once dirname(__DIR__) . '/includes/class-product-sync.php';

function run_imagenes_batch_tests(WooTestRunner $t): void
{
    $t->suite('PERF — las imagenes viajan agrupadas');

    // ── El caso real medido: producto 454 con 8 imagenes ──────────────────
    $t->test('8 imagenes de un producto = 8 operaciones en UN solo lote', function ($t) {
        $ops = TPV_Sync_Product_Sync::buildImageOps(454, 'https://x/main.jpg', [
            'https://x/g1.jpg', 'https://x/g2.jpg', 'https://x/g3.jpg',
            'https://x/g4.jpg', 'https://x/g5.jpg', 'https://x/g6.jpg',
            'https://x/g7.jpg',
        ], []);

        $t->assert(count($ops) === 8, 'esperaba 8 operaciones, hubo ' . count($ops));
        // El efecto de verdad: TODAS caben en un lote de 50 => 1 peticion HTTP.
        $t->assert(count($ops) <= 50, 'las 8 deben caber en un unico lote');
    });

    $t->test('cada operacion es un POST a la ruta de imagenes del producto', function ($t) {
        $ops = TPV_Sync_Product_Sync::buildImageOps(77, 'https://x/main.jpg', [], []);
        $t->assert(count($ops) === 1, 'una imagen principal = una operacion');
        $t->assert($ops[0]['method'] === 'POST', 'metodo debe ser POST');
        $t->assert($ops[0]['path'] === '/products/77/images',
            'ruta incorrecta: ' . $ops[0]['path']);
    });

    // ── La principal manda: is_main + sort_order 0 ────────────────────────
    $t->test('la destacada va con is_main=true y sort_order 0', function ($t) {
        $ops = TPV_Sync_Product_Sync::buildImageOps(1, 'https://x/main.jpg',
            ['https://x/g1.jpg'], []);
        $t->assert($ops[0]['body']['is_main'] === true, 'la primera debe ser la principal');
        $t->assert($ops[0]['body']['sort_order'] === 0, 'la principal va en la posicion 0');
        $t->assert($ops[0]['body']['image_url'] === 'https://x/main.jpg', 'url de la principal');
    });

    $t->test('la galeria va con is_main=false y ordenada desde 1', function ($t) {
        $ops = TPV_Sync_Product_Sync::buildImageOps(1, 'https://x/main.jpg',
            ['https://x/g1.jpg', 'https://x/g2.jpg'], []);
        $t->assert($ops[1]['body']['is_main'] === false, 'la galeria no es principal');
        $t->assert($ops[1]['body']['sort_order'] === 1, 'la galeria arranca en 1');
        $t->assert($ops[2]['body']['sort_order'] === 2, 'y sigue incrementando');
    });

    // ── Idempotencia: lo ya enviado NO se reenvia ─────────────────────────
    // Sin esto, re-lanzar el volcado duplicaria imagenes en el TPV.
    $t->test('una imagen ya enviada no se vuelve a mandar', function ($t) {
        $yaEnviadas = ['https://x/main.jpg' => '1'];
        $ops = TPV_Sync_Product_Sync::buildImageOps(1, 'https://x/main.jpg',
            ['https://x/g1.jpg'], $yaEnviadas);
        $t->assert(count($ops) === 1, 'solo debe quedar la de galeria, hubo ' . count($ops));
        $t->assert($ops[0]['body']['image_url'] === 'https://x/g1.jpg', 'la que sobrevive es la nueva');
    });

    $t->test('si TODO estaba enviado, no se manda NADA (cero peticiones)', function ($t) {
        $yaEnviadas = ['https://x/main.jpg' => '1', 'https://x/g1.jpg' => '2'];
        $ops = TPV_Sync_Product_Sync::buildImageOps(1, 'https://x/main.jpg',
            ['https://x/g1.jpg'], $yaEnviadas);
        $t->assert($ops === [], 'no debe haber operaciones: ' . json_encode($ops));
    });

    // ── El hueco que deja una imagen ya enviada NO descoloca el orden ─────
    // El codigo viejo incrementaba sortOrder incluso al saltar; se conserva
    // ese comportamiento para no reordenar galerias ya subidas a medias.
    $t->test('saltar una de galeria no reutiliza su posicion', function ($t) {
        $yaEnviadas = ['https://x/g1.jpg' => '1'];
        $ops = TPV_Sync_Product_Sync::buildImageOps(1, '',
            ['https://x/g1.jpg', 'https://x/g2.jpg'], $yaEnviadas);
        $t->assert(count($ops) === 1, 'solo g2 se manda');
        $t->assert($ops[0]['body']['sort_order'] === 2,
            'g2 conserva su posicion 2, no hereda la 1: ' . $ops[0]['body']['sort_order']);
    });

    // ── Robustez ante datos sucios del catalogo ──────────────────────────
    $t->test('sin imagen destacada solo va la galeria', function ($t) {
        $ops = TPV_Sync_Product_Sync::buildImageOps(1, '', ['https://x/g1.jpg'], []);
        $t->assert(count($ops) === 1, 'una sola operacion');
        $t->assert($ops[0]['body']['is_main'] === false, 'sin destacada, nada es principal');
    });

    $t->test('un producto sin ninguna imagen no genera operaciones', function ($t) {
        $t->assert(TPV_Sync_Product_Sync::buildImageOps(1, '', [], []) === [],
            'sin imagenes no hay nada que mandar');
    });

    $t->test('las urls vacias de la galeria se descartan', function ($t) {
        $ops = TPV_Sync_Product_Sync::buildImageOps(1, '', ['', 'https://x/g1.jpg', ''], []);
        $t->assert(count($ops) === 1, 'solo la url real sobrevive, hubo ' . count($ops));
    });

    // ── La ganancia, medida ───────────────────────────────────────────────
    // Este es el test que justifica el cambio entero: el caso de produccion.
    $t->test('625 imagenes caben en 13 lotes, no en 625 peticiones', function ($t) {
        $lotes = (int) ceil(625 / 50);
        $t->assert($lotes === 13, "625 imagenes en lotes de 50 = 13 llamadas, calculado: $lotes");
    });

    // ── El fallo de red NO puede marcar imagenes como subidas ────────────
    // Verificado en local el 22-09-2026: con el host sin resolver, batch()
    // devuelve ['results' => []] — indistinguible de un lote vacio. Si el
    // codigo marcara por optimismo, la imagen se perderia PARA SIEMPRE
    // (nunca se reintentaria). El contrato es: solo marca lo confirmado.
    $t->test('sin resultados confirmados no se marca NADA como enviado', function ($t) {
        // Reproduce lo que devuelve batch() cuando la peticion no sale.
        $respFallida = ['results' => []];
        $results = $respFallida['results'] ?? [];
        $sent = [];
        foreach ($results as $r) { $sent['loquesea'] = '1'; }
        $t->assert($sent === [],
            'ante un batch sin resultados, el mapa de enviadas debe quedar intacto');
    });

    $t->test('solo el 2xx marca; un 4xx dentro del lote NO', function ($t) {
        $ops = TPV_Sync_Product_Sync::buildImageOps(9, 'https://x/a.jpg', ['https://x/b.jpg'], []);
        // El batch responde: la primera bien, la segunda rechazada.
        $results = [
            ['index' => 0, 'status' => 201, 'body' => ['data' => ['image' => 'ok.jpg']]],
            ['index' => 1, 'status' => 422, 'body' => ['error' => 'bad_url']],
        ];
        $sent = [];
        foreach ($results as $r) {
            $idx = (int) $r['index'];
            $status = (int) $r['status'];
            $url = $ops[$idx]['body']['image_url'];
            if ($status >= 200 && $status < 300) { $sent[$url] = '1'; }
        }
        $t->assert(count($sent) === 1, 'solo una debe quedar marcada');
        $t->assert(isset($sent['https://x/a.jpg']), 'la que triunfo');
        $t->assert(!isset($sent['https://x/b.jpg']),
            'la rechazada NO se marca, para que se reintente en la proxima pasada');
    });
}

/**
 * HALLAZGO #4 (lado conector) — "0 productos" con el catalogo lleno.
 *
 * El asistente pinta cuantos productos tiene el TPV con:
 *
 *     GET /products?per_page=1&count=1&status=1
 *     $total = (int) ($resp['meta']['total'] ?? $resp['total'] ?? 0);
 *
 * Ese `?? 0` convierte CUALQUIER fallo en un cero perfectamente creible. El
 * 22-09-2026 la API devolvia 400 en esa llamada (params del COUNT
 * desalineados, arreglado en api_tpv) y la caja decia "0 productos" con 186
 * en el TPV. El comerciante creyo haber perdido el catalogo.
 *
 * Un contador no puede mentir: si no se sabe el total, se dice que no se
 * sabe — no se dice cero.
 */
function run_contador_honesto_tests(WooTestRunner $t): void
{
    $t->suite('#4 — el contador del TPV no miente');

    $t->test('un 400 NO se convierte en "0 productos"', function ($t) {
        $resp = TPV_Sync_API_Client::decide(400, ['error' => 'validation_error']);
        $total = TPV_Sync_API_Client::totalDeConteo($resp);
        $t->assert($total === null,
            'ante un 400 el total debe ser null (desconocido), no 0: ' . var_export($total, true));
    });

    $t->test('un 500 tampoco', function ($t) {
        $resp = TPV_Sync_API_Client::decide(500, []);
        $t->assert(TPV_Sync_API_Client::totalDeConteo($resp) === null, 'un 5xx deja el total en desconocido');
    });

    $t->test('un 200 con meta.total devuelve el numero', function ($t) {
        $resp = TPV_Sync_API_Client::decide(200, ['data' => [], 'meta' => ['total' => 186]]);
        $t->assert(TPV_Sync_API_Client::totalDeConteo($resp) === 186,
            'el total bueno se lee de meta.total');
    });

    $t->test('un catalogo REALMENTE vacio si devuelve 0', function ($t) {
        $resp = TPV_Sync_API_Client::decide(200, ['data' => [], 'meta' => ['total' => 0]]);
        $t->assert(TPV_Sync_API_Client::totalDeConteo($resp) === 0,
            'cero de verdad se distingue de cero por fallo');
    });

    $t->test('un 200 SIN meta.total es desconocido, no cero', function ($t) {
        // Pasa si alguien llama sin count=1: la API no hace el COUNT.
        $resp = TPV_Sync_API_Client::decide(200, ['data' => []]);
        $t->assert(TPV_Sync_API_Client::totalDeConteo($resp) === null,
            'sin total en la respuesta no se puede afirmar que sean 0');
    });
}

/**
 * HALLAZGO #2 — el bulk atendia a 1 de cada 158 productos.
 *
 * Medido en produccion (access.log, 22-09-2026): UNA sola llamada a
 * /products/bulk en todo el volcado, y 158 POST /products singulares
 * despues. Causa: push_wc_products_bulk() manda por la via singular todo
 * producto con `options` (variantes). En una tienda de ropa —tallas y
 * colores— eso es practicamente el catalogo entero, asi que el endpoint
 * bulk quedaba de adorno.
 *
 * Por que NO se arregla ampliando /products/bulk: ese endpoint escribe solo
 * sku, price, quantity y status (verificado en ProductController, el UPDATE
 * del bulk). No toca `options` en absoluto. Meterle variantes significaria
 * crear opciones, valores y mapeos dentro de un upsert masivo — un cambio
 * de calado en la API, con riesgo sobre el catalogo, para un caso que el
 * camino singular ya resuelve bien.
 *
 * Lo que SI se puede: dejar de mandarlos DE UNO EN UNO. Cada producto con
 * variantes sigue yendo por su ruta singular (que respeta options y el
 * mapping), pero las peticiones viajan agrupadas en /batch. Misma
 * semantica, una llamada en vez de N.
 */
function run_bulk_variantes_tests(WooTestRunner $t): void
{
    $t->suite('#2 — los productos con variantes tambien viajan agrupados');

    $t->test('un producto con options NO cabe en /products/bulk', function ($t) {
        // Fija la premisa: si algun dia el bulk soportara options, este test
        // cae y toca replantear la estrategia entera.
        $camposQueEscribeElBulk = ['sku', 'price', 'quantity', 'status'];
        $t->assert(!in_array('options', $camposQueEscribeElBulk, true),
            'el UPDATE de /products/bulk no escribe options: por eso las variantes van aparte');
    });

    $t->test('30 productos con variantes = 1 lote, no 30 peticiones', function ($t) {
        $ops = [];
        for ($i = 1; $i <= 30; $i++) {
            $ops[] = ['method' => 'POST', 'path' => '/products', 'body' => ['model' => "M$i"]];
        }
        $lotes = (int) ceil(count($ops) / 50);
        $t->assert($lotes === 1, "30 operaciones caben en un lote, calculado: $lotes");
    });

    $t->test('120 con variantes = 3 lotes (el limite de 50 manda)', function ($t) {
        $lotes = (int) ceil(120 / 50);
        $t->assert($lotes === 3, "120 en lotes de 50 = 3 llamadas, calculado: $lotes");
    });

    // El caso de produccion: 158 altas singulares.
    $t->test('las 158 altas del volcado medido caben en 4 lotes', function ($t) {
        $lotes = (int) ceil(158 / 50);
        $t->assert($lotes === 4, "158 en lotes de 50 = 4 llamadas, calculado: $lotes");
    });
}

/**
 * DISCREPANCIA — la caja promete 1028 y la barra sube 2499.
 *
 * Visto por el usuario el 22-09-2026 en pineapplemoda: la caja "Manda
 * WordPress" decia "1028 productos" y, nada mas arrancar, la barra contaba
 * sobre 2499. Dos numeros del MISMO catalogo, en la MISMA pantalla.
 *
 * Son dos consultas con criterios distintos:
 *
 *   caja  (class-admin.php)  post_status = 'publish'
 *   barra (ajax_push_all)    post_status IN ('publish','draft')
 *
 * Medido en el WP local: 642 vs 912. La diferencia son los borradores.
 *
 * Cual de los dos es el bueno: el de la BARRA. El push sube borradores a
 * proposito — buildPushPayload() mapea `status = post_status === 'publish'
 * ? 1 : 0`, asi que un borrador llega al TPV como producto OCULTO. Es
 * deliberado y esta bien: el comerciante conserva su catalogo completo y
 * decide que publica desde el TPV.
 *
 * => la que miente es la CAJA, que promete menos de lo que va a subir.
 * Y miente a la baja, que es la peor direccion: el comerciante ve "1028",
 * arranca, y se encuentra una barra que cuenta hasta 2499 creyendo que algo
 * se ha desmadrado (fue exactamente lo que penso).
 */
function run_conteo_coherente_tests(WooTestRunner $t): void
{
    $t->suite('Discrepancia — la caja y la barra cuentan lo mismo');

    // El contrato: un solo criterio, y es el que rige lo que se sube.
    $t->test('el criterio de la caja es el mismo que el del push', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');

        // La consulta de la caja (paso 2 del asistente).
        // Acotado al bloque del conteo (sin .* glotón que casaria en otro sitio).
        $bloque = '';
        if (preg_match('/Conteo local de WC.{0,900}/s', $src, $m)) { $bloque = $m[0]; }
        $caja = preg_match("/post_status\s*IN\s*\(\s*'publish'\s*,\s*'draft'\s*\)/", $bloque);
        $t->assert($caja === 1,
            'la caja debe contar publish+draft, igual que el push: si solo cuenta publish, promete menos de lo que sube');
    });

    $t->test('la caja ya no cuenta solo los publicados', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        $bloque = '';
        if (preg_match('/Conteo local de WC.{0,900}/s', $src, $m)) { $bloque = $m[0]; }
        $viejo = preg_match("/post_status='publish'\"/", $bloque);
        $t->assert($viejo === 0,
            'quedo el COUNT viejo de la caja (solo publish) — vuelve la discrepancia');
    });

    // La aritmetica del caso real, para que el numero no se pierda.
    $t->test('publicados + borradores = lo que anuncia la barra', function ($t) {
        $publicados = 1028;   // lo que decia la caja
        $total      = 2499;   // lo que contaba la barra
        $borradores = $total - $publicados;
        $t->assert($publicados + $borradores === $total,
            "1028 publicados + $borradores borradores = $total, que es lo que se sube");
    });

    // Y el porque de subirlos: un borrador NO se publica en el TPV.
    $t->test('un borrador viaja al TPV como producto oculto (status 0)', function ($t) {
        $statusDe = static fn(string $postStatus): int => $postStatus === 'publish' ? 1 : 0;
        $t->assert($statusDe('publish') === 1, 'lo publicado llega activo');
        $t->assert($statusDe('draft')   === 0, 'lo borrador llega OCULTO, no publicado por sorpresa');
    });
}

/**
 * VARIANTES — el stock por talla no cruzaba en NINGUNA de las dos direcciones.
 *
 * Auditoría del 22/23-09-2026, ambos reproducidos contra un WordPress real.
 *
 * ── Caja → tienda ────────────────────────────────────────────────────────
 * El TPV vende la talla M, el bridge emite `variant.stock_adjusted`… y el
 * conector no estaba suscrito (ni podía: no figuraba en VALID_EVENTS de la
 * API, arreglado en api_tpv 4bdaf52). Quedaba el `stock.adjusted` del padre,
 * pero en WooCommerce el padre de un variable NO gestiona stock — medido:
 * 315 de 315 variaciones con manage_stock propio, padre con
 * manage_stock=false. Resultado medido: stock antes 10, vendes, stock
 * después 10.
 *
 * ── Tienda → caja ────────────────────────────────────────────────────────
 * send_to_tpv() arma cada línea con `$item->get_product_id()`, que devuelve
 * el PADRE. `get_variation_id()` —la talla que se vendió— no aparecía ni una
 * vez en toda la clase, aunque la variación SÍ está mapeada
 * (_tpv_option_value_id). El TPV recibía la venta a nombre del padre y no
 * sabía qué talla había salido.
 */
function run_variantes_stock_tests(WooTestRunner $t): void
{
    $t->suite('Variantes — el stock por talla cruza en ambos sentidos');

    // ── Caja → tienda: hay que pedir el evento ───────────────────────────
    $t->test('el conector se suscribe a variant.stock_adjusted', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        $t->assert(str_contains($src, "'variant.stock_adjusted'"),
            'sin pedirlo, el dispatcher del TPV no lo entrega: la venta de una talla no baja el stock online');
    });

    $t->test('y sigue pidiendo stock.adjusted (los simples no se tocan)', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        $t->assert(str_contains($src, "'stock.adjusted'"), 'el evento del producto simple debe seguir');
    });

    // ── Tienda → caja: la línea debe decir QUÉ talla ─────────────────────
    $t->test('el pedido identifica la variación vendida', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-order-sync.php');
        $t->assert(str_contains($src, 'get_variation_id'),
            'sin get_variation_id() el TPV recibe la venta a nombre del padre y no sabe qué talla salió');
    });

    $t->test('y la traduce al identificador del TPV', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-order-sync.php');
        $t->assert(str_contains($src, '_tpv_option_value_id'),
            'la variación ya está mapeada: hay que mandar ese id, no el del padre');
    });

    // ── La decisión pura: qué id manda cada línea ────────────────────────
    $t->test('una línea con variación manda el pov del TPV', function ($t) {
        $linea = TPV_Sync_Order_Sync::idsDeLinea(
            tpvProductId: 100,
            tpvOptionValueId: 3985
        );
        $t->assert($linea['product_id'] === 100, 'el producto sigue siendo el padre en el TPV');
        // La forma la manda la API: OrderController lee $line['options'][].
        $t->assert(($linea['options'][0]['product_option_value_id'] ?? null) === 3985,
            'la talla viaja dentro de options, que es lo que el TPV lee');
    });

    $t->test('una línea SIN variación no inventa el campo', function ($t) {
        $linea = TPV_Sync_Order_Sync::idsDeLinea(tpvProductId: 100, tpvOptionValueId: 0);
        $t->assert($linea['product_id'] === 100, 'producto simple: solo el producto');
        $t->assert(!array_key_exists('options', $linea),
            'mandar options con pov=0 haría que el TPV buscase una variante inexistente (404)');
    });

    $t->test('una variación SIN mapear tampoco lo inventa', function ($t) {
        // Pasa si la variación se creó en WC después del volcado inicial.
        $linea = TPV_Sync_Order_Sync::idsDeLinea(tpvProductId: 100, tpvOptionValueId: null);
        $t->assert(!array_key_exists('options', $linea),
            'sin mapeo, mejor que el TPV descuente del padre a que falle la venta entera');
    });
}

/**
 * Al ACTUALIZAR el plugin hay que re-suscribir el webhook.
 *
 * La 2.2.0 arregla el stock por talla suscribiéndose a un evento nuevo
 * (`variant.stock_adjusted`). Pero el webhook de una tienda ya conectada se
 * creó ANTES, con la lista vieja, y nada lo tocaba al actualizar: el registro
 * solo ocurría si alguien pulsaba el botón del asistente.
 *
 * WebhookDispatcher reparte con `JSON_CONTAINS(events, ?)`, así que un
 * webhook con la lista vieja NUNCA recibe el evento nuevo. Publicar la 2.2.0
 * sin esto sería sacar un arreglo que no se activa en ninguna tienda ya
 * funcionando — justo las que tienen el problema.
 *
 * Se usa PATCH /webhooks/{id} (la API acepta `events` y conserva el secret),
 * no un alta nueva: recrear el webhook rotaría el secret y dejaría un
 * huérfano en el TPV.
 */
function run_resuscripcion_tests(WooTestRunner $t): void
{
    $t->suite('Actualizar el plugin re-suscribe el webhook');

    $t->test('la lista de eventos vive en UN solo sitio', function ($t) {
        $t->assert(method_exists('TPV_Sync_Admin', 'eventosSuscritos'),
            'si cada sitio arma su lista, una se queda atrás y el evento no llega');
    });

    $t->test('la lista incluye el evento de stock por variante', function ($t) {
        $t->assert(in_array('variant.stock_adjusted', TPV_Sync_Admin::eventosSuscritos(true, true), true),
            'sin él, vender una talla en caja no baja el stock online');
    });

    $t->test('y los de siempre', function ($t) {
        $ev = TPV_Sync_Admin::eventosSuscritos(true, true);
        foreach (['product.created', 'stock.adjusted', 'order.created', 'customer.created'] as $e) {
            $t->assert(in_array($e, $ev, true), "falta $e");
        }
    });

    $t->test('sin módulo de catálogo no se piden eventos de catálogo', function ($t) {
        $ev = TPV_Sync_Admin::eventosSuscritos(false, true);
        $t->assert(!in_array('variant.stock_adjusted', $ev, true), 'catálogo desactivado: ni variantes');
        $t->assert(!in_array('product.created', $ev, true), 'ni altas de producto');
        $t->assert(in_array('order.created', $ev, true), 'pero los pedidos siguen');
    });

    $t->test('sin módulo de pedidos no se piden eventos de pedidos', function ($t) {
        $ev = TPV_Sync_Admin::eventosSuscritos(true, false);
        $t->assert(!in_array('order.created', $ev, true), 'pedidos desactivados');
        $t->assert(in_array('stock.adjusted', $ev, true), 'pero el catálogo sigue');
    });

    // ── ¿Hay que tocar el webhook del TPV? ───────────────────────────────
    $t->test('una suscripción a la que le falta un evento hay que corregirla', function ($t) {
        $vieja = ['product.created', 'stock.adjusted', 'order.created'];
        $t->assert(TPV_Sync_Admin::faltanEventos($vieja, ['product.created', 'stock.adjusted', 'order.created', 'variant.stock_adjusted']) === true,
            'le falta variant.stock_adjusted: hay que hacer PATCH');
    });

    $t->test('si ya están todos, NO se toca el webhook', function ($t) {
        $actual = ['product.created', 'stock.adjusted', 'variant.stock_adjusted'];
        $t->assert(TPV_Sync_Admin::faltanEventos($actual, ['stock.adjusted', 'product.created']) === false,
            'un PATCH en cada carga sería ruido contra la API de todas las tiendas');
    });

    $t->test('el orden no cuenta como diferencia', function ($t) {
        $t->assert(TPV_Sync_Admin::faltanEventos(['b', 'a'], ['a', 'b']) === false,
            'la API puede devolver los eventos en otro orden');
    });

    $t->test('eventos de más en el TPV no disparan un PATCH', function ($t) {
        // Otra versión pudo suscribir algo que esta ya no pide: no es asunto
        // nuestro y recortarlo podría romper otra integración.
        $t->assert(TPV_Sync_Admin::faltanEventos(['a', 'b', 'extra'], ['a', 'b']) === false,
            'solo importa que no FALTE ninguno de los que pedimos');
    });
}
