<?php
declare(strict_types=1);
/**
 * BUG-A en la COLA — el sitio donde el falso exito cuesta stock.
 *
 * class-queue.php::execute() decide si una operacion se marca aplicada y se
 * BORRA de la cola. Si decide que si ante un 4xx/5xx, el stock de WooCommerce y
 * el del TPV divergen sin ninguna senal: nadie reintenta, porque la entrada ya
 * no existe.
 *
 * Estos tests no cargan la clase real (arrastra $wpdb, WC_Product y el resto de
 * WooCommerce). Prueban LA DECISION — que es donde vivia el bug — replicando
 * literalmente las dos formas del switch, la vieja y la nueva, y confrontandolas
 * con los cuerpos que la API devuelve de verdad.
 */

require_once dirname(__DIR__) . '/includes/class-api-client.php';

function run_queue_success_tests(WooTestRunner $t): void
{
    // Como decidia la cola ANTES del fix (class-queue.php:215,223 originales).
    $criterioViejo = static fn($r): bool => empty($r['errors']) && empty($r['error']);
    // Como decide DESPUES.
    $criterioNuevo = static fn($r): bool => TPV_Sync_API_Client::fueBien($r);

    // Los cuatro modos de fallo reales que colaban, mas el exito de control.
    $casos = [
        'problem+json 404' => [
            'code' => 404,
            'body' => ['type' => 'https://api.tpv/errors/not_found', 'title' => 'Not Found',
                       'status' => 404, 'detail' => 'product 99999999 no existe'],
            'esperado' => false,
            'porque'   => 'el producto no existe en el TPV: el stock NO se aplico',
        ],
        '502 de proxy (HTML)' => [
            'code' => 502,
            'body' => [],   // json_decode del HTML de nginx ?? []
            'esperado' => false,
            'porque'   => 'nginx contesto por el backend: la peticion no llego',
        ],
        '429 agotado' => [
            'code' => 429,
            'body' => ['title' => 'Too Many Requests', 'status' => 429],
            'esperado' => false,
            'porque'   => 'rate limit: hay que reintentar, no borrar de la cola',
        ],
        '500 con cuerpo vacio' => [
            'code' => 500,
            'body' => [],
            'esperado' => false,
            'porque'   => 'el servidor reventó y el cuerpo no dice nada',
        ],
        '200 aplicado' => [
            'code' => 200,
            'body' => ['data' => ['quantity' => 7]],
            'esperado' => true,
            'porque'   => 'la ruta feliz debe seguir funcionando',
        ],
    ];

    $t->suite('BUG-A — la cola: el criterio VIEJO daba por buenos los fallos');

    foreach ($casos as $nombre => $c) {
        if ($c['esperado'] === true) { continue; }
        $t->test("$nombre: el criterio viejo lo daba por EXITO (el bug)", function ($t) use ($c, $criterioViejo, $nombre) {
            $t->assert($criterioViejo($c['body']) === true,
                "$nombre deberia colar con el criterio viejo; si no cuela, el caso ya no reproduce el bug");
        });
    }

    $t->suite('BUG-A — la cola: el criterio NUEVO mira el status');

    foreach ($casos as $nombre => $c) {
        $t->test("$nombre ⇒ " . ($c['esperado'] ? 'exito' : 'fallo') . " ({$c['porque']})", function ($t) use ($c, $criterioNuevo, $nombre) {
            $r = TPV_Sync_API_Client::decide($c['code'], $c['body']);
            $t->assert($criterioNuevo($r) === $c['esperado'],
                "$nombre: esperado " . var_export($c['esperado'], true) . ', dio ' . var_export($criterioNuevo($r), true));
        });
    }

    $t->suite('BUG-A — consecuencia: que se borra de la cola');

    $t->test('un 404 NO se marca aplicado (la entrada sobrevive para reintentar)', function ($t) use ($criterioNuevo) {
        $r = TPV_Sync_API_Client::decide(404, ['type' => 'https://api.tpv/errors/not_found']);
        $seBorra = $criterioNuevo($r);
        $t->assert($seBorra === false,
            'Si se borra, WC y el TPV divergen para siempre y nadie se entera');
    });

    $t->test('el health-check NO da verde ante credenciales revocadas', function ($t) use ($criterioViejo, $criterioNuevo) {
        // class-admin.php:1215 — POST /auth/verify con secret rotado ⇒ 401.
        // Es el semaforo que el comerciante mira para saber si sincroniza.
        $body401 = ['type' => 'https://api.tpv/errors/invalid_client', 'title' => 'Unauthorized', 'status' => 401];
        $t->assert($criterioViejo($body401) === true,
            'El criterio viejo daba VERDE con las credenciales revocadas (el peor de los 8 sitios)');
        $t->assert($criterioNuevo(TPV_Sync_API_Client::decide(401, $body401)) === false,
            'El nuevo debe dar ROJO: es justo cuando el chip tiene que avisar');
    });
}
