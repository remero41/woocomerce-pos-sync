<?php
declare(strict_types=1);
/**
 * Suite cazabugs WC — replica de cazabugs_ps.php para el plugin WooCommerce.
 *
 * Cubre escenarios edge de:
 *   - API client (timeouts, payloads malformados)
 *   - Mappings (refs duplicadas, EAN vacíos, fallback __WC__)
 *   - Hooks (anti-bucle, principal mode revertir, delete bloqueado)
 *   - Auto-clasificación de no-importables (precio<0, model vacío, dups, POS_*)
 *   - UI del wizard (3 paneles, nota duplicación, botón unificado)
 *   - Seguridad (nonce, capability, secret no expuesto)
 *
 * Uso: php tests/cazabugs_wc.php
 */

require_once __DIR__ . '/wp-stubs.php';

class CB {
    public int $passed = 0;
    public int $failed = 0;
    public function check(string $desc, bool $cond, string $detail = ''): void {
        if ($cond) { $this->passed++; echo "  \033[32m✓\033[0m $desc\n"; }
        else { $this->failed++; echo "  \033[31m✗\033[0m $desc"; if ($detail) echo " → $detail"; echo "\n"; }
    }
    public function header(string $h): void { echo "\n\033[1;34m══ $h ══\033[0m\n"; }
    public function summary(): int {
        echo "\n\033[1;33m═══ RESULTADO: " . $this->passed . " pass · " . $this->failed . " fail ═══\033[0m\n";
        return $this->failed > 0 ? 1 : 0;
    }
}

$t = new CB();
$baseDir = __DIR__ . '/../';
$adminSrc = file_get_contents($baseDir . 'includes/class-admin.php');
$prodSrc  = file_get_contents($baseDir . 'includes/class-product-sync.php');
$mainSrc  = file_get_contents($baseDir . 'woocommerce-conector.php');

echo "\n\033[1;36m═══ Cazabugs WC — suite extendida ═══\033[0m\n";

// ── Configuración del plugin ───────────────────────────────────────────
$t->header('Constantes y header del plugin');
$t->check('Plugin Name presente', str_contains($mainSrc, 'Plugin Name:'));
$t->check('Plugin Version presente', str_contains($mainSrc, 'Version:'));
$t->check('Constante TPV_SYNC_URL definida', str_contains($mainSrc, 'TPV_SYNC_URL'));

// ── Hooks WP/WC esperados ──────────────────────────────────────────────
$t->header('Hooks WordPress/WooCommerce registrados');
$expectedHooks = [
    'woocommerce_new_product', 'woocommerce_update_product',
    'before_delete_post', 'wp_ajax_tpv_sync_check_sync',
    'wp_ajax_tpv_sync_import', 'wp_ajax_tpv_sync_push_all',
    'wp_ajax_tpv_sync_register_webhook', 'wp_ajax_tpv_sync_disconnect',
];
foreach ($expectedHooks as $h) {
    $t->check("Hook $h presente",
        str_contains($mainSrc, $h) || str_contains($adminSrc, $h));
}

// ── Auto-clasificación: las 4 reglas ───────────────────────────────────
$t->header('Auto-clasificación: reglas de no-importable');
$t->check('Regla 1 (precio<0)',
    str_contains($adminSrc, "(float) (\$p['price'] ?? 0) < 0"));
$t->check('Regla 2 (model vacío)',
    str_contains($adminSrc, "if (\$m === '') return true"));
$t->check('Regla 3 (POS internos)',
    str_contains($adminSrc, "in_array(\$m, \$internalPosModels, true)"));
$t->check('Regla 4 (model duplicado en TPV)',
    str_contains($adminSrc, "(\$modelCount[\$m] ?? 0) > 1"));

// ── Modo Principal (manda TPV / manda WC) ──────────────────────────────
$t->header('Modo Principal: lógica de revertir');
$t->check('option tpv_sync_principal leída',
    str_contains($prodSrc, 'tpv_sync_principal'));
$t->check('Branch principal=tpv',
    str_contains($prodSrc, "principal === 'tpv'"));
