<?php
declare(strict_types=1);
/**
 * El adaptador real de términos devuelve el nombre como lo ve la persona.
 *
 * WordPress guarda los nombres de término escapados (filtro pre_term_name →
 * _wp_specialchars): «Hogar & Cocina» queda como «Hogar &amp; Cocina». Sin
 * decodificar, ese texto llegaría literal al TPV.
 */
require_once dirname(__DIR__) . '/includes/class-terminos.php';

if (!function_exists('get_term')) {
    function get_term($id, $tax = '') { return $GLOBALS['__wp_terms'][(int) $id] ?? null; }
}
if (!function_exists('get_terms')) {
    function get_terms($args = []) { return array_values($GLOBALS['__wp_terms'] ?? []); }
}
if (!function_exists('get_term_meta')) {
    function get_term_meta($id, $k = '', $single = false) { return $GLOBALS['__wp_termmeta'][(int) $id][$k] ?? ''; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($x) { return false; }
}

function run_terminos_wp_tests(WooTestRunner $t): void
{
    $t->suite('Términos de WordPress: el nombre sin escapar');

    $t->test('nombreDe y todos() devuelven «&» y comillas, no sus entidades', function ($t) {
        $GLOBALS['__wp_terms'] = [7 => (object) ['term_id' => 7, 'parent' => 0, 'name' => 'Hogar &amp; Cocina &#039;Top&#039;']];
        $GLOBALS['__wp_termmeta'] = [7 => ['tpv_category_id' => 3]];
        $w = new TPV_Sync_Terminos();
        $t->assertEquals("Hogar & Cocina 'Top'", $w->nombreDe(7));
        $t->assertEquals([['term_id' => 7, 'parent' => 0, 'name' => "Hogar & Cocina 'Top'", 'tpv_id' => 3]], $w->todos());
        unset($GLOBALS['__wp_terms'], $GLOBALS['__wp_termmeta']);
    });
}
