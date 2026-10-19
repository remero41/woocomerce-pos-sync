<?php
declare(strict_types=1);
/**
 * EL PRECIO QUE PONE LA CAJA, Y QUIÉN LO CAMBIA DESPUÉS (lulubeauty, 19-10-2026).
 *
 * Con «manda la tienda», la clienta corrigió precios en la caja y la web los
 * deshizo:
 *
 *   1. La revisión de 6 h tomaba el precio de Woo como «lo que la web cobra»
 *      aunque ese precio lo hubiera escrito la propia caja tiempo atrás, y lo
 *      devolvía a la caja. Por eso la caja marca cada precio que escribe en
 *      Woo (`_tpv_pvp_de_caja`): si sigue igual, nadie lo ha tocado en Woo.
 *   2. El precio de las tallas solo se escribía en las nuevas: un cambio de
 *      precio en la caja no llegaba nunca a las variaciones y el siguiente
 *      envío desde Woo devolvía a la caja el precio viejo. Ahora se escribe
 *      si la variación conserva el que puso la caja; si alguien lo cambió en
 *      Woo, se respeta.
 */

const INTEG_PC_TPV_ID   = 990201;
const INTEG_PC_SIMPLE   = 990301;
const INTEG_PC_POV      = [990211 => 'S', 990212 => 'M'];

function integ_pc_variable(float $precio): array
{
    $valores = [];
    foreach (INTEG_PC_POV as $pov => $talla) {
        $valores[] = ['value_name' => $talla, 'product_option_value_id' => $pov,
            'quantity' => 2, 'price' => 0, 'price_prefix' => '+', 'code_bar' => ''];
    }
    return [
        'product_id' => INTEG_PC_TPV_ID, 'name' => 'Pantalón prueba precio caja',
        'model' => 'INTEG-990201', 'sku' => 'INTEG-990201', 'price' => $precio,
        'special_price' => null, 'quantity' => 4, 'subtract' => true,
        'status' => 1, 'tax_class_id' => 0, 'description' => '',
        'options' => [['option_name' => 'Talla', 'values' => $valores]],
    ];
}

function integ_pc_simple(float $precio): array
{
    return [
        'product_id' => INTEG_PC_SIMPLE, 'name' => 'Bruma prueba precio caja',
        'model' => 'INTEG-990301', 'sku' => 'INTEG-990301', 'price' => $precio,
        'special_price' => null, 'quantity' => 3, 'subtract' => true,
        'status' => 1, 'tax_class_id' => 0, 'description' => '', 'options' => [],
    ];
}

