<?php
declare(strict_types=1);
/**
 * RECONCILIAR NO REHACE PRODUCTOS CON EL LISTADO (lulubeauty, 18/19-10-2026).
 *
 * Con «manda la caja», reconciliar traía a Woo cada producto que discrepaba
 * llamando a upsert() con la fila del LISTADO de la API (GET /products). Ese
 * listado no trae descripción, tallas, imágenes ni categorías, y upsert()
 * tomaba lo que falta por vacío:
 *
 *   - `post_content = ''`: 116 productos de lulubeauty se quedaron sin
 *     descripción en la web (la caja la conservaba).
 *   - sin `options`, un producto variable pasaba a SIMPLE.
 *
 * El listado sirve para comparar; para escribir hace falta el detalle
 * (GET /products/{id}). Y upsert() no borra lo que no le llega.
 */

const INTEG_RL_SIMPLE   = 990401;
const INTEG_RL_VARIABLE = 990402;
const INTEG_RL_NUEVO    = 990403;
const INTEG_RL_DESC     = '<p>Aceite de alfa-bisabolol. Aporta a la piel un efecto glow.</p>';

function integ_rl_simple(string $nombre): array
{
    return [
        'product_id' => INTEG_RL_SIMPLE, 'name' => $nombre,
        'model' => 'INTEG-990401', 'sku' => 'INTEG-990401', 'price' => 20.0,
        'special_price' => null, 'quantity' => 3, 'subtract' => true,
        'status' => 1, 'tax_class_id' => 0, 'description' => INTEG_RL_DESC, 'options' => [],
    ];
}

function integ_rl_variable(string $nombre, int $tpvId = INTEG_RL_VARIABLE): array
{
    $valores = [];
    foreach ([$tpvId * 10 + 1 => 'S', $tpvId * 10 + 2 => 'M'] as $pov => $talla) {
        $valores[] = ['value_name' => $talla, 'product_option_value_id' => $pov,
            'quantity' => 2, 'price' => 0, 'price_prefix' => '+', 'code_bar' => ''];
    }
    return [
        'product_id' => $tpvId, 'name' => $nombre,
        'model' => "INTEG-$tpvId", 'sku' => "INTEG-$tpvId", 'price' => 30.0,
        'special_price' => null, 'quantity' => 4, 'subtract' => true,
        'status' => 1, 'tax_class_id' => 0, 'description' => INTEG_RL_DESC,
        'options' => [['option_name' => 'Talla', 'values' => $valores]],
    ];
}

function integ_rl_limpiar(): void
{
    global $wpdb;
    foreach ([INTEG_RL_SIMPLE, INTEG_RL_VARIABLE, INTEG_RL_NUEVO] as $tpvId) {
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tpv_product_id' AND meta_value = %d", $tpvId));
        foreach ($ids as $id) {
            foreach (get_children(['post_parent' => (int) $id, 'post_type' => 'product_variation',
                'post_status' => 'any', 'fields' => 'ids']) as $hijo) {
                wp_delete_post((int) $hijo, true);
            }
            wp_delete_post((int) $id, true);
        }
    }
    $GLOBALS['__tpv_productos'] = [];
}

/** Crea el producto en Woo como lo haría el webhook (con el detalle). */
function integ_rl_en_woo(array $p): int
{
    $GLOBALS['__tpv_productos'][$p['product_id']] = $p;
    TPV_Sync::instance()->products->upsert($p);
    return TPV_Sync::instance()->products->find_wc_post((int) $p['product_id']);
}

function integ_rl_reconciliar(): array
{
    $GLOBALS['__http'] = [];
    $stats = TPV_Sync::instance()->products->reconcileBidirectional(false, ['stock' => 'ninguno', 'catalogo' => 'tpv']);
    wp_cache_flush();
    return $stats;
}

