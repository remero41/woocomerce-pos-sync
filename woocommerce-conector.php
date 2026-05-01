<?php
declare(strict_types=1);
/**
 * Plugin Name: Catinfog Conector
 * Plugin URI:  https://catinfog.com
 * Description: Conecta tu tienda WooCommerce con el TPV Catinfog. Sincroniza productos, stock, ventas y devoluciones en tiempo real.
 * Version:     2.0.0
 * Author:      Catinfog
 * Text Domain: tpv-sync
 * Domain Path: /languages
 * Requires WC: 7.0
 */
defined('ABSPATH') || exit;

define('TPV_SYNC_VERSION', '2.0.0');
define('TPV_SYNC_DIR',     plugin_dir_path(__FILE__));
define('TPV_SYNC_URL',     plugin_dir_url(__FILE__));

// ─── Autoload ─────────────────────────────────────────────────────────────────

require_once TPV_SYNC_DIR . 'includes/class-secrets.php';
require_once TPV_SYNC_DIR . 'includes/class-circuit-breaker.php';
require_once TPV_SYNC_DIR . 'includes/class-api-client.php';
require_once TPV_SYNC_DIR . 'includes/class-product-sync.php';
require_once TPV_SYNC_DIR . 'includes/class-order-sync.php';
require_once TPV_SYNC_DIR . 'includes/class-customer-sync.php';
require_once TPV_SYNC_DIR . 'includes/class-webhook-handler.php';
require_once TPV_SYNC_DIR . 'includes/class-queue.php';
require_once TPV_SYNC_DIR . 'includes/class-notifications.php';
require_once TPV_SYNC_DIR . 'includes/class-admin.php';

// WP-CLI commands (solo si estamos en contexto WP-CLI).
if (defined('WP_CLI') && WP_CLI) {
    require_once TPV_SYNC_DIR . 'includes/class-cli.php';
}

// ─── Helpers de configuración de módulos ─────────────────────────────────────

function tpv_sync_module_catalog(): bool
{
    return (bool) get_option('tpv_sync_module_catalog', 1);
}

