<?php
declare(strict_types=1);
/**
 * IMAGENES QUE NUNCA SUBEN Y QUE NADIE REINTENTA.
 *
 * Sintoma reportado el 25-09-2026 en pineapplemoda: "no se pasaron todas las
 * imagenes".
 *
 * El conector agrupa las subidas en POST /batch. Como la API limita a 50
 * operaciones por llamada, class-api-client.php::batch() parte la lista en
 * trozos de 50 y CONCATENA los resultados:
 *
 *     foreach (array_chunk($operations, 50) as $chunk) {
 *         $resp = $this->post('/batch', ['operations' => $chunk]);
 *         $results = array_merge($results, $items);      // <- aqui
 *     }
 *
 * Y cada respuesta numera sus resultados DESDE 0 (verificado en la API:
 * BatchController llama a $bulk->add($i, ...) con el indice dentro de SU
 * peticion, y BulkResult::add lo emite como 'index').
 *
 * El consumidor, en class-product-sync.php::push_images_to_tpv, hace:
 *
 *     $idx = (int) ($r['index'] ?? -1);
 *     $url = (string) $ops[$idx]['body']['image_url'];   // <- $ops GLOBAL
 *
 * Con mas de 50 imagenes, el resultado 0 del SEGUNDO lote (que corresponde a
 * la imagen 51) se casa con $ops[0], que es la imagen 1. Consecuencias:
 *
 *   - se marca como subida una imagen que no se subio;
 *   - como queda en el meta `_tpv_images_sent`, NO SE REINTENTA NUNCA;
 *   - y el aviso de fallo, si lo hubo, sale con la URL equivocada.
 *
 * El arreglo es que batch() devuelva indices GLOBALES, coherentes con la lista
 * que recibio. Quien llama le paso UNA lista y tiene derecho a que los indices
 * se refieran a esa lista, no a un trozo interno que ni sabe que existe.
 */

require_once dirname(__DIR__) . '/includes/class-api-client.php';

/**
 * Cliente de prueba: sustituye la llamada de red por respuestas preparadas,
 * numerando cada una desde 0 como hace la API de verdad.
 */
final class ClienteBatchFalso extends TPV_Sync_API_Client
{
    public array $lotesRecibidos = [];
    /** @var array<int,array<int,int>> status por lote, en orden */
    private array $statusPorLote;

    public function __construct(array $statusPorLote)
    {
        $this->statusPorLote = $statusPorLote;
    }

    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $ops = $body['operations'] ?? [];
        $this->lotesRecibidos[] = count($ops);

        $n       = count($this->lotesRecibidos) - 1;
        $statuses = $this->statusPorLote[$n] ?? array_fill(0, count($ops), 200);

        $results = [];
        foreach ($ops as $i => $op) {
            // La API numera desde 0 en CADA peticion. Ahi esta el problema.
            $results[] = [
                'index'  => $i,
                'status' => $statuses[$i] ?? 200,
                'body'   => ['data' => ['image' => 'img-' . $i . '.jpg']],
            ];
        }
        return ['data' => ['results' => $results]];
    }
}