function integ_pc_limpiar(): void
{
    global $wpdb;
    foreach ([INTEG_PC_TPV_ID, INTEG_PC_SIMPLE] as $tpvId) {
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
}

/** Precio normal de cada talla, leído de cero. */
function integ_pc_precios_tallas(): array
{
    $padre = TPV_Sync::instance()->products->find_wc_post(INTEG_PC_TPV_ID);
    wp_cache_flush();
    $precios = [];
    foreach (get_children(['post_parent' => $padre, 'post_type' => 'product_variation',
        'post_status' => 'any', 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']) as $varId) {
        $precios[(int) get_post_meta((int) $varId, '_tpv_option_value_id', true)]
            = (string) get_post_meta((int) $varId, '_regular_price', true);
    }
    ksort($precios);
    return $precios;
}

function integ_pc_variacion(int $pov): int
{
    $padre = TPV_Sync::instance()->products->find_wc_post(INTEG_PC_TPV_ID);
    foreach (get_children(['post_parent' => $padre, 'post_type' => 'product_variation',
        'post_status' => 'any', 'fields' => 'ids']) as $varId) {
        if ((int) get_post_meta((int) $varId, '_tpv_option_value_id', true) === $pov) {
            return (int) $varId;
        }
    }
    return 0;
}

function integ_pc_upsert(array $p): void
{
    $GLOBALS['__tpv_productos'][$p['product_id']] = $p;
    TPV_Sync::instance()->products->upsert($p);
}

function integracion_suite_precio_desde_caja(IntegracionRunner $t): void
{
    integ_pc_limpiar();
    update_option('tpv_sync_principal', 'wc');

    $t->test('simple: el precio que escribe la caja queda marcado como de la caja', function ($t) {
        integ_pc_upsert(integ_pc_simple(15.72));
        $id = TPV_Sync::instance()->products->find_wc_post(INTEG_PC_SIMPLE);
        $t->cierto($id > 0, 'el upsert no creó el producto');
        $t->igual('15.72', (string) get_post_meta($id, '_tpv_pvp_de_caja', true));
    });

    $t->test('tallas: al crearlas, su precio queda marcado como de la caja', function ($t) {
        integ_pc_upsert(integ_pc_variable(15.72));
        $t->igual([990211 => '15.72', 990212 => '15.72'], integ_pc_precios_tallas());
        $t->igual('15.72', (string) get_post_meta(integ_pc_variacion(990211), '_tpv_pvp_de_caja', true));
    });

    $t->test('la caja cambia el precio: las tallas que nadie tocó en Woo lo reciben', function ($t) {
        integ_pc_upsert(integ_pc_variable(12.99));
        $t->igual([990211 => '12.99', 990212 => '12.99'], integ_pc_precios_tallas(),
            'antes: se quedaban en 15,72 y el siguiente envío desde Woo lo devolvía a la caja');
    });

    $t->test('una talla con precio puesto en Woo no se pisa', function ($t) {
        $var = wc_get_product(integ_pc_variacion(990212));
        $var->set_regular_price('14.00');
        $var->save();
        integ_pc_upsert(integ_pc_variable(11.50));
        $t->igual([990211 => '11.5', 990212 => '14.00'], integ_pc_precios_tallas());
    });

    $t->test('talla de antes sin marca (tiendas de antes): no se pisa, como hasta ahora', function ($t) {
        delete_post_meta(integ_pc_variacion(990211), '_tpv_pvp_de_caja');
        integ_pc_upsert(integ_pc_variable(10.00));
        $t->igual('11.5', integ_pc_precios_tallas()[990211]);
    });

    // Reconciliar con «manda Woo» comparaba el precio del padre variable
    // (vacío en Woo = 0) con el de la caja: siempre «discrepa» y siempre
    // empujaba. Y un precio que puso la caja volvía a la caja.
    $t->test('reconciliar: un variable igual en los dos lados no se empuja por su precio', function ($t) {
        integ_pc_limpiar();
        integ_pc_upsert(integ_pc_variable(15.72));
        $GLOBALS['__tpv_productos'] = [INTEG_PC_TPV_ID => integ_pc_variable(15.72)];
        $GLOBALS['__http'] = [];
        TPV_Sync::instance()->products->reconcileBidirectional(false, ['stock' => 'ninguno', 'catalogo' => 'woo']);
        $escrituras = array_filter($GLOBALS['__http'], static fn ($r) => $r['metodo'] !== 'GET'
            && str_contains($r['ruta'], (string) INTEG_PC_TPV_ID));
        $t->igual([], array_values($escrituras), 'nada que escribir: antes el padre (0 en Woo) siempre «discrepaba»');
    });

    $t->test('reconciliar: el precio que puso la caja no vuelve a la caja aunque la caja cambiara', function ($t) {
        integ_pc_limpiar();
        integ_pc_upsert(integ_pc_simple(15.72));
        $GLOBALS['__tpv_productos'] = [INTEG_PC_SIMPLE => integ_pc_simple(12.99)];
        $GLOBALS['__http'] = [];
        TPV_Sync::instance()->products->reconcileBidirectional(false, ['stock' => 'ninguno', 'catalogo' => 'woo']);
        $t->igual([], integ_pc_escrituras_con_precio(), 'antes: PATCH price 15,72 deshacía la corrección');
    });

    update_option('tpv_sync_principal', '');
    integ_pc_limpiar();
}

/**
 * Escrituras a la caja que llevan precio (PATCH/POST/bulk) para los productos
 * de este test. La reconciliación recorre todo el catálogo de la tienda de
 * pruebas: lo demás no cuenta.
 */
function integ_pc_escrituras_con_precio(): array
{
    $con = [];
    foreach ($GLOBALS['__http'] as $r) {
        if ($r['metodo'] === 'GET' || $r['ruta'] === '/auth/token') continue;
        $c = (array) ($r['cuerpo'] ?? []);
        $items = isset($c['items']) ? (array) $c['items'] : [$c];
        foreach ($items as $it) {
            $nuestro = str_contains($r['ruta'], (string) INTEG_PC_TPV_ID) || str_contains($r['ruta'], (string) INTEG_PC_SIMPLE)
                || in_array((string) (is_array($it) ? ($it['model'] ?? '') : ''), ['INTEG-990201', 'INTEG-990301'], true);
            if ($nuestro && is_array($it) && array_key_exists('price', $it)) {
                $con[] = $r['metodo'] . ' ' . $r['ruta'] . ' price=' . $it['price'];
            }
        }
    }
    return $con;
}
