<?php
declare(strict_types=1);
/**
 * FOTOS REPETIDAS EN LA CAJA Y EN LA WEB (lulubeauty, 19-10-2026).
 *
 * 893 de 1064 productos con fotos tenían copias en la caja (3852 filas de
 * 5566 sobraban) y la web enseñaba las mismas copias (931: 30 fotos, 5
 * distintas). Era un eco:
 *
 *   1. la web sube su foto a la caja; la API la guarda con nombre aleatorio;
 *   2. la caja avisa y sync_images no reconoce esa URL ⇒ adjunto NUEVO en
 *      Woo (tpv-931-main.jpg) que pasa a ser la destacada;
 *   3. al guardar en la web, push_images_to_tpv ve una URL que nunca envió
 *      (la del adjunto nuevo) y la sube OTRA VEZ a la caja; is_main manda la
 *      principal anterior a la galería;
 *   4. vuelta al paso 2.
 *
 * Arreglo: una foto que vino de la caja no vuelve a la caja; una foto que la
 * web ya tiene (mismo contenido) no se duplica aunque llegue con otro nombre;
 * y una cura por tandas quita de las galerías las copias que ya existen.
 */

const INTEG_FE_TPV = 990501;
const INTEG_FE_IMG = 'https://tpv.prueba.invalid/image/catalog/api/';

function integ_fe_producto(array $fotos): array
{
    $images = [];
    foreach ($fotos as $i => $nombre) {
        $images[] = ['url' => INTEG_FE_IMG . $nombre, 'is_main' => $i === 0, 'sort_order' => $i];
    }
    return [
        'product_id' => INTEG_FE_TPV, 'name' => 'Ultrasonic cleaner prueba',
        'model' => 'INTEG-990501', 'sku' => 'INTEG-990501', 'price' => 50.0,
        'special_price' => null, 'quantity' => 2, 'subtract' => true,
        'status' => 1, 'tax_class_id' => 0, 'description' => '<p>Limpiador</p>',
        'options' => [], 'images' => $images,
    ];
}

function integ_fe_limpiar(): void
{
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tpv_product_id' AND meta_value = %d", INTEG_FE_TPV));
    foreach ($ids as $id) {
        foreach (get_children(['post_parent' => (int) $id, 'post_type' => 'attachment', 'fields' => 'ids']) as $att) {
            wp_delete_attachment((int) $att, true);
        }
        wp_delete_post((int) $id, true);
    }
    $GLOBALS['__tpv_productos'] = [];
    $GLOBALS['__tpv_imagenes'] = [];
}

function integ_fe_upsert(array $p): int
{
    $GLOBALS['__tpv_productos'][$p['product_id']] = $p;
    TPV_Sync::instance()->products->upsert($p);
    wp_cache_flush();
    return TPV_Sync::instance()->products->find_wc_post((int) $p['product_id']);
}

/** Adjunto subido «a mano» en la web (no viene de la caja). */
function integ_fe_adjunto_web(int $postId, string $clave): int
{
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = wp_tempnam('foto-web');
    file_put_contents($tmp, integracion_jpeg($clave));
    $id = media_handle_sideload(['name' => 'foto-web-' . md5($clave) . '.jpg', 'tmp_name' => $tmp], $postId);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    return (int) $id;
}

/** Fotos que la web intentó subir a la caja en las peticiones apuntadas. */
function integ_fe_subidas(): array
{
    $urls = [];
    foreach ($GLOBALS['__http'] as $r) {
        if ($r['ruta'] !== '/batch') continue;
        foreach ((array) ($r['cuerpo']['operations'] ?? []) as $op) {
            if (str_ends_with((string) ($op['path'] ?? ''), '/images')) {
                $urls[] = (string) ($op['body']['image_url'] ?? '');
            }
        }
    }
    return $urls;
}

function integ_fe_galeria(int $postId): array
{
    wp_cache_flush();
    return array_values(array_filter(array_map('intval',
        explode(',', (string) get_post_meta($postId, '_product_image_gallery', true)))));
}

/** La cura entera, tanda tras tanda, como la encadena el cron. */
function integ_fe_curar(): int
{
    $curados = 0;
    $desde = 0;
    do {
        $r = TPV_Sync::instance()->products->curarGaleriasRepetidas(100, $desde);
        $curados += $r['curados'];
        $desde = $r['siguiente'];
    } while ($desde > 0);
    return $curados;
}

