<?php
declare(strict_types=1);
/**
 * El cableado de las categorías está en su sitio (SPEC_categorias_woo_tpv.md
 * §3-§4). Lo puro ya se prueba ejecutándolo; aquí se fija que cada pieza esté
 * enganchada donde toca, leyendo el fuente (mismo estilo que test_cron_cableado).
 */
require_once dirname(__DIR__) . '/includes/class-admin.php';

function run_categorias_cableado_tests(WooTestRunner $t): void
{
    $t->suite('Categorías — cableado');
    $raiz = dirname(__DIR__);
    $prin = (string) file_get_contents("$raiz/woocommerce-conector.php");
    $hand = (string) file_get_contents("$raiz/includes/class-webhook-handler.php");
    $prod = (string) file_get_contents("$raiz/includes/class-product-sync.php");
    $adm  = (string) file_get_contents("$raiz/includes/class-admin.php");

    $t->test('la tienda se suscribe a category.*', function ($t) {
        $ev = TPV_Sync_Admin::eventosSuscritos(true);
        foreach (['category.created', 'category.updated', 'category.deleted'] as $e) {
            $t->assert(in_array($e, $ev, true), "suscrita a $e");
        }
    });
    $t->test('la revisión de la suscripción también corre en el cron', function ($t) use ($prin) {
        $t->assert((bool) preg_match("/add_action\('tpv_sync_autocurar',\s*\[\\\$tpvAdmin,\s*'revisarSuscripcionWebhook'\]/", $prin),
            'sin esperar a que alguien abra el admin');
    });
    $t->test('el cron hace la unión, aparte de autocurar', function ($t) use ($prin) {
        $t->assert((bool) preg_match("/add_action\\('tpv_sync_autocurar',\\s*function \\(\\) \\{[^}]*?->categorias->pasoDeUnion\\(\\)/s", $prin),
            'callback propio del cron tpv_sync_autocurar');
    });
    $t->test('hooks de términos registrados', function ($t) use ($prin) {
        foreach (['created_product_cat', 'edited_product_cat', 'pre_delete_term'] as $h) {
            $t->assert(str_contains($prin, "'$h'"), "hook $h");
        }
    });
    $t->test('el handler delega los avisos de categoría y se va el camino viejo', function ($t) use ($hand) {
        $t->assert(str_contains($hand, '->categorias->aplicarAviso('), 'aplicarAviso');
        $t->assert(!str_contains($hand, 'sync_category_from_tpv'), 'fuera el camino viejo por nombre');
    });
    $t->test('upsert usa el enlace por número y se quita el casado por nombre', function ($t) use ($prod) {
        $t->assert(str_contains($prod, '->categorias->ponerCategoriasDesdeTpv('), 'upsert por número');
        $t->assert(!preg_match("/get_term_by\\('name',[^;]*'product_cat'\\)/", $prod), 'ni rastro del casado de categorías por nombre');
        $t->assert(substr_count($prod, '$this->juntarCategorias = true;') >= 2, 'el volcado y la reconciliación juntan');
        $t->assert(substr_count($prod, '->categorias->paraPayload(') >= 2, 'envío suelto y bulk llevan categorías');

    });
    $t->test('reset y desconexión total olvidan los enlaces de categoría (la pausa no)', function ($t) use ($adm) {
        $t->assert(substr_count($adm, '->categorias->olvidar()') === 2, 'ajax_reset_sync y ajax_full_disconnect');
        $pausa = substr($adm, (int) strpos($adm, 'public function ajax_disconnect()'));
        $pausa = substr($pausa, 0, (int) strpos($pausa, 'public function ajax_full_disconnect()'));
        $t->assert(!str_contains($pausa, '->categorias->olvidar()'), 'ajax_disconnect (pausa) conserva los enlaces');
    });
    $t->test('el asistente une el árbol como primer paso', function ($t) use ($adm) {
        $t->assert(substr_count($adm, '->categorias->unirArbol()') >= 2, 'ajax_import y ajax_push_all');
    });
    $t->test('el panel enseña los avisos de categorías, escapados', function ($t) {
        $h = TPV_Sync_Admin::htmlAvisosCategorias(['2026-10-14 10:00 — «<b>X</b>» sin pareja']);
        $t->assert(str_contains($h, '&lt;b&gt;X&lt;/b&gt;'), 'escapa');
        $t->assertEquals('', TPV_Sync_Admin::htmlAvisosCategorias([]));
    });
}