function tpv_sync_module_orders(): bool
{
    return (bool) get_option('tpv_sync_module_orders', 0);
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────

class TPV_Sync
{
    private static ?TPV_Sync $instance = null;

    public TPV_Sync_API_Client    $api;
    public TPV_Sync_Product_Sync  $products;
    public TPV_Sync_Order_Sync    $orders;
    public TPV_Sync_Customer_Sync $customers;
    public TPV_Sync_Webhook       $webhooks;
    public TPV_Sync_Queue         $queue;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->api       = new TPV_Sync_API_Client();
        $this->products  = new TPV_Sync_Product_Sync($this->api);
        $this->orders    = new TPV_Sync_Order_Sync($this->api);
        $this->customers = new TPV_Sync_Customer_Sync($this->api);
        $this->webhooks  = new TPV_Sync_Webhook($this->products, $this->orders, $this->api);
        $this->queue     = new TPV_Sync_Queue($this->api, $this->products, $this->orders);

        // Webhook endpoint TPV → WC (siempre activo si hay credenciales)
        add_action('init',               [$this, 'register_webhook_endpoint']);
        add_action('template_redirect',  [$this, 'handle_webhook_request']);

        // ── WC → TPV: pedidos y devoluciones (SIEMPRE activo) ─────────────────
        // Cada venta y cada devolución de WC se integra en el TPV como pedido
        // o devolución nativa. No depende del flag del módulo pedidos — es el
        // comportamiento estricto pedido.
        add_action('woocommerce_payment_complete',         [$this->orders, 'send_to_tpv']);
        add_action('woocommerce_order_status_processing',  [$this->orders, 'send_to_tpv']);
        add_action('woocommerce_order_status_changed',     [$this->orders, 'on_wc_status_changed'], 10, 3);

        // Refund creado en WC → POST /orders/{tpv_order_id}/returns
        add_action('woocommerce_order_refunded',           [$this->orders, 'on_wc_refund'], 10, 2);

        // ── Stock estricto WC → TPV ───────────────────────────────────────────
        // Cualquier cambio de stock en WC (admin, otros plugins, etc.) se
        // replica al TPV. El descuento por venta lo gestiona el TPV cuando
        // registra el pedido enviado arriba, así que NO se llama a
        // deduct_stock_from_wc_order (evita doble descuento).
        add_action('woocommerce_product_object_updated_props',
            [$this->products, 'push_wc_stock_change'], 10, 2);

        // ── Catálogo WC → TPV (módulo catálogo) ───────────────────────────────
        // Cuando el admin edita/crea/borra un producto en WC, empujamos al TPV.
        // Usamos woocommerce_update_product (dispara tras guardar desde el admin
        // y vía REST). Save_post genérico no vale — dispara con cualquier cambio
        // de meta y crea bucles.
        if (tpv_sync_module_catalog()) {
            add_action('woocommerce_new_product',      [$this->products, 'push_wc_product_to_tpv'], 10, 1);
            add_action('woocommerce_update_product',   [$this->products, 'push_wc_product_to_tpv'], 10, 1);
            // Trash/untrash → status=0/1 en TPV (oculto/visible)
            add_action('wp_trash_post',                [$this->products, 'push_wc_trash_to_tpv'],   10, 1);
            add_action('untrashed_post',               [$this->products, 'push_wc_untrash_to_tpv'], 10, 1);
            // Delete permanente → DELETE /products/{id} (el TPV internamente
            // mantiene el histórico — ver ProductController::delete).
            add_action('before_delete_post',           [$this->products, 'push_wc_delete_to_tpv'],  10, 1);
        }

        // ── Clientes WC → TPV ────────────────────────────────────────────────
        // Cubrimos los puntos donde WC/WP actualiza datos de un customer:
        //   - user_register: signup nuevo (front, admin, REST).
        //   - profile_update: wp_update_user core (email, nombre).
        //   - woocommerce_customer_save_address: cliente edita dirección
        //     en Mi Cuenta. Los update_user_meta sueltos de billing_* NO
        //     disparan profile_update, así que necesitamos este hook.
        //   - woocommerce_customer_object_updated_props: WC API REST y
        //     cambios via WC_Customer object (admin → editar usuario WC,
        //     plugins de import, etc.).
        //   - delete_user: borrado permanente.
        // El plugin filtra dentro a usuarios con rol 'customer' para no
        // empujar admins/editores/suscriptores genéricos al TPV.
        add_action('user_register',                       [$this->customers, 'push_wc_user_to_tpv'],        10, 1);
        add_action('profile_update',                      [$this->customers, 'push_wc_user_to_tpv'],        10, 1);
        add_action('woocommerce_customer_save_address',   [$this->customers, 'push_wc_user_to_tpv'],        10, 1);
        add_action('woocommerce_customer_object_updated_props', function ($customer) {
            if (is_object($customer) && method_exists($customer, 'get_id')) {
                TPV_Sync::instance()->customers->push_wc_user_to_tpv((int)$customer->get_id());
            }
        }, 10, 1);
        add_action('delete_user',                         [$this->customers, 'push_wc_user_delete_to_tpv'], 10, 1);
    }

    public function register_webhook_endpoint(): void
    {
        add_rewrite_rule('^tpv-webhook/?$', 'index.php?tpv_webhook=1', 'top');
        add_rewrite_tag('%tpv_webhook%', '1');
    }

    public function handle_webhook_request(): void
    {
        if (!get_query_var('tpv_webhook')) return;
        $this->webhooks->handle();
        exit;
    }
}

// ─── Activación / desactivación ───────────────────────────────────────────────

// ─── Cron de reconciliación semanal ──────────────────────────────────────────

add_action('tpv_sync_reconcile', function () {
    if (!class_exists('TPV_Sync')) return;
    TPV_Sync::instance()->products->reconcile(100);
});

// ─── Cron de fallback queue: procesa pending cada minuto ─────────────────────

add_action('tpv_sync_queue_process', function () {
    if (!class_exists('TPV_Sync')) return;
    TPV_Sync::instance()->queue->process(20);
});

// ─── Cron de purga de queue: done/abandoned >30d cada día ───────────────────

add_action('tpv_sync_queue_purge', function () {
    if (class_exists('TPV_Sync_Webhook')) {
        TPV_Sync_Webhook::purge_idem(48);
    }
    if (!class_exists('TPV_Sync')) return;
    TPV_Sync::instance()->queue->purge(30);
});

