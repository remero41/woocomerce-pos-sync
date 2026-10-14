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
}
