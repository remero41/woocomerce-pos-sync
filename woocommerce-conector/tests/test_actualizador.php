<?php
declare(strict_types=1);
/**
 * Actualizacion automatica desde GitHub Releases.
 *
 * Hasta la 2.1.0 el plugin no tenia NINGUN mecanismo de actualizacion: ni
 * `Update URI`, ni `pre_set_site_transient_update_plugins`, ni librerias.
 * WordPress solo actualiza solo lo que viene de wordpress.org, asi que una
 * tienda con la 2.0.0 se quedaba ahi para siempre salvo que alguien entrara
 * a subir el ZIP a mano, tienda por tienda.
 *
 * El repo es publico, asi que la API de releases se consulta SIN token:
 *   GET https://api.github.com/repos/remero41/woocomerce-pos-sync/releases/latest
 *
 * Lo que se prueba aqui es la DECISION —¿hay version nueva? ¿cual es el ZIP?—
 * que es donde viven los errores de verdad. La parte de WordPress (registrar
 * el filtro) no se simula: seria probar WordPress, no el plugin.
 *
 * Las comparaciones de version usan version_compare() de PHP, que entiende
 * 2.10.0 > 2.9.0 (un strcmp diria lo contrario y dejaria tiendas colgadas en
 * la 2.9 para siempre). Eso se asserta abajo explicitamente.
 */

require_once dirname(__DIR__) . '/includes/class-updater.php';

