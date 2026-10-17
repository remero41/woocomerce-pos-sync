<?php
declare(strict_types=1);
/**
 * PADRE VARIABLE «SIN EXISTENCIAS» CON TALLAS EN STOCK (lulubeauty, 17-10-2026).
 *
 * En la web, cuatro productos con tallas salían «Sin existencias» en el
 * listado aunque la ficha dejaba comprar sus tallas. Dos fallos encadenados:
 *
 *   1. WooCommerce decide si el padre tiene stock leyendo
 *      `wc_product_meta_lookup` de sus variaciones. El conector creaba las
 *      variaciones con wp_insert_post y les escribía el stock con
 *      update_post_meta: la tabla no se enteraba y el padre quedaba agotado.
 *   2. Al tratar un aviso de stock del TPV (una venta de caja), el conector
 *      recalculaba el padre con WC_Product_Variable::sync(), que lo GUARDA:
 *      `woocommerce_update_product` ⇒ el conector creía que la tienda había
 *      editado el producto y mandaba a la caja sus tallas tal como las tiene
 *      Woo. La caja las rehacía y perdía el código de barras de cada talla.
 */

const INTEG_TPV_ID = 990001;
const INTEG_POV    = [990011 => '3/4', 990012 => '4/5', 990013 => '5/6'];

function integ_producto_tpv(array $cantidades, array $povs = INTEG_POV): array
{
    $valores = [];
    foreach ($povs as $pov => $talla) {
        $valores[] = ['value_name' => $talla, 'product_option_value_id' => $pov,
            'quantity' => $cantidades[$pov] ?? 0, 'price' => 0, 'price_prefix' => '+',
            'code_bar' => 'INTEG-990001'];
    }
    return [
        'product_id' => INTEG_TPV_ID, 'name' => 'Sudadera prueba integración',
        'model' => 'INTEG-990001', 'sku' => 'INTEG-990001', 'price' => 22.99,
        'special_price' => null, 'quantity' => array_sum($cantidades), 'subtract' => true,
        'status' => 1, 'tax_class_id' => 0, 'description' => '',
        'options' => [['option_name' => 'Talla', 'values' => $valores]],
    ];
}

/** Borra lo que hayan dejado este test o una pasada anterior. */
function integ_limpiar(): void
{
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tpv_product_id' AND meta_value = %d",
        INTEG_TPV_ID));
    foreach ($ids as $id) {
        foreach (get_children(['post_parent' => (int) $id, 'post_type' => 'product_variation',
            'post_status' => 'any', 'fields' => 'ids']) as $hijo) {
            wp_delete_post((int) $hijo, true);
        }
        wp_delete_post((int) $id, true);
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tpv\\_sync\\_last\\_stock\\_%\\_99001%'");
}

function integ_post(): int
{
    return TPV_Sync::instance()->products->find_wc_post(INTEG_TPV_ID);
}

/** Lo que ve la tienda del padre, leído de cero (sin cachés de esta petición). */
function integ_estado_padre(int $postId): array
{
    global $wpdb;
    wp_cache_flush();
    wc_delete_product_transients($postId);
    $p = wc_get_product($postId);
    return [
        'objeto'  => $p->get_stock_status(),
        'lookup'  => (string) $wpdb->get_var($wpdb->prepare(
            "SELECT stock_status FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", $postId)),
        // El listado oculta/agota por este término cuando la tienda esconde lo agotado.
        'termino' => has_term('outofstock', 'product_visibility', $postId) ? 'outofstock' : 'instock',
    ];
}

