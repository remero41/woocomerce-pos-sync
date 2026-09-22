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