function run_actualizador_tests(WooTestRunner $t): void
{
    $t->suite('Actualizador — decidir si hay version nueva');

    // La respuesta REAL de la API, recortada a lo que se usa.
    $release = [
        'tag_name' => 'v2.1.0',
        'name'     => 'WooCommerce conector 2.1.0',
        'body'     => 'Notas de la versión',
        'html_url' => 'https://github.com/remero41/woocomerce-pos-sync/releases/tag/v2.1.0',
        'assets'   => [[
            'name' => 'woommerce-conector-2.1.0.zip',
            'browser_download_url' => 'https://github.com/remero41/woocomerce-pos-sync/releases/download/v2.1.0/woocommerce-conector-2.1.0.zip',
        ]],
    ];

    // ── La version sale del tag, sin la v ────────────────────────────────
    $t->test('el tag v2.1.0 es la version 2.1.0', function ($t) use ($release) {
        $t->assert(TPV_Sync_Updater::versionDeRelease($release) === '2.1.0',
            'la v del tag no es parte de la version');
    });

    $t->test('un tag sin v tambien vale', function ($t) {
        $t->assert(TPV_Sync_Updater::versionDeRelease(['tag_name' => '2.1.0']) === '2.1.0',
            'no todos los proyectos prefijan con v');
    });

    $t->test('sin tag no hay version (null, no cadena vacia)', function ($t) {
        $t->assert(TPV_Sync_Updater::versionDeRelease([]) === null,
            'una respuesta sin tag_name no puede pasar por version');
    });

    // ── Hay que actualizar? ──────────────────────────────────────────────
    $t->test('2.0.0 instalada + 2.1.0 publicada ⇒ hay actualizacion', function ($t) {
        $t->assert(TPV_Sync_Updater::hayQueActualizar('2.0.0', '2.1.0') === true, '2.1.0 es mas nueva');
    });

    $t->test('misma version ⇒ NO se ofrece nada', function ($t) {
        $t->assert(TPV_Sync_Updater::hayQueActualizar('2.1.0', '2.1.0') === false,
            'ofrecer la version que ya tienes es ruido en el panel de todas las tiendas');
    });

    $t->test('instalada MAS nueva que la publicada ⇒ no se degrada', function ($t) {
        $t->assert(TPV_Sync_Updater::hayQueActualizar('2.2.0', '2.1.0') === false,
            'nunca proponer bajar de version');
    });

    // El clasico: comparar versiones como texto.
    $t->test('2.10.0 es MAS nueva que 2.9.0 (no es comparacion de texto)', function ($t) {
        $t->assert(TPV_Sync_Updater::hayQueActualizar('2.9.0', '2.10.0') === true,
            'con strcmp "2.10.0" < "2.9.0" y la tienda se quedaria clavada en la 2.9 para siempre');
        $t->assert(TPV_Sync_Updater::hayQueActualizar('2.10.0', '2.9.0') === false,
            'y al reves tampoco debe degradar');
    });

    $t->test('una version rara no provoca una actualizacion fantasma', function ($t) {
        $t->assert(TPV_Sync_Updater::hayQueActualizar('2.1.0', '') === false, 'sin version publicada no hay nada que ofrecer');
        $t->assert(TPV_Sync_Updater::hayQueActualizar('2.1.0', 'latest') === false, 'un tag no numerico no es una version');
    });

    // ── El ZIP: el automatico de GitHub NO sirve ─────────────────────────
    // Ya mordio una vez (18-09): el "Source code (zip)" lleva la raiz del
    // repo con el plugin en un subdirectorio, y WordPress no lo instala.
    $t->test('se coge el ZIP del asset, no el zipball de GitHub', function ($t) use ($release) {
        $url = TPV_Sync_Updater::zipDeRelease($release);
        $t->assert($url === 'https://github.com/remero41/woocomerce-pos-sync/releases/download/v2.1.0/woocommerce-conector-2.1.0.zip',
            'url incorrecta: ' . var_export($url, true));
        $t->assert(!str_contains((string) $url, 'zipball'),
            'el zipball automatico de GitHub no es instalable: raiz del repo + plugin en subcarpeta');
    });

    $t->test('un release SIN asset no ofrece actualizacion', function ($t) {
        $t->assert(TPV_Sync_Updater::zipDeRelease(['tag_name' => 'v2.2.0', 'assets' => []]) === null,
            'sin ZIP armado no hay nada que instalar: mejor no ofrecer que romper la instalacion');
    });

    $t->test('entre varios assets se elige el .zip', function ($t) {
        $url = TPV_Sync_Updater::zipDeRelease(['assets' => [
            ['name' => 'checksums.txt', 'browser_download_url' => 'https://x/checksums.txt'],
            ['name' => 'plugin-2.2.0.zip', 'browser_download_url' => 'https://x/plugin-2.2.0.zip'],
        ]]);
        $t->assert($url === 'https://x/plugin-2.2.0.zip', 'debe elegir el zip: ' . var_export($url, true));
    });

    // ── Lo que se le entrega a WordPress ─────────────────────────────────
    $t->test('el objeto para WordPress lleva slug, version y paquete', function ($t) use ($release) {
        $info = TPV_Sync_Updater::infoParaWp($release, 'woocommerce-conector/woocommerce-conector.php');
        $t->assert($info !== null, 'con un release completo debe haber info');
        $t->assert($info->new_version === '2.1.0', 'version');
        $t->assert($info->slug === 'woocommerce-conector', 'slug');
        $t->assert(str_ends_with($info->package, '.zip'), 'el paquete es el ZIP del asset');
        $t->assert($info->plugin === 'woocommerce-conector/woocommerce-conector.php', 'ruta del plugin');
    });

    $t->test('un release inservible no genera objeto', function ($t) {
        $t->assert(TPV_Sync_Updater::infoParaWp(['tag_name' => 'v9.9.9', 'assets' => []], 'x/x.php') === null,
            'sin ZIP no se entrega nada a WordPress');
        $t->assert(TPV_Sync_Updater::infoParaWp([], 'x/x.php') === null,
            'una respuesta vacia (API caida, rate limit) no puede romper el panel');
    });

    // ── Robustez: la API de GitHub puede fallar ──────────────────────────
    // 60 peticiones/hora por IP sin token. Si falla, el panel debe seguir
    // funcionando: nunca una excepcion, nunca una actualizacion inventada.
    $t->test('respuestas basura no revientan nada', function ($t) {
        foreach ([[], ['message' => 'API rate limit exceeded'], ['tag_name' => null]] as $basura) {
            $t->assert(TPV_Sync_Updater::infoParaWp($basura, 'x/x.php') === null,
                'basura de la API: ' . json_encode($basura));
        }
    });
}
