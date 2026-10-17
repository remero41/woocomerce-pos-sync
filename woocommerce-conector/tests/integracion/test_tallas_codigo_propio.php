<?php
declare(strict_types=1);
/**
 * LO QUE LA TIENDA MANDA DE CADA TALLA AL GUARDAR, Y CÓMO BORRA UNA.
 *
 * lulubeauty (17-10-2026): al guardar un producto con tallas en Woo, el
 * conector mandaba como código de cada talla `$variation->get_sku()`, que
 * en WooCommerce HEREDA el SKU del padre cuando la variación no tiene uno
 * propio. La caja recibía la referencia del producto en las 9 tallas y
 * perdía sus EAN. El código de una talla es solo el suyo: su GTIN o su SKU
 * propio (`get_sku('edit')`). Sin ninguno, el número de la variación viaja
 * aparte (`barcode_si_vacio`): la caja lo usa solo si la talla no tiene
 * código, así que nunca pisa un EAN.
 *
 * Y borrar una variación en Woo borra la talla en la caja al momento
 * (DELETE /products/{id}/variants/{vid}): hasta ahora la caja solo se
 * enteraba, por ausencia, en el siguiente guardado del producto.
 *
 * Las dos cosas solo con una API que anuncie `variants_in_place`: contra una
 * API vieja la tienda se comporta como siempre.
 */

/** Guarda el producto en Woo como lo haría el comerciante y devuelve el PATCH que salió. */
function integ_guardar_en_woo(int $postId): ?array
{
    $GLOBALS['__http'] = [];
    $p = wc_get_product($postId);
    $p->set_description('editado ' . microtime(true));
    $p->save();
    foreach ($GLOBALS['__http'] as $r) {
        if ($r['metodo'] === 'PATCH' && $r['ruta'] === '/products/' . INTEG_TPV_ID) return $r;
    }
    return null;
}

/** Las tallas del PATCH: nombre => ['barcode' => ?, 'barcode_si_vacio' => ?]. */
function integ_tallas_del_patch(?array $patch): array
{
    $out = [];
    foreach ((array) ($patch['cuerpo']['options'][0]['values'] ?? []) as $v) {
        $out[(string) $v['name']] = [
            'barcode'          => $v['barcode'] ?? null,
            'barcode_si_vacio' => $v['barcode_si_vacio'] ?? null,
        ];
    }
    return $out;
}

/** Variaciones del producto: nombre de talla => id de la variación. */
function integ_variaciones(int $postId): array
{
    $out = [];
    foreach (wc_get_product($postId)->get_children() as $hijo) {
        wp_cache_delete($hijo, 'post_meta');
        $slug = (string) get_post_meta($hijo, 'attribute_pa_talla', true);
        $term = get_term_by('slug', $slug, 'pa_talla');
        $out[$term ? $term->name : $slug] = (int) $hijo;
    }
    return $out;
}

function integ_borrados_de_talla(): array
{
    return array_values(array_filter($GLOBALS['__http'],
        static fn ($r) => $r['metodo'] === 'DELETE' && str_contains($r['ruta'], '/variants/')));
}

/** Cambia lo que la API anuncia en /health y olvida lo que el cliente ya recordaba. */
function integ_capacidades(array $caps): void
{
    $GLOBALS['__tpv_capacidades'] = $caps;
    $prop = new ReflectionProperty(TPV_Sync_API_Client::class, 'capacidades');
    $prop->setAccessible(true);
    $prop->setValue(TPV_Sync::instance()->api, null);
}

