<?php
declare(strict_types=1);

/**
 * La URL del webhook que se da al TPV no depende de los enlaces permanentes.
 *
 * Antes era home_url('/tpv-webhook/'), que solo existe con enlaces permanentes
 * «bonitos». Con los «Simple» (los que deja `wp core install`), WordPress sirve
 * la portada con un 200 a esa ruta: el TPV daba cada evento por entregado y el
 * Woo no aplicaba ninguno, sin un error en ningún lado (lo vio la T32 de B3a en
 * el Woo de prueba de tc, 07-11-2026). Con `?tpv_webhook=1` la petición llega
 * igual con cualquier ajuste, también si la tienda los cambia después.
 *
 * Las tiendas ya conectadas tienen la ruta vieja guardada en el TPV: la
 * revisión que corre tras cada actualización la corrige con un PATCH (sin
 * rotar el secret ni dejar huérfanos), y la regla /tpv-webhook/ se queda para
 * que sigan recibiendo mientras tanto.
 */
function run_webhook_url_tests(WooTestRunner $t): void
{
    $t->suite('La URL del webhook no depende de los enlaces permanentes');

    $t->test('la URL lleva el parámetro de consulta, no una ruta', function ($t) {
        $t->assertEquals('https://tienda.test/?tpv_webhook=1', TPV_Sync_Webhook::urlDesde('https://tienda.test/'));
        $t->assertEquals('https://tienda.test/?tpv_webhook=1', TPV_Sync_Webhook::urlDesde('https://tienda.test'),
            'sin barra final, también');
        $t->assertEquals('https://tienda.test/sub/?tpv_webhook=1', TPV_Sync_Webhook::urlDesde('https://tienda.test/sub/'),
            'un WordPress en un subdirectorio conserva su ruta');
    });

    $t->test('si la portada ya trae una consulta, se añade sin pisarla', function ($t) {
        $t->assertEquals('https://tienda.test/?lang=fr&tpv_webhook=1', TPV_Sync_Webhook::urlDesde('https://tienda.test/?lang=fr'));
        $t->assertEquals('https://tienda.test/?tpv_webhook=1', TPV_Sync_Webhook::urlDesde('https://tienda.test/?tpv_webhook=1'),
            'y no se repite si ya está');
    });

    $t->test('url() es la de la portada con ?tpv_webhook=1', function ($t) {
        // En otro proceso: aquí no hay home_url() y definirla pisaría la de otros tests.
        $codigo = 'define("ABSPATH", "/"); function home_url($p = "") { return "https://tienda.test" . $p; }'
            . ' require ' . var_export(dirname(__DIR__) . '/includes/class-webhook-handler.php', true) . ';'
            . ' echo TPV_Sync_Webhook::url();';
        $salida = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1');
        $t->assertEquals('https://tienda.test/?tpv_webhook=1', $salida);
    });

    $viejo = ['url' => 'https://tienda.test/tpv-webhook/', 'events' => ['product.created', 'stock.adjusted']];
    $nueva = 'https://tienda.test/?tpv_webhook=1';

    $t->test('una tienda conectada con la ruta vieja recibe un PATCH con la URL nueva', function ($t) use ($viejo, $nueva) {
        $parche = TPV_Sync_Admin::parcheDeWebhook($viejo, ['stock.adjusted', 'product.created'], $nueva);
        $t->assertEquals(['url' => $nueva], $parche, 'solo la URL: los eventos ya están');
    });

    $t->test('la URL y los eventos que faltan van en el mismo PATCH', function ($t) use ($viejo, $nueva) {
        $queremos = ['product.created', 'stock.adjusted', 'variant.stock_adjusted'];
        $parche = TPV_Sync_Admin::parcheDeWebhook($viejo, $queremos, $nueva);
        $t->assertEquals(['events' => $queremos, 'url' => $nueva], $parche);
    });

    $t->test('con la URL buena y todos los eventos no se toca nada', function ($t) use ($nueva) {
        $actual = ['url' => $nueva, 'events' => ['product.created', 'stock.adjusted']];
        $t->assertEquals([], TPV_Sync_Admin::parcheDeWebhook($actual, ['product.created'], $nueva));
    });

    $t->test('si el TPV no devuelve la URL, no se adivina', function ($t) use ($nueva) {
        $actual = ['events' => ['product.created']];
        $t->assertEquals([], TPV_Sync_Admin::parcheDeWebhook($actual, ['product.created'], $nueva),
            'un TPV que no la enseña no recibe un PATCH a ciegas');
    });

    $raiz  = dirname(__DIR__);
    $admin = (string) file_get_contents($raiz . '/includes/class-admin.php');
    $api   = (string) file_get_contents($raiz . '/includes/class-api-client.php');
    $prin  = (string) file_get_contents($raiz . '/woocommerce-conector.php');

    $t->test('las dos altas del webhook usan la misma URL', function ($t) use ($admin, $api) {
        $t->assert(str_contains($admin, 'TPV_Sync_Webhook::url()'), 'el asistente da TPV_Sync_Webhook::url()');
        $t->assert(str_contains($api, 'TPV_Sync_Webhook::url()'), 'el re-registro silencioso también');
        $t->assert(!str_contains($admin . $api, "home_url('/tpv-webhook/')"),
            'ninguna alta construye la ruta vieja: dos URL = una se queda atrás');
    });

    $t->test('la revisión tras actualizar corrige la URL con el PATCH', function ($t) use ($admin) {
        $f = substr($admin, (int) strpos($admin, 'public function revisarSuscripcionWebhook()'));
        $f = substr($f, 0, (int) strpos($f, "\n    }\n"));
        $t->assert(str_contains($f, 'self::parcheDeWebhook('), 'la revisión decide con parcheDeWebhook');
        $t->assert(str_contains($f, 'TPV_Sync_Webhook::url()'), 'con la URL de ahora');
        $t->assert(str_contains($f, '$api->patch("/webhooks/$webhookId", $parche)'), 'y manda ese parche');
    });

    $t->test('la regla /tpv-webhook/ sigue para las tiendas que aún la tienen guardada', function ($t) use ($prin) {
        $t->assert(str_contains($prin, "add_rewrite_rule('^tpv-webhook/?$', 'index.php?tpv_webhook=1', 'top')"),
            'quitarla dejaría sin eventos a las tiendas conectadas hasta su primera revisión');
        $t->assert(str_contains($prin, "add_rewrite_tag('%tpv_webhook%', '1')"),
            'y la etiqueta, que es la que hace pública la variable ?tpv_webhook');
    });
}
