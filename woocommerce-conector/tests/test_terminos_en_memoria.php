<?php
declare(strict_types=1);
/**
 * El doble en memoria de TPV_Sync_Terminos se porta como WordPress en lo que
 * importa a la sincronización: rechaza una hermana con el mismo nombre plegado
 * (term_exists), y al borrar un término sube sus hijas y lo quita de los
 * productos. Si el doble mintiera, los tests de W4-W6 probarían otra cosa.
 */
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';

function run_terminos_en_memoria_tests(WooTestRunner $t): void
{
    $t->suite('Términos en memoria: se portan como WordPress');

    $t->test('rechaza una hermana con el mismo nombre plegado; en otra madre, no', function ($t) {
        $m = new TerminosEnMemoria();
        $a = $m->crear('Lencería', 0);
        $t->assertEquals(0, $m->crear('LENCERIA', 0));
        $t->assertEquals('term_exists', $m->ultimoError);
        $t->assert($m->crear('LENCERIA', $a) > 0, 'bajo otra madre sí');
    });

    $t->test('enlazar, buscar por tpv_id y olvidar', function ($t) {
        $m = new TerminosEnMemoria();
        $a = $m->crear('Uno', 0);
        $m->enlazar($a, 55);
        $t->assertEquals([$a, 55], [$m->porTpvId(55), $m->tpvIdDe($a)]);
        $m->olvidarEnlaces();
        $t->assertEquals(0, $m->porTpvId(55));
    });

    $t->test('borrar sube las hijas y quita el término de los productos', function ($t) {
        $m = new TerminosEnMemoria();
        $a = $m->crear('A', 0); $b = $m->crear('B', $a); $c = $m->crear('C', $b);
        $m->ponerAProducto(1, [$b, $c]);
        $m->borrar($b);
        $t->assertEquals([$a, [$c]], [$m->padreDe($c), $m->deProducto(1)]);
    });
}
