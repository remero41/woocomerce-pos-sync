<?php
declare(strict_types=1);
/**
 * BUG-F — con un secret invalido el chip se pone rojo, pero el motivo se pierde.
 *
 * Verificado en el banco vivo (uuuuis.remero.io, 2026-08-26) tras cambiar el
 * secret por uno invalido: el chip pasa a rojo correctamente (ese es el fix de
 * BUG-A funcionando), pero el texto dice "Sin conexion con el TPV. Comprueba
 * que el TPV esta accesible" y "el TPV no responde. Verifica que el TPV este
 * online" — y el TPV estaba perfectamente online. Manda al comerciante a
 * mirar donde no es.
 *
 * La cadena, verificada eslabon a eslabon:
 *   1. La API responde 401 {"errors":[{"error":"invalid_client"}]} al pedir
 *      el token. Comprobado con curl contra elc21f74e.remero.io.
 *   2. authenticate() (class-api-client.php:51) pide /auth/token con
 *      wp_remote_post DIRECTO: no pasa por parse().
 *   3. Al no haber access_token lanza RuntimeException con un mensaje
 *      generico, sin distinguir "credenciales malas" de "servidor caido".
 *   4. resolveConnectionState() hace catch (Throwable) { $ok = false; }:
 *      la causa se traga.
 *   5. flagInvalidCredentials() NUNCA se llama: vive en parse(), que ademas
 *      excluye explicitamente /auth/token (class-api-client.php:517).
 * ⇒ el estado es correcto ('down'), el diagnostico se pierde.
 *
 * Consecuencia: el conector SABE que las credenciales estan rotas y no lo
 * dice. La UI cae al texto por defecto ("el TPV no responde") y el comerciante
 * revisa su servidor en vez de repegar el secret.
 */

require_once dirname(__DIR__) . '/includes/class-api-client.php';

