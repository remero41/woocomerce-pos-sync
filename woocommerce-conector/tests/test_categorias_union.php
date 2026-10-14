<?php
declare(strict_types=1);
/**
 * Unión inicial por fases (SPEC_categorias_woo_tpv.md §4): árbol → marca
 * 'arbol' → productos enlazados por tandas → marca 'completa'. Solo añade:
 * no mueve ni borra. Sin la capacidad o sin el ámbito, no empieza y lo dice.
 */
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-categorias-sync.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';
require_once __DIR__ . '/fixtures/api_categorias_falsa.php';

function run_categorias_union_tests(WooTestRunner $t): void
{
    $t->suite('Categorías — unión inicial por fases');

    $montar = function (array $enlazados = []): array {
        foreach ([TPV_Sync_Categorias_Sync::MARCA, TPV_Sync_Categorias_Sync::AVISOS, TPV_Sync_Categorias_Sync::CURSOR] as $k) {
            unset($GLOBALS['__wp_options'][$k]);
        }
        $api = new ApiCategoriasFalsa();
        $m = new TerminosEnMemoria();
        $s = new TPV_Sync_Categorias_Sync($api, $m, fn (int $desde, int $lote): array =>
            array_slice(array_values(array_filter($enlazados, fn ($e) => $e['post_id'] > $desde)), 0, $lote));
        return [$api, $m, $s];
    };

    $t->test('árbol: empareja por nombre, crea lo que falta en cada lado, no mueve nada, marca arbol', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $api->c = [1 => ['parent_id' => 0, 'name' => 'Ropa', 'status' => 1, 'external_id' => null],
                   2 => ['parent_id' => 1, 'name' => 'Pijamas', 'status' => 1, 'external_id' => null]];
        $w = $m->crear('ROPA', 0);
        $u = $m->crear('Uñas', 0);
        $f = $m->crear('Fresas', $u);
        $t->assertEquals(true, $s->unirArbol());
        $t->assertEquals('arbol', TPV_Sync_Categorias_Sync::fase());
        $t->assertEquals([1, (string) $w], [$m->tpvIdDe($w), $api->c[1]['external_id']]);
        $pij = $m->porTpvId(2);
        $t->assertEquals($w, $m->padreDe($pij), 'Pijamas se crea en Woo dentro de su madre emparejada');
        $t->assertEquals($m->tpvIdDe($u), $api->c[$m->tpvIdDe($f)]['parent_id'] ?? null, 'Fresas se crea en el TPV dentro de Uñas');
        $t->assertEquals([0, 'ROPA'], [$m->padreDe($w), $m->nombreDe($w)], 'nada se mueve ni se renombra');
    });

    $t->test('productos: junta en los dos lados por tandas y acaba en completa', function ($t) use ($montar) {
        [$api, $m, $s] = $montar([['post_id' => 10, 'tpv_id' => 500], ['post_id' => 11, 'tpv_id' => 501]]);
        $api->c = [1 => ['parent_id' => 0, 'name' => 'Ropa', 'status' => 1, 'external_id' => null]];
        $api->productos = [500 => [1], 501 => []];
        $w = $m->crear('Uñas', 0);
        $m->ponerAProducto(10, [$w]);
        $m->ponerAProducto(11, [$w]);
        $s->unirArbol();
        $t->assertEquals(false, $s->unirProductos(1), 'primera tanda: queda otra');
        $t->assertEquals(false, $s->unirProductos(1), 'segunda tanda llena: aún no sabe que es la última');
        $t->assertEquals(true, $s->unirProductos(1), 'tanda vacía: fin');
        $t->assertEquals('completa', TPV_Sync_Categorias_Sync::fase());
        $t->assertEquals(TPV_Sync_Categorias::juntar([$w, $m->porTpvId(1)]), TPV_Sync_Categorias::juntar($m->deProducto(10)),
            'Woo: las suyas más las del TPV');
        $t->assertEquals(TPV_Sync_Categorias::juntar([1, $m->tpvIdDe($w)]), $api->productos[500], 'TPV: las suyas más las de Woo');
        $t->assertEquals([$m->tpvIdDe($w)], $api->productos[501]);
    });

    $t->test('sin capacidad o con 403: no empieza, lo dice y no marca', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $api->caps = [];
        $s->pasoDeUnion();
        $t->assertEquals(['', []], [TPV_Sync_Categorias_Sync::fase(), $api->llamadas], 'sin capacidad ni siquiera pregunta');
        $api->caps = ['categories_write'];
        $api->statusForzado = 403;
        $s->pasoDeUnion();
        $t->assertEquals('', TPV_Sync_Categorias_Sync::fase());
        $avisos = implode("\n", (array) ($GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS] ?? []));
        $t->assert(str_contains($avisos, 'categories:write'), 'el panel dice qué permiso falta');
    });

    $t->test('pasoDeUnion completo en una tienda pequeña: árbol y productos en una vuelta', function ($t) use ($montar) {
        [$api, $m, $s] = $montar([['post_id' => 10, 'tpv_id' => 500]]);
        $api->productos = [500 => []];
        $s->pasoDeUnion();
        $t->assertEquals('completa', TPV_Sync_Categorias_Sync::fase());
        $n = count($api->llamadas);
        $s->pasoDeUnion();
        $t->assertEquals($n, count($api->llamadas), 'completa: no vuelve a hacer nada');
    });

    $t->test('olvidar: borra termmeta, marca y cursor', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $a = $m->crear('A', 0);
        $m->enlazar($a, 3);
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'completa';
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::CURSOR] = 99;
        $s->olvidar();
        $t->assertEquals([0, '', 0], [$m->tpvIdDe($a), TPV_Sync_Categorias_Sync::fase(),
            (int) ($GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::CURSOR] ?? 0)]);
    });

    $t->test('reconexión: la unión reconstruye el enlace desde external_id antes que por nombre', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $a = $m->crear('Renombrada en Woo', 0);
        $api->c = [8 => ['parent_id' => 0, 'name' => 'Nombre viejo', 'status' => 1, 'external_id' => (string) $a]];
        $s->unirArbol();
        $t->assertEquals([8, 1, 1], [$m->tpvIdDe($a), count($m->t), count($api->c)]);
    });
}
