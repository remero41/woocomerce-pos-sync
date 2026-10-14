<?php
declare(strict_types=1);
/**
 * Categorías de la tienda al TPV (SPEC_categorias_woo_tpv.md §3, C2 y C3):
 * hooks de términos, «Sin categorizar» fuera, anti-eco, y qué categorías lleva
 * un envío de producto según la fase.
 */
require_once dirname(__DIR__) . '/includes/class-categorias.php';
require_once dirname(__DIR__) . '/includes/class-terminos.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-categorias-sync.php';
require_once __DIR__ . '/fixtures/terminos_en_memoria.php';
require_once __DIR__ . '/fixtures/api_categorias_falsa.php';

function run_categorias_woo_a_tpv_tests(WooTestRunner $t): void
{
    $t->suite('Categorías Woo → TPV');

    $nuevo = function (): array {
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA]  = 'completa';
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS] = [];
        $GLOBALS[TPV_Sync_Categorias_Sync::GUARDA] = false;
        $api = new ApiCategoriasFalsa();
        $m = new TerminosEnMemoria();
        $m->defecto = 1;
        $m->t[1] = ['parent' => 0, 'name' => 'Sin categorizar', 'tpv_id' => 0];
        return [$api, $m, new TPV_Sync_Categorias_Sync($api, $m)];
    };

    $t->test('alta en la web: crea en el TPV con su madre (madre primero) y enlaza', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('Uñas', 0);
        $b = $m->crear('Fresas', $a);
        $s->alGuardarTermino($b);
        $t->assert($m->tpvIdDe($a) > 0 && $m->tpvIdDe($b) > 0, 'las dos enlazadas');
        $t->assertEquals($m->tpvIdDe($a), $api->c[$m->tpvIdDe($b)]['parent_id'] ?? null);
        $t->assertEquals((string) $b, $api->c[$m->tpvIdDe($b)]['external_id'] ?? null);
    });

    $t->test('cambio en la web: PATCH; si el TPV ya no la tiene (404), se recrea y reenlaza', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('X', 0);
        $s->alGuardarTermino($a);
        $viejo = $m->tpvIdDe($a);
        $m->actualizar($a, 'X1', 0);
        $s->alGuardarTermino($a);
        $t->assertEquals([$viejo, 'X1'], [$m->tpvIdDe($a), $api->c[$viejo]['name'] ?? null], 'PATCH al mismo');
        unset($api->c[$viejo]);
        $m->actualizar($a, 'X2', 0);
        $s->alGuardarTermino($a);
        $t->assert($m->tpvIdDe($a) > 0 && $m->tpvIdDe($a) !== $viejo, 'nuevo enlace');
        $t->assertEquals('X2', $api->c[$m->tpvIdDe($a)]['name'] ?? null);
    });

    $t->test('borrar en la web: DELETE; 404 cuenta como hecho; otra taxonomía no llama', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('X', 0);
        $s->alGuardarTermino($a);
        $tid = $m->tpvIdDe($a);
        $s->antesDeBorrarTermino($a, 'product_cat');
        $t->assertEquals(false, isset($api->c[$tid]));
        $s->antesDeBorrarTermino($a, 'product_cat');
        $n = count($api->llamadas);
        $s->antesDeBorrarTermino($a, 'category');
        $t->assertEquals($n, count($api->llamadas), 'otra taxonomía no llama a la API');
    });

    $t->test('anti-eco y Sin categorizar: no se envía nada; su hija va a la raíz', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('X', 0);
        $GLOBALS[TPV_Sync_Categorias_Sync::GUARDA] = true;
        $s->alGuardarTermino($a);
        $GLOBALS[TPV_Sync_Categorias_Sync::GUARDA] = false;
        $s->alGuardarTermino(1);
        $hijo = $m->crear('Bajo defecto', 1);
        $s->alGuardarTermino($hijo);
        $t->assertEquals(0, $m->tpvIdDe($a), 'con la guarda no sale');
        $t->assertEquals(0, $m->tpvIdDe(1), 'Sin categorizar nunca');
        $t->assertEquals(0, $api->c[$m->tpvIdDe($hijo)]['parent_id'] ?? null, 'su hija va a la raíz');
    });

    $t->test('nombre que choca con una hermana en el TPV (409): se avisa y el enlace sigue', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('Uno', 0);
        $s->alGuardarTermino($a);
        $tid = $m->tpvIdDe($a);
        $api409 = new class extends ApiCategoriasFalsa {
            public function patch(string $path, array $body = []): array
            {
                $this->llamadas[] = "PATCH $path";
                return ['_status' => 409, '_ok' => false, 'errors' => [['error' => 'duplicate_name']]];
            }
        };
        $s409 = new TPV_Sync_Categorias_Sync($api409, $m);
        $m->actualizar($a, 'Dos', 0);
        $s409->alGuardarTermino($a);
        $t->assertEquals($tid, $m->tpvIdDe($a));
        $t->assertEquals(1, count((array) $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::AVISOS]));
    });

    $t->test('manda el TPV: cambiar las categorías de un producto en la web llega al TPV antes de releerlo', function ($t) use ($nuevo) {
        // Las categorías ignoran «quién manda» (spec D1): sin esto, el plugin
        // relee el producto del TPV y la web pierde las categorías que se le
        // acaban de poner.
        require_once dirname(__DIR__) . '/includes/class-product-sync.php';
        [$api, $m, $s] = $nuevo();
        $GLOBALS['__wp_options']['tpv_sync_principal'] = 'tpv';
        $GLOBALS['__wp_meta'][8308]['_tpv_product_id'] = 501;
        $a = $m->crear('Uñas', 0);
        $m->ponerAProducto(8308, [$a]);
        $sync = new TPV_Sync_Product_Sync($api);
        $sync->categorias = $s;
        $sync->push_wc_product_to_tpv(8308);
        $t->assertEquals([$m->tpvIdDe($a)], $api->productos[501] ?? null, 'PATCH /products/501 con sus category_ids');
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'arbol';
        unset($api->productos[501]);
        $sync->push_wc_product_to_tpv(8308);
        $t->assertEquals(false, isset($api->productos[501]), 'en arbol una actualización no sustituye las del TPV');
        unset($GLOBALS['__wp_options']['tpv_sync_principal'], $GLOBALS['__wp_meta'][8308]['_tpv_product_id']);
    });

    $t->test('payload de producto según la fase', function ($t) use ($nuevo) {
        [$api, $m, $s] = $nuevo();
        $a = $m->crear('A', 0);
        $m->ponerAProducto(7, [$a, 1]);
        $con = $s->paraPayload(7, false);
        $t->assertEquals([$m->tpvIdDe($a)], $con['category_ids'] ?? null, 'completa: siempre, sin Sin categorizar');
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = 'arbol';
        $t->assertEquals([], $s->paraPayload(7, false), 'arbol: actualización sin category_ids');
        $t->assertEquals([$m->tpvIdDe($a)], $s->paraPayload(7, true)['category_ids'] ?? null, 'arbol: alta con category_ids');
        $GLOBALS['__wp_options'][TPV_Sync_Categorias_Sync::MARCA] = '';
        $t->assertEquals([], $s->paraPayload(7, true), 'sin marca: nunca');
    });
}
