<?php
declare(strict_types=1);
/**
 * Un aviso del TPV no rehace la estructura de un producto variable de Woo.
 *
 * Reproducido en vivo el 07-10-2026 (tpv99wp ↔ tpv200): al volver del TPV
 * un producto Talla×Color quedaba con un atributo «Talla-Color», 4
 * variaciones nuevas y las 4 originales como comodín; y un producto de una
 * talla, tras reconectar a otro TPV, perdía sus 5 variaciones (sku, rebaja)
 * y recibía 5 nuevas. También: privado → borrador.
 */
require_once dirname(__DIR__) . '/includes/class-product-sync.php';

function opciones_tpv(array $valores, string $nombre = 'Talla'): array
{
    $vals = [];
    foreach ($valores as $pov => [$name, $code]) {
        $vals[] = ['product_option_value_id' => $pov, 'value_name' => $name, 'code_bar' => $code, 'quantity' => 1, 'price' => 0, 'price_prefix' => '+'];
    }
    return [['option_name' => $nombre, 'values' => $vals]];
}

function run_variaciones_plan_tests(WooTestRunner $t): void
{
    $t->suite('Variaciones: emparejar con Woo, no rehacer');

    $t->test('Talla×Color en Woo: la estructura se respeta y las tallas se emparejan por código', function ($t) {
        $opts = opciones_tpv([71 => ['S-Rojo', 'AUD-s-rojo'], 72 => ['S-Azul', 'AUD-s-azul'], 73 => ['M-Rojo', 'AUD-m-rojo']], 'Talla-Color');
        $woo = [
            2930 => ['pov' => 0, 'sku' => 'AUD-s-rojo', 'gtin' => '', 'attrs' => ['pa_talla' => 's', 'pa_color' => 'rojo']],
            2931 => ['pov' => 0, 'sku' => 'AUD-s-azul', 'gtin' => '', 'attrs' => ['pa_talla' => 's', 'pa_color' => 'azul']],
            2932 => ['pov' => 0, 'sku' => '',           'gtin' => 'AUD-M-ROJO', 'attrs' => ['pa_talla' => 'm', 'pa_color' => 'rojo']],
        ];
        $plan = TPV_Sync_Product_Sync::planVariaciones($opts, $woo, ['pa_talla', 'pa_color']);
        $t->assertEquals(false, $plan['compatible'], 'dos atributos no son la opción única del TPV');
        $t->assertEquals([71 => 2930, 72 => 2931, 73 => 2932], $plan['emparejadas'], 'por sku y por GTIN, sin distinguir mayúsculas');
        $t->assertEquals([], $plan['crear'], 'nada nuevo: no se duplican');
        $t->assertEquals([], $plan['sobrantes']);
        $t->assert(str_contains($plan['motivo'], 'pa_talla'), 'el motivo dice qué atributos tiene Woo');
    });

    $t->test('una talla, enlaces de OTRO TPV: se empareja por nombre de valor y no se borra nada', function ($t) {
        $opts = opciones_tpv([101 => ['S', 'var-x'], 102 => ['M', 'var-x'], 103 => ['L', 'var-x']], 'Talla E2E');
        $woo = [
            2901 => ['pov' => 55, 'sku' => 'var-x', 'gtin' => '', 'attrs' => ['pa_talla-e2e' => 's']],
            2902 => ['pov' => 56, 'sku' => 'var-x', 'gtin' => '', 'attrs' => ['pa_talla-e2e' => 'm']],
            2903 => ['pov' => 57, 'sku' => 'var-x', 'gtin' => '', 'attrs' => ['pa_talla-e2e' => 'l']],
        ];
        $plan = TPV_Sync_Product_Sync::planVariaciones($opts, $woo, ['pa_talla-e2e']);
        $t->assertEquals(true, $plan['compatible'], 'misma opción (Talla E2E → pa_talla-e2e)');
        $t->assertEquals([101 => 2901, 102 => 2902, 103 => 2903], $plan['emparejadas'],
            'el sku repetido solo vale una vez; el resto por el nombre del valor');
        $t->assertEquals([], $plan['crear']);
        $t->assertEquals([], $plan['sobrantes'], 'un enlace viejo no es motivo para borrar: se adopta');
    });

    // lulubeauty (17-10-2026): tras el rebote de una venta de caja, el TPV
    // tenía TODAS las tallas con el código del padre, y Woo heredaba ese mismo
    // sku en sus variaciones. Un código repetido no identifica una talla: la
    // primera de la caja se casaba con la primera variación de Woo, fuera
    // cual fuera su talla.
    $t->test('un código que comparten varias tallas no empareja: manda el nombre del valor', function ($t) {
        $opts = opciones_tpv([103 => ['L', 'ZK-26042.'], 101 => ['S', 'ZK-26042.'], 102 => ['M', 'ZK-26042.']]);
        $woo = [
            2901 => ['pov' => 0, 'sku' => 'ZK-26042.', 'gtin' => '', 'attrs' => ['pa_talla' => 's']],
            2902 => ['pov' => 0, 'sku' => 'ZK-26042.', 'gtin' => '', 'attrs' => ['pa_talla' => 'm']],
            2903 => ['pov' => 0, 'sku' => 'ZK-26042.', 'gtin' => '', 'attrs' => ['pa_talla' => 'l']],
        ];
        $plan = TPV_Sync_Product_Sync::planVariaciones($opts, $woo, ['pa_talla']);
        $t->assertEquals([103 => 2903, 101 => 2901, 102 => 2902], $plan['emparejadas'],
            'la L de la caja con la L de Woo, no con la primera que tenga el código');
    });

    $t->test('un código repetido solo en Woo tampoco empareja', function ($t) {
        $opts = opciones_tpv([103 => ['L', 'ZK-26042.'], 101 => ['S', '8400000000017']]);
        $woo = [
            2901 => ['pov' => 0, 'sku' => 'ZK-26042.', 'gtin' => '8400000000017', 'attrs' => ['pa_talla' => 's']],
            2903 => ['pov' => 0, 'sku' => 'ZK-26042.', 'gtin' => '', 'attrs' => ['pa_talla' => 'l']],
        ];
        $plan = TPV_Sync_Product_Sync::planVariaciones($opts, $woo, ['pa_talla']);
        $t->assertEquals([103 => 2903, 101 => 2901], $plan['emparejadas'],
            'el GTIN único sí vale; el sku que comparten dos variaciones, no');
    });

    $t->test('un código repetido solo en la caja tampoco empareja', function ($t) {
        // La caja rehízo las tallas con el código del padre; en Woo solo la S lo tiene.
        $opts = opciones_tpv([103 => ['L', 'ZK-26042.'], 101 => ['S', 'ZK-26042.']]);
        $woo = [
            2901 => ['pov' => 0, 'sku' => 'ZK-26042.', 'gtin' => '', 'attrs' => ['pa_talla' => 's']],
            2903 => ['pov' => 0, 'sku' => '', 'gtin' => '', 'attrs' => ['pa_talla' => 'l']],
        ];
        $plan = TPV_Sync_Product_Sync::planVariaciones($opts, $woo, ['pa_talla']);
        $t->assertEquals([103 => 2903, 101 => 2901], $plan['emparejadas'],
            'la L de la caja no se queda con la S de Woo por compartir código');
    });

    $t->test('valor nuevo en el TPV ⇒ se crea; valor que ya no está ⇒ sobrante (privada), nunca borrada', function ($t) {
        $opts = opciones_tpv([101 => ['S', ''], 104 => ['XL', '']]);
        $woo = [
            2901 => ['pov' => 101, 'sku' => '', 'gtin' => '', 'attrs' => ['pa_talla' => 's']],
            2902 => ['pov' => 102, 'sku' => '', 'gtin' => '', 'attrs' => ['pa_talla' => 'm']],
        ];
        $plan = TPV_Sync_Product_Sync::planVariaciones($opts, $woo, ['pa_talla']);
        $t->assertEquals([101 => 2901], $plan['emparejadas']);
        $t->assertEquals([104 => true], $plan['crear']);
        $t->assertEquals([2902], $plan['sobrantes']);
    });

    $t->test('producto sin variaciones aún (viene del TPV): se crea todo', function ($t) {
        $plan = TPV_Sync_Product_Sync::planVariaciones(opciones_tpv([1 => ['S', ''], 2 => ['M', '']]), [], []);
        $t->assertEquals(true, $plan['compatible']);
        $t->assertEquals([1 => true, 2 => true], $plan['crear']);
    });

    $t->test('atributo propio de Woo (sin taxonomía) ⇒ incompatible: solo stock por código', function ($t) {
        $opts = opciones_tpv([1 => ['S', 'C-S'], 2 => ['M', 'C-M']]);
        $woo = [9 => ['pov' => 0, 'sku' => 'C-S', 'gtin' => '', 'attrs' => ['talla' => 'S']], 10 => ['pov' => 0, 'sku' => '', 'gtin' => '', 'attrs' => ['talla' => 'M']]];
        $plan = TPV_Sync_Product_Sync::planVariaciones($opts, $woo, ['talla']);
        $t->assertEquals(false, $plan['compatible']);
        $t->assertEquals([1 => 9], $plan['emparejadas'], 'la M no tiene código: no se adivina');
        $t->assertEquals([], $plan['crear'], 'sin estructura compatible no se crean variaciones');
    });

    $t->test('slug estable: acentos y espacios como sanitize_title', function ($t) {
        $t->assertEquals('talla-e2e', TPV_Sync_Product_Sync::slugAtributo('Talla E2E'));
        $t->assertEquals('talla-audit-color-audit', TPV_Sync_Product_Sync::slugAtributo('Talla Audit-Color Audit'));
    });

    $t->suite('Estado del post tras un aviso del TPV');
    $t->test('privado, pendiente y programado se conservan; borrador↔publicado sigue al TPV', function ($t) {
        $e = fn ($actual, $tpv) => TPV_Sync_Product_Sync::estadoPost($actual, $tpv);
        $t->assertEquals('private', $e('private', 1)); $t->assertEquals('private', $e('private', 0));
        $t->assertEquals('pending', $e('pending', 1)); $t->assertEquals('future', $e('future', 0));
        $t->assertEquals('publish', $e('draft', 1));   $t->assertEquals('draft', $e('publish', 0));
        $t->assertEquals('publish', $e('publish', 1)); $t->assertEquals('draft', $e('draft', 0));
        $t->assertEquals('publish', $e('', 1), 'alta visible'); $t->assertEquals('draft', $e('', 0), 'alta oculta');
    });
}