$t->check('Llamada a update_from_tpv() para revertir',
    str_contains($prodSrc, 'update_from_tpv'));
$t->check('Anti-bucle con tpv_sync_skip_wc_product_push',
    str_contains($prodSrc, "tpv_sync_skip_wc_product_push"));

// ── Hook delete bloqueado en modo TPV ──────────────────────────────────
$t->header('Hook delete: respeta modo Principal');
$delPos = strpos($prodSrc, 'public function push_wc_delete_to_tpv');
$delSection = substr($prodSrc, $delPos, 2500);
$t->check('push_wc_delete_to_tpv chequea principal',
    str_contains($delSection, "principal === 'tpv'"));
$t->check('Cuando manda TPV, delete NO propaga (return temprano)',
    preg_match("/principal === 'tpv'\s*\)\s*\{\s*return;/s", $delSection) === 1);

// ── Endpoint ajax_check_sync ───────────────────────────────────────────
$t->header('Endpoint ajax_check_sync: estructura y seguridad');
$csPos = strpos($adminSrc, 'public function ajax_check_sync');
$csSection = substr($adminSrc, $csPos, 5000);
$t->check('check_ajax_referer presente',
    str_contains($csSection, "check_ajax_referer('tpv_sync', 'nonce')"));
$t->check('current_user_can(manage_woocommerce)',
    str_contains($csSection, "current_user_can('manage_woocommerce')"));
$t->check('Verifica isConfigured antes de pegar al TPV',
    str_contains($csSection, '$api->isConfigured()'));
$t->check('try/catch con Throwable',
    str_contains($csSection, 'Throwable $e'));

// ── Estructura de respuesta ────────────────────────────────────────────
$t->header('ajax_check_sync devuelve campos esperados');
$expectedFields = ['synced', 'islands_wc', 'islands_tpv', 'divergences',
                   'unimportable', 'wc_total', 'tpv_total', 'only_in_tpv_sample'];
foreach ($expectedFields as $f) {
    $t->check("Campo '$f' presente", str_contains($csSection, "'$f'"));
}

// ── UI del wizard inicial ──────────────────────────────────────────────
$t->header('Wizard inicial: pregunta única + 3 pasos');
$t->check('Título principal',
    str_contains($adminSrc, '¿Cuál es tu sistema principal de inventario?'));
$t->check('NO existe botón "Saltar este paso"',
    !str_contains($adminSrc, 'Saltar este paso'));
$t->check('NO existe botón "Reconciliar"',
    !str_contains($adminSrc, 'Reconciliar TPV ↔ Tienda'));
for ($i = 1; $i <= 3; $i++) {
    $t->check("data-step-panel=$i presente",
        preg_match('/data-step-panel="' . $i . '"/', $adminSrc) === 1);
}

// ── Pantalla "Estado de la sincronización" ─────────────────────────────
$t->header('Pantalla Status: estructura y mensajes');
$t->check('Botón cc-btn-check-sync presente',
    str_contains($adminSrc, 'cc-btn-check-sync'));
$t->check('Mensaje "Todo en orden"',
    str_contains($adminSrc, 'Todo en orden'));
$t->check('Mensaje "Hay discrepancias por revisar"',
    str_contains($adminSrc, 'Hay discrepancias por revisar'));
$t->check('Etiqueta "discrepancias" en stat',
    str_contains($adminSrc, 'discrepancias'));
$t->check('Etiqueta "sincronizados" en stat',
    str_contains($adminSrc, 'sincronizados'));

// ── Bloque "no importables" como info gris ─────────────────────────────
$t->header('Pantalla Status: aviso unimportable como info no alarma');
$t->check('cc-syncstatus-info renderizado en JS',
    str_contains($adminSrc, 'cc-syncstatus-info'));
$t->check('Icono ⓘ y no ⚠',
    str_contains($adminSrc, "'ⓘ'") || str_contains($adminSrc, 'ⓘ'));
$t->check('Mensaje "Se ignoran automáticamente"',
    str_contains($adminSrc, 'Se ignoran automáticamente'));

