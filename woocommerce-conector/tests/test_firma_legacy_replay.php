<?php
declare(strict_types=1);
/**
 * H10 — Una entrega firmada en formato antiguo (sha256=HMAC(cuerpo), sin
 * marca de tiempo) se puede REENVIAR para siempre: el receptor acepta la
 * firma vieja con cualquier X-Webhook-Timestamp fresco que ponga el atacante.
 *
 * Lo que debe pasar: sin marca de tiempo FIRMADA no hay anti-replay, así que
 * una firma legacy solo vale si el TPV no manda nada mejor; y desde luego una
 * cabecera de tiempo sin firmar no puede "rejuvenecer" una firma vieja.
 */
require_once dirname(__DIR__) . '/includes/class-webhook-handler.php';

function run_firma_legacy_replay_tests(WooTestRunner $t): void
{
    $t->suite('Webhook: firma legacy no se rejuvenece con una cabecera sin firmar');
    $secret  = str_repeat('s', 64);
    $cuerpo  = json_encode(['event_type' => 'stock.adjusted', 'resource_id' => 7, 'changed_fields' => ['quantity' => 0]]);
    // Lo que capturó el atacante hace un mes: firma legacy sin timestamp.
    $firmaVieja = 'sha256=' . hash_hmac('sha256', $cuerpo, $secret);

    $t->test('firma v2 (t=) con timestamp firmado: OK', function (WooTestRunner $t) use ($secret, $cuerpo) {
        $ts = time();
        $sig = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $cuerpo, $secret);
        $t->assert(TPV_Sync_Webhook::verify_signature($cuerpo, $sig, $secret, $ts), 'v2 válida');
    });

    $t->test('firma legacy + X-Webhook-Timestamp fresco del atacante: debe RECHAZARSE', function (WooTestRunner $t) use ($secret, $cuerpo, $firmaVieja) {
        $ahora = time();   // cabecera que el atacante pone a su gusto
        $t->assert(!TPV_Sync_Webhook::verify_signature($cuerpo, $firmaVieja, $secret, $ahora),
            'una firma sin timestamp no puede aceptarse como fresca por una cabecera sin firmar');
    });
}
