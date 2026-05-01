<?php
/**
 * Endpoint de test E2E — permite disparar hooks WC→TPV desde un script externo
 * para validar la sincronía completa WC → TPV con HTTP real.
 *
 * SEGURIDAD: solo responde si se presenta el secret correcto en el header
 * X-Test-Secret (generado aleatoriamente la primera vez y persistido en
 * options tpv_sync_e2e_trigger_secret). El script está deshabilitado si
 * no se fija TPV_SYNC_E2E_ENABLED=1 en wp-config.php.
 *
 * Uso del test:
 *   curl -H "X-Test-Secret: XXX" "https://wp/wp-content/plugins/tpv-sync/tests/e2e_trigger.php?op=create&post_id=123"
 *
 * Operaciones:
 *   op=create  → woocommerce_new_product    hook
 *   op=update  → woocommerce_update_product hook
 *   op=trash   → wp_trash_post              hook
 *   op=untrash → untrashed_post             hook
 *   op=delete  → before_delete_post         hook
 */

// Cargar WordPress
$wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($wpLoad)) {
    http_response_code(500);
    exit('wp-load.php no encontrado');
}
require_once $wpLoad;

header('Content-Type: application/json');

if (!defined('TPV_SYNC_E2E_ENABLED') || TPV_SYNC_E2E_ENABLED !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'E2E disabled — set TPV_SYNC_E2E_ENABLED=true in wp-config.php (DEV ONLY)']);
    exit;
}

// Hardening: incluso si el flag está true, exigir que el entorno NO sea producción
// oficial. Si WP_ENV/WP_ENVIRONMENT_TYPE indica producción, negar siempre.
$envType = defined('WP_ENVIRONMENT_TYPE')
    ? WP_ENVIRONMENT_TYPE
    : (getenv('WP_ENV') ?: 'production');
if (in_array($envType, ['production', 'prod'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'E2E trigger not allowed in production environment']);
    exit;
}

// Auto-generar secret si no existe
$secret = get_option('tpv_sync_e2e_trigger_secret', '');
if (!$secret) {
    $secret = bin2hex(random_bytes(32));
    update_option('tpv_sync_e2e_trigger_secret', $secret, false);
}

$provided = $_SERVER['HTTP_X_TEST_SECRET'] ?? '';
if (!hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'bad secret', 'hint' => 'run with ?show_secret=1 on same host to reveal']);
    exit;
}

$op     = sanitize_key($_GET['op'] ?? '');
$postId = (int)($_GET['post_id'] ?? 0);

if (!$postId || !in_array($op, ['create', 'update', 'trash', 'untrash', 'delete'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad op or missing post_id']);
    exit;
}

// Disparar manualmente la acción correspondiente
try {
    switch ($op) {
        case 'create':
            do_action('woocommerce_new_product', $postId);
            break;
        case 'update':
            do_action('woocommerce_update_product', $postId);
            break;
        case 'trash':
            do_action('wp_trash_post', $postId);
            break;
        case 'untrash':
            do_action('untrashed_post', $postId);
            break;
        case 'delete':
            do_action('before_delete_post', $postId, get_post($postId));
            break;
    }
    $tpvId = (int)get_post_meta($postId, '_tpv_product_id', true);
    echo json_encode(['ok' => true, 'op' => $op, 'post_id' => $postId, 'tpv_id' => $tpvId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