function integ_fe_adjuntos(int $postId): int
{
    return count(get_children(['post_parent' => $postId, 'post_type' => 'attachment', 'fields' => 'ids']));
}

function integracion_suite_fotos_sin_eco(IntegracionRunner $t): void
{
    integ_fe_limpiar();
    update_option('tpv_sync_principal', 'wc');

    $t->test('una foto que vino de la caja no vuelve a la caja al guardar en la web', function ($t) {
        integ_fe_limpiar();
        $id = integ_fe_upsert(integ_fe_producto(['x1.jpg', 'x2.jpg']));
        $t->cierto(get_post_thumbnail_id($id) > 0, 'preparación: sin destacada');
        $GLOBALS['__http'] = [];
        TPV_Sync::instance()->products->push_wc_product_to_tpv($id);
        $t->igual([], integ_fe_subidas(), 'antes: subía tpv-…-main.jpg como foto nueva y la caja la duplicaba');
    });

    $t->test('una foto subida en la web sí va a la caja', function ($t) {
        integ_fe_limpiar();
        $id = integ_fe_upsert(integ_fe_producto(['x1.jpg']));
        $nueva = integ_fe_adjunto_web($id, 'foto-hecha-en-la-tienda');
        update_post_meta($id, '_product_image_gallery', (string) $nueva);
        $GLOBALS['__http'] = [];
        TPV_Sync::instance()->products->push_wc_product_to_tpv($id);
        $t->igual([wp_get_attachment_url($nueva)], integ_fe_subidas());
    });

    $t->test('la caja manda la misma foto con otro nombre: la web no crea otra copia', function ($t) {
        integ_fe_limpiar();
        $GLOBALS['__tpv_imagenes'][INTEG_FE_IMG . 'x3.jpg'] = INTEG_FE_IMG . 'x2.jpg';   // x3 = x2
        $GLOBALS['__tpv_imagenes'][INTEG_FE_IMG . 'x4.jpg'] = INTEG_FE_IMG . 'x1.jpg';   // x4 = la principal
        $id = integ_fe_upsert(integ_fe_producto(['x1.jpg', 'x2.jpg', 'x3.jpg', 'x4.jpg']));
        $t->igual(2, integ_fe_adjuntos($id), 'dos fotos distintas, dos adjuntos');
        $t->igual(1, count(integ_fe_galeria($id)), 'la galería: solo x2 (x3 es x2 y x4 es la principal)');

        // Otra vuelta del eco: la caja ya tiene una copia más de la principal.
        $GLOBALS['__tpv_imagenes'][INTEG_FE_IMG . 'x5.jpg'] = INTEG_FE_IMG . 'x1.jpg';
        integ_fe_upsert(integ_fe_producto(['x5.jpg', 'x1.jpg', 'x2.jpg']));
        $t->igual(2, integ_fe_adjuntos($id), 'antes: cada vuelta añadía tpv-…-main-N.jpg');
        $t->igual(1, count(integ_fe_galeria($id)));
    });

    $t->test('la cura quita de la galería las copias y la principal repetida', function ($t) {
        integ_fe_limpiar();
        $id = integ_fe_upsert(integ_fe_producto(['x1.jpg']));
        $a = integ_fe_adjunto_web($id, INTEG_FE_IMG . 'x2.jpg');
        $b = integ_fe_adjunto_web($id, INTEG_FE_IMG . 'x2.jpg');   // copia de $a
        $c = integ_fe_adjunto_web($id, INTEG_FE_IMG . 'x1.jpg');   // copia de la principal
        $d = integ_fe_adjunto_web($id, 'otra-foto');
        update_post_meta($id, '_product_image_gallery', implode(',', [$a, $b, $c, $d]));

        $t->cierto(integ_fe_curar() >= 1, 'no curó nada');
        $t->igual([$a, $d], integ_fe_galeria($id));
    });

    $t->test('la cura no habla con la caja ni la avisa', function ($t) {
        integ_fe_limpiar();
        $id = integ_fe_upsert(integ_fe_producto(['x1.jpg']));
        $a = integ_fe_adjunto_web($id, 'g1');
        $b = integ_fe_adjunto_web($id, 'g1');
        update_post_meta($id, '_product_image_gallery', "$a,$b");
        $GLOBALS['__http'] = [];
        integ_fe_curar();
        $t->igual([], $GLOBALS['__http']);
        $t->igual([$a], integ_fe_galeria($id));
    });

    integ_fe_limpiar();
}