function run_auth_401_diagnostico_tests(WooTestRunner $t): void
{
    $t->suite('BUG-F — 401 invalid_client: el motivo debe sobrevivir');

    // El cuerpo REAL del 401 de /auth/token. Copiado de la respuesta de la
    // API en el banco vivo, no inventado:
    //   curl -X POST .../auth/token -d '{"client_id":"banco-woo",
    //        "client_secret":"SECRET-INVALIDO-PRUEBA-CHIP", ...}'
    //   → 401 {"errors":[{"error":"invalid_client",
    //          "message":"Invalid client credentials.", "request_id":"..."}]}
    $body401 = [
        'errors' => [[
            'error'      => 'invalid_client',
            'message'    => 'Invalid client credentials.',
            'request_id' => '7f0a1b8048ac77c2',
        ]],
    ];

    $t->test('la premisa: la API dice invalid_client con todas las letras', function ($t) use ($body401) {
        $t->assert(($body401['errors'][0]['error'] ?? '') === 'invalid_client',
            'Si esto cayera, el plugin no podria distinguir el caso ni queriendo');
    });

    $t->test('un 401 es un fallo (decide/fueBien lo ven)', function ($t) use ($body401) {
        $r = TPV_Sync_API_Client::decide(401, $body401);
        $t->assert(($r['_status'] ?? null) === 401, '_status debe ser 401');
        $t->assert(TPV_Sync_API_Client::fueBien($r) === false, 'un 401 no es exito');
    });

    // El corazon del bug: el 401 de /auth/token NO llega a parse(), asi que
    // nadie levanta el flag. Se comprueba sobre el FUENTE, que es donde vive
    // la decision — no se puede ejercer authenticate() sin red.
    $t->test('parse() excluye /auth/token, que es donde ocurre el 401 de credenciales', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-api-client.php');
        $excluye = str_contains($src, "\$code === 401 && \$path !== '/auth/token'");
        $t->assert($excluye,
            'Si esta linea cambia, revisar este test: era la exclusion que dejaba ' .
            'el 401 de credenciales fuera del unico sitio que levanta el flag');
    });

    $t->test('authenticate() pide el token SIN pasar por parse()', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-api-client.php');
        // El bloque de authenticate(): wp_remote_post directo al /auth/token.
        $t->assert(str_contains($src, "wp_remote_post(\$this->baseUrl . '/auth/token'"),
            'authenticate() usa wp_remote_post directo');
        // Y su unica salida ante un cuerpo sin token es una excepcion generica.
        $t->assert(str_contains($src, "'TPV API: no se obtuvo token. '"),
            'el fallo de auth sale como RuntimeException con mensaje generico');
    });

    // EL FIX: authenticate() marca la causa antes de lanzar, para que
    // resolveConnectionState() y la UI puedan decir "credenciales invalidas"
    // en vez de "el TPV no responde".
    // La UI: con las credenciales rotas NO debe afirmar que el TPV esta caido.
    // Validado en el banco vivo (captura 2026-08-26): salian TRES mensajes
    // rojos y DOS se contradecian — el banner correcto ("no reconoce tus
    // credenciales") enterrado entre dos que mandaban a revisar el servidor.
    $t->test('la UI no dice "el TPV no responde" cuando el fallo son las credenciales', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');

        $t->assert(str_contains($src, '$badCreds'),
            'debe existir un flag que distinga credenciales rotas de TPV caido');

        // El texto de "TPV caido" solo puede salir si NO son las credenciales.
        $tpvCaido = 'La sincronización está activa pero el TPV no responde';
        $pos = strpos($src, $tpvCaido);
        $t->assert($pos !== false, 'el texto de TPV caido sigue existiendo (es valido en su caso)');

        // Y debe ir precedido por la rama del ternario que lo condiciona.
        $contexto = substr($src, max(0, $pos - 400), 400);
        $t->assert(str_contains($contexto, '$badCreds'),
            'el texto "el TPV no responde" debe estar condicionado a que NO sean las credenciales');

        // El texto correcto para el caso de credenciales debe existir.
        $t->assert(str_contains($src, 'El TPV responde, pero rechaza estas credenciales'),
            'debe haber un texto que diga la verdad: el TPV responde, lo que falla es el secret');

        // AMBITO: que el texto exista no basta. La primera version de este fix
        // condiciono la tarjeta con una $badCreds definida en render_page(),
        // mientras la tarjeta se pinta en render_home_tab() — otro metodo, otro
        // ambito: el ternario evaluaba null y seguia saliendo "el TPV no
        // responde" con el TPV online. Validado en el banco vivo, captura de
        // las 17:11. El flag debe definirse DENTRO del metodo que lo usa.
        $ini = strpos($src, 'private function render_home_tab');
        $t->assert($ini !== false, 'render_home_tab debe existir');
        $fin = strpos($src, 'El TPV responde, pero rechaza estas credenciales', (int) $ini);
        $t->assert($fin !== false, 'el texto de credenciales debe estar dentro de render_home_tab');
        $cuerpo = substr($src, (int) $ini, (int) $fin - (int) $ini);
        $t->assert(str_contains($cuerpo, '$badCreds    = is_array(') || str_contains($cuerpo, '$badCreds = is_array('),
            '$badCreds debe CALCULARSE dentro de render_home_tab: definirla en otro ' .
            'metodo la deja fuera de ambito y el ternario evalua null (falsy)');
    });

    $t->test('authenticate() marca invalid_client antes de lanzar la excepcion', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-api-client.php');
        // Recorte de authenticate(): desde la firma hasta el cierre del metodo.
        $ini = strpos($src, 'wp_remote_post($this->baseUrl . \'/auth/token\'');
        $fin = strpos($src, 'return $this->token;', (int) $ini);
        $auth = substr($src, (int) $ini, max(0, (int) $fin - (int) $ini));
        // Buscar la LLAMADA, no la mencion: el bloque lleva un comentario que
        // nombra flagInvalidCredentials() para explicar el bug, y un
        // str_contains a secas se daba por satisfecho con el comentario —
        // el test sobrevivia a borrar la llamada de verdad.
        $sinComentarios = preg_replace('~//[^
]*~', '', $auth) ?? $auth;
        $marca = str_contains($sinComentarios, 'TPV_Sync_Secrets::flagInvalidCredentials(');
        $t->assert($marca,
            'authenticate() debe llamar a flagInvalidCredentials() ante un 401 ' .
            'invalid_client: es el UNICO punto donde ese 401 es visible.');
    });
}
