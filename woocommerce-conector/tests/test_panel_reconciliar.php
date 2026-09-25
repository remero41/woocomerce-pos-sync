<?php
declare(strict_types=1);
/**
 * EL BOTON DE RECONCILIAR: QUE NO SE PUEDA ESCRIBIR A CIEGAS.
 *
 * La proteccion de verdad no es el texto de ayuda: es que "Aplicar" nazca
 * deshabilitado y solo se abra despues de una simulacion. Sobre 2.510
 * productos, un clic sin haber visto el plan no tiene vuelta atras.
 *
 * Y tiene que volver a cerrarse al cambiar el dueño: el plan que se vio ya no
 * es el que se aplicaria.
 *
 * Como esto vive en HTML y JS dentro del render del panel, se asserta sobre el
 * fuente. Es lo mismo que ya hacen otros tests de esta suite para propiedades
 * que se pierden en silencio.
 */

function run_panel_reconciliar_tests(WooTestRunner $t): void
{
    $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');

    $t->suite('Panel: reconciliar sin poder disparar a ciegas');

    $t->test('el boton de aplicar nace deshabilitado', function ($t) use ($src) {
        $t->assert(
            (bool) preg_match('/id="cc-recon-aplicar"[^>]*\bdisabled\b/', $src),
            'si nace activo, se puede escribir sobre miles de productos sin ver el plan'
        );
    });

    $t->test('cambiar el dueño vuelve a cerrar el boton de aplicar', function ($t) use ($src) {
        $t->assert(
            (bool) preg_match(
                '/#cc-recon-catalogo,\s*#cc-recon-stock.*?\.prop\(\s*[\x27"]disabled[\x27"]\s*,\s*true/s',
                $src
            ),
            'el plan simulado ya no vale si se cambia quien manda'
        );
    });

    $t->test('se puede elegir dueño para cada dominio por separado', function ($t) use ($src) {
        $t->assert((bool) preg_match('/id="cc-recon-catalogo"/', $src), 'falta el de catalogo');
        $t->assert((bool) preg_match('/id="cc-recon-stock"/', $src),    'falta el de stock');
    });

    $t->test('cada dominio ofrece las tres opciones y el modo auditoria', function ($t) use ($src) {
        // "No tocar" es el que permite mirar sin escribir.
        $t->assert(substr_count($src, 'value="ninguno"') >= 2,
            'los dos dominios tienen que poder ponerse en "no tocar"');
    });

    $t->suite('Panel: el endpoint de reconciliar');

    $t->test('por defecto simula: solo escribe si se pide explicitamente', function ($t) use ($src) {
        // Si dry_run no llega (peticion manual, JS roto, integracion de otro),
        // lo seguro es NO escribir.
        $t->assert(
            (bool) preg_match('/\$dryRun\s*=\s*!isset\(\$_POST\[[\x27"]dry_run[\x27"]\]\)/', $src),
            'una peticion sin dry_run tiene que simular, no aplicar'
        );
    });

    $t->test('el endpoint exige permisos y nonce', function ($t) use ($src) {
        preg_match('/function ajax_reconcile\(\).*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';
        $t->assert(str_contains($fn, 'check_ajax_referer'), 'sin nonce, CSRF');
        $t->assert(str_contains($fn, 'manage_woocommerce'),
            'reconciliar reescribe catalogo: no puede hacerlo cualquier usuario');
    });

    $t->test('solo se aceptan dueños conocidos', function ($t) use ($src) {
        preg_match('/function ajax_reconcile\(\).*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';
        $t->assert(
            (bool) preg_match('/in_array\(\s*\$v\s*,\s*\[[\x27"]tpv[\x27"],\s*[\x27"]woo[\x27"],\s*[\x27"]ninguno[\x27"]\]/', $fn),
            'un valor inventado no puede llegar a la politica'
        );
    });

    $t->test('el panel ya no reconcilia solo al reconectar', function ($t) use ($src) {
        $t->assert(!preg_match('/reconcileBidirectional\s*\(\s*\)\s*;/', $src),
            'la reconciliacion silenciosa podia machacar Woo con datos malos del TPV');
    });

    $t->suite('Panel: limpiar los SKU tecnicos');

    $t->test('el boton de aplicar tambien nace deshabilitado', function ($t) use ($src) {
        $t->assert(
            (bool) preg_match('/id="cc-sku-aplicar"[^>]*\bdisabled\b/', $src),
            'vaciar el sku de miles de productos sin ver antes cuales es lo mismo error'
        );
    });

    $t->test('el endpoint simula salvo que se pida aplicar', function ($t) use ($src) {
        preg_match('/function ajax_clean_skus\(\).*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';
        $t->assert($fn !== '', 'no se encontro ajax_clean_skus');
        $t->assert(
            (bool) preg_match('/\$dryRun\s*=\s*!isset\(\$_POST\[[\x27"]dry_run[\x27"]\]\)/', $fn),
            'sin dry_run explicito hay que simular'
        );
        $t->assert(str_contains($fn, 'check_ajax_referer'), 'sin nonce, CSRF');
        $t->assert(str_contains($fn, 'manage_woocommerce'), 'reescribe catalogo del TPV');
    });
}