// Schedule "every_minute" si no existe (WP no lo trae por defecto).
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = ['interval' => 60, 'display' => 'Cada minuto'];
    }
    return $schedules;
});

add_action('plugins_loaded', function () {
    if (!wp_next_scheduled('tpv_sync_reconcile')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', 'tpv_sync_reconcile');
    }
    if (!wp_next_scheduled('tpv_sync_queue_process')) {
        wp_schedule_event(time() + 60, 'every_minute', 'tpv_sync_queue_process');
    }
    if (!wp_next_scheduled('tpv_sync_queue_purge')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'tpv_sync_queue_purge');
    }
    if (!wp_next_scheduled('tpv_sync_notifications_eval')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'tpv_sync_notifications_eval');
    }

    // Creación idempotente de la tabla idempotency (plugins ya instalados antes
    // de este fix no pasaron por el activation hook).
    if (class_exists('TPV_Sync_Webhook') && get_option('tpv_sync_idem_table_v1') !== '1') {
        TPV_Sync_Webhook::create_idem_table();
        update_option('tpv_sync_idem_table_v1', '1', false);
    }
}, 20);

register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table = $wpdb->prefix . 'tpv_sync_log';
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type  VARCHAR(64)     NOT NULL,
        resource    VARCHAR(64)     NOT NULL DEFAULT '',
        resource_id INT UNSIGNED    NOT NULL DEFAULT 0,
        status      VARCHAR(16)     NOT NULL DEFAULT 'ok',
        message     TEXT            DEFAULT NULL,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event (event_type, created_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Tabla de fallback queue
    if (class_exists('TPV_Sync_Queue')) {
        TPV_Sync_Queue::create_table();
    }

    // Tabla de idempotencia webhook (PK UNIQUE, INSERT IGNORE atómico)
    if (class_exists('TPV_Sync_Webhook')) {
        TPV_Sync_Webhook::create_idem_table();
    }

    // Valores por defecto de módulos
    add_option('tpv_sync_module_catalog', 1);
    add_option('tpv_sync_module_orders',  0);

    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    $api = new TPV_Sync_API_Client();
    $webhookId = get_option('tpv_sync_webhook_id');
    if ($webhookId) {
        $api->delete("/webhooks/$webhookId");
        delete_option('tpv_sync_webhook_id');
        delete_option('tpv_sync_webhook_secret');
    }
    wp_clear_scheduled_hook('tpv_sync_reconcile');
    wp_clear_scheduled_hook('tpv_sync_queue_process');
    wp_clear_scheduled_hook('tpv_sync_queue_purge');
    wp_clear_scheduled_hook('tpv_sync_notifications_eval');
    flush_rewrite_rules();
});

register_uninstall_hook(__FILE__, 'tpv_sync_uninstall');
function tpv_sync_uninstall(): void
{
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tpv_sync_log");
    foreach ([
        'tpv_sync_api_url', 'tpv_sync_client_id', 'tpv_sync_client_secret',
        'tpv_sync_webhook_id', 'tpv_sync_webhook_secret',
        'tpv_sync_module_catalog', 'tpv_sync_module_orders',
    ] as $option) {
        delete_option($option);
    }
}

// ─── Arrancar ────────────────────────────────────────────────────────────────

add_action('plugins_loaded', function () {
    load_plugin_textdomain('tpv-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Secrets: encriptar/desencriptar auto + migrar legacy.
    if (class_exists('TPV_Sync_Secrets')) {
        TPV_Sync_Secrets::register_filters();
        // Migración oportunista: solo corre si hay algún secret legacy en plano.
        if (!get_option('tpv_sync_secrets_migrated', false)) {
            TPV_Sync_Secrets::migrate_plaintext();
            update_option('tpv_sync_secrets_migrated', 1, false);
        }
    }

    if (!class_exists('WooCommerce')) return;
    TPV_Sync::instance();
    (new TPV_Sync_Admin())->init();
});

// ─── Cron: re-sync tras CSV import en TPV ────────────────────────────────────

add_action('tpv_sync_import_all', function () {
    if (class_exists('TPV_Sync') && tpv_sync_module_catalog()) {
        TPV_Sync::instance()->products->import_all();
    }
});