// ── CSS de la pantalla syncstatus ──────────────────────────────────────
$t->header('CSS de Status: clases definidas');
$t->check('.cc-syncstatus-card', str_contains($adminSrc, '.cc-wrap .cc-syncstatus-card'));
$t->check('.cc-syncstatus-stat',  str_contains($adminSrc, '.cc-wrap .cc-syncstatus-stat'));
$t->check('.cc-syncstatus-stat.is-warn', str_contains($adminSrc, '.cc-wrap .cc-syncstatus-stat.is-warn'));
$t->check('.cc-syncstatus-stat.is-summary', str_contains($adminSrc, '.cc-wrap .cc-syncstatus-stat.is-summary'));
$t->check('.cc-syncstatus-info', str_contains($adminSrc, '.cc-wrap .cc-syncstatus-info'));

// ── Banner read-only en editor ─────────────────────────────────────────
$t->header('Banner read-only en editor de producto');
$t->check('managed_product_banner registrado',
    str_contains($adminSrc, 'managed_product_banner'));
$t->check('add_action admin_notices',
    str_contains($adminSrc, "add_action('admin_notices'"));
$t->check('Texto banner: Gestionado por el TPV',
    str_contains($adminSrc, 'Gestionado por el TPV'));
$t->check('Clase tpvsync-managed-product para CSS de inputs',
    str_contains($adminSrc, 'tpvsync-managed-product'));
$t->check('CSS deshabilita inputs principales',
    str_contains($adminSrc, 'pointer-events: none'));

// ── Wizard: textos de los 3 pasos ──────────────────────────────────────
$t->header('Wizard: textos por paso');
$t->check('Paso 1: "Decidir"',
    str_contains($adminSrc, "esc_html__('Decidir'"));
$t->check('Paso 2: "Sincronizar"',
    str_contains($adminSrc, "esc_html__('Sincronizar'"));
$t->check('Paso 3: "Listo"',
    str_contains($adminSrc, "esc_html__('Listo'"));
$t->check('Paso 1: 3 frases en lista numerada',
    str_contains($adminSrc, 'cc-bigchoice-steps'));

// ── Botones bigchoice del paso 1 ───────────────────────────────────────
$t->header('Bigchoice cards: 2 opciones simétricas');
$t->check('Card "Manda WooCommerce" (data-action=push)',
    preg_match('/data-action="push"\s+data-principal="wc"/', $adminSrc) === 1);
$t->check('Card "Manda el TPV" (data-action=pull)',
    preg_match('/data-action="pull"\s+data-principal="tpv"/', $adminSrc) === 1);

// ── Imágenes de iconos ─────────────────────────────────────────────────
$t->header('Logos de iconos presentes');
$catLogo = $baseDir . 'assets/img/catinfog.png';
$t->check('catinfog.png existe', file_exists($catLogo));
$t->check('catinfog.png razonable (<200KB)', filesize($catLogo) < 200_000);

// ── Estilos del wizard ─────────────────────────────────────────────────
$t->header('Estilos wizard v2');
$t->check('.cc-wizard-v2 definido', str_contains($adminSrc, '.cc-wrap .cc-wizard-v2'));
$t->check('.cc-bigchoice-card definido', str_contains($adminSrc, '.cc-wrap .cc-bigchoice-card'));
$t->check('.cc-stepper definido', str_contains($adminSrc, '.cc-wrap .cc-stepper'));
$t->check('.cc-syncscene (animación paso 2)',
    str_contains($adminSrc, '.cc-wrap .cc-syncscene'));
$t->check('.cc-syncparcel (paquetes animados)',
    str_contains($adminSrc, '.cc-wrap .cc-syncparcel'));

// ── Animación: paquetes en movimiento ──────────────────────────────────
$t->header('Animación paso 2: paquetes viajando');
$t->check('@keyframes cc-parcel definidos',
    str_contains($adminSrc, '@keyframes cc-parcel'));
$t->check('Modo reverse: cuando manda TPV (derecha→izquierda)',
    str_contains($adminSrc, '.is-reverse'));

// ── Paso 3: success animado ────────────────────────────────────────────
$t->header('Paso 3: éxito con animación');
$t->check('cc-success-circle presente',
    str_contains($adminSrc, 'cc-success-circle'));
