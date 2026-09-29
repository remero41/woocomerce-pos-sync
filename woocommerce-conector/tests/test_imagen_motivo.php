<?php
declare(strict_types=1);
/**
 * «Imagen no subida (status=401)» no dice POR QUE (lulubeauty, 29-09-2026).
 *
 * El /batch de imagenes fallaba en toda la tienda y el registro solo guardaba
 * el codigo. La API si contaba el motivo (signature_invalid, «Host not in
 * allowed_domain», «Could not download image (HTTP 404)»...), pero se tiraba:
 * para saber cual de los cinco filtros de la descarga habia saltado hubo que
 * reconstruirlo desde el servidor. El motivo va al registro, legible.
 *
 * Funcion pura: el resultado de una suboperacion del /batch -> texto.
 */

require_once dirname(__DIR__) . '/includes/class-product-sync.php';

function run_imagen_motivo_tests(WooTestRunner $t): void
{
    $t->suite('Imagen no subida — el registro cuenta el motivo');

    $t->test('error de la API (firma): codigo y mensaje', function ($t) {
        $m = TPV_Sync_Product_Sync::motivoDelFallo(['index' => 0, 'status' => 401, 'body' => null,
            'error' => ['errors' => [['error' => 'signature_invalid', 'message' => 'Invalid request signature.']]]]);
        $t->assert($m === 'signature_invalid: Invalid request signature.', "obtenido: $m");
    });

    $t->test('validacion de la descarga: campo y mensaje', function ($t) {
        $m = TPV_Sync_Product_Sync::motivoDelFallo(['status' => 422, 'error' => ['errors' => [[
            'error' => 'validation_error', 'field' => 'image_url',
            'message' => 'Host not in allowed_domain for this client.']]]]);
        $t->assert($m === 'image_url: Host not in allowed_domain for this client.', "obtenido: $m");
    });

    $t->test('varios errores: todos, separados', function ($t) {
        $m = TPV_Sync_Product_Sync::motivoDelFallo(['status' => 422, 'error' => ['errors' => [
            ['error' => 'validation_error', 'field' => 'a', 'message' => 'uno'],
            ['error' => 'validation_error', 'field' => 'b', 'message' => 'dos']]]]);
        $t->assert($m === 'a: uno; b: dos', "obtenido: $m");
    });

    $t->test('error propio del batch (sin envoltorio errors)', function ($t) {
        $m = TPV_Sync_Product_Sync::motivoDelFallo(['status' => 500,
            'error' => ['error' => 'batch_sub_error', 'message' => 'boom']]);
        $t->assert($m === 'batch_sub_error: boom', "obtenido: $m");
    });

    $t->test('sin detalle: cadena vacia, nunca un aviso de PHP', function ($t) {
        $avisos = [];
        set_error_handler(function ($n, $msg) use (&$avisos) { $avisos[] = $msg; return true; });
        try {
            $t->assert(TPV_Sync_Product_Sync::motivoDelFallo(['status' => 500]) === '', 'sin error');
            $t->assert(TPV_Sync_Product_Sync::motivoDelFallo(['status' => 500, 'error' => 'texto']) === 'texto', 'error como texto');
            $t->assert(TPV_Sync_Product_Sync::motivoDelFallo(['status' => 500, 'error' => ['errors' => 'x']]) === '', 'errors malformado');
            $t->assert(TPV_Sync_Product_Sync::motivoDelFallo(['status' => 500, 'error' => ['errors' => ['x', null]]]) === '', 'items no-array');
        } finally {
            restore_error_handler();
        }
        $t->assert($avisos === [], 'avisos de PHP: ' . implode(' | ', $avisos));
    });

    $t->test('mensaje enorme: se recorta para el registro', function ($t) {
        $m = TPV_Sync_Product_Sync::motivoDelFallo(['status' => 422,
            'error' => ['error' => 'x', 'message' => str_repeat('a', 1000)]]);
        $t->assert(strlen($m) <= 300, 'longitud ' . strlen($m));
    });
}
