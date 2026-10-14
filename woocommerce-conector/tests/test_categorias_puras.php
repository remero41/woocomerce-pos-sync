<?php
declare(strict_types=1);
/**
 * Piezas puras de la sincronización de categorías (SPEC_categorias_woo_tpv.md
 * §1.1 y §4). La normalización es la MISMA que Api\V1\NombreCategoria de
 * api_tpv: los dos tests usan los mismos vectores.
 */
require_once dirname(__DIR__) . '/includes/class-categorias.php';

function run_categorias_puras_tests(WooTestRunner $t): void
{
    $t->suite('Categorías — una sola regla de nombre (los MISMOS vectores que la API)');

    $t->test('mayúsculas, acentos, ñ, espacios y entidades se pliegan', function ($t) {
        $n = [TPV_Sync_Categorias::class, 'normalizar'];
        $t->assertEquals('lenceria', $n('Lencería'));
        $t->assertEquals('lenceria', $n('  LENCERIA '));
        $t->assertEquals('nino', $n('NIÑO'));
        $t->assertEquals('cosmetica', $n('CÓSMETICA'));
        $t->assertEquals('hogar & cocina', $n('Hogar &amp; Cocina'));
        $t->assertEquals('tonico facial', $n("TONICO   FACIAL "));
    });

    $t->test('juntar: suma sin repetidos, sin ceros, ordenada', function ($t) {
        $t->assertEquals([3, 5, 9], TPV_Sync_Categorias::juntar([9, 3], [5, 3, 0]));
        $t->assertEquals([], TPV_Sync_Categorias::juntar([], []));
    });

    $t->suite('Categorías — plan de unión (solo aditivo)');

    $T = fn (int $id, int $padre, string $n, ?string $ext = null) => ['category_id' => $id, 'parent_id' => $padre, 'name' => $n, 'external_id' => $ext];
    $W = fn (int $id, int $padre, string $n, int $tpv = 0) => ['term_id' => $id, 'parent' => $padre, 'name' => $n, 'tpv_id' => $tpv];

    $t->test('pasadas: enlace (termmeta o external_id) → nombre+madre → solo nombre', function ($t) use ($T, $W) {
        // El orden por id emparejaría mal (2↔10, 3↔30): decide la madre.
        $tpv = [$T(1, 0, 'Niño'), $T(2, 1, 'Camisetas'), $T(3, 0, 'Camisetas'), $T(5, 0, 'Viejo', '90'), $T(6, 0, 'Enlazada')];
        $woo = [$W(10, 0, 'CAMISETAS'), $W(20, 0, 'NINO'), $W(30, 20, 'camisetas'), $W(90, 0, 'Renombrada'),
                $W(95, 0, 'Otro nombre', 6), $W(99, 0, 'Sin categorizar')];
        $plan = TPV_Sync_Categorias::planUnion($tpv, $woo, 99);
        $pares = array_map(fn ($p) => $p['tpv'] . '-' . $p['term'], $plan['parejas']);
        sort($pares);
        $t->assertEquals(['1-20', '2-30', '3-10', '5-90', '6-95'], $pares,
            'Camisetas de Niño con la de NINO y la de la raíz con la de la raíz; 5↔90 por external_id y 6↔95 por termmeta aunque se llamen distinto');
        $t->assertEquals([], $plan['crearEnWoo']);
        $t->assertEquals([], $plan['crearEnTpv'], 'Sin categorizar nunca se crea en el TPV');
    });

    $t->test('lo que falta se crea, madres antes que hijas; la hija de Sin categorizar va a la raíz', function ($t) use ($T, $W) {
        $tpv = [$T(7, 6, 'Hija'), $T(6, 0, 'Madre')];
        $woo = [$W(71, 70, 'Nieta web'), $W(70, 0, 'Madre web'), $W(72, 99, 'Bajo sin categorizar')];
        $plan = TPV_Sync_Categorias::planUnion($tpv, $woo, 99);
        $t->assertEquals([6, 7], $plan['crearEnWoo']);
        $t->assertEquals([70, 71, 72], $plan['crearEnTpv']);
    });

    $t->test('cada una se empareja una vez; madre desconocida o ciclo no cuelgan', function ($t) use ($T, $W) {
        $tpv = [$T(1, 0, 'Uno'), $T(2, 0, 'uno'), $T(3, 99, 'Huérfana'), $T(4, 5, 'C1'), $T(5, 4, 'C2')];
        $woo = [$W(10, 0, 'UNO')];
        $plan = TPV_Sync_Categorias::planUnion($tpv, $woo, 0);
        $t->assertEquals([['tpv' => 1, 'term' => 10]], $plan['parejas']);
        $crear = $plan['crearEnWoo'];
        sort($crear);
        $t->assertEquals([2, 3, 4, 5], $crear, 'en un ciclo el orden da igual: que estén todas y que termine');
    });

    $t->test('lulubeauty 14-10: 58 parejas, 23 altas en la web, 58 en el TPV', function ($t) {
        $f = require __DIR__ . '/fixtures/lulubeauty_categorias.php';
        $plan = TPV_Sync_Categorias::planUnion($f['tpv'], $f['woo'], $f['por_defecto']);
        $t->assertEquals([58, 23, 58], [count($plan['parejas']), count($plan['crearEnWoo']), count($plan['crearEnTpv'])]);
    });
}