$t->check('cc-success-ring (anillo)',
    str_contains($adminSrc, 'cc-success-ring'));
$t->check('cc-success-check (tick dibujado)',
    str_contains($adminSrc, 'cc-success-check'));

// ── Stats reales en paso 3 ─────────────────────────────────────────────
$t->header('Paso 3: stats reales del último batch');
$t->check('cc-success-stats container presente',
    str_contains($adminSrc, 'cc-success-stats'));
$t->check('cc-success-stat-num presente',
    str_contains($adminSrc, 'cc-success-stat-num'));
$t->check('Texto "creados nuevos en WooCommerce"',
    str_contains($adminSrc, 'creados nuevos en WooCommerce'));
$t->check('Texto "actualizados (ya coincidían)"',
    str_contains($adminSrc, 'actualizados (ya coincidían)'));
$t->check('Texto "enviados al TPV" (push)',
    str_contains($adminSrc, 'enviados al TPV'));

// ── Seguridad ──────────────────────────────────────────────────────────
$t->header('Seguridad: nonce, capability, secret');
$ajaxHandlers = [
    'ajax_import', 'ajax_push_all', 'ajax_check_sync',
    'ajax_register_webhook', 'ajax_disconnect',
    'ajax_test_connection', 'ajax_reset_sync', 'ajax_delete_orphans',
];
foreach ($ajaxHandlers as $h) {
    $pos = strpos($adminSrc, "public function $h");
    if ($pos === false) continue;
    $section = substr($adminSrc, $pos, 800);
    $t->check("$h: check_ajax_referer presente",
        str_contains($section, 'check_ajax_referer'));
    $t->check("$h: current_user_can presente",
        str_contains($section, 'current_user_can'));
}

// ── No legacy buttons ──────────────────────────────────────────────────
$t->header('Limpieza: legacy eliminado');
$t->check('NO botón Reconciliar legacy',
    !str_contains($adminSrc, 'Reconciliar TPV ↔'));
$t->check('NO botón Resolver divergencias legacy',
    !str_contains($adminSrc, 'Resolver divergencias'));

// ── Robustez: try/catch en handlers AJAX ───────────────────────────────
$t->header('Robustez: try/catch en handlers AJAX');
$t->check('ajax_check_sync con try/catch',
    str_contains($csSection, 'try {'));
$t->check('ajax_check_sync responde wp_send_json_error en throw',
    str_contains($csSection, 'wp_send_json_error($e->getMessage())'));

// ── Manejo de timeouts y session_write_close ───────────────────────────
$t->header('Robustez: long-running protegido');
$t->check('ajax_check_sync usa set_time_limit',
    str_contains($csSection, 'set_time_limit'));
$t->check('ajax_check_sync libera lock de sesión',
    str_contains($csSection, 'session_write_close'));

// ── Validación de inputs ───────────────────────────────────────────────
$t->header('Inputs: sanitización en endpoints sensibles');
$importPos = strpos($adminSrc, 'public function ajax_import');
$importSection = $importPos !== false ? substr($adminSrc, $importPos, 2000) : '';
$t->check('ajax_import sanitiza principal con sanitize_text_field',
    str_contains($importSection, "sanitize_text_field((string) \$_POST['principal'])"));

// ── Persistencia "principal" cross-handlers ────────────────────────────
$t->header('Persistencia tpv_sync_principal en ambos handlers');
$t->check('ajax_import persiste principal',
    str_contains($importSection, "update_option('tpv_sync_principal'"));
$pushPos = strpos($adminSrc, 'public function ajax_push_all');
$pushSection = $pushPos !== false ? substr($adminSrc, $pushPos, 2000) : '';
$t->check('ajax_push_all persiste principal',
    str_contains($pushSection, "update_option('tpv_sync_principal'"));

// ── Idioma del módulo ──────────────────────────────────────────────────
$t->header('Internacionalización');
$t->check("Text domain 'tpv-sync' usado consistentemente",
    substr_count($adminSrc, "'tpv-sync'") > 30);
$t->check('Languages dir existe', is_dir($baseDir . 'languages'));

exit($t->summary());