function integracion_suite_tallas_codigo_propio(IntegracionRunner $t): void
{
    integ_limpiar();
    integ_capacidades(['price_gross_write', 'categories_write', 'variants_in_place']);

    $t->test('al guardar en Woo, una talla sin código propio no manda el SKU del padre', function ($t) {
        $id = integ_crear_desde_tpv([990011 => 1, 990012 => 1, 990013 => 1]);
        $patch = integ_guardar_en_woo($id);
        $t->cierto($patch !== null, 'no salió el PATCH del producto');
        $tallas = integ_tallas_del_patch($patch);
        $t->igual(3, count($tallas), 'tres tallas');
        foreach ($tallas as $nombre => $c) {
            $t->cierto($c['barcode'] === null, "la talla $nombre manda barcode=" . var_export($c['barcode'], true));
        }
    });

    $t->test('el número de la variación viaja aparte, como barcode_si_vacio', function ($t) {
        $patch = integ_guardar_en_woo(integ_post());
        $tallas = integ_tallas_del_patch($patch);
        $vars = integ_variaciones(integ_post());
        foreach ($vars as $nombre => $varId) {
            $t->igual((string) $varId, (string) ($tallas[$nombre]['barcode_si_vacio'] ?? ''), "talla $nombre");
        }
    });

    $t->test('con GTIN propio en la variación, ese es su código', function ($t) {
        $vars = integ_variaciones(integ_post());
        update_post_meta($vars['3/4'], '_global_unique_id', '8400000000011');
        $tallas = integ_tallas_del_patch(integ_guardar_en_woo(integ_post()));
        $t->igual('8400000000011', $tallas['3/4']['barcode']);
        $t->igual(null, $tallas['3/4']['barcode_si_vacio'], 'con código propio no hace falta el número');
        $t->igual(null, $tallas['4/5']['barcode'], 'las demás siguen sin código');
        delete_post_meta($vars['3/4'], '_global_unique_id');
    });

    $t->test('con SKU propio en la variación, ese es su código', function ($t) {
        $vars = integ_variaciones(integ_post());
        update_post_meta($vars['4/5'], '_sku', 'TALLA-45');
        $tallas = integ_tallas_del_patch(integ_guardar_en_woo(integ_post()));
        $t->igual('TALLA-45', $tallas['4/5']['barcode']);
        $t->igual(null, $tallas['3/4']['barcode']);
        delete_post_meta($vars['4/5'], '_sku');
    });

    $t->test('sin la capacidad variants_in_place la tienda manda como siempre', function ($t) {
        integ_capacidades(['price_gross_write', 'categories_write']);
        try {
            $tallas = integ_tallas_del_patch(integ_guardar_en_woo(integ_post()));
            $vars = integ_variaciones(integ_post());
            foreach ($vars as $nombre => $varId) {
                $t->igual((string) $varId, (string) ($tallas[$nombre]['barcode'] ?? ''), "talla $nombre: el número como barcode");
                $t->igual(null, $tallas[$nombre]['barcode_si_vacio'], 'sin campo que la API vieja no entiende');
            }
        } finally {
            integ_capacidades(['price_gross_write', 'categories_write', 'variants_in_place']);
        }
    });

    $t->test('borrar una variación en Woo borra la talla en la caja al momento', function ($t) {
        $vars = integ_variaciones(integ_post());
        $GLOBALS['__http'] = [];
        wc_get_product($vars['5/6'])->delete(true);
        $borrados = integ_borrados_de_talla();
        $t->igual(1, count($borrados), 'un DELETE: ' . json_encode(array_column($GLOBALS['__http'], 'ruta')));
        $t->igual('/products/' . INTEG_TPV_ID . '/variants/990013', $borrados[0]['ruta']);
    });

    $t->test('con «manda el TPV» el borrado en Woo no se propaga', function ($t) {
        $GLOBALS['__opciones_conector']['tpv_sync_principal'] = 'tpv';
        try {
            $vars = integ_variaciones(integ_post());
            $GLOBALS['__http'] = [];
            wc_get_product($vars['4/5'])->delete(true);
            $t->igual([], integ_borrados_de_talla(), 'el TPV manda: su talla se queda');
        } finally {
            $GLOBALS['__opciones_conector']['tpv_sync_principal'] = '';
        }
    });

    $t->test('sin la capacidad, el borrado no se manda (la API vieja no tiene el endpoint)', function ($t) {
        integ_capacidades(['price_gross_write', 'categories_write']);
        try {
            $vars = integ_variaciones(integ_post());
            $GLOBALS['__http'] = [];
            wc_get_product($vars['3/4'])->delete(true);
            $t->igual([], integ_borrados_de_talla());
        } finally {
            integ_capacidades(['price_gross_write', 'categories_write', 'variants_in_place']);
        }
    });

    integ_limpiar();
}