/** Un aviso del TPV, por el mismo camino que el webhook real. */
function integ_aviso(string $tipo, int $recurso, array $campos): void
{
    static $eventoId = 0;
    $eventoId = $eventoId ?: time() * 10;
    $eventoId++;
    $dispatch = new ReflectionMethod(TPV_Sync_Webhook::class, 'dispatch');
    $dispatch->invoke(TPV_Sync::instance()->webhooks, [
        'event_id' => $eventoId, 'event_type' => $tipo, 'resource_id' => $recurso,
        'changed_fields' => $campos, 'source' => 'tpv',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

/** Venta de caja de una talla: lo que manda el TPV (variante + producto). */
function integ_venta_caja(int $pov, int $quedanTalla, int $quedanProducto): void
{
    integ_aviso('variant.stock_adjusted', $pov, ['delta' => -1, 'reason' => 'sale',
        'quantity' => $quedanTalla, 'product_id' => INTEG_TPV_ID, 'product_option_value_id' => $pov]);
    integ_aviso('stock.adjusted', INTEG_TPV_ID, ['delta' => -1, 'reason' => 'sale',
        'quantity' => $quedanProducto, 'product_id' => INTEG_TPV_ID]);
}

/** Peticiones a la caja que no son leer (lo que la modifica). */
function integ_escrituras_a_la_caja(): array
{
    return array_values(array_filter($GLOBALS['__http'],
        static fn ($r) => $r['metodo'] !== 'GET' && $r['ruta'] !== '/auth/token'));
}

function integ_crear_desde_tpv(array $cantidades): int
{
    $GLOBALS['__tpv_productos'][INTEG_TPV_ID] = integ_producto_tpv($cantidades);
    TPV_Sync::instance()->products->upsert(integ_producto_tpv($cantidades));
    return integ_post();
}

function integracion_suite_padre_variable_stock(IntegracionRunner $t): void
{
    integ_limpiar();

    $t->test('creado desde el TPV con tallas en stock: el padre está en stock', function ($t) {
        $id = integ_crear_desde_tpv([990011 => 1, 990012 => 1, 990013 => 0]);
        $t->cierto($id > 0, 'el upsert no creó el producto');
        $e = integ_estado_padre($id);
        $t->igual('instock', $e['objeto'], 'estado del producto');
        $t->igual('instock', $e['lookup'], 'tabla wc_product_meta_lookup');
        $t->igual('instock', $e['termino'], 'término product_visibility');
    });

    $t->test('venta de caja de una talla: el padre sigue en stock', function ($t) {
        integ_venta_caja(990011, 0, 1);
        $e = integ_estado_padre(integ_post());
        $t->igual('instock', $e['objeto'], 'estado del producto');
        $t->igual('instock', $e['lookup'], 'tabla wc_product_meta_lookup');
        $t->igual('instock', $e['termino'], 'término product_visibility');
    });

    $t->test('la venta de caja no vuelve a la caja como edición del producto', function ($t) {
        integ_venta_caja(990011, 0, 1);
        $t->igual([], integ_escrituras_a_la_caja(), 'peticiones que modifican la caja');
    });

    $t->test('con «manda el TPV» tampoco rebota (ni relee ni parchea)', function ($t) {
        $GLOBALS['__opciones_conector']['tpv_sync_principal'] = 'tpv';
        try {
            integ_venta_caja(990011, 0, 1);
            $t->igual([], $GLOBALS['__http'], 'peticiones a la caja');
        } finally {
            $GLOBALS['__opciones_conector']['tpv_sync_principal'] = '';
        }
    });

    $t->test('vendida la última talla con stock, el padre sí se agota', function ($t) {
        integ_venta_caja(990012, 0, 0);
        $e = integ_estado_padre(integ_post());
        $t->igual('outofstock', $e['objeto'], 'estado del producto');
        $t->igual('outofstock', $e['lookup'], 'tabla wc_product_meta_lookup');
    });

    $t->test('una talla que vuelve a tener stock (devolución) repone el padre', function ($t) {
        integ_aviso('variant.stock_adjusted', 990013, ['delta' => 1, 'reason' => 'return',
            'quantity' => 1, 'product_id' => INTEG_TPV_ID, 'product_option_value_id' => 990013]);
        $e = integ_estado_padre(integ_post());
        $t->igual('instock', $e['objeto'], 'estado del producto');
        $t->igual('instock', $e['lookup'], 'tabla wc_product_meta_lookup');
        $t->igual([], integ_escrituras_a_la_caja(), 'peticiones que modifican la caja');
    });

    // ── Autocuración: las tiendas que ya tienen padres rotos ──────────────
    $t->test('un aviso de stock del padre cura un padre roto como los de lulubeauty', function ($t) {
        $id = integ_post();
        integ_romper_como_lulubeauty($id);
        integ_aviso('stock.adjusted', INTEG_TPV_ID, ['delta' => 0, 'reason' => 'manual',
            'quantity' => 1, 'product_id' => INTEG_TPV_ID]);
        $e = integ_estado_padre($id);
        $t->igual('instock', $e['objeto'], 'estado del producto');
        $t->igual('instock', $e['lookup'], 'tabla wc_product_meta_lookup');
        $t->igual('instock', $e['termino'], 'término product_visibility');
        $t->igual([], integ_escrituras_a_la_caja(), 'peticiones que modifican la caja');
    });

    $t->test('curarPadresVariables() cura los padres rotos sin hablar con la caja', function ($t) {
        $id = integ_post();
        integ_romper_como_lulubeauty($id);
        $r = integ_curar_todo();
        $e = integ_estado_padre($id);
        $t->igual('instock', $e['objeto'], 'estado del producto');
        $t->igual('instock', $e['lookup'], 'tabla wc_product_meta_lookup');
        $t->igual('instock', $e['termino'], 'término product_visibility');
        $t->cierto(($r['curados'] ?? 0) >= 1, 'debe contar el padre curado: ' . json_encode($r));
        $t->igual([], $GLOBALS['__http'], 'peticiones a la caja');
    });

    $t->test('curar no repone un padre cuyas tallas están todas agotadas', function ($t) {
        $id = integ_post();
        integ_venta_caja(990013, 0, 0);   // la última que quedaba (la devuelta)
        integ_romper_como_lulubeauty($id, 'instock');
        integ_curar_todo();
        $e = integ_estado_padre($id);
        $t->igual('outofstock', $e['objeto'], 'estado del producto');
        $t->igual('outofstock', $e['lookup'], 'tabla wc_product_meta_lookup');
    });

    $t->test('el evento de cura encadena la tanda siguiente hasta acabar', function ($t) {
        $programados = [];
        $captura = static function ($pre, $evento) use (&$programados) {
            $programados[] = ['hook' => $evento->hook, 'args' => $evento->args];
            return true;   // no se guarda en el cron de la tienda
        };
        add_filter('pre_schedule_event', $captura, 10, 2);
        try {
            $r = TPV_Sync::instance()->products->curarPadresVariables(1, 0);
            do_action('tpv_sync_curar_padres_variables', 0);
        } finally {
            remove_filter('pre_schedule_event', $captura, 10);
        }
        $siguientes = array_values(array_filter($programados,
            static fn ($e) => $e['hook'] === 'tpv_sync_curar_padres_variables'));
        $t->igual(1, count($siguientes), 'una tanda más programada');
        $t->cierto(($siguientes[0]['args'][0] ?? 0) > 0, 'continúa donde acabó la tanda');
        $t->cierto($r['siguiente'] > 0, 'con tanda 1 siempre queda algo en tpv85');
        $t->igual([], integ_escrituras_a_la_caja(), 'peticiones que modifican la caja');
    });

    // ── Tallas que la caja rehízo con ids nuevos (el rebote de lulubeauty) ──
    $t->test('el repaso re-empareja las tallas rehechas en la caja y les pone su stock', function ($t) {
        integ_limpiar();
        $id = integ_crear_desde_tpv([990011 => 1, 990012 => 1, 990013 => 0]);
        // La caja rehízo las tallas (ids nuevos, mismos nombres) y vendió/repuso.
        $nuevos = [990021 => '3/4', 990022 => '4/5', 990023 => '5/6'];
        $GLOBALS['__tpv_productos'] = [INTEG_TPV_ID =>
            integ_producto_tpv([990021 => 0, 990022 => 1, 990023 => 2], $nuevos)];
        $GLOBALS['__http'] = [];

        TPV_Sync::instance()->products->reconcile(100);

        $porTalla = [];
        foreach (wc_get_product($id)->get_children() as $hijo) {
            wp_cache_delete($hijo, 'post_meta');
            $talla = (string) get_post_meta($hijo, 'attribute_pa_talla', true);
            $porTalla[$talla] = [(int) get_post_meta($hijo, '_tpv_option_value_id', true),
                (int) get_post_meta($hijo, '_stock', true)];
        }
        ksort($porTalla);
        $t->igual(['3-4' => [990021, 0], '4-5' => [990022, 1], '5-6' => [990023, 2]], $porTalla,
            'talla => [id de la caja, stock]');
        $t->igual('instock', integ_estado_padre($id)['lookup'], 'el padre');
        $t->igual([], array_values(array_filter(integ_escrituras_a_la_caja(),
            static fn ($r) => $r['ruta'] !== '/batch')), 'peticiones que modifican la caja');
        $t->igual(3, count(wc_get_product($id)->get_children()), 'no crea variaciones de más');
    });

    integ_limpiar();
}

/** Todas las tandas, como las encadena el cron. */
function integ_curar_todo(): array
{
    $total = ['revisados' => 0, 'curados' => 0];
    $desde = 0;
    do {
        $r = TPV_Sync::instance()->products->curarPadresVariables(100, $desde);
        $total['revisados'] += $r['revisados'];
        $total['curados']   += $r['curados'];
        $desde = $r['siguiente'];
    } while ($desde > 0);
    return $total;
}

/**
 * Deja el producto como estaban los de lulubeauty: las tallas sin fila en
 * wc_product_meta_lookup y el padre con el estado contrario al de sus tallas.
 */
function integ_romper_como_lulubeauty(int $id, string $estadoFalso = 'outofstock'): void
{
    global $wpdb;
    wp_cache_flush();
    foreach (wc_get_product($id)->get_children() as $hijo) {
        $wpdb->delete($wpdb->wc_product_meta_lookup, ['product_id' => (int) $hijo]);
    }
    update_post_meta($id, '_stock_status', $estadoFalso);
    $wpdb->update($wpdb->wc_product_meta_lookup, ['stock_status' => $estadoFalso], ['product_id' => $id]);
    if ($estadoFalso === 'outofstock') {
        wp_set_object_terms($id, 'outofstock', 'product_visibility', true);
    } else {
        wp_remove_object_terms($id, 'outofstock', 'product_visibility');
    }
    $e = integ_estado_padre($id);
    if ($e['objeto'] !== $estadoFalso || $e['lookup'] !== $estadoFalso) {
        throw new RuntimeException('no se pudo romper el producto: ' . json_encode($e));
    }
}