function run_imagenes_indices_tests(WooTestRunner $t): void
{
    $t->suite('Imagenes: con mas de 50, los resultados se casan con su imagen');

    // 120 imagenes = 3 lotes (50 + 50 + 20).
    $ops = [];
    for ($i = 0; $i < 120; $i++) {
        $ops[] = [
            'method' => 'POST',
            'path'   => '/products/454/images',
            'body'   => ['image_url' => "https://x/img$i.jpg", 'is_main' => $i === 0,
                         'sort_order' => $i],
        ];
    }

    $t->test('120 imagenes salen en 3 lotes', function ($t) use ($ops) {
        $c = new ClienteBatchFalso([]);
        $c->batch($ops);
        $t->assertEquals([50, 50, 20], $c->lotesRecibidos, 'se trocea de 50 en 50');
    });

    $t->test('los indices no se repiten entre lotes', function ($t) use ($ops) {
        $c = new ClienteBatchFalso([]);
        $r = $c->batch($ops);

        $indices = array_map(fn($x) => $x['index'], $r['results']);
        $t->assertEquals(120, count($indices), 'vuelven los 120 resultados');
        $t->assertEquals(120, count(array_unique($indices)),
            'si los indices se repiten, unos resultados pisan a otros');
    });

    $t->test('el indice apunta a la imagen que de verdad se mando', function ($t) use ($ops) {
        $c = new ClienteBatchFalso([]);
        $r = $c->batch($ops);

        // El caso del bug: el primer resultado del SEGUNDO lote es la imagen
        // 51 (indice 50), no la primera.
        $delSegundoLote = $r['results'][50];
        $t->assertEquals(50, $delSegundoLote['index'],
            'el primer resultado del segundo lote es la imagen 51, no la 1');

        // Y el consumidor casa por indice contra la lista global:
        $url = $ops[$delSegundoLote['index']]['body']['image_url'];
        $t->assertEquals('https://x/img50.jpg', $url,
            'con indices por lote, aqui se marcaba img0 como subida sin haberla subido');
    });

    $t->test('el ultimo indice es el de la ultima imagen', function ($t) use ($ops) {
        $c = new ClienteBatchFalso([]);
        $r = $c->batch($ops);
        $t->assertEquals(119, $r['results'][119]['index'], 'el ultimo es 119, no 19');
    });

    // ── Lo que de verdad duele: marcar como subido lo que fallo ──────────
    $t->suite('Imagenes: lo que falla se reintenta, no se da por subido');

    $t->test('un fallo en el segundo lote no marca una imagen del primero', function ($t) use ($ops) {
        // La imagen 51 (indice 50, primera del segundo lote) falla con 500.
        $falloEn51 = array_fill(0, 50, 200);
        $falloEn51[0] = 500;
        $c = new ClienteBatchFalso([array_fill(0, 50, 200), $falloEn51]);
        $r = $c->batch($ops);

        $fallidos = array_values(array_filter($r['results'], fn($x) => $x['status'] !== 200));
        $t->assertEquals(1, count($fallidos), 'ha fallado una');
        $t->assertEquals(50, $fallidos[0]['index'],
            'el fallo es de la imagen 51; con indices por lote se le colgaba a la 1');

        $url = $ops[$fallidos[0]['index']]['body']['image_url'];
        $t->assertEquals('https://x/img50.jpg', $url,
            'la que hay que reintentar es la 51, no la 1');
    });

    $t->test('menos de 50 imagenes siguen funcionando igual', function ($t) {
        // Sin regresion: con un solo lote los indices ya eran correctos.
        $pocas = [];
        for ($i = 0; $i < 8; $i++) {
            $pocas[] = ['method' => 'POST', 'path' => '/products/454/images',
                        'body' => ['image_url' => "https://x/g$i.jpg"]];
        }
        $c = new ClienteBatchFalso([]);
        $r = $c->batch($pocas);

        $t->assertEquals([8], $c->lotesRecibidos, 'un solo lote');
        $t->assertEquals(8, count($r['results']));
        $t->assertEquals(0, $r['results'][0]['index']);
        $t->assertEquals(7, $r['results'][7]['index']);
    });

    $t->test('una lista vacia no rompe', function ($t) {
        $c = new ClienteBatchFalso([]);
        $r = $c->batch([]);
        $t->assertEquals(0, count($r['results']));
    });

    $t->test('justo 50 imagenes (el limite) van en un solo lote', function ($t) {
        $justas = [];
        for ($i = 0; $i < 50; $i++) {
            $justas[] = ['method' => 'POST', 'path' => '/p/1/images',
                         'body' => ['image_url' => "https://x/$i.jpg"]];
        }
        $c = new ClienteBatchFalso([]);
        $r = $c->batch($justas);
        $t->assertEquals([50], $c->lotesRecibidos, '50 caben justas en un lote');
        $t->assertEquals(49, $r['results'][49]['index']);
    });

    $t->test('51 imagenes ya son dos lotes y el indice 50 se conserva', function ($t) {
        $lista = [];
        for ($i = 0; $i < 51; $i++) {
            $lista[] = ['method' => 'POST', 'path' => '/p/1/images',
                        'body' => ['image_url' => "https://x/$i.jpg"]];
        }
        $c = new ClienteBatchFalso([]);
        $r = $c->batch($lista);
        $t->assertEquals([50, 1], $c->lotesRecibidos);
        $t->assertEquals(50, $r['results'][50]['index'],
            'la imagen 51 es el indice 50, no el 0');
    });
}
