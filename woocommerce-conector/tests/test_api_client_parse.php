<?php
declare(strict_types=1);
/**
 * BUG-A — el cliente decidia el exito sin mirar el status HTTP.
 *
 * Reproduce el bug sin WordPress y sin red: solo hace falta el cuerpo que la API
 * devuelve de verdad y el codigo de estado que lo acompanaba.
 *
 * La cadena, verificada eslabon a eslabon en la auditoria:
 *   1. api/v1/classes/Response.php:247-262 (problemJson) emite
 *      type/title/status/detail/instance/request_id/code — SIN clave 'errors'.
 *   2. Este cliente NEGOCIA ese formato: 'Accept: application/problem+json'
 *      (class-api-client.php:414).
 *   3. Ocho puntos del plugin concluian exito con empty(errors) && empty(error).
 * ⇒ un 4xx en problem+json se marcaba como aplicado.
 */

require_once dirname(__DIR__) . '/includes/class-api-client.php';

function run_api_client_parse_tests(WooTestRunner $t): void
{
    $t->suite('BUG-A — API client: el status manda');

    // El cuerpo REAL de un 404 de la API. Copiado de la forma que emite
    // problemJson(), no inventado.
    $problem404 = [
        'type'       => 'https://api.tpv/errors/not_found',
        'title'      => 'Not Found',
        'status'     => 404,
        'detail'     => 'product 99999999 no existe',
        'request_id' => 'c1d214f17a7a4558',
    ];

    $t->test('el cuerpo de un problem+json NO trae la clave errors (la premisa del bug)', function ($t) use ($problem404) {
        $t->assert(empty($problem404['errors']) && empty($problem404['error']),
            'Si este aserto cayera, BUG-A no existiria: el bug es justo que la clave no esta');
    });

    $t->test('404 problem+json ⇒ _ok=false y _status=404', function ($t) use ($problem404) {
        $r = TPV_Sync_API_Client::decide(404, $problem404);
        $t->assert(($r['_status'] ?? null) === 404, '_status debe propagarse: ' . var_export($r['_status'] ?? null, true));
        $t->assert(($r['_ok'] ?? null) === false, '_ok debe ser false en 4xx');
        $t->assert(TPV_Sync_API_Client::fueBien($r) === false,
            'fueBien() debe decir NO ante un 404, aunque el cuerpo no traiga errors');
    });

    $t->test('502 de proxy (cuerpo HTML ⇒ json_decode da []) ⇒ fallo', function ($t) {
        // wp_remote_retrieve_body devuelve el HTML de nginx; json_decode(...) ?? []
        // lo convierte en un array VACIO: ni errors, ni error, ni type. El caso
        // que mas limpio colaba como exito.
        $r = TPV_Sync_API_Client::decide(502, []);
        $t->assert(($r['_ok'] ?? null) === false, '_ok=false en 502');
        $t->assert(TPV_Sync_API_Client::fueBien($r) === false,
            'Un cuerpo vacio con status 502 es un fallo, no un exito silencioso');
    });

    $t->test('429 tras agotar reintentos ⇒ fallo', function ($t) {
        $r = TPV_Sync_API_Client::decide(429, ['title' => 'Too Many Requests', 'status' => 429]);
        $t->assert(TPV_Sync_API_Client::fueBien($r) === false, 'Un 429 no es un exito');
    });

    $t->test('207 parcial es DISTINGUIBLE de un 200 (BUG-B de rebote)', function ($t) {
        $r207 = TPV_Sync_API_Client::decide(207, ['results' => [['ok' => true], ['error' => 'x']]]);
        $r200 = TPV_Sync_API_Client::decide(200, ['results' => [['ok' => true], ['ok' => true]]]);
        $t->assert($r207['_status'] === 207 && $r200['_status'] === 200,
            'Sin _status ambos eran el mismo array y el 207 era indistinguible del 200');
        $t->assert(TPV_Sync_API_Client::fueBien($r207) === true,
            '207 esta en la familia 2xx: no es un fallo del transporte. Quien haga bulk debe mirar _status');
    });

    $t->test('200 normal ⇒ exito', function ($t) {
        $r = TPV_Sync_API_Client::decide(200, ['data' => ['product_id' => 42]]);
        $t->assert(($r['_ok'] ?? null) === true, '_ok=true en 200');
        $t->assert(TPV_Sync_API_Client::fueBien($r) === true, 'Un 200 con data es exito');
        $t->assert(($r['data']['product_id'] ?? null) === 42,
            'decide() no debe pisar el payload: el guion bajo existe para eso');
    });

    $t->test('201 created ⇒ exito', function ($t) {
        $t->assert(TPV_Sync_API_Client::fueBien(TPV_Sync_API_Client::decide(201, [])) === true, '201 es 2xx');
    });

    $t->suite('BUG-A — fallback por cuerpo (respuestas que no pasaron por decide)');

    $t->test('sin _ok, un cuerpo con errors sigue siendo fallo', function ($t) {
        $t->assert(TPV_Sync_API_Client::fueBien(['errors' => [['message' => 'x']]]) === false, 'errors ⇒ fallo');
    });

    $t->test('sin _ok, un cuerpo con type sigue siendo fallo (antipatron hermano unificado)', function ($t) {
        $t->assert(TPV_Sync_API_Client::fueBien(['type' => 'https://api.tpv/errors/not_found']) === false,
            'Cuatro sitios miraban type y acertaban por accidente: el fallback los cubre');
    });

    $t->test('sin _ok, un cuerpo limpio es exito', function ($t) {
        $t->assert(TPV_Sync_API_Client::fueBien(['data' => []]) === true, 'Sin senal de error ⇒ exito');
    });

    $t->test('un no-array nunca es exito', function ($t) {
        $t->assert(TPV_Sync_API_Client::fueBien(null) === false, 'null no es exito');
        $t->assert(TPV_Sync_API_Client::fueBien('') === false, 'string vacio no es exito');
    });

    $t->test('_ok=false MANDA aunque el cuerpo parezca limpio', function ($t) {
        // El corazon del fix: un 500 con cuerpo {} tiene toda la pinta de exito
        // si solo se mira el cuerpo. El status es lo que lo desmiente.
        $t->assert(TPV_Sync_API_Client::fueBien(['_ok' => false, '_status' => 500]) === false,
            'El status prevalece sobre la ausencia de senales en el cuerpo');
    });
}