function integracion_suite_reconciliar_listado_parcial(IntegracionRunner $t): void
{
    integ_rl_limpiar();

    $t->test('manda la caja: reconciliar trae el nombre nuevo y NO vacía la descripción', function ($t) {
        integ_rl_limpiar();
        $id = integ_rl_en_woo(integ_rl_simple('Bisabolol Face Oil'));
        $t->igual(INTEG_RL_DESC, get_post_field('post_content', $id), 'preparación');

        $GLOBALS['__tpv_productos'][INTEG_RL_SIMPLE] = integ_rl_simple('Bisabolol Face Oil 30 ml');
        $stats = integ_rl_reconciliar();

        $t->igual(1, $stats['fixed'], 'tenía que traer el producto de la caja');
        $t->igual('Bisabolol Face Oil 30 ml', get_the_title($id));
        $t->igual(INTEG_RL_DESC, get_post_field('post_content', $id),
            'antes: el listado no trae description y la web se quedaba vacía');
    });

    $t->test('manda la caja: reconciliar no convierte un variable en simple', function ($t) {
        integ_rl_limpiar();
        $id = integ_rl_en_woo(integ_rl_variable('Pantalón niña'));
        $t->cierto(wc_get_product($id)->is_type('variable'), 'preparación: no nació variable');

        $GLOBALS['__tpv_productos'][INTEG_RL_VARIABLE] = integ_rl_variable('Pantalón niña verano');
        integ_rl_reconciliar();

        $producto = wc_get_product($id);
        $t->igual('variable', $producto->get_type(), 'antes: sin options en el listado pasaba a simple');
        $t->igual(2, count($producto->get_children()), 'las tallas siguen ahí');
        $t->igual(INTEG_RL_DESC, get_post_field('post_content', $id));
    });

    $t->test('manda la caja: un producto que solo está en la caja llega con descripción y tallas', function ($t) {
        integ_rl_limpiar();
        $GLOBALS['__tpv_productos'][INTEG_RL_NUEVO] = integ_rl_variable('Vestido volantes', INTEG_RL_NUEVO);
        integ_rl_reconciliar();

        $id = TPV_Sync::instance()->products->find_wc_post(INTEG_RL_NUEVO);
        $t->cierto($id > 0, 'no se creó en Woo');
        $t->igual('variable', wc_get_product($id)->get_type(), 'antes: nacía simple, sin tallas');
        $t->igual(INTEG_RL_DESC, get_post_field('post_content', $id), 'antes: nacía sin descripción');
    });

    $t->test('si el detalle no llega, reconciliar no toca el producto (ni lo escribe a medias)', function ($t) {
        integ_rl_limpiar();
        $id = integ_rl_en_woo(integ_rl_simple('Bisabolol Face Oil'));
        $GLOBALS['__tpv_productos'][INTEG_RL_SIMPLE] = integ_rl_simple('Bisabolol Face Oil 30 ml');
        $GLOBALS['__tpv_sin_detalle'] = [INTEG_RL_SIMPLE];
        try {
            $stats = integ_rl_reconciliar();
        } finally {
            $GLOBALS['__tpv_sin_detalle'] = [];
        }
        $t->igual(0, $stats['fixed']);
        $t->igual(1, $stats['errors'], 'se cuenta como error, no se calla');
        $t->igual('Bisabolol Face Oil', get_the_title($id));
        $t->igual(INTEG_RL_DESC, get_post_field('post_content', $id));
    });

    $t->test('upsert sin la clave description no borra la de la web', function ($t) {
        integ_rl_limpiar();
        $id = integ_rl_en_woo(integ_rl_simple('Bisabolol Face Oil'));
        $sinDescripcion = integ_rl_simple('Bisabolol Face Oil');
        unset($sinDescripcion['description']);
        TPV_Sync::instance()->products->upsert($sinDescripcion);
        wp_cache_flush();
        $t->igual(INTEG_RL_DESC, get_post_field('post_content', $id));
    });

    $t->test('upsert con description vacía SÍ la vacía (la caja la borró a propósito)', function ($t) {
        integ_rl_limpiar();
        $id = integ_rl_en_woo(integ_rl_simple('Bisabolol Face Oil'));
        $vacia = integ_rl_simple('Bisabolol Face Oil');
        $vacia['description'] = '';
        TPV_Sync::instance()->products->upsert($vacia);
        wp_cache_flush();
        $t->igual('', get_post_field('post_content', $id));
    });

    integ_rl_limpiar();
}
