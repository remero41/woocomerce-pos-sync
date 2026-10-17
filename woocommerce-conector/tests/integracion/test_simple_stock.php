<?php
declare(strict_types=1);
/**
 * PRODUCTO SIMPLE: EL ESTADO DE STOCK ENTERO, NO SOLO LOS METAS.
 *
 * Mismo fallo de fondo que el padre variable (test_padre_variable_stock.php):
 * el conector escribía `_stock` y `_stock_status` con update_post_meta. La
 * ficha (que lee metas) salía bien, pero WooCommerce usa otras dos cosas que
 * no se enteraban:
 *   - `wc_product_meta_lookup`: filtros de stock del listado y la Store API;
 *   - el término `outofstock` de `product_visibility`: con «Ocultar productos
 *     agotados» activo, un producto que la caja repone seguía escondido.
 */

const INTEG_SIMPLE_TPV_ID = 990101;

function integ_simple_tpv(int $cantidad): array
{
    return [
        'product_id' => INTEG_SIMPLE_TPV_ID, 'name' => 'Gorra prueba integración',
        'model' => 'INTEG-990101', 'sku' => 'INTEG-990101', 'price' => 9.99,
        'special_price' => null, 'quantity' => $cantidad, 'subtract' => true,
        'status' => 1, 'tax_class_id' => 0, 'description' => '',
    ];
}

function integ_simple_limpiar(): void
{
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tpv_product_id' AND meta_value = %d",
        INTEG_SIMPLE_TPV_ID));
    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tpv\\_sync\\_last\\_stock\\_%\\_990101'");
}

function integ_simple_post(): int
{
    return TPV_Sync::instance()->products->find_wc_post(INTEG_SIMPLE_TPV_ID);
}

function integracion_suite_simple_stock(IntegracionRunner $t): void
{
    integ_simple_limpiar();

    $t->test('creado desde el TPV sin stock: agotado en ficha, tabla y catálogo', function ($t) {
        TPV_Sync::instance()->products->upsert(integ_simple_tpv(0));
        $e = integ_estado_padre(integ_simple_post());
        $t->igual(['objeto' => 'outofstock', 'lookup' => 'outofstock', 'termino' => 'outofstock'], $e);
    });

    $t->test('la caja lo repone: vuelve a estar en stock en los tres sitios', function ($t) {
        integ_aviso('stock.adjusted', INTEG_SIMPLE_TPV_ID, ['delta' => 3, 'reason' => 'manual',
            'quantity' => 3, 'product_id' => INTEG_SIMPLE_TPV_ID]);
        $e = integ_estado_padre(integ_simple_post());
        $t->igual(['objeto' => 'instock', 'lookup' => 'instock', 'termino' => 'instock'], $e);
        $t->igual([], integ_escrituras_a_la_caja(), 'peticiones que modifican la caja');
    });

    $t->test('la caja vende lo último: agotado en los tres sitios', function ($t) {
        integ_aviso('stock.adjusted', INTEG_SIMPLE_TPV_ID, ['delta' => -3, 'reason' => 'sale',
            'quantity' => 0, 'product_id' => INTEG_SIMPLE_TPV_ID]);
        $e = integ_estado_padre(integ_simple_post());
        $t->igual(['objeto' => 'outofstock', 'lookup' => 'outofstock', 'termino' => 'outofstock'], $e);
        $t->igual([], integ_escrituras_a_la_caja(), 'peticiones que modifican la caja');
    });

    $t->test('una edición en el TPV con stock lo repone también (upsert)', function ($t) {
        TPV_Sync::instance()->products->upsert(integ_simple_tpv(2));
        $e = integ_estado_padre(integ_simple_post());
        $t->igual(['objeto' => 'instock', 'lookup' => 'instock', 'termino' => 'instock'], $e);
    });

    integ_simple_limpiar();
}
