<?php
declare(strict_types=1);
/**
 * Categorías del TPV a la tienda (SPEC_categorias_woo_tpv.md §3, C1 y C3).
 * Se ejecuta TPV_Sync_Categorias_Sync de verdad, con los términos y la API en
 * memoria.
 */
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-categorias-sync.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';
require_once __DIR__ . '/fixtures/api_categorias_falsa.php';

function run_categorias_tpv_a_woo_tests(WooTestRunner $t): void
{
    $t->suite('Categorías TPV → Woo');

    $montar = function (string $fase = 'arbol'): array {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA]  = $fase;
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS] = [];
        $api = new ApiCategoriasFalsa();
        $m = new TerminosEnMemoria();
        return [$api, $m, new TPV_Sync_Categorias_Sync($api, $m)];
    };

    $t->test('aviso con categoría desconocida: la trae con su madre y enlaza', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $api->c = [5 => ['parent_id' => 0, 'name' => 'CALZADO', 'status' => 1, 'external_id' => null],
                   6 => ['parent_id' => 5, 'name' => 'BOTAS', 'status' => 0, 'external_id' => null]];
        $s->aplicarAviso('category.created', 6);
        $botas = $m->porTpvId(6);
        $calzado = $m->porTpvId(5);
        $t->assert($botas > 0 && $calzado > 0, 'crea las dos (la inactiva también)');
        $t->assertEquals($calzado, $m->padreDe($botas));
        $t->assertEquals([(string) $calzado, (string) $botas], [$api->c[5]['external_id'], $api->c[6]['external_id']],
            'registra el enlace en la API');
    });

    $t->test('renombrar en el TPV actualiza el término, no crea otro', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $api->c = [5 => ['parent_id' => 0, 'name' => 'Viejo', 'status' => 1, 'external_id' => null]];
        $s->aplicarAviso('category.created', 5);
        $api->c[5]['name'] = 'Nuevo';
        $s->aplicarAviso('category.updated', 5);
        $t->assertEquals([1, 'Nuevo'], [count($m->t), $m->nombreDe($m->porTpvId(5))]);
    });

    $t->test('borrado en el TPV borra el término enlazado', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $id = $m->crear('X', 0);
        $m->enlazar($id, 9);
        $s->aplicarAviso('category.deleted', 9);
        $t->assertEquals([], $m->t);
    });

    $t->test('sin marca no se toca nada', function ($t) use ($montar) {
        [$api, $m, $s] = $montar('');
        $api->c = [5 => ['parent_id' => 0, 'name' => 'X', 'status' => 1, 'external_id' => null]];
        $s->aplicarAviso('category.created', 5);
        $t->assertEquals([[], []], [$m->t, $api->llamadas]);
    });

    $t->test('hermana duplicada en Woo: no se enlaza a la de otra y se avisa', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $ya = $m->crear('Lencería', 0);
        $m->enlazar($ya, 171);
        $api->c = [170 => ['parent_id' => 0, 'name' => 'LENCERIA', 'status' => 1, 'external_id' => null]];
        $s->aplicarAviso('category.created', 170);
        $t->assertEquals([0, 171], [$m->porTpvId(170), $m->tpvIdDe($ya)]);
        $t->assertEquals(1, count((array) $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS]),
            'queda en «Categorías sin pareja»');
    });

    $t->test('producto: junta, sustituye y lista vacía; una categoría que ya no existe (404) no rompe', function ($t) use ($montar) {
        [$api, $m, $s] = $montar();
        $api->c = [5 => ['parent_id' => 0, 'name' => 'A', 'status' => 1, 'external_id' => null]];
        $web = $m->crear('Solo web', 0);
        $m->ponerAProducto(1, [$web]);
        $s->ponerCategoriasDesdeTpv(1, [5, 404404], true);
        $a = $m->porTpvId(5);
        $t->assertEquals(TPV_Sync_Categorias::juntar([$web, $a]), TPV_Sync_Categorias::juntar($m->deProducto(1)), 'junta');
        $s->ponerCategoriasDesdeTpv(1, [5], false);
        $t->assertEquals([$a], $m->deProducto(1), 'sustituye');
        $s->ponerCategoriasDesdeTpv(1, [], false);
        $t->assertEquals([], $m->deProducto(1), 'lista vacía: quita todas');
    });
}
