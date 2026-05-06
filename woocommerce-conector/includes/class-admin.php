<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

class TPV_Sync_Admin
{
    public function init(): void
    {
        add_action('admin_menu',    [$this, 'add_menu']);
        add_action('admin_init',    [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('wp_ajax_tpv_sync_import',           [$this, 'ajax_import']);
        add_action('wp_ajax_tpv_sync_push_all',         [$this, 'ajax_push_all']);
        add_action('wp_ajax_tpv_sync_reset_sync',       [$this, 'ajax_reset_sync']);
        add_action('wp_ajax_tpv_sync_delete_orphans',   [$this, 'ajax_delete_orphans']);
        add_action('wp_ajax_tpv_sync_register_webhook', [$this, 'ajax_register_webhook']);
        add_action('wp_ajax_tpv_sync_disconnect',       [$this, 'ajax_disconnect']);
        add_action('wp_ajax_tpv_sync_test_connection',  [$this, 'ajax_test_connection']);
        add_action('wp_ajax_tpv_sync_check_sync',       [$this, 'ajax_check_sync']);
        add_action('wp_ajax_tpv_sync_clear_principal',  [$this, 'ajax_clear_principal']);
        add_action('wp_ajax_tpv_sync_full_disconnect',  [$this, 'ajax_full_disconnect']);
        add_action('wp_ajax_tpv_sync_count_remote',     [$this, 'ajax_count_remote']);
        add_action('wp_ajax_tpv_sync_load_tax_mapping', [$this, 'ajax_load_tax_mapping']);
        add_action('wp_ajax_tpv_sync_save_tax_mapping', [$this, 'ajax_save_tax_mapping']);
        add_action('wp_ajax_tpv_sync_push_customers',   [$this, 'ajax_push_customers']);
        add_action('admin_notices', [$this, 'managed_product_banner']);
        add_action('admin_notices', [$this, 'tax_misconfig_banner']);
    }

    /**
     * Banner global en la página del plugin si la configuración fiscal de
     * WooCommerce introduce inconsistencias con el TPV. Dos casos:
     *
     *   - Aviso (warning): calc_taxes=yes pero sin rates → ventas web a 0%,
     *     TPV físico cobra IVA. Mismatch web↔caja.
     *   - Crítico (error): además prices_include_tax=yes → el plugin no puede
     *     descontar IVA al sincronizar (no hay rate). El TPV recibirá el
     *     precio "bruto" como neto y al imprimir ticket aplicará IVA encima
     *     ⇒ DOBLE IVA. Hay que arreglarlo antes de sincronizar.
     */
    public function tax_misconfig_banner(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !str_contains((string)$screen->id, 'tpv-sync')) return;

        if (get_option('woocommerce_calc_taxes', 'no') !== 'yes') return;

        global $wpdb;
        $rates = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates");
        if ($rates > 0) return;

        $url = admin_url('admin.php?page=wc-settings&tab=tax');
        $pricesInclTax = get_option('woocommerce_prices_include_tax', 'no') === 'yes';

        if ($pricesInclTax) {
            // Caso CRÍTICO: doble IVA garantizado al sincronizar.
            echo '<div class="notice notice-error"><p><strong>'
                . esc_html__('Catinfog Conector — RIESGO DE DOBLE IVA:', 'tpv-sync') . '</strong> '
                . esc_html__('WooCommerce está configurado para introducir precios CON IVA pero no tiene tasas configuradas. El plugin no puede descontar el IVA al enviar productos al TPV: el TPV los recibe como neto y luego cobra el IVA encima en cada ticket → tu cliente paga el IVA dos veces. ', 'tpv-sync')
                . '<a href="' . esc_url($url) . '"><strong>' . esc_html__('Configura las tasas en WooCommerce ahora →', 'tpv-sync') . '</strong></a> '
                . esc_html__('o cambia "Yes, prices inclusive of tax" a "No".', 'tpv-sync')
                . '</p></div>';
        } else {
            // Caso aviso: solo mismatch web/TPV en cobro.
            echo '<div class="notice notice-warning"><p><strong>'
                . esc_html__('Catinfog Conector — Impuestos:', 'tpv-sync') . '</strong> '
                . esc_html__('WooCommerce tiene los impuestos activados pero no hay tasas configuradas. Las ventas online saldrán al 0%, mientras el TPV físico sí aplicará IVA según el mapeo. ', 'tpv-sync')
                . '<a href="' . esc_url($url) . '">' . esc_html__('Configura las tasas en WooCommerce →', 'tpv-sync') . '</a></p></div>';
        }
    }

    /**
     * Banner read-only en el editor de producto WC cuando manda el TPV y el
     * producto está sincronizado. Avisa al admin de que sus cambios se
     * revertirán y deshabilita visualmente los inputs principales (CSS).
     */
    public function managed_product_banner(): void
    {
        $principal = (string) get_option('tpv_sync_principal', '');
        if ($principal !== 'tpv') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') return;
        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if ($postId <= 0) return;
        $tpvId = (int) get_post_meta($postId, '_tpv_product_id', true);
        if ($tpvId === 0) return; // isla — editable libremente
        ?>
        <style>
          .tpvsync-managed-banner {
            background: #fff5e6; border-left: 4px solid #ffb84d; color: #663300;
            padding: 12px 16px; margin: 12px 0;
            font-size: 13px; line-height: 1.5;
          }
          .tpvsync-managed-banner strong { color: #b35900; }
          body.post-type-product.tpvsync-managed-product input[name="post_title"],
          body.post-type-product.tpvsync-managed-product textarea[name="content"],
          body.post-type-product.tpvsync-managed-product input[name="_regular_price"],
          body.post-type-product.tpvsync-managed-product input[name="_sale_price"],
          body.post-type-product.tpvsync-managed-product input[name="_sku"],
          body.post-type-product.tpvsync-managed-product input[name="_global_unique_id"] {
            pointer-events: none; opacity: 0.6; background: #f6f6f6 !important;
          }
        </style>
        <div class="notice tpvsync-managed-banner">
            <p>
                <strong><?= esc_html__('⚙ Gestionado por el TPV.', 'tpv-sync') ?></strong>
                <?= esc_html__('Este producto se gestiona desde el TPV. Los cambios que hagas aquí en campos sincronizados (nombre, precio, descripción, SKU, estado…) se revertirán al estado del TPV. El stock sí es bidireccional.', 'tpv-sync') ?>
            </p>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.body.classList.add('tpvsync-managed-product');
        });
        </script>
        <?php
    }

    public function add_menu(): void
    {
        // Menú top-level con icono propio para que destaque (antes era submenú de WooCommerce).
        add_menu_page(
            'Catinfog Conector',
            'Catinfog Conector',
            'manage_woocommerce',
            'tpv-sync',
            [$this, 'render_page'],
            'dashicons-update',
            56
        );
    }

    public function register_settings(): void
    {
        $cap = ['sanitize_callback' => 'sanitize_text_field', 'capability' => 'manage_woocommerce'];
        register_setting('tpv_sync_settings', 'tpv_sync_api_url', [
            'sanitize_callback' => function($v) {
                // SEGURIDAD (HIGH): bloquea SSRF a metadatos cloud y red interna.
                // esc_url_raw acepta http://127.0.0.1, http://169.254.169.254 (AWS IMDS),
                // http://localhost, file://, etc. — un admin malicioso o XSS en otro
                // plugin podría apuntar el cliente OAuth a un servicio interno y
                // exfiltrar credenciales/datos. Forzamos https + host público.
                //
                // PHP 8: trim(null) lanza TypeError. WP llama al sanitize incluso
                // con $v=null cuando el campo no se envía (forms del paso 2/3 no
                // mandan tpv_sync_api_url). En ese caso preservamos el valor
                // existente en BD en vez de petar.
                if ($v === null || $v === '') return get_option('tpv_sync_api_url', '');
                $url = esc_url_raw(trim((string) $v));
                if ($url === '') return get_option('tpv_sync_api_url', '');
                $parts = wp_parse_url($url);
                if (!$parts || !isset($parts['scheme'], $parts['host'])) return get_option('tpv_sync_api_url', '');
                $allowedSchemes = ['https'];
                // En entornos dev (WP_DEBUG=true + host local) admitimos http para tests.
                if (defined('WP_DEBUG') && WP_DEBUG) $allowedSchemes[] = 'http';
                if (!in_array(strtolower($parts['scheme']), $allowedSchemes, true)) {
                    return get_option('tpv_sync_api_url', '');
                }
                $host = strtolower($parts['host']);
                // Bloqueo nombres reservados.
                if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
                    return get_option('tpv_sync_api_url', '');
                }
                // Resuelve y bloquea IPs privadas/loopback/link-local/cloud metadata.
                // En entornos dev (WP_DEBUG) permitimos loopback porque el TPV puede
                // estar en la misma máquina (tpv.local, multi-tenant en localhost)
                // y rechazar 127.0.0.1 ahí rompería el setup. La protección SSRF
                // sigue activa en producción donde nunca se debería usar.
                $devMode = defined('WP_DEBUG') && WP_DEBUG;
                $ips = @gethostbynamel($host) ?: [];
                foreach ($ips as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        if (!$devMode) {
                            return get_option('tpv_sync_api_url', '');
                        }
                    }
                }
                return $url;
            },
            'capability' => 'manage_woocommerce',
        ]);
        // client_id: si el form no envía el campo (forms del paso 2/3),
        // preservamos el valor en BD para no perderlo.
        register_setting('tpv_sync_settings', 'tpv_sync_client_id', [
            'capability' => 'manage_woocommerce',
            'sanitize_callback' => function($v) {
                if ($v === null) return get_option('tpv_sync_client_id', '');
                $clean = sanitize_text_field((string) $v);
                if ($clean === '') return get_option('tpv_sync_client_id', '');
                return $clean;
            },
        ]);
        register_setting('tpv_sync_settings', 'tpv_sync_client_secret', [
            'capability'        => 'manage_woocommerce',
            'sanitize_callback' => function($v) {
                if ($v === null) return get_option('tpv_sync_client_secret', '');
                $v = sanitize_text_field((string) $v);
                if ($v === '') {
                    return get_option('tpv_sync_client_secret', '');
                }
                return $v;
            },
        ]);
        register_setting('tpv_sync_settings', 'tpv_sync_module_catalog', ['default' => 1, 'capability' => 'manage_woocommerce']);
        register_setting('tpv_sync_settings', 'tpv_sync_module_orders',  ['default' => 0, 'capability' => 'manage_woocommerce']);
        // Decisión "¿quién manda?" — '' (sin decidir, modo legacy = WC manda),
        // 'tpv' (TPV es la fuente de verdad), 'wc' (WC es la fuente de verdad).
        register_setting('tpv_sync_settings', 'tpv_sync_principal', [
            'default' => '',
            'capability' => 'manage_woocommerce',
            'sanitize_callback' => function ($v) {
                if ($v === null) return get_option('tpv_sync_principal', '');
                return in_array($v, ['tpv', 'wc'], true) ? $v : '';
            },
        ]);
    }

    public function enqueue_styles(string $hook): void
    {
        if (strpos($hook, 'tpv-sync') === false) return;
        ?>
        <style>
        /* ═════════════════════════════════════════════════════════════════
           Catinfog Conector — UI minimalista premium
           Inspirada en PrestaShop: pasos numerados grandes, cards prominentes,
           jerarquía visual fuerte. Mismo bagaje informativo que la versión
           anterior pero con peso visual al nivel de un producto pro.
           ═════════════════════════════════════════════════════════════════ */
        :root {
            --cc-ink: #0f172a;
            --cc-ink-soft: #334155;
            --cc-muted: #64748b;
            --cc-muted-light: #94a3b8;
            --cc-bg: #f8fafc;
            --cc-surface: #ffffff;
            --cc-border: #e2e8f0;
            --cc-border-soft: #f1f5f9;
            --cc-primary: #2563eb;
            --cc-primary-hover: #1d4ed8;
            --cc-primary-soft: #eff6ff;
            --cc-primary-ring: rgba(37, 99, 235, 0.18);
            --cc-success: #10b981;
            --cc-success-bg: #ecfdf5;
            --cc-success-ink: #047857;
            --cc-warn: #d97706;
            --cc-warn-bg: #fffbeb;
            --cc-danger: #dc2626;
            --cc-danger-bg: #fef2f2;
        }

        body.toplevel_page_tpv-sync,
        body[class*="catinfog-conector"] { background: var(--cc-bg); }
        body.toplevel_page_tpv-sync #wpcontent,
        body[class*="catinfog-conector"] #wpcontent { background: var(--cc-bg); padding-left: 0; }

        .cc-wrap {
            max-width: 760px;
            margin: 0 auto;
            padding: 32px 24px 60px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            color: var(--cc-ink);
            font-size: 14px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Hero grande y vistoso ── */
        .cc-hero {
            text-align: center;
            margin-bottom: 28px;
        }
        .cc-hero-logos {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }
        .cc-hero-logos .dashicons {
            width: 44px; height: 44px; font-size: 44px;
            color: #21759b;
        }
        .cc-hero-logos .cc-arrows {
            color: var(--cc-muted-light);
            font-size: 22px; width: 22px; height: 22px;
        }
        .cc-hero-cat {
            background: var(--cc-ink);
            color: #fff;
            padding: 8px 18px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 16px;
            letter-spacing: -0.01em;
        }
        .cc-hero h1 {
            margin: 0 0 6px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--cc-ink);
            text-wrap: balance;
        }
        .cc-hero p {
            margin: 0;
            color: var(--cc-muted);
            font-size: 15px;
            text-wrap: pretty;
        }
        .cc-hero-version {
            display: inline-block;
            margin-top: 10px;
            font-size: 11.5px;
            color: var(--cc-muted-light);
            letter-spacing: 0.02em;
        }

        /* ── Chip de estado superior ── */
        .cc-status-chip {
            display: inline-flex; align-items: center; gap: 9px;
            margin: 0 auto 24px;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 13.5px;
            font-weight: 500;
            border: 1px solid transparent;
        }
        .cc-status-chip-ok {
            background: var(--cc-success-bg);
            color: var(--cc-success-ink);
            border-color: #a7f3d0;
        }
        .cc-status-chip-down {
            background: var(--cc-danger-bg);
            color: var(--cc-danger);
            border-color: #fecaca;
        }
        .cc-status-chip-partial {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }
        .cc-status-chip-partial .cc-status-dot {
            background: #f59e0b;
            animation: cc-pulse-soft 2s ease-in-out infinite;
        }
        .cc-status-chip-off {
            background: var(--cc-border-soft);
            color: var(--cc-muted);
            border-color: var(--cc-border);
        }
        .cc-status-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: currentColor;
        }
        @keyframes cc-pulse-soft {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: .55; transform: scale(.85); }
        }
        .cc-status-chip-ok .cc-status-dot { animation: cc-pulse-soft 2s ease-in-out infinite; }

        /* ── Sub-nav ── */
        .cc-subnav {
            display: flex;
            gap: 4px;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--cc-border);
        }
        .cc-subnav a {
            padding: 10px 16px;
            color: var(--cc-muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            font-size: 13.5px;
            font-weight: 500;
            margin-bottom: -1px;
            transition: color .12s ease, border-color .12s ease;
        }
        .cc-subnav a:hover { color: var(--cc-ink); }
        .cc-subnav a.active {
            color: var(--cc-ink);
            border-bottom-color: var(--cc-ink);
        }

        /* ── Pasos (la pieza central de la UX) ── */
        .cc-step {
            background: var(--cc-surface);
            border: 1px solid var(--cc-border);
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 14px;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .cc-step:hover { border-color: #cbd5e1; }
        .cc-step-pending {
            background: transparent;
            border-style: dashed;
            opacity: .55;
        }
        .cc-step-pending:hover { border-color: var(--cc-border); }
        .cc-step-done .cc-step-num,
        .cc-step-status .cc-step-num {
            background: var(--cc-success);
            color: #fff;
        }

        .cc-step-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 4px;
        }
        .cc-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--cc-ink);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            flex-shrink: 0;
            font-variant-numeric: tabular-nums;
        }
        .cc-step-pending .cc-step-num {
            background: var(--cc-border);
            color: var(--cc-muted);
        }
        .cc-step-head h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: var(--cc-ink);
            letter-spacing: -0.01em;
            flex: 1;
            text-wrap: balance;
        }
        .cc-step-sub {
            color: var(--cc-muted-light);
            font-weight: 400;
            font-size: 14px;
        }
        .cc-host {
            color: var(--cc-muted);
            font-weight: 500;
            font-size: 14px;
        }
        .cc-step-help {
            margin: 10px 0 16px 50px;
            color: var(--cc-muted);
            font-size: 13.5px;
            line-height: 1.6;
            text-wrap: pretty;
        }
        .cc-step > .cc-form,
        .cc-step > .cc-toggles,
        .cc-step > .cc-bigchoice-row,
        .cc-step > #cc-wizard-progress {
            margin-left: 50px;
        }
        @media (max-width: 600px) {
            .cc-step { padding: 20px 18px; }
            .cc-step-help,
            .cc-step > .cc-form,
            .cc-step > .cc-toggles,
            .cc-step > .cc-bigchoice-row { margin-left: 0; }
        }

        /* ── Badge de estado en cabecera de step ── */
        .cc-step-badge {
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 999px;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .cc-badge-ok {
            background: var(--cc-success-bg);
            color: var(--cc-success-ink);
        }
        .cc-badge-pending {
            background: var(--cc-warn-bg);
            color: var(--cc-warn);
        }
        .cc-badge-warn {
            background: var(--cc-warn-bg);
            color: var(--cc-warn);
        }
        .cc-badge-err {
            background: var(--cc-danger-bg);
            color: var(--cc-danger);
        }

        /* ── Wizard: barra de progreso superior con pasos completados ── */
        .cc-progress {
            display: flex; flex-direction: column;
            gap: 6px;
            margin-bottom: 18px;
            padding: 14px 18px;
            background: var(--cc-success-bg);
            border: 1px solid #a7f3d0;
            border-radius: 10px;
        }
        .cc-progress-step {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
        }
        .cc-progress-num {
            display: inline-flex;
            align-items: center; justify-content: center;
            width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--cc-success);
            color: #fff;
            font-size: 12px; font-weight: 700;
            flex-shrink: 0;
        }
        .cc-progress-text {
            flex: 1;
            color: var(--cc-success-ink);
        }
        .cc-progress-text strong {
            font-weight: 600;
            margin-right: 4px;
        }
        .cc-progress-detail {
            color: var(--cc-muted);
            font-size: 12px;
            margin-left: 4px;
        }
        .cc-progress-edit {
            font-size: 12px;
            color: var(--cc-muted);
            text-decoration: none;
        }
        .cc-progress-edit:hover {
            color: var(--cc-ink);
            text-decoration: underline;
        }

        /* ── Wizard: paso activo (shadow azul para destacar foco) ── */
        .cc-step-active {
            border-color: #93c5fd;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
        }
        .cc-step-active .cc-step-num {
            background: #2563eb;
            color: #fff;
        }

        /* ── Bloque toggles operativo (subtítulo H3) ── */
        .cc-step-toggles {
            background: var(--cc-border-soft);
            border-color: transparent;
            padding: 16px 20px;
        }
        .cc-step-toggles .cc-step-head h3 {
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--cc-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ── Card 3 dinámica (replica patrón PS): variantes según sub-estado ── */
        .cc-step-action {
            border-width: 1.5px;
        }
        .cc-step-action-warn {
            border-color: #fcd34d;
            background: linear-gradient(180deg, #fffbeb 0%, #fff 60%);
        }
        .cc-step-action-down {
            border-color: #fca5a5;
            background: linear-gradient(180deg, #fef2f2 0%, #fff 60%);
        }
        .cc-step-action-ok {
            border-color: #a7f3d0;
            background: linear-gradient(180deg, #f0fdf4 0%, #fff 60%);
        }

        /* ── Botón grande de Card 3 (CTA principal en estado partial) ── */
        .cc-action-row {
            display: flex; flex-wrap: wrap;
            align-items: center; gap: 12px;
            margin-top: 16px;
        }
        .cc-btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: 8px;
            border: 1px solid var(--cc-border);
            background: #fff;
            color: var(--cc-ink);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .cc-btn-action:hover:not(:disabled) {
            border-color: var(--cc-ink-soft);
            background: #f9fafb;
        }
        .cc-btn-action:disabled {
            opacity: 0.6; cursor: not-allowed;
        }
        .cc-btn-primary-big {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
            font-weight: 600;
            font-size: 15px;
            padding: 13px 22px;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.15);
        }
        .cc-btn-primary-big:hover:not(:disabled) {
            background: #1d4ed8;
            border-color: #1d4ed8;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
        }
        .cc-btn-secondary-big {
            background: #fff;
            color: #2563eb;
            border-color: #93c5fd;
        }
        .cc-btn-secondary-big:hover:not(:disabled) {
            background: #eff6ff;
            border-color: #2563eb;
        }
        .cc-btn-icon {
            font-size: 16px; line-height: 1;
        }

        /* ── Card 1 read-only colapsable (estado 3) ── */
        .cc-step-creds {
            cursor: default;
        }
        .cc-step-creds > summary {
            list-style: none;
            cursor: pointer;
            padding: 0;
        }
        .cc-step-creds > summary::-webkit-details-marker { display: none; }
        .cc-step-creds > summary::before {
            content: '▸';
            display: inline-block;
            margin-right: 8px;
            color: var(--cc-muted);
            font-size: 11px;
            transition: transform 0.15s ease;
            vertical-align: middle;
        }
        .cc-step-creds[open] > summary::before {
            transform: rotate(90deg);
        }
        .cc-step-creds .cc-step-head {
            display: inline-flex;
            vertical-align: middle;
        }
        .cc-step-body {
            padding-top: 14px;
            border-top: 1px solid var(--cc-border-soft);
            margin-top: 14px;
        }

        /* ── Form fields ── */
        .cc-form {
            display: flex; flex-direction: column;
            gap: 14px;
        }
        .cc-field {
            display: flex; flex-direction: column;
            gap: 6px;
        }
        .cc-field label {
            font-size: 12.5px;
            font-weight: 500;
            color: var(--cc-ink-soft);
            letter-spacing: 0.01em;
        }
        .cc-field input.regular-text {
            padding: 10px 13px;
            border: 1px solid var(--cc-border);
            border-radius: 8px;
            font-size: 14px;
            background: var(--cc-surface);
            color: var(--cc-ink);
            transition: border-color .15s ease, box-shadow .15s ease;
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            box-sizing: border-box;
        }
        .cc-field input:focus {
            outline: none;
            border-color: var(--cc-primary);
            box-shadow: 0 0 0 3px var(--cc-primary-ring);
        }
        .cc-field input::placeholder {
            color: var(--cc-muted-light);
        }
        .cc-actions {
            display: flex; gap: 10px;
            margin-top: 4px;
            align-items: center;
        }

        /* ── Botón primario azul (estilo PS) ── */
        .cc-btn-primary,
        .cc-form .button-primary,
        .button.cc-btn-primary {
            background: var(--cc-primary) !important;
            border-color: var(--cc-primary) !important;
            color: #fff !important;
            border-radius: 8px !important;
            font-weight: 500 !important;
            box-shadow: none !important;
            text-shadow: none !important;
            padding: 8px 22px !important;
            min-height: 38px;
            font-size: 14px !important;
            letter-spacing: 0;
            transition: background-color .12s ease, border-color .12s ease;
        }
        .cc-btn-primary:hover,
        .cc-form .button-primary:hover,
        .button.cc-btn-primary:hover {
            background: var(--cc-primary-hover) !important;
            border-color: var(--cc-primary-hover) !important;
            color: #fff !important;
        }
        .cc-btn-primary:focus,
        .button.cc-btn-primary:focus {
            box-shadow: 0 0 0 3px var(--cc-primary-ring) !important;
        }

        /* ── Tubería con paquetes (entre las 2 cards bigchoice) ── */
        .cc-bigchoice-with-pipe {
            grid-template-columns: 1fr 100px 1fr !important;
            align-items: center;
        }
        @media (max-width: 700px) {
            .cc-bigchoice-with-pipe {
                grid-template-columns: 1fr !important;
            }
            .cc-syncpipe { display: none; }
        }
        .cc-bigchoice-counter {
            display: inline-block;
            font-size: 12px;
            color: var(--cc-muted);
            margin-top: 4px;
            margin-bottom: 4px;
            padding: 2px 8px;
            background: var(--cc-border-soft);
            border-radius: 10px;
            font-variant-numeric: tabular-nums;
            font-weight: 500;
        }
        .cc-syncpipe {
            position: relative;
            height: 50px;
            overflow: hidden;
        }
        .cc-syncpipe::before {
            content: "";
            position: absolute;
            left: 0; right: 0; top: 50%;
            height: 2px;
            background: repeating-linear-gradient(
                90deg,
                #2563eb 0 6px,
                transparent 6px 12px
            );
            transform: translateY(-50%);
            opacity: .35;
        }
        .cc-syncparcel {
            position: absolute;
            top: 50%;
            width: 14px; height: 14px;
            background: #2563eb;
            border-radius: 3px;
            transform: translate(-50%, -50%);
            box-shadow: 0 2px 6px -2px rgba(37, 99, 235, 0.5);
            opacity: 0;
        }
        .cc-syncscene.is-active .cc-syncparcel,
        .cc-syncscene.is-importing .cc-syncparcel {
            animation: cc-parcel-fly 1.4s linear infinite;
        }
        /* Durante la importación, paquetes más rápidos para enfatizar movimiento */
        .cc-syncscene.is-importing .cc-syncparcel {
            animation-duration: 0.9s;
        }
        .cc-syncparcel-1 { animation-delay: 0s     !important; left: 0; }
        .cc-syncparcel-2 { animation-delay: -0.45s !important; left: 0; }
        .cc-syncparcel-3 { animation-delay: -0.9s  !important; left: 0; }
        @keyframes cc-parcel-fly {
            0%   { left: 0%;   opacity: 0; transform: translate(-50%, -50%) scale(0.6); }
            10%  { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            90%  { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            100% { left: 100%; opacity: 0; transform: translate(-50%, -50%) scale(0.6); }
        }
        .cc-syncscene.is-reverse .cc-syncparcel {
            animation-name: cc-parcel-fly-reverse;
        }
        @keyframes cc-parcel-fly-reverse {
            0%   { left: 100%; opacity: 0; transform: translate(-50%, -50%) scale(0.6); }
            10%  { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            90%  { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            100% { left: 0%;   opacity: 0; transform: translate(-50%, -50%) scale(0.6); }
        }

        /* ── Barra de progreso del paso 2 ── */
        .cc-syncprogress {
            max-width: 560px;
            margin: 8px auto 4px;
        }
        .cc-syncprogress-title {
            font-size: 13px;
            color: var(--cc-ink-soft);
            margin-bottom: 8px;
            text-align: center;
            font-weight: 500;
        }
        .cc-syncprogress-bar-wrap {
            height: 10px;
            background: var(--cc-border);
            border-radius: 999px;
            overflow: hidden;
            position: relative;
        }
        .cc-syncprogress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 999px;
            transition: width .35s ease-out;
            position: relative;
        }
        .cc-syncprogress-bar::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(
                90deg,
                transparent 0%,
                rgba(255,255,255,0.3) 50%,
                transparent 100%
            );
            animation: cc-shimmer 1.6s linear infinite;
        }
        @keyframes cc-shimmer {
            0%   { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .cc-syncprogress-meta {
            margin-top: 6px;
            font-size: 12px;
            color: var(--cc-muted);
            text-align: center;
            font-variant-numeric: tabular-nums;
        }

        /* ── Wizard "¿quién manda?" — 2 cards grandes ── */
        .cc-bigchoice-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 4px;
        }
        @media (max-width: 600px) {
            .cc-bigchoice-row { grid-template-columns: 1fr; }
        }
        .cc-bigchoice {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            padding: 22px 22px 24px;
            border: 1.5px solid var(--cc-border);
            border-radius: 12px;
            background: var(--cc-surface);
            text-align: center;
            cursor: pointer;
            transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease;
            font-family: inherit;
            color: var(--cc-ink);
        }
        .cc-bigchoice-body {
            text-align: center;
            width: 100%;
        }
        .cc-bigchoice:hover {
            border-color: var(--cc-ink);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, .08);
        }
        .cc-bigchoice:focus-visible {
            outline: none;
            border-color: var(--cc-primary);
            box-shadow: 0 0 0 3px var(--cc-primary-ring);
        }
        .cc-bigchoice-active {
            border-color: var(--cc-success) !important;
            background: var(--cc-success-bg) !important;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, .15) !important;
            transform: none !important;
        }
        .cc-bigchoice-active .cc-bigchoice-icon {
            box-shadow: 0 0 0 3px rgba(16, 185, 129, .25);
        }
        /* Card no elegida atenuada durante el import */
        .cc-bigchoice-dim {
            opacity: .35;
            filter: grayscale(0.6);
            transition: opacity .25s, filter .25s;
        }
        .cc-bigchoice:disabled {
            cursor: default;
            transform: none;
            box-shadow: none;
        }
        .cc-bigchoice-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            flex-shrink: 0;
        }
        .cc-bigchoice-icon-wc  { background: #21759b; }
        .cc-bigchoice-icon-tpv { background: var(--cc-ink); }
        .cc-bigchoice-icon .dashicons {
            font-size: 24px; width: 24px; height: 24px;
        }
        .cc-bigchoice-body strong {
            display: block;
            font-size: 16px; font-weight: 600;
            margin-bottom: 4px;
            letter-spacing: -0.01em;
        }
        .cc-bigchoice-body small {
            font-size: 13px;
            color: var(--cc-muted);
            line-height: 1.5;
            display: block;
        }

        /* ── Estado 3: pulso de estado ── */
        .cc-status-pulse {
            position: relative;
            width: 14px; height: 14px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .cc-pulse-ok {
            background: var(--cc-success);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, .15);
            animation: cc-pulse-soft 2s ease-in-out infinite;
        }
        .cc-pulse-warn {
            background: var(--cc-warn);
            box-shadow: 0 0 0 4px rgba(217, 119, 6, .15);
        }
        .cc-status-meta {
            margin: 4px 0 0 50px;
            color: var(--cc-muted);
            font-size: 13.5px;
        }
        .cc-status-meta code {
            background: var(--cc-border-soft);
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 13px;
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, monospace;
            color: var(--cc-ink-soft);
        }
        .cc-status-meta strong {
            color: var(--cc-ink-soft);
            font-weight: 600;
        }
        .cc-sep { margin: 0 8px; opacity: .4; }
        @media (max-width: 600px) {
            .cc-status-meta { margin-left: 0; }
        }

        /* ── Toggles módulos ── */
        .cc-toggles {
            display: flex; flex-direction: column;
            gap: 14px;
            margin-top: 6px;
        }
        .cc-toggle {
            display: flex; align-items: center;
            gap: 14px;
            cursor: pointer;
            padding: 4px 0;
        }
        .cc-toggle input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0; height: 0;
            pointer-events: none;
        }
        .cc-toggle-slider {
            position: relative;
            width: 40px; height: 22px;
            background: var(--cc-border);
            border-radius: 999px;
            transition: background-color .18s ease;
            flex-shrink: 0;
        }
        .cc-toggle-slider::after {
            content: '';
            position: absolute;
            top: 2px; left: 2px;
            width: 18px; height: 18px;
            background: #fff;
            border-radius: 50%;
            transition: transform .18s ease, box-shadow .18s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .12);
        }
        .cc-toggle input[type="checkbox"]:checked + .cc-toggle-slider {
            background: var(--cc-success);
        }
        .cc-toggle input[type="checkbox"]:checked + .cc-toggle-slider::after {
            transform: translateX(18px);
        }
        .cc-toggle input[type="checkbox"]:focus-visible + .cc-toggle-slider {
            box-shadow: 0 0 0 3px var(--cc-primary-ring);
        }
        .cc-toggle-label {
            display: flex; flex-direction: column;
            gap: 2px;
            line-height: 1.4;
        }
        .cc-toggle-label strong {
            font-weight: 600;
            font-size: 14.5px;
            color: var(--cc-ink);
        }
        .cc-toggle-label small {
            color: var(--cc-muted);
            font-size: 12.5px;
        }

        /* ── Acordeón Avanzado ── */
        .cc-advanced {
            margin-top: 16px;
            border: 1px dashed var(--cc-border);
            border-radius: 12px;
            background: transparent;
        }
        .cc-advanced > summary {
            cursor: pointer;
            padding: 12px 18px;
            color: var(--cc-muted);
            font-size: 13.5px;
            font-weight: 500;
            list-style: none;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .cc-advanced > summary::-webkit-details-marker { display: none; }
        .cc-advanced > summary::before {
            content: '›';
            display: inline-block;
            font-size: 18px;
            line-height: 1;
            transition: transform .15s ease;
            color: var(--cc-muted-light);
        }
        .cc-advanced[open] > summary::before {
            transform: rotate(90deg);
        }
        .cc-advanced > summary:hover { color: var(--cc-ink); }
        .cc-advanced[open] {
            background: var(--cc-surface);
            border-style: solid;
        }
        .cc-advanced-body {
            padding: 4px 22px 22px 38px;
            display: flex; flex-direction: column;
            gap: 22px;
        }
        .cc-adv-section h3 {
            margin: 0 0 4px;
            font-size: 14px;
            font-weight: 600;
            color: var(--cc-ink);
            letter-spacing: -0.005em;
        }
        .cc-adv-section .cc-step-help {
            margin: 0 0 12px;
            font-size: 13px;
        }
        .cc-adv-actions {
            display: flex; gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .cc-adv-actions .button {
            border-radius: 8px !important;
            font-size: 13px !important;
            padding: 5px 14px !important;
            min-height: 32px !important;
            line-height: 1.6 !important;
        }
        .cc-adv-danger {
            border-top: 1px dashed var(--cc-border);
            padding-top: 18px;
            margin-top: 4px;
        }
        .cc-adv-danger h3 { color: var(--cc-danger); }
        .button-link-delete {
            color: var(--cc-danger) !important;
            border-color: #fecaca !important;
        }
        .button-link-delete:hover {
            background: var(--cc-danger-bg) !important;
            border-color: var(--cc-danger) !important;
            color: var(--cc-danger) !important;
        }
        .cc-result {
            font-size: 12.5px;
            color: var(--cc-muted);
        }
        .cc-result-ok { color: var(--cc-success-ink); font-weight: 500; }
        .cc-result-err { color: var(--cc-danger); font-weight: 500; }

        /* ── Reduced motion ── */
        @media (prefers-reduced-motion: reduce) {
            .cc-bigchoice, .cc-toggle-slider, .cc-toggle-slider::after,
            .cc-advanced > summary::before, .cc-status-chip-ok .cc-status-dot,
            .cc-pulse-ok, .cc-step {
                transition: none; animation: none;
            }
        }
        </style>
        <?php
    }

    /**
     * Resuelve el estado del chip de conexión. 4 estados reales:
     *   - 'off'    : sin credenciales (instalación virgen o "Eliminar conexión").
     *   - 'partial': hay credenciales pero falta algo (principal sin elegir,
     *                webhook caído tras un disconnect, etc.). El usuario VE
     *                la card "Conexión a medias" — el chip debe coincidir.
     *   - 'down'   : todo configurado pero el TPV no responde a /auth/verify.
     *   - 'ok'     : webhook registrado + TPV responde firmado correctamente.
     *
     * El health check se cachea 30s en wp_options.
     */
    private function resolveConnectionState(): string
    {
        // Usamos los MISMOS criterios que el wizard (api_url + secret) en vez
        // de mirar client_id. Razón: el plugin defaultea client_id a
        // "woocommerce" hardcoded para retrocompat — si miráramos client_id,
        // el chip diría "credenciales OK" siempre que la BD esté vacía,
        // contradiciendo el wizard que pinta paso 1.
        $hasCreds = (string) get_option('tpv_sync_api_url', '') !== ''
                 && (string) get_option('tpv_sync_client_secret', '') !== '';
        if (!$hasCreds) {
            return 'off';
        }
        $hasPrincipal = (string) get_option('tpv_sync_principal', '') !== '';
        $hasWebhook   = (string) get_option('tpv_sync_webhook_id', '') !== ''
                     && (string) get_option('tpv_sync_webhook_secret', '') !== '';
        if (!$hasPrincipal || !$hasWebhook) {
            return 'partial';
        }
        $now = time();
        $cachedAt = (int) get_option('tpv_sync_health_checked_at', 0);
        $cachedOk = (int) get_option('tpv_sync_health_ok', 0) === 1;

        // TTL adaptativo: si el último probe fue OK, cacheamos solo 5s para
        // que el chip refleje rápido un pause/disconnect en el TPV (estado
        // que el cliente espera ver inmediatamente al refrescar). Si fue
        // DOWN, cacheamos 30s para no martillear una API caída.
        // Bug 2026-04-28: con TTL=30s constante, tras pausar el conector en
        // el TPV, el chip seguía diciendo "ok" durante 30s aunque el cliente
        // refrescase. Confuso.
        $ttl = $cachedOk ? 5 : 30;

        // También: además del flag invalid_credentials que set parse() en
        // 401, si está presente devolvemos 'down' inmediatamente sin probe.
        $invalidCreds = get_option('tpv_sync_invalid_credentials');
        if (is_array($invalidCreds) && !empty($invalidCreds['at'])) {
            $cacheAge = $now - (int) $invalidCreds['at'];
            if ($cacheAge < 60) return 'down'; // las creds se sabe rotas hace <1min
        }

        if ($cachedAt > 0 && ($now - $cachedAt) < $ttl) {
            return $cachedOk ? 'ok' : 'down';
        }
        // Probe FIRMADO: POST /auth/verify es 200 si el HMAC del cliente
        // coincide con el del servidor, 401 si no. Detecta secrets desincronizados
        // que GET /health (sin firma) no podría ver. Es la diferencia entre
        // un chip verde mentiroso y un chip rojo accionable.
        $ok = false;
        try {
            $api = new TPV_Sync_API_Client();
            if ($api->isConfigured()) {
                $r = $api->post('/auth/verify', []);
                $ok = is_array($r) && empty($r['error']) && empty($r['errors']);
            }
        } catch (Throwable $e) {
            $ok = false;
        }
        update_option('tpv_sync_health_ok', $ok ? 1 : 0, false);
        update_option('tpv_sync_health_checked_at', $now, false);
        return $ok ? 'ok' : 'down';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'tpv-sync'));
        }
        $tab = sanitize_key($_GET['tab'] ?? 'home');
        $statusState = $this->resolveConnectionState();
        ?>
        <div class="wrap cc-wrap">

            <!-- Hero -->
            <div class="cc-hero">
                <div class="cc-hero-logos">
                    <span class="dashicons dashicons-wordpress-alt" aria-hidden="true"></span>
                    <span class="cc-arrows dashicons dashicons-update-alt" aria-hidden="true"></span>
                    <span class="cc-hero-cat">Catinfog</span>
                </div>
                <h1>Catinfog Conector</h1>
                <p><?= esc_html__('Sincroniza tu tienda WooCommerce con el TPV en tiempo real.', 'tpv-sync') ?></p>
                <span class="cc-hero-version">v<?= esc_html(TPV_SYNC_VERSION) ?></span>
            </div>

            <?php
            // Banner de fallo de cifrado: si decrypt() falla (AUTH_KEY rotada,
            // backup restaurado, etc.) el secret en BD es ilegible y ningún
            // intento de auth funcionará.
            $decryptFail = get_option('tpv_sync_secret_decrypt_failed');
            if (is_array($decryptFail) && !empty($decryptFail['at'])):
            ?>
                <div class="cc-status-chip cc-status-chip-down" style="margin-bottom:8px;">
                    <span class="cc-status-dot"></span>
                    <span><?= esc_html__('La contraseña guardada está ilegible (probablemente las claves de WordPress cambiaron). Pega de nuevo el Client Secret del TPV en el paso 1.', 'tpv-sync') ?></span>
                </div>
            <?php endif; ?>

            <?php
            // Banner de credenciales inválidas: el plugin se autenticó pero el
            // TPV respondió "invalid_client" → las credenciales pegadas no
            // corresponden a ningún conector del TPV (típico tras eliminar
            // el conector en el TPV o pegar credenciales de otra tienda por
            // error). Distinto del decrypt fail: aquí el descifrado funcionó.
            $invalidCreds = get_option('tpv_sync_invalid_credentials');
            if (is_array($invalidCreds) && !empty($invalidCreds['at']) && empty($decryptFail)):
            ?>
                <div class="cc-status-chip cc-status-chip-down" style="margin-bottom:8px;">
                    <span class="cc-status-dot"></span>
                    <span><?= esc_html__('El TPV no reconoce tus credenciales. Verifica el Client ID y el Secret en el panel del TPV (puede que el conector haya sido eliminado o el secret rotado).', 'tpv-sync') ?></span>
                </div>
            <?php endif; ?>

            <!-- Chip de estado superior (4 estados: ok / partial / down / off) -->
            <?php if ($statusState === 'ok'): ?>
                <div class="cc-status-chip cc-status-chip-ok">
                    <span class="cc-status-dot"></span>
                    <span><?= esc_html__('Conectado y sincronizado con tu TPV Catinfog', 'tpv-sync') ?></span>
                </div>
            <?php elseif ($statusState === 'partial'): ?>
                <div class="cc-status-chip cc-status-chip-partial">
                    <span class="cc-status-dot"></span>
                    <span><?= esc_html__('Conexión a medias — completa la configuración abajo', 'tpv-sync') ?></span>
                </div>
            <?php elseif ($statusState === 'down'): ?>
                <div class="cc-status-chip cc-status-chip-down">
                    <span class="cc-status-dot"></span>
                    <span><?= esc_html__('Sin conexión con el TPV. Comprueba que el TPV está accesible.', 'tpv-sync') ?></span>
                </div>
            <?php else: ?>
                <div class="cc-status-chip cc-status-chip-off">
                    <span class="cc-status-dot"></span>
                    <span><?= esc_html__('Sin conectar', 'tpv-sync') ?></span>
                </div>
            <?php endif; ?>

            <!-- Sub-nav: Inicio | Impuestos | Log -->
            <nav class="cc-subnav">
                <a href="?page=tpv-sync" class="<?= $tab === 'home' ? 'active' : '' ?>">
                    <?= esc_html__('Inicio', 'tpv-sync') ?>
                </a>
                <a href="?page=tpv-sync&tab=taxes" class="<?= $tab === 'taxes' ? 'active' : '' ?>">
                    <?= esc_html__('Impuestos', 'tpv-sync') ?>
                </a>
                <a href="?page=tpv-sync&tab=logs" class="<?= $tab === 'logs' ? 'active' : '' ?>">
                    <?= esc_html__('Log', 'tpv-sync') ?>
                </a>
            </nav>

            <?php match($tab) {
                'logs'  => $this->render_logs_tab(),
                'taxes' => $this->render_taxes_tab(),
                default => $this->render_home_tab(),
            }; ?>
        </div>
        <?php
    }

    // ── Página Inicio ─────────────────────────────────────────────────────────

    private function render_home_tab(): void
    {
        $nonce      = wp_create_nonce('tpv_sync');
        $api        = new TPV_Sync_API_Client();
        $configured = $api->isConfigured();
        $webhookId  = get_option('tpv_sync_webhook_id', '');
        $modCatalog = (bool) get_option('tpv_sync_module_catalog', 1);
        $modOrders  = (bool) get_option('tpv_sync_module_orders',  0);
        $hasApiUrl  = (bool) get_option('tpv_sync_api_url', '');
        $hasSecret  = (bool) get_option('tpv_sync_client_secret', '');
        $principal  = (string) get_option('tpv_sync_principal', '');
        $apiUrl     = (string) get_option('tpv_sync_api_url', '');
        $healthOk   = (bool) get_option('tpv_sync_health_ok', false);

        // ── Wizard secuencial de 4 estados (paso 1 → 2 → 3 → operativo) ──
        // Cada paso completado se colapsa arriba con check; el activo se
        // muestra grande; los futuros NO se muestran. En estado operativo
        // los 3 pasos quedan en una "barra de progreso" colapsada y el foco
        // pasa al estado del webhook + Avanzado.
        $hasWebhook = (string) get_option('tpv_sync_webhook_id', '') !== ''
                   && (string) get_option('tpv_sync_webhook_secret', '') !== '';

        if (!$hasApiUrl || !$hasSecret) {
            $wizardStep = 1; // Conectar credenciales
        } elseif ($principal === '') {
            $wizardStep = 2; // Elegir fuente
        } elseif (!$hasWebhook) {
            $wizardStep = 3; // Activar sincronización (registrar webhook)
        } else {
            $wizardStep = 4; // Operativo
        }

        // Sub-estado del webhook cuando el wizard ha terminado: nos sirve
        // para distinguir "ok" (todo verde) de "down" (webhook OK pero TPV
        // no responde). En ambos casos los 3 pasos del wizard están done;
        // solo cambia el chip de estado del bloque operativo.
        $opSubState = $wizardStep === 4 ? ($healthOk ? 'ok' : 'down') : null;

        $hostShort = $apiUrl ? (parse_url($apiUrl, PHP_URL_HOST) ?: $apiUrl) : '';
        $fuente    = $principal === 'wc' ? 'WooCommerce' : 'TPV';
        ?>

        <?php
        // Helper: render de un paso "completado" en la barra de progreso
        // superior. Click → permite volver a ese paso (re-conectar / cambiar
        // fuente). Sin esto, el usuario operativo no puede revisar nunca
        // qué credenciales o fuente eligió.
        $renderDoneStep = function (int $num, string $title, string $detail, string $editAction = '') {
            $editLink = $editAction !== ''
                ? '<a class="cc-progress-edit" href="' . esc_url($editAction) . '">' . esc_html__('Editar', 'tpv-sync') . '</a>'
                : '';
            echo '<div class="cc-progress-step cc-progress-done">'
               . '<span class="cc-progress-num">✓</span>'
               . '<div class="cc-progress-text"><strong>' . esc_html__('Paso', 'tpv-sync') . " {$num}.</strong> "
               . '<span>' . esc_html($title) . '</span> '
               . '<span class="cc-progress-detail">' . esc_html($detail) . '</span>'
               . '</div>'
               . $editLink
               . '</div>';
        };
        ?>

        <?php /* ── BARRA DE PROGRESO SUPERIOR (pasos completados) ── */ ?>
        <?php if ($wizardStep > 1): ?>
        <div class="cc-progress">
            <?php $renderDoneStep(1, __('Credenciales del TPV', 'tpv-sync'), $hostShort); ?>
            <?php if ($wizardStep > 2): ?>
                <?php $renderDoneStep(2, __('Fuente de catálogo', 'tpv-sync'), $fuente); ?>
            <?php endif; ?>
            <?php if ($wizardStep > 3): ?>
                <?php $renderDoneStep(3, __('Sincronización activa', 'tpv-sync'), __('listo', 'tpv-sync')); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($wizardStep === 1): /* ─── PASO 1: CONECTAR CREDENCIALES ─── */ ?>

        <div class="cc-step cc-step-active">
            <div class="cc-step-head">
                <span class="cc-step-num">1</span>
                <h2><?= esc_html__('Conectar al TPV', 'tpv-sync') ?></h2>
                <span class="cc-step-badge cc-badge-pending"><?= esc_html__('Paso 1 de 3', 'tpv-sync') ?></span>
            </div>
            <p class="cc-step-help">
                <?= esc_html__('Pega las credenciales del TPV (las copias desde Configuración → Conector en el TPV).', 'tpv-sync') ?>
            </p>
            <form method="post" action="options.php" class="cc-form">
                <?php settings_fields('tpv_sync_settings'); ?>
                <input type="hidden" name="tpv_sync_module_catalog" value="<?= $modCatalog ? 1 : 0 ?>">
                <input type="hidden" name="tpv_sync_module_orders"  value="<?= $modOrders ? 1 : 0 ?>">

                <div class="cc-field">
                    <label for="cc-api-url"><?= esc_html__('URL del TPV', 'tpv-sync') ?></label>
                    <input type="url" id="cc-api-url" name="tpv_sync_api_url" class="regular-text"
                           value="<?= esc_attr($apiUrl) ?>"
                           placeholder="https://mitpv.catinfog.com/api/v1"
                           autocomplete="off" spellcheck="false" required>
                </div>
                <div class="cc-field">
                    <label for="cc-client-id"><?= esc_html__('Client ID', 'tpv-sync') ?></label>
                    <input type="text" id="cc-client-id" name="tpv_sync_client_id" class="regular-text"
                           value="<?= esc_attr((string) get_option('tpv_sync_client_id', '')) ?>"
                           placeholder="wo_xxxxxxxxxxxx"
                           autocomplete="off" spellcheck="false" required>
                </div>
                <div class="cc-field">
                    <label for="cc-secret"><?= esc_html__('Client Secret', 'tpv-sync') ?></label>
                    <input type="password" id="cc-secret" name="tpv_sync_client_secret" class="regular-text"
                           value=""
                           placeholder="<?= $hasSecret ? esc_attr__('(guardada — déjalo vacío para no cambiarla)', 'tpv-sync') : esc_attr__('Pégala aquí', 'tpv-sync') ?>"
                           autocomplete="new-password" spellcheck="false">
                </div>
                <div class="cc-actions">
                    <?php submit_button(__('Continuar →', 'tpv-sync'), 'primary cc-btn-primary', 'submit', false); ?>
                </div>
            </form>
        </div>

        <?php elseif ($wizardStep === 2): /* ─── PASO 2: ELEGIR FUENTE + SYNC ─── */ ?>

        <?php
        // Conteo local de WC: rápido, una query.
        global $wpdb;
        $wcCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
        );
        ?>

        <div class="cc-step cc-step-active" id="cc-step2">
            <div class="cc-step-head">
                <span class="cc-step-num">2</span>
                <h2><?= esc_html__('¿Cuál es tu fuente de catálogo?', 'tpv-sync') ?></h2>
                <span class="cc-step-badge cc-badge-pending"><?= esc_html__('Paso 2 de 3', 'tpv-sync') ?></span>
            </div>
            <p class="cc-step-help">
                <?= esc_html__('Solo necesitas decidirlo una vez. La fuente envía su catálogo al otro lado y a partir de ahí los cambios fluyen automáticamente.', 'tpv-sync') ?>
            </p>

            <!-- 2 cards (no 4): cada una contiene icono + nombre + conteo + descripción.
                 La tubería con los paquetes vuela entre los iconos de las dos cards. -->
            <div class="cc-bigchoice-row cc-bigchoice-with-pipe" id="cc-syncscene">
                <button type="button" class="cc-bigchoice" data-principal="wc">
                    <div class="cc-bigchoice-icon cc-bigchoice-icon-wc" aria-hidden="true">
                        <span class="dashicons dashicons-wordpress"></span>
                    </div>
                    <div class="cc-bigchoice-body">
                        <strong><?= esc_html__('Manda WordPress', 'tpv-sync') ?></strong>
                        <span class="cc-bigchoice-counter" id="cc-count-wc"><?= (int)$wcCount ?> <?= esc_html__('productos', 'tpv-sync') ?></span>
                        <small><?= esc_html__('Tu catálogo de WordPress se enviará al TPV. Recomendado si vendes principalmente online.', 'tpv-sync') ?></small>
                    </div>
                </button>

                <!-- Tubería con paquetes entre las dos cards -->
                <div class="cc-syncpipe" aria-hidden="true">
                    <span class="cc-syncparcel cc-syncparcel-1"></span>
                    <span class="cc-syncparcel cc-syncparcel-2"></span>
                    <span class="cc-syncparcel cc-syncparcel-3"></span>
                </div>

                <button type="button" class="cc-bigchoice" data-principal="tpv">
                    <div class="cc-bigchoice-icon cc-bigchoice-icon-tpv" aria-hidden="true">
                        <span class="dashicons dashicons-store"></span>
                    </div>
                    <div class="cc-bigchoice-body">
                        <strong><?= esc_html__('Manda el TPV', 'tpv-sync') ?></strong>
                        <span class="cc-bigchoice-counter" id="cc-count-tpv">…</span>
                        <small><?= esc_html__('El catálogo del TPV se traerá a WordPress. Recomendado si vendes principalmente en tienda física.', 'tpv-sync') ?></small>
                    </div>
                </button>
            </div>

            <!-- Progreso (oculto hasta elegir) -->
            <div class="cc-syncprogress" id="cc-syncprogress" style="display:none;">
                <div class="cc-syncprogress-title" id="cc-syncprogress-title"></div>
                <div class="cc-syncprogress-bar-wrap">
                    <div class="cc-syncprogress-bar" id="cc-syncprogress-bar"></div>
                </div>
                <div class="cc-syncprogress-meta" id="cc-syncprogress-meta">0 %</div>
            </div>
        </div>

        <details class="cc-advanced">
            <summary><?= esc_html__('Cancelar y empezar de cero', 'tpv-sync') ?></summary>
            <div class="cc-advanced-body">
                <p class="cc-step-help">
                    <?= esc_html__('Si quieres cambiar las credenciales o desconectarte, puedes hacerlo aquí.', 'tpv-sync') ?>
                </p>
                <button type="button" class="button button-link-delete" id="cc-disconnect">
                    <?= esc_html__('Detener y borrar credenciales locales', 'tpv-sync') ?>
                </button>
                <span id="cc-disconnect-result" class="cc-result"></span>
            </div>
        </details>

        <script>
        jQuery(function($) {
            var ajaxurl = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
            var nonce   = <?= wp_json_encode($nonce) ?>;

            // Conteo del TPV: pedimos al backend un contador rápido. Si falla
            // mostramos "—" y seguimos: no es bloqueante.
            $.post(ajaxurl, {action:'tpv_sync_count_remote', nonce:nonce}, function(resp) {
                if (resp.success && typeof resp.data.total === 'number') {
                    $('#cc-count-tpv').text(resp.data.total + ' <?= esc_js(__('productos', 'tpv-sync')) ?>');
                } else {
                    $('#cc-count-tpv').text('—');
                }
            }).fail(function() { $('#cc-count-tpv').text('—'); });

            // Click en una de las dos cajas grandes → arrancar la sincronización.
            // No hacemos submit del form a options.php — ahora todo es AJAX por
            // lotes, con barra de progreso y animación de paquetes en la
            // dirección correcta (WC→TPV o TPV→WC).
            $('.cc-bigchoice').on('click', function() {
                var $btn      = $(this);
                var principal = $btn.data('principal'); // 'wc' o 'tpv'
                var direction = (principal === 'tpv') ? 'pull' : 'push';

                $('.cc-bigchoice').prop('disabled', true).addClass('cc-bigchoice-dim');
                $btn.removeClass('cc-bigchoice-dim').addClass('cc-bigchoice-active');

                // La escena con la tubería sigue visible durante el import.
                // Activamos la animación de paquetes y, si la fuente es TPV,
                // invertimos la dirección (TPV→WC) con .is-reverse. Esto hace
                // que el cliente vea los paquetes "viajando" en la dirección
                // correcta mientras se importa, no una pantalla muerta.
                var $scene = $('#cc-syncscene').addClass('is-active is-importing');
                if (principal === 'tpv') $scene.addClass('is-reverse');

                $('#cc-syncprogress').slideDown(200);

                // Mensajes rotatorios para hacer la espera amena. Cambiamos
                // cada 3.5s mientras la barra avanza. La idea es que el
                // cliente sepa que algo pasa aunque la barra esté quieta
                // entre lotes de 50 productos (que tardan ~6s cada uno).
                var msgsPull = [
                    <?= wp_json_encode(__('🚚 Trayendo productos del TPV…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('📥 Recibiendo datos en WordPress…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('🖼️ Descargando imágenes…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('🏷️ Asignando categorías…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('📦 Guardando productos en WooCommerce…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('🔗 Enlazando con el TPV…', 'tpv-sync')) ?>,
                ];
                var msgsPush = [
                    <?= wp_json_encode(__('📤 Enviando productos al TPV…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('🚚 Subiendo datos…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('🏷️ Sincronizando categorías…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('📦 Guardando en el TPV…', 'tpv-sync')) ?>,
                    <?= wp_json_encode(__('🔗 Enlazando con WooCommerce…', 'tpv-sync')) ?>,
                ];
                var msgs = (direction === 'pull') ? msgsPull : msgsPush;
                var msgIdx = 0;
                var $title = $('#cc-syncprogress-title').text(msgs[0]);
                var rotator = setInterval(function() {
                    msgIdx = (msgIdx + 1) % msgs.length;
                    $title.fadeOut(150, function() {
                        $(this).text(msgs[msgIdx]).fadeIn(150);
                    });
                }, 3500);

                var endpoint = (direction === 'pull') ? 'tpv_sync_import' : 'tpv_sync_push_all';
                var totalProcessed = 0, totalAll = 0, isFirst = true;

                // Detector de "lote vacío" que evita bucle infinito si el
                // backend devuelve processed=0 repetidamente sin error explícito.
                // Si vemos 3 ticks seguidos sin progreso, paramos.
                var stallCount = 0;
                var lastProcessed = -1;

                function tick() {
                    // Si el usuario pulsó "Cancelar y empezar de cero" durante
                    // el import, paramos el bucle inmediatamente. Sin este
                    // check, cada tick seguía atacando a credenciales borradas
                    // y reportaba 401 en bucle.
                    if (window.__tpvSyncCancelled) {
                        clearInterval(rotator);
                        return;
                    }
                    var payload = { action: endpoint, nonce: nonce };
                    if (isFirst) {
                        payload.principal = principal;
                        payload.reset = 1;
                        isFirst = false;
                    }
                    $.post(ajaxurl, payload, function(resp) {
                        if (!resp.success) {
                            clearInterval(rotator);
                            $scene.removeClass('is-active is-importing');
                            var errMsg = (resp.data && typeof resp.data === 'string')
                                ? resp.data
                                : <?= wp_json_encode(__('⚠️ Error durante la sincronización. Revisa el Log.', 'tpv-sync')) ?>;
                            $title.stop(true,true).show().html(
                                '⚠️ ' + $('<div>').text(errMsg).html()
                            );
                            $('#cc-syncprogress-bar').css('width', '100%').css('background', '#dc2626');
                            return;
                        }
                        var d = resp.data || {};
                        totalProcessed = d.processed || totalProcessed;
                        totalAll       = d.total     || totalAll;

                        // Detector de stall: si processed no avanza tras 3 ticks,
                        // algo está mal silenciosamente (típico: token revocado
                        // sin error visible). Paramos antes de loop infinito.
                        if (totalProcessed === lastProcessed) {
                            stallCount++;
                            if (stallCount >= 3) {
                                clearInterval(rotator);
                                $scene.removeClass('is-active is-importing');
                                $title.stop(true,true).show().text(
                                    <?= wp_json_encode(__('⚠️ La sincronización está atascada. El TPV puede haber revocado las credenciales. Recarga la página y vuelve a intentarlo.', 'tpv-sync')) ?>
                                );
                                $('#cc-syncprogress-bar').css('background', '#f59e0b');
                                return;
                            }
                        } else {
                            stallCount = 0;
                            lastProcessed = totalProcessed;
                        }

                        var pct = totalAll > 0 ? Math.min(100, Math.round((totalProcessed / totalAll) * 100)) : 0;
                        $('#cc-syncprogress-bar').css('width', pct + '%');
                        $('#cc-syncprogress-meta').text(
                            totalProcessed + ' / ' + (totalAll || '?') +
                            (typeof d.created === 'number' && d.created > 0 ? '  ·  ' + d.created + ' nuevos' : '') +
                            (typeof d.updated === 'number' && d.updated > 0 ? '  ·  ' + d.updated + ' actualizados' : '')
                        );
                        if (d.done) {
                            clearInterval(rotator);
                            $scene.removeClass('is-importing'); // paran los paquetes
                            $title.stop(true,true).show().text(<?= wp_json_encode(__('✓ ¡Sincronización completa!', 'tpv-sync')) ?>);
                            $('#cc-syncprogress-bar').css('width', '100%');
                            setTimeout(function() { location.reload(); }, 1200);
                            return;
                        }
                        tick();
                    }).fail(function() {
                        $title.stop(true,true).show().text(<?= wp_json_encode(__('Error de red. Reintentando…', 'tpv-sync')) ?>);
                        setTimeout(tick, 3000);
                    });
                }
                tick();
            });

            // Flag global compartido con el bucle de import: si el usuario
            // pulsa "Cancelar y empezar de cero" mientras se está importando,
            // queremos que el bucle se detenga inmediatamente. Sin esto, el
            // tick() seguía pidiendo lotes contra credenciales ya borradas
            // (loop 401 infinito que vimos en producción 2026-04-28).
            window.__tpvSyncCancelled = false;

            // Botón "Cancelar y empezar de cero" del paso 2: llamamos al
            // endpoint full_disconnect (no al disconnect "soft") para que
            // realmente se borren credenciales y volvamos al paso 1.
            $('#cc-disconnect').on('click', function() {
                if (!confirm(<?= wp_json_encode(__('¿Borrar credenciales y empezar de cero? Tendrás que volver a pegar la URL y el secret del TPV. Si hay una sincronización en curso se cancelará.', 'tpv-sync')) ?>)) return;
                window.__tpvSyncCancelled = true; // detiene el tick() del import
                var $r = $('#cc-disconnect-result').text(<?= wp_json_encode(__('Borrando…', 'tpv-sync')) ?>);
                $.post(ajaxurl, {action:'tpv_sync_full_disconnect', nonce:nonce}, function(resp) {
                    if (resp.success) location.reload();
                    else $r.text(<?= wp_json_encode(__('Error al borrar', 'tpv-sync')) ?>).addClass('cc-result-err');
                });
            });
        });
        </script>

        <?php elseif ($wizardStep === 3): /* ─── PASO 3: ACTIVAR SINCRONIZACIÓN ─── */ ?>

        <div class="cc-step cc-step-active cc-step-action-warn">
            <div class="cc-step-head">
                <span class="cc-step-num">3</span>
                <h2><?= esc_html__('Activar la sincronización', 'tpv-sync') ?></h2>
                <span class="cc-step-badge cc-badge-pending"><?= esc_html__('Paso 3 de 3', 'tpv-sync') ?></span>
            </div>
            <p class="cc-step-help">
                <?= esc_html__('Último paso: activar la sincronización en tiempo real entre tu tienda y el TPV. Es un click.', 'tpv-sync') ?>
            </p>
            <div class="cc-action-row">
                <button type="button" class="cc-btn-action cc-btn-primary-big" id="cc-reconnect-big">
                    <span class="cc-btn-icon">🔗</span>
                    <?= esc_html__('Conectar y sincronizar ahora', 'tpv-sync') ?>
                </button>
                <span id="cc-conn-result" class="cc-result"></span>
            </div>
        </div>

        <details class="cc-advanced">
            <summary><?= esc_html__('Volver atrás', 'tpv-sync') ?></summary>
            <div class="cc-advanced-body">
                <p class="cc-step-help">
                    <?= esc_html__('Si quieres cambiar la fuente o desconectarte por completo, hazlo desde aquí.', 'tpv-sync') ?>
                </p>
                <div class="cc-adv-actions">
                    <button type="button" class="button" id="cc-change-source"><?= esc_html__('Cambiar fuente', 'tpv-sync') ?></button>
                    <button type="button" class="button button-link-delete" id="cc-disconnect"><?= esc_html__('Detener y borrar credenciales locales', 'tpv-sync') ?></button>
                    <span id="cc-disconnect-result" class="cc-result"></span>
                </div>
            </div>
        </details>

        <script>
        jQuery(function($) {
            var ajaxurl = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
            var nonce   = <?= wp_json_encode($nonce) ?>;

            $('#cc-reconnect-big').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var $r = $('#cc-conn-result')
                    .text(<?= wp_json_encode(__('Conectando…', 'tpv-sync')) ?>)
                    .removeClass('cc-result-ok cc-result-err');
                $.post(ajaxurl, {action:'tpv_sync_register_webhook', nonce:nonce}, function(resp) {
                    if (resp.success) {
                        $r.text(<?= wp_json_encode(__('✓ ¡Sincronización activada!', 'tpv-sync')) ?>).addClass('cc-result-ok');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        $btn.prop('disabled', false);
                        $r.text(<?= wp_json_encode(__('✗ Error al activar la sincronización', 'tpv-sync')) ?>).addClass('cc-result-err');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $r.text(<?= wp_json_encode(__('✗ Error de red', 'tpv-sync')) ?>).addClass('cc-result-err');
                });
            });

            $('#cc-change-source').on('click', function() {
                if (!confirm(<?= wp_json_encode(__('¿Cambiar la fuente de catálogo? Volverás al paso 2.', 'tpv-sync')) ?>)) return;
                $.post(ajaxurl, {action:'tpv_sync_clear_principal', nonce:nonce}, function() {
                    location.reload();
                });
            });

            // Botón "Detener y borrar credenciales locales" del paso 3:
            // limpieza total → vuelta al paso 1.
            $('#cc-disconnect').on('click', function() {
                if (!confirm(<?= wp_json_encode(__('¿Borrar credenciales y empezar de cero? Tendrás que volver a pegar la URL y el secret del TPV.', 'tpv-sync')) ?>)) return;
                var $r = $('#cc-disconnect-result').text(<?= wp_json_encode(__('Borrando…', 'tpv-sync')) ?>);
                $.post(ajaxurl, {action:'tpv_sync_full_disconnect', nonce:nonce}, function(resp) {
                    if (resp.success) location.reload();
                    else $r.text(<?= wp_json_encode(__('Error al borrar', 'tpv-sync')) ?>).addClass('cc-result-err');
                });
            });
        });
        </script>

        <?php else: /* ─── OPERATIVO: wizard completado ─── */ ?>

        <?php /* Bloque de estado: verde si todo OK, rojo si webhook caído. */ ?>
        <?php if ($opSubState === 'ok'): ?>
        <div class="cc-step cc-step-action cc-step-action-ok">
            <div class="cc-step-head">
                <span class="cc-status-pulse cc-pulse-ok"></span>
                <h2><?= esc_html__('Conectado y sincronizando', 'tpv-sync') ?></h2>
                <span class="cc-step-badge cc-badge-ok">●&nbsp;<?= esc_html__('Activo', 'tpv-sync') ?></span>
            </div>
            <div class="cc-status-meta">
                <span><?= esc_html__('TPV:', 'tpv-sync') ?> <code><?= esc_html($hostShort) ?></code></span>
                <span class="cc-sep">·</span>
                <span><?= esc_html__('Fuente:', 'tpv-sync') ?> <strong><?= esc_html($fuente) ?></strong></span>
            </div>
        </div>
        <?php else: /* down */ ?>
        <div class="cc-step cc-step-action cc-step-action-down">
            <div class="cc-step-head">
                <span class="cc-step-num">!</span>
                <h2><?= esc_html__('Sin conexión con el TPV', 'tpv-sync') ?></h2>
                <span class="cc-step-badge cc-badge-err">●&nbsp;<?= esc_html__('Caído', 'tpv-sync') ?></span>
            </div>
            <p class="cc-step-help">
                <?= esc_html__('La sincronización está activa pero el TPV no responde. Verifica que el TPV esté online.', 'tpv-sync') ?>
            </p>
            <div class="cc-action-row">
                <button type="button" class="cc-btn-action" id="cc-test-conn-big">
                    <?= esc_html__('Probar conexión', 'tpv-sync') ?>
                </button>
                <button type="button" class="cc-btn-action cc-btn-secondary-big" id="cc-reconnect-big">
                    <?= esc_html__('Reconectar sincronización', 'tpv-sync') ?>
                </button>
                <span id="cc-conn-result" class="cc-result"></span>
            </div>
        </div>
        <?php endif; ?>

        <?php /* Toggles "Qué se sincroniza" — visibles en operativo, son la operativa diaria. */ ?>
        <div class="cc-step cc-step-toggles">
            <div class="cc-step-head">
                <h3><?= esc_html__('Qué se sincroniza', 'tpv-sync') ?></h3>
            </div>
            <form method="post" action="options.php" class="cc-toggles" id="cc-modules-form">
                <?php settings_fields('tpv_sync_settings'); ?>
                <input type="hidden" name="tpv_sync_api_url"       value="<?= esc_attr($apiUrl) ?>">
                <input type="hidden" name="tpv_sync_client_id"     value="<?= esc_attr((string) get_option('tpv_sync_client_id', '')) ?>">
                <input type="hidden" name="tpv_sync_client_secret" value="">
                <input type="hidden" name="tpv_sync_principal"     value="<?= esc_attr($principal) ?>">

                <label class="cc-toggle">
                    <input type="hidden" name="tpv_sync_module_catalog" value="0">
                    <input type="checkbox" name="tpv_sync_module_catalog" value="1" <?= checked($modCatalog, true, false) ?> data-auto-submit>
                    <span class="cc-toggle-slider"></span>
                    <span class="cc-toggle-label">
                        <strong><?= esc_html__('Catálogo', 'tpv-sync') ?></strong>
                        <small><?= esc_html__('Productos, precios, stock', 'tpv-sync') ?></small>
                    </span>
                </label>
                <label class="cc-toggle">
                    <input type="hidden" name="tpv_sync_module_orders" value="0">
                    <input type="checkbox" name="tpv_sync_module_orders" value="1" <?= checked($modOrders, true, false) ?> data-auto-submit>
                    <span class="cc-toggle-slider"></span>
                    <span class="cc-toggle-label">
                        <strong><?= esc_html__('Pedidos', 'tpv-sync') ?></strong>
                        <small><?= esc_html__('Se crean en el TPV cuando se pagan en WooCommerce', 'tpv-sync') ?></small>
                    </span>
                </label>
            </form>
        </div>

        <details class="cc-advanced">
            <summary><?= esc_html__('Avanzado', 'tpv-sync') ?></summary>
            <div class="cc-advanced-body">
                <div class="cc-adv-section">
                    <h3><?= esc_html__('Sincronización inicial', 'tpv-sync') ?></h3>
                    <p class="cc-step-help"><?= esc_html__('Solo necesitas hacerla una vez al conectar.', 'tpv-sync') ?></p>
                    <div class="cc-adv-actions">
                        <button type="button" class="button" id="cc-pull"><?= esc_html__('Importar del TPV', 'tpv-sync') ?></button>
                        <button type="button" class="button" id="cc-push"><?= esc_html__('Enviar a TPV', 'tpv-sync') ?></button>
                        <span id="cc-init-result" class="cc-result"></span>
                    </div>
                </div>

                <div class="cc-adv-section">
                    <h3><?= esc_html__('Sincronizar clientes', 'tpv-sync') ?></h3>
                    <p class="cc-step-help"><?= esc_html__('Empuja todos los clientes WooCommerce al TPV. Los que ya existan en el TPV (mismo email) se reconectan automáticamente.', 'tpv-sync') ?></p>
                    <div class="cc-adv-actions">
                        <button type="button" class="button" id="cc-push-customers"><?= esc_html__('Enviar clientes al TPV', 'tpv-sync') ?></button>
                        <span id="cc-push-customers-result" class="cc-result"></span>
                    </div>
                </div>

                <script>
                jQuery(function($) {
                    var nonce = <?= json_encode(wp_create_nonce('tpv_sync')) ?>;
                    var ajaxurl = <?= json_encode(admin_url('admin-ajax.php')) ?>;
                    var $btn = $('#cc-push-customers');
                    var $result = $('#cc-push-customers-result');

                    function runOneBatch(reset) {
                        return $.post(ajaxurl, {
                            action: 'tpv_sync_push_customers',
                            nonce: nonce,
                            reset: reset ? 1 : 0,
                            batch: 100
                        });
                    }

                    function pushLoop(reset) {
                        $btn.prop('disabled', true);
                        $result.css('color', '#666').text(<?= json_encode(__('Procesando…', 'tpv-sync')) ?>);
                        runOneBatch(reset).done(function(resp) {
                            if (!resp.success) {
                                $result.css('color', '#d63638').text(resp.data || 'error');
                                $btn.prop('disabled', false);
                                return;
                            }
                            var d = resp.data;
                            var msg = (d.accumulated.sent || 0) + '/' + (d.total || 0)
                                + ' (' + (d.accumulated.created || 0) + ' creados, '
                                + (d.accumulated.matched || 0) + ' reconectados, '
                                + (d.accumulated.skipped || 0) + ' saltados, '
                                + (d.accumulated.errors || 0) + ' errores)';
                            $result.text(msg);
                            if (d.done) {
                                $result.css('color', '#1c7c4a');
                                $btn.prop('disabled', false);
                            } else {
                                // Siguiente batch sin reset
                                setTimeout(function() { pushLoop(false); }, 200);
                            }
                        }).fail(function() {
                            $result.css('color', '#d63638').text(<?= json_encode(__('Error de red', 'tpv-sync')) ?>);
                            $btn.prop('disabled', false);
                        });
                    }

                    $btn.on('click', function() { pushLoop(true); });
                });
                </script>

                <?php if ($opSubState === 'ok'): ?>
                <div class="cc-adv-section">
                    <h3><?= esc_html__('Diagnóstico', 'tpv-sync') ?></h3>
                    <div class="cc-adv-actions">
                        <button type="button" class="button" id="cc-test-conn"><?= esc_html__('Probar conexión', 'tpv-sync') ?></button>
                        <span id="cc-conn-result-adv" class="cc-result"></span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="cc-adv-section cc-adv-danger">
                    <h3><?= esc_html__('Zona peligrosa', 'tpv-sync') ?></h3>
                    <p class="cc-step-help"><?= esc_html__('Detiene la sincronización. Las credenciales se conservan para reconectar.', 'tpv-sync') ?></p>
                    <div class="cc-adv-actions">
                        <button type="button" class="button button-link-delete" id="cc-disconnect"><?= esc_html__('Detener sincronización', 'tpv-sync') ?></button>
                        <span id="cc-disconnect-result" class="cc-result"></span>
                    </div>
                </div>
            </div>
        </details>

        <script>
        jQuery(function($) {
            var ajaxurl = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
            var nonce   = <?= wp_json_encode($nonce) ?>;

            $('input[data-auto-submit]').on('change', function() {
                $('#cc-modules-form').trigger('submit');
            });

            // Edición del paso 1 (credenciales) desde la barra de progreso.
            $(document).on('click', '.cc-progress-edit[data-edit="creds"]', function(e) {
                e.preventDefault();
                if (!confirm(<?= wp_json_encode(__('¿Cambiar credenciales? La sincronización se detendrá hasta que reconectes.', 'tpv-sync')) ?>)) return;
                $.post(ajaxurl, {action:'tpv_sync_disconnect', nonce:nonce}, function() {
                    location.reload();
                });
            });

            $('#cc-test-conn, #cc-test-conn-big').on('click', function() {
                var $r = $(this).siblings('.cc-result, #cc-conn-result, #cc-conn-result-adv').first()
                    .text(<?= wp_json_encode(__('Probando…', 'tpv-sync')) ?>)
                    .removeClass('cc-result-ok cc-result-err');
                $.post(ajaxurl, {action:'tpv_sync_test_connection', nonce:nonce}, function(resp) {
                    if (resp.success) $r.text(<?= wp_json_encode(__('✓ TPV alcanzable', 'tpv-sync')) ?>).addClass('cc-result-ok');
                    else $r.text(<?= wp_json_encode(__('✗ Error de conexión', 'tpv-sync')) ?>).addClass('cc-result-err');
                }).fail(function() {
                    $r.text(<?= wp_json_encode(__('✗ Error de conexión', 'tpv-sync')) ?>).addClass('cc-result-err');
                });
            });

            $('#cc-reconnect-big').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                var $r = $('#cc-conn-result')
                    .text(<?= wp_json_encode(__('Reconectando…', 'tpv-sync')) ?>)
                    .removeClass('cc-result-ok cc-result-err');
                $.post(ajaxurl, {action:'tpv_sync_register_webhook', nonce:nonce}, function(resp) {
                    if (resp.success) {
                        $r.text(<?= wp_json_encode(__('✓ Sincronización activa', 'tpv-sync')) ?>).addClass('cc-result-ok');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        $btn.prop('disabled', false);
                        $r.text(<?= wp_json_encode(__('✗ Error', 'tpv-sync')) ?>).addClass('cc-result-err');
                    }
                });
            });

            $('#cc-disconnect').on('click', function() {
                if (!confirm(<?= wp_json_encode(__('¿Detener la sincronización? Las credenciales se mantienen para reconectar.', 'tpv-sync')) ?>)) return;
                var $r = $('#cc-disconnect-result').text(<?= wp_json_encode(__('Deteniendo…', 'tpv-sync')) ?>);
                $.post(ajaxurl, {action:'tpv_sync_disconnect', nonce:nonce}, function(resp) {
                    if (resp.success) location.reload();
                    else $r.text(<?= wp_json_encode(__('Error al detener', 'tpv-sync')) ?>).addClass('cc-result-err');
                });
            });

            $('#cc-pull').on('click', function() {
                if (!confirm(<?= wp_json_encode(__('Importar el catálogo del TPV a WooCommerce. Puede tardar varios minutos. ¿Continuar?', 'tpv-sync')) ?>)) return;
                var $r = $('#cc-init-result').text(<?= wp_json_encode(__('Importando…', 'tpv-sync')) ?>);
                $('#cc-pull, #cc-push').prop('disabled', true);
                $.post(ajaxurl, {action:'tpv_sync_import', nonce:nonce, source:'pull'}, function(resp) {
                    $('#cc-pull, #cc-push').prop('disabled', false);
                    if (resp.success) {
                        var d = resp.data || {};
                        var n = (d.created || 0) + (d.updated || 0);
                        $r.text(n + ' ' + <?= wp_json_encode(__('productos importados', 'tpv-sync')) ?>).addClass('cc-result-ok');
                    } else {
                        $r.text(<?= wp_json_encode(__('Error en la importación', 'tpv-sync')) ?>).addClass('cc-result-err');
                    }
                });
            });

            $('#cc-push').on('click', function() {
                if (!confirm(<?= wp_json_encode(__('Enviar el catálogo de WooCommerce al TPV. ¿Continuar?', 'tpv-sync')) ?>)) return;
                var $r = $('#cc-init-result').text(<?= wp_json_encode(__('Enviando…', 'tpv-sync')) ?>);
                $('#cc-pull, #cc-push').prop('disabled', true);
                $.post(ajaxurl, {action:'tpv_sync_push_all', nonce:nonce}, function(resp) {
                    $('#cc-pull, #cc-push').prop('disabled', false);
                    if (resp.success) {
                        var d = resp.data || {};
                        $r.text((d.pushed || 0) + ' ' + <?= wp_json_encode(__('productos enviados', 'tpv-sync')) ?>).addClass('cc-result-ok');
                    } else {
                        $r.text(<?= wp_json_encode(__('Error al enviar', 'tpv-sync')) ?>).addClass('cc-result-err');
                    }
                });
            });
        });
        </script>

        <?php endif; ?>
        <?php
    }

    /**
     * Pestaña Impuestos: mapeo entre clases fiscales WooCommerce y clases del TPV.
     * Renderiza un esqueleto con loading state; los datos se cargan via AJAX
     * (ajax_load_tax_mapping) para no bloquear el render del admin si el TPV
     * tarda en responder.
     */
    private function render_taxes_tab(): void
    {
        $nonce = wp_create_nonce('tpv_sync');
        ?>
        <div class="cc-step" id="cc-taxes-root">
            <h2 style="margin-top:0;"><?= esc_html__('Equivalencia de impuestos', 'tpv-sync') ?></h2>
            <p style="color:#555;max-width:760px;">
                <?= esc_html__('Indica qué impuesto del TPV corresponde a cada impuesto de tu tienda WooCommerce. Cuando un producto se sincronice, el TPV usará el impuesto equivalente para calcular el IVA del ticket. Por defecto: "Sin impuestos".', 'tpv-sync') ?>
            </p>

            <div id="cc-taxes-warning" style="display:none;background:#fff8e5;border-left:4px solid #f0b849;padding:12px 16px;margin:12px 0;border-radius:4px;">
                <strong><?= esc_html__('Atención:', 'tpv-sync') ?></strong>
                <span id="cc-taxes-warning-text"></span>
                <a id="cc-taxes-warning-link" href="#" style="margin-left:8px;"><?= esc_html__('Ir a ajustes de impuestos de WooCommerce →', 'tpv-sync') ?></a>
            </div>

            <div id="cc-taxes-loading" style="padding:24px 0;color:#666;">
                <?= esc_html__('Cargando clases de impuestos…', 'tpv-sync') ?>
            </div>

            <div id="cc-taxes-content" style="display:none;">
                <table class="widefat striped" id="cc-taxes-table" style="max-width:780px;">
                    <thead>
                        <tr>
                            <th style="width:40%;"><?= esc_html__('Impuesto en WooCommerce', 'tpv-sync') ?></th>
                            <th><?= esc_html__('Impuesto equivalente en el TPV', 'tpv-sync') ?></th>
                        </tr>
                    </thead>
                    <tbody id="cc-taxes-tbody"></tbody>
                </table>

                <p style="margin-top:18px;">
                    <button id="cc-taxes-save" type="button" class="button button-primary">
                        <?= esc_html__('Guardar', 'tpv-sync') ?>
                    </button>
                    <span id="cc-taxes-save-status" style="margin-left:12px;color:#666;"></span>
                </p>
            </div>

            <div id="cc-taxes-error" style="display:none;background:#fde7e9;border-left:4px solid #d63638;padding:12px 16px;margin:12px 0;border-radius:4px;">
                <strong><?= esc_html__('Error:', 'tpv-sync') ?></strong>
                <span id="cc-taxes-error-text"></span>
            </div>
        </div>

        <style>
        #cc-taxes-table .cc-rates {
            display:block;
            font-size:11px;
            color:#888;
            margin-top:2px;
            font-family:ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        #cc-taxes-table td { vertical-align:top; padding:14px 12px; }
        #cc-taxes-table select { min-width:280px; }
        </style>

        <script>
        jQuery(function($) {
            const nonce = <?= json_encode($nonce) ?>;
            const ajaxurl = <?= json_encode(admin_url('admin-ajax.php')) ?>;
            const $loading = $('#cc-taxes-loading');
            const $content = $('#cc-taxes-content');
            const $error   = $('#cc-taxes-error');
            const $errorTx = $('#cc-taxes-error-text');
            const $warn    = $('#cc-taxes-warning');
            const $warnTx  = $('#cc-taxes-warning-text');
            const $warnLnk = $('#cc-taxes-warning-link');
            const $tbody   = $('#cc-taxes-tbody');
            const $status  = $('#cc-taxes-save-status');

            // Construye el dropdown TPV con rates en gris claro debajo del title.
            function buildOptionsHtml(tpvClasses, currentId) {
                let html = '<option value="0"' + (currentId === 0 ? ' selected' : '') + '>'
                    + <?= json_encode(__('Sin impuestos', 'tpv-sync')) ?> + '</option>';
                tpvClasses.forEach(function(cls) {
                    if (cls.tax_class_id === 0) return;
                    const sel = (cls.tax_class_id === currentId) ? ' selected' : '';
                    // El title del option se concatena con los rates entre paréntesis.
                    // <select> no soporta HTML enriquecido en options nativos —
                    // los rates van como texto en el option, y aparte los
                    // mostramos en gris debajo del select (renderRow).
                    let label = cls.title;
                    if (cls.rates && cls.rates.length > 0) {
                        const rs = cls.rates.map(r => {
                            const v = r.type === 'F'
                                ? (r.rate.toFixed(2) + ' €')
                                : (r.rate.toFixed(0) + '%');
                            return v;
                        }).join(', ');
                        label += ' (' + rs + ')';
                    } else {
                        label += ' ⚠ ' + <?= json_encode(__('sin tasas', 'tpv-sync')) ?>;
                    }
                    html += '<option value="' + cls.tax_class_id + '"' + sel + '>' + label + '</option>';
                });
                return html;
            }

            function renderRow(wc, tpvClasses, currentId) {
                const $tr = $('<tr>');
                const $tdName = $('<td>').text(wc.name);
                const $select = $('<select>').attr('data-slug', wc.slug)
                    .html(buildOptionsHtml(tpvClasses, currentId));
                $tr.append($tdName).append($('<td>').append($select));
                return $tr;
            }

            function load() {
                $.post(ajaxurl, {action:'tpv_sync_load_tax_mapping', nonce:nonce})
                    .done(function(resp) {
                        if (!resp.success) {
                            $loading.hide();
                            $error.show();
                            $errorTx.text(resp.data || 'unknown');
                            return;
                        }
                        const d = resp.data;
                        $tbody.empty();
                        d.wc_classes.forEach(function(wc) {
                            const currentId = (d.mapping[wc.slug] || 0) | 0;
                            $tbody.append(renderRow(wc, d.tpv_classes, currentId));
                        });
                        if (d.warn_empty_rates) {
                            $warnTx.text(<?= json_encode(__('WooCommerce tiene los impuestos activados pero NO hay tasas (rates) configuradas. Las ventas online saldrán al 0%, mientras el TPV físico sí aplicará IVA según la clase mapeada. Mismatch fiscal entre web y caja.', 'tpv-sync')) ?>);
                            $warnLnk.attr('href', d.wc_tax_settings_url);
                            $warn.show();
                        } else {
                            $warn.hide();
                        }
                        $loading.hide();
                        $content.show();
                    })
                    .fail(function() {
                        $loading.hide();
                        $error.show();
                        $errorTx.text(<?= json_encode(__('No se pudieron cargar los datos.', 'tpv-sync')) ?>);
                    });
            }

            $('#cc-taxes-save').on('click', function() {
                const mapping = {};
                $('#cc-taxes-tbody select').each(function() {
                    const slug = $(this).attr('data-slug');
                    const id = parseInt($(this).val(), 10) || 0;
                    mapping[slug] = id;
                });
                $status.text(<?= json_encode(__('Guardando…', 'tpv-sync')) ?>).css('color', '#666');
                // Enviar como JSON en un único campo. jQuery serializaría
                // mapping[''] como mapping[]=13 y PHP lo interpretaría como
                // índice numérico 0, perdiendo el slug vacío de Standard.
                $.post(ajaxurl, {
                    action: 'tpv_sync_save_tax_mapping',
                    nonce: nonce,
                    mapping_json: JSON.stringify(mapping)
                })
                    .done(function(resp) {
                        if (resp.success) {
                            $status.text(<?= json_encode(__('Guardado ✓', 'tpv-sync')) ?>).css('color', '#1c7c4a');
                        } else {
                            $status.text(<?= json_encode(__('Error al guardar', 'tpv-sync')) ?>).css('color', '#d63638');
                        }
                    })
                    .fail(function() {
                        $status.text(<?= json_encode(__('Error al guardar', 'tpv-sync')) ?>).css('color', '#d63638');
                    });
            });

            load();
        });
        </script>
        <?php
    }

    private function render_logs_tab(): void
    {
        global $wpdb;
        $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tpv_sync_log ORDER BY id DESC LIMIT %d", 200));
        ?>
        <div class="cc-step" style="padding:0;overflow:hidden;">
            <table class="widefat striped cc-log-table" style="border:none;">
                <thead>
                    <tr>
                        <th style="width:150px;"><?= esc_html__('Fecha', 'tpv-sync') ?></th>
                        <th style="width:160px;"><?= esc_html__('Evento', 'tpv-sync') ?></th>
                        <th style="width:80px;">ID</th>
                        <th style="width:80px;"><?= esc_html__('Estado', 'tpv-sync') ?></th>
                        <th><?= esc_html__('Mensaje', 'tpv-sync') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:30px;"><?= esc_html__('Sin eventos todavía.', 'tpv-sync') ?></td></tr>
                <?php else: foreach ($logs as $log): ?>
                    <tr>
                        <td style="color:#888;"><?= esc_html(substr($log->created_at, 0, 16)) ?></td>
                        <td><code><?= esc_html($log->event_type) ?></code></td>
                        <td style="color:#888;"><?= $log->resource_id ?: '—' ?></td>
                        <td>
                            <?php if ($log->status === 'ok'): ?>
                                <span style="color:#1a7f37;font-weight:600;">✓ ok</span>
                            <?php elseif ($log->status === 'skip'): ?>
                                <span style="color:#9a6700;">⏭ skip</span>
                            <?php else: ?>
                                <span style="color:#cf222e;font-weight:600;">✗ error</span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc_html($log->message) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- DIAGNÓSTICO — Cola de reintentos (semioculto) -->
        <details class="cc-diag">
            <summary><?= esc_html__('Diagnóstico avanzado: cola de reintentos', 'tpv-sync') ?></summary>
            <div class="cc-diag-body">
                <?php $this->render_queue_section(); ?>
            </div>
        </details>

        <!-- DIAGNÓSTICO — Webhooks fallidos (DLQ) -->
        <details class="cc-diag">
            <summary><?= esc_html__('Diagnóstico avanzado: webhooks fallidos (DLQ)', 'tpv-sync') ?></summary>
            <div class="cc-diag-body">
                <?php $this->render_dlq_section(); ?>
            </div>
        </details>
        <?php
    }

    /**
     * Diagnóstico DLQ — webhooks que llegaron OK firma+idempotencia pero
     * que fallaron al ejecutarse en el handler (excepción en upsert,
     * stock, return, etc.). Sin esta UI no había forma de reintentarlos
     * tras arreglar la causa subyacente.
     */
    private function render_dlq_section(): void
    {
        global $wpdb;
        $t = TPV_Sync_Webhook::dlq_table_name();
        $api = new TPV_Sync_API_Client();
        $webhook = new TPV_Sync_Webhook(
            new TPV_Sync_Product_Sync($api),
            new TPV_Sync_Order_Sync($api),
            $api
        );

        if (isset($_POST['tpv_dlq_action']) && wp_verify_nonce((string) ($_POST['tpv_sync_dlq_nonce'] ?? ''), 'tpv_sync_dlq')) {
            $action = sanitize_key($_POST['tpv_dlq_action']);
            $id     = (int) ($_POST['id'] ?? 0);
            if ($action === 'replay' && $id > 0) {
                $r = $webhook->dlq_replay($id);
                if (!empty($r['ok'])) {
                    echo '<div class="notice notice-success"><p>' . esc_html__('Evento reintentado con éxito.', 'tpv-sync') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Reintento falló: %s', 'tpv-sync'), $r['error'] ?? 'desconocido')) . '</p></div>';
                }
            } elseif ($action === 'replay_all') {
                $r = $webhook->dlq_replay_all();
                echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                    __('Reintentados: %d · OK: %d · errores: %d', 'tpv-sync'),
                    $r['attempted'], $r['ok'], $r['err']
                )) . '</p></div>';
            } elseif ($action === 'delete' && $id > 0) {
                TPV_Sync_Webhook::dlq_delete($id);
                echo '<div class="notice notice-success"><p>' . esc_html__('Entrada eliminada.', 'tpv-sync') . '</p></div>';
            } elseif ($action === 'purge_replayed') {
                $deleted = $wpdb->query("DELETE FROM $t WHERE status = 'replayed'");
                echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('%d entradas replayed purgadas.', 'tpv-sync'), (int) $deleted)) . '</p></div>';
            }
        }

        $stats = TPV_Sync_Webhook::dlq_stats();
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 100");
        ?>
        <p style="color:#64748b;font-size:13px;margin-top:0;">
            <?= esc_html__('Webhooks que llegaron correctos (firma + no duplicados) pero fallaron al procesarse en WordPress. Reintenta cuando hayas arreglado la causa.', 'tpv-sync') ?>
        </p>
        <div style="display:flex;gap:12px;margin:12px 0;">
            <?php foreach (['pending' => '#d94f4f', 'replayed' => '#2bb673'] as $key => $color): ?>
                <div style="flex:1;padding:10px 12px;border-left:4px solid <?= esc_attr($color) ?>;background:#fff;box-shadow:0 1px 0 rgba(0,0,0,0.04);">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?= esc_html(ucfirst($key)) ?></div>
                    <div style="font-size:20px;font-weight:600;"><?= intval($stats[$key] ?? 0) ?></div>
                </div>
            <?php endforeach; ?>
            <div style="flex:1;padding:10px 12px;border-left:4px solid #555;background:#fff;">
                <div style="font-size:11px;color:#666;text-transform:uppercase;"><?= esc_html__('Total', 'tpv-sync') ?></div>
                <div style="font-size:20px;font-weight:600;"><?= intval($stats['total']) ?></div>
            </div>
        </div>

        <div style="margin:12px 0;display:flex;gap:6px;flex-wrap:wrap;">
            <?php if (($stats['pending'] ?? 0) > 0): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?= esc_js(__('¿Reintentar todos los webhooks pendientes?', 'tpv-sync')) ?>');">
                <?php wp_nonce_field('tpv_sync_dlq', 'tpv_sync_dlq_nonce'); ?>
                <input type="hidden" name="tpv_dlq_action" value="replay_all">
                <button class="button button-primary button-small">🔁 <?= esc_html__('Reintentar todos los pendientes', 'tpv-sync') ?></button>
            </form>
            <?php endif; ?>
            <?php if (($stats['replayed'] ?? 0) > 0): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?= esc_js(__('¿Borrar las entradas replayed?', 'tpv-sync')) ?>');">
                <?php wp_nonce_field('tpv_sync_dlq', 'tpv_sync_dlq_nonce'); ?>
                <input type="hidden" name="tpv_dlq_action" value="purge_replayed">
                <button class="button button-small"><?= esc_html__('Purgar replayed', 'tpv-sync') ?></button>
            </form>
            <?php endif; ?>
        </div>

        <table class="wp-list-table widefat striped" style="font-size:12px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?= esc_html__('Evento', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Recurso', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Estado', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Intentos', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Último error', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Recibido', 'tpv-sync') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" style="text-align:center;padding:20px;color:#999;"><?= esc_html__('Sin webhooks fallidos. ✓', 'tpv-sync') ?></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) $r->id ?></td>
                    <td><code><?= esc_html($r->event_type) ?></code></td>
                    <td><?= (int) $r->resource_id ?: '-' ?></td>
                    <td>
                        <?php $c = $r->status === 'pending' ? '#d94f4f' : '#2bb673'; ?>
                        <span style="padding:2px 8px;border-radius:10px;background:<?= esc_attr($c) ?>;color:#fff;font-size:11px;"><?= esc_html($r->status) ?></span>
                    </td>
                    <td><?= (int) $r->attempts ?></td>
                    <td style="max-width:280px;font-family:monospace;font-size:11px;color:#a00;"><?= esc_html(substr((string) $r->last_error, 0, 200)) ?></td>
                    <td><?= esc_html((string) $r->created_at) ?></td>
                    <td>
                        <?php if ($r->status === 'pending'): ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('tpv_sync_dlq', 'tpv_sync_dlq_nonce'); ?>
                            <input type="hidden" name="tpv_dlq_action" value="replay">
                            <input type="hidden" name="id" value="<?= (int) $r->id ?>">
                            <button class="button button-small" title="<?= esc_attr__('Reintentar ahora', 'tpv-sync') ?>">🔁</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('<?= esc_js(__('¿Borrar?', 'tpv-sync')) ?>');">
                            <?php wp_nonce_field('tpv_sync_dlq', 'tpv_sync_dlq_nonce'); ?>
                            <input type="hidden" name="tpv_dlq_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r->id ?>">
                            <button class="button button-small button-link-delete">×</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    // ── Diagnóstico: Queue (dentro del desplegable) ───────────────────────────

    private function render_queue_section(): void
    {
        global $wpdb;
        $t   = $wpdb->prefix . 'tpv_sync_queue';
        $api = new TPV_Sync_API_Client();
        $queue = new TPV_Sync_Queue(
            $api,
            new TPV_Sync_Product_Sync($api),
            new TPV_Sync_Order_Sync($api)
        );

        if (isset($_POST['tpv_queue_action']) && wp_verify_nonce((string)$_POST['tpv_sync_queue_nonce'] ?? '', 'tpv_sync_queue')) {
            $action = sanitize_key($_POST['tpv_queue_action']);
            $id     = (int)($_POST['id'] ?? 0);
            if ($action === 'retry' && $id > 0) {
                $queue->retry($id);
                echo '<div class="notice notice-success"><p>' . esc_html__('Entrada reencolada para reintento inmediato.', 'tpv-sync') . '</p></div>';
            } elseif ($action === 'retry_all_abandoned') {
                $ids = $wpdb->get_col("SELECT id FROM $t WHERE status='abandoned' LIMIT 500");
                foreach ($ids as $rowId) $queue->retry((int)$rowId);
                echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('%d entradas abandonadas reencoladas.', 'tpv-sync'), count($ids)) . '</p></div>';
            } elseif ($action === 'delete' && $id > 0) {
                $wpdb->delete($t, ['id' => $id]);
                echo '<div class="notice notice-success"><p>' . esc_html__('Entrada eliminada.', 'tpv-sync') . '</p></div>';
            } elseif ($action === 'purge') {
                $n = $queue->purge(30);
                echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('%d entradas purgadas (done/abandoned > 30 días).', 'tpv-sync'), $n) . '</p></div>';
            }
        }

        $stats  = $queue->stats();
        $filter = sanitize_key($_GET['qfilter'] ?? 'all');
        $where  = match ($filter) {
            'pending'   => "WHERE status='pending'",
            'abandoned' => "WHERE status='abandoned'",
            'done'      => "WHERE status='done'",
            default     => '',
        };
        $rows = $wpdb->get_results("SELECT * FROM $t $where ORDER BY id DESC LIMIT 100");
        ?>
        <p style="color:#64748b;font-size:13px;margin-top:0;">
            <?= esc_html__('Cuando una petición al TPV falla, entra aquí para reintentarse con backoff. Solo es útil para diagnóstico — en operación normal no hace falta mirarlo.', 'tpv-sync') ?>
        </p>
        <div style="display:flex;gap:12px;margin:12px 0;">
            <?php foreach (['pending' => '#f0b429', 'done' => '#2bb673', 'abandoned' => '#d94f4f'] as $key => $color): ?>
                <div style="flex:1;padding:10px 12px;border-left:4px solid <?= esc_attr($color) ?>;background:#fff;box-shadow:0 1px 0 rgba(0,0,0,0.04);">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?= esc_html(ucfirst($key)) ?></div>
                    <div style="font-size:20px;font-weight:600;"><?= intval($stats[$key] ?? 0) ?></div>
                </div>
            <?php endforeach; ?>
            <div style="flex:1;padding:10px 12px;border-left:4px solid #555;background:#fff;">
                <div style="font-size:11px;color:#666;text-transform:uppercase;"><?= esc_html__('Total', 'tpv-sync') ?></div>
                <div style="font-size:20px;font-weight:600;"><?= intval($stats['total'] ?? 0) ?></div>
            </div>
        </div>
        <div style="margin:12px 0;display:flex;gap:6px;flex-wrap:wrap;">
            <a href="?page=tpv-sync&tab=logs&qfilter=all"       class="button button-small <?= $filter==='all'?'button-primary':'' ?>"><?= esc_html__('Todos', 'tpv-sync') ?></a>
            <a href="?page=tpv-sync&tab=logs&qfilter=pending"   class="button button-small <?= $filter==='pending'?'button-primary':'' ?>"><?= esc_html__('Pendientes', 'tpv-sync') ?></a>
            <a href="?page=tpv-sync&tab=logs&qfilter=abandoned" class="button button-small <?= $filter==='abandoned'?'button-primary':'' ?>"><?= esc_html__('Abandonados', 'tpv-sync') ?></a>
            <a href="?page=tpv-sync&tab=logs&qfilter=done"      class="button button-small <?= $filter==='done'?'button-primary':'' ?>"><?= esc_html__('Completados', 'tpv-sync') ?></a>
            <div style="flex:1"></div>
            <?php if (($stats['abandoned'] ?? 0) > 0): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?= esc_js(__('¿Reencolar todas las entradas abandonadas?', 'tpv-sync')) ?>');">
                <?php wp_nonce_field('tpv_sync_queue', 'tpv_sync_queue_nonce'); ?>
                <input type="hidden" name="tpv_queue_action" value="retry_all_abandoned">
                <button class="button button-small">🔁 <?= esc_html__('Reencolar abandonados', 'tpv-sync') ?></button>
            </form>
            <?php endif; ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?= esc_js(__('¿Purgar entradas done/abandoned de >30 días?', 'tpv-sync')) ?>');">
                <?php wp_nonce_field('tpv_sync_queue', 'tpv_sync_queue_nonce'); ?>
                <input type="hidden" name="tpv_queue_action" value="purge">
                <button class="button button-small"><?= esc_html__('Purgar antiguos', 'tpv-sync') ?></button>
            </form>
        </div>
        <table class="wp-list-table widefat striped" style="font-size:12px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?= esc_html__('Operación', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Estado', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Intentos', 'tpv-sync') ?></th>
                    <th><?= esc_html__('Último error', 'tpv-sync') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;padding:20px;color:#999;"><?= esc_html__('Sin entradas.', 'tpv-sync') ?></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r->id ?></td>
                    <td><code><?= esc_html($r->operation) ?></code></td>
                    <td>
                        <?php
                        $colors = ['pending' => '#f0b429', 'done' => '#2bb673', 'abandoned' => '#d94f4f'];
                        $c = $colors[$r->status] ?? '#777';
                        ?>
                        <span style="padding:2px 8px;border-radius:10px;background:<?= esc_attr($c) ?>;color:#fff;font-size:11px;"><?= esc_html($r->status) ?></span>
                    </td>
                    <td><?= (int)$r->attempts ?></td>
                    <td style="max-width:320px;font-family:monospace;font-size:11px;color:#a00;"><?= esc_html(substr((string)$r->last_error, 0, 120)) ?></td>
                    <td>
                        <?php if (in_array($r->status, ['pending', 'abandoned'], true)): ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('tpv_sync_queue', 'tpv_sync_queue_nonce'); ?>
                            <input type="hidden" name="tpv_queue_action" value="retry">
                            <input type="hidden" name="id" value="<?= (int)$r->id ?>">
                            <button class="button button-small" title="<?= esc_attr__('Reintentar ahora', 'tpv-sync') ?>">🔁</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('<?= esc_js(__('¿Borrar?', 'tpv-sync')) ?>');">
                            <?php wp_nonce_field('tpv_sync_queue', 'tpv_sync_queue_nonce'); ?>
                            <input type="hidden" name="tpv_queue_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r->id ?>">
                            <button class="button button-small" title="<?= esc_attr__('Eliminar', 'tpv-sync') ?>">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    /**
     * Limpia la opción `tpv_sync_principal` para hacer que el wizard
     * vuelva al paso 2. Sólo lo invoca el botón "Cambiar fuente" del
     * paso 3 cuando el usuario decide reconsiderar antes de activar el
     * webhook. NO toca credenciales ni webhook (este último todavía no
     * existe en este punto del wizard).
     */
    public function ajax_clear_principal(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        delete_option('tpv_sync_principal');
        wp_send_json_success('ok');
    }

    /**
     * Conteo rápido de productos en el TPV — usado por el paso 2 del wizard
     * para mostrar "X productos" en la caja del TPV. Best-effort: si la API
     * falla, devolvemos null y la UI muestra "—". No bloquea el flujo.
     */
    public function ajax_count_remote(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        try {
            $api = new TPV_Sync_API_Client();
            if (!$api->isConfigured()) {
                wp_send_json_error('not_configured');
            }
            // GET /products?per_page=1&count=1 → la respuesta incluye meta.total.
            // Sin `count=1` la API devuelve total=null (cursor pagination evita
            // el COUNT(*) por defecto para no penalizar performance).
            $resp = $api->get('/products', ['per_page' => 1, 'count' => 1]);
            $total = (int) ($resp['meta']['total'] ?? $resp['total'] ?? 0);
            wp_send_json_success(['total' => $total]);
        } catch (Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_test_connection(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        $api = new TPV_Sync_API_Client();
        if (!$api->isConfigured()) {
            wp_send_json_error(__('Configura primero las credenciales.', 'tpv-sync'));
        }
        try {
            $result = $api->get('/stores');
            isset($result['data'])
                ? wp_send_json_success(__('Conexión OK', 'tpv-sync'))
                : wp_send_json_error(__('Respuesta inesperada: ', 'tpv-sync') . wp_json_encode($result));
        } catch (Throwable $e) { wp_send_json_error($e->getMessage()); }
    }

    /**
     * AJAX para el botón unificado "Comprobar sincronización".
     * Devuelve los 4 números del estado: synced/islands_wc/islands_tpv/divergences,
     * más el contador unimportable (productos del TPV que no se pueden importar
     * a WC por datos rotos: precio negativo, model vacío, model duplicado en TPV,
     * o productos internos del TPV como POS_DISCOUNT/POS_SERVICE/POS_TIP).
     *
     * Mismo contrato que el endpoint check_sync del módulo PS:
     *   { synced, islands_wc, islands_tpv, divergences, unimportable,
     *     wc_total, tpv_total, only_in_tpv_sample, error }
     */
    public function ajax_check_sync(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        @set_time_limit(60);
        if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

        try {
            $api = new TPV_Sync_API_Client();
            if (!$api->isConfigured()) {
                wp_send_json_error(__('Configura primero las credenciales.', 'tpv-sync'));
            }

            // Snapshot del catálogo TPV.
            $tpvProducts = $api->getAll('/products', [
                'fields' => 'product_id,model,sku,name,price',
            ]);
            $tpvIndex = [];
            foreach ($tpvProducts as $row) {
                $tid = (int) ($row['product_id'] ?? 0);
                if ($tid <= 0) continue;
                $tpvIndex[$tid] = [
                    'tpv_product_id' => $tid,
                    'model'          => (string) ($row['model'] ?? ''),
                    'sku'            => (string) ($row['sku'] ?? ''),
                    'name'           => (string) ($row['name'] ?? ''),
                    'price'          => (float) ($row['price'] ?? 0),
                ];
            }
            $tpvTotal = count($tpvIndex);

            // Mapeos locales: post_meta _tpv_product_id en WC.
            global $wpdb;
            $mappedRows = $wpdb->get_results(
                "SELECT post_id, meta_value AS tpv_product_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tpv_product_id' AND meta_value > 0",
                ARRAY_A
            ) ?: [];
            $inWc = [];
            foreach ($mappedRows as $m) {
                $inWc[(int) $m['tpv_product_id']] = true;
            }
            $mapped = count($inWc);

            // Auto-clasificación de productos NO IMPORTABLES (mismo criterio que PS):
            //   - precio negativo (WC rechaza con get_regular_price() < 0)
            //   - model vacío (sin identificador único cross-system)
            //   - model duplicado en TPV (UNIQUE en WC rechaza el 2º)
            //   - productos internos del POS (POS_DISCOUNT, POS_SERVICE, POS_TIP)
            $modelCount = [];
            foreach ($tpvIndex as $p) {
                $m = trim((string) $p['model']);
                if ($m !== '') {
                    $modelCount[$m] = ($modelCount[$m] ?? 0) + 1;
                }
            }
            $internalPosModels = ['POS_DISCOUNT', 'POS_SERVICE', 'POS_TIP'];
            $isUnimportable = function (array $p) use ($modelCount, $internalPosModels): bool {
                if ((float) ($p['price'] ?? 0) < 0) return true;
                $m = trim((string) ($p['model'] ?? ''));
                if ($m === '') return true;
                if (in_array($m, $internalPosModels, true)) return true;
                if (($modelCount[$m] ?? 0) > 1) return true;
                return false;
            };

            // Productos solo en TPV (que SÍ se podrían importar = islas TPV reales).
            $onlyInTpv = [];
            $unimportableCount = 0;
            foreach ($tpvIndex as $tid => $p) {
                if (isset($inWc[$tid])) continue;
                if ($isUnimportable($p)) { $unimportableCount++; continue; }
                $onlyInTpv[] = $p;
            }
            $islandsTpv = count($onlyInTpv);
            $sampleTpv = array_slice($onlyInTpv, 0, 100);

            // Productos solo en WC (sin meta _tpv_product_id).
            $wcTotal = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'product' AND post_status IN ('publish','draft','private')"
            );
            $islandsWc = max(0, $wcTotal - $mapped);

            wp_send_json_success([
                'synced'           => $mapped,
                'islands_wc'       => $islandsWc,
                'islands_tpv'      => $islandsTpv,
                'divergences'      => $islandsTpv,
                'unimportable'     => $unimportableCount,
                'wc_total'         => $wcTotal,
                'tpv_total'        => $tpvTotal,
                'only_in_tpv_sample' => $sampleTpv,
                'error'            => null,
            ]);
        } catch (Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX import por lotes — refactorizado para no reventar PHP en
     * catálogos grandes. El cliente JS llama hasta `done=true`.
     */
    public function ajax_import(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        @set_time_limit(120);
        if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

        // Persistimos la decisión "¿quién manda?" en una option TEMPORAL
        // (tpv_sync_principal_pending) durante el import, no en la
        // definitiva. Razón: si el cliente refresca o el import falla a
        // mitad, NO queremos que el wizard salte al paso 3 (operativo)
        // creyendo que ya está todo hecho. Solo movemos pending → real
        // cuando done=true (ver más abajo).
        // Bug 2026-04-28: guardábamos directamente tpv_sync_principal y
        // si fallaba el import por 401, el siguiente refresh saltaba al
        // paso 3 con webhook ausente y todo a medias.
        $principal = isset($_POST['principal']) ? sanitize_text_field((string) $_POST['principal']) : '';
        if (in_array($principal, ['tpv', 'wc'], true)) {
            update_option('tpv_sync_principal_pending', $principal, false);
        }

        $batchSize = 50;
        $reset = !empty($_POST['reset']);

        if ($reset) {
            delete_option('tpv_sync_pull_offset');
            delete_option('tpv_sync_pull_created_total');
            delete_option('tpv_sync_pull_updated_total');
            delete_option('tpv_sync_pull_errors_total');
            delete_option('tpv_sync_pull_ids_cache');
            delete_option('tpv_sync_pull_ids_cache_ts');
        }

        $offset = (int) get_option('tpv_sync_pull_offset', 0);

        try {
            $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
            $stats = $sync->import_all(['offset' => $offset, 'limit' => $batchSize]);

            update_option('tpv_sync_pull_created_total',
                ((int) get_option('tpv_sync_pull_created_total', 0)) + (int) ($stats['created'] ?? 0), false);
            update_option('tpv_sync_pull_updated_total',
                ((int) get_option('tpv_sync_pull_updated_total', 0)) + (int) ($stats['updated'] ?? 0), false);
            update_option('tpv_sync_pull_errors_total',
                ((int) get_option('tpv_sync_pull_errors_total', 0)) + (int) ($stats['errors'] ?? 0), false);

            $next = (int) ($stats['next_offset'] ?? 0);
            $done = $next === 0;

            if ($done) {
                $createdT = (int) get_option('tpv_sync_pull_created_total', 0);
                $updatedT = (int) get_option('tpv_sync_pull_updated_total', 0);
                $errorsT  = (int) get_option('tpv_sync_pull_errors_total', 0);
                delete_option('tpv_sync_pull_offset');
                delete_option('tpv_sync_pull_created_total');
                delete_option('tpv_sync_pull_updated_total');
                delete_option('tpv_sync_pull_errors_total');
                delete_option('tpv_sync_pull_ids_cache');
                delete_option('tpv_sync_pull_ids_cache_ts');

                // Promover pending → definitiva. Solo aquí (cuando done=true)
                // confirmamos la decisión "¿quién manda?". Si el cliente
                // refresca antes de llegar aquí, el wizard se queda en el
                // paso 2 esperando que vuelva a elegir.
                $pending = get_option('tpv_sync_principal_pending', '');
                if (in_array($pending, ['tpv', 'wc'], true)) {
                    update_option('tpv_sync_principal', $pending, false);
                    delete_option('tpv_sync_principal_pending');
                }

                wp_send_json_success([
                    'processed' => $offset + (int) ($stats['processed'] ?? 0),
                    'total'     => (int) ($stats['total_seen'] ?? 0),
                    'created'   => $createdT,
                    'updated'   => $updatedT,
                    'errors'    => $errorsT,
                    'done'      => true,
                    'message'   => sprintf(
                        __('%d creados · %d actualizados · %d errores.', 'tpv-sync'),
                        $createdT, $updatedT, $errorsT
                    ),
                    'orphans' => $stats['orphans'] ?? [],
                ]);
                return;
            }

            update_option('tpv_sync_pull_offset', $next, false);
            wp_send_json_success([
                'processed' => $offset + (int) ($stats['processed'] ?? 0),
                'total'     => (int) ($stats['total_seen'] ?? 0),
                'created'   => (int) ($stats['created'] ?? 0),
                'updated'   => (int) ($stats['updated'] ?? 0),
                'errors'    => (int) ($stats['errors'] ?? 0),
                'done'      => false,
                'orphans'   => [],
            ]);
        } catch (Throwable $e) { wp_send_json_error($e->getMessage()); }
    }

    /**
     * AJAX push por lotes — el cliente JS llama en bucle hasta `done=true`.
     *
     * Cambios sobre la versión anterior (todo-en-una-request):
     *   - Procesa solo BATCH_SIZE productos por llamada (default 100).
     *   - Usa el endpoint POST /products/bulk del TPV (1 request HTTP por
     *     lote vs 100 individuales) — 30× más rápido en catálogos grandes.
     *   - Persiste offset entre llamadas en una opción de WP.
     *   - Devuelve {processed, total, sent, skipped, errors, done} igual
     *     que processPushBatch del plugin PS para reusar el mismo JS.
     *
     * El cliente puede pasar action=push&reset=1 para arrancar desde 0.
     */
    public function ajax_push_all(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        @set_time_limit(120);
        // Liberar lock de sesión cuanto antes — concurrencia real con
        // múltiples merchants simultáneos en un mismo WP Multisite.
        if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

        // Pending (no definitivo) — solo se promueve al terminar el push.
        // Ver explicación en ajax_import.
        $principal = isset($_POST['principal']) ? sanitize_text_field((string) $_POST['principal']) : '';
        if (in_array($principal, ['tpv', 'wc'], true)) {
            update_option('tpv_sync_principal_pending', $principal, false);
        }

        $batchSize  = 100;
        $skipSynced = !empty($_POST['skip_synced']);
        $reset      = !empty($_POST['reset']);

        if ($reset) {
            delete_option('tpv_sync_push_offset');
            delete_option('tpv_sync_push_sent_total');
            delete_option('tpv_sync_push_skipped_total');
            delete_option('tpv_sync_push_errors_total');
        }

        $offset = (int) get_option('tpv_sync_push_offset', 0);

        // Total con un único COUNT — get_posts(-1) cargaba todos los IDs en
        // memoria solo para contar, derroche con 50k productos.
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status IN ('publish','draft')"
        );

        $postIds = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => $batchSize,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $sync   = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
        $sent = 0; $skipped = 0; $errors = 0;
        $errorMessages = [];

        // Filtrar pre-mapeados si skipSynced=1 (ahorra llamadas).
        $idsToPush = [];
        foreach ($postIds as $postId) {
            if ($skipSynced && get_post_meta($postId, '_tpv_product_id', true)) {
                $skipped++;
                continue;
            }
            $idsToPush[] = (int) $postId;
        }

        // Push bulk si hay productos. El método `pushProductsBulk` del WC
        // usa /products/bulk del TPV cuando es posible y cae al singular
        // sólo para productos con variantes (que el bulk del TPV no maneja).
        if (!empty($idsToPush) && method_exists($sync, 'push_wc_products_bulk')) {
            try {
                $r = $sync->push_wc_products_bulk($idsToPush);
                $sent    = (int) ($r['sent']    ?? 0);
                $errors  = (int) ($r['errors']  ?? 0);
                $skipped = $skipped + max(0, count($idsToPush) - $sent - $errors);
            } catch (Throwable $e) {
                // Fallback completo a singular si el bulk peta.
                foreach ($idsToPush as $postId) {
                    try {
                        if ($sync->push_wc_product_to_tpv($postId)) { $sent++; }
                        else { $skipped++; }
                    } catch (Throwable $e2) {
                        $errors++;
                        if (count($errorMessages) < 20) {
                            $errorMessages[] = "post_id=$postId: " . $e2->getMessage();
                        }
                    }
                }
            }
        } else {
            // Plugin todavía sin push_wc_products_bulk — modo legacy singular.
            foreach ($idsToPush as $postId) {
                try {
                    if ($sync->push_wc_product_to_tpv($postId)) { $sent++; }
                    else { $skipped++; }
                } catch (Throwable $e) {
                    $errors++;
                    if (count($errorMessages) < 20) {
                        $errorMessages[] = "post_id=$postId: " . $e->getMessage();
                    }
                }
            }
        }

        // Acumuladores cross-batch para resumen final.
        update_option('tpv_sync_push_sent_total',
            ((int) get_option('tpv_sync_push_sent_total', 0)) + $sent, false);
        update_option('tpv_sync_push_skipped_total',
            ((int) get_option('tpv_sync_push_skipped_total', 0)) + $skipped, false);
        update_option('tpv_sync_push_errors_total',
            ((int) get_option('tpv_sync_push_errors_total', 0)) + $errors, false);

        $processed = $offset + count($postIds);
        $done = count($postIds) < $batchSize;

        if ($done) {
            // Snapshot finales y limpieza.
            $sentT    = (int) get_option('tpv_sync_push_sent_total', 0);
            $skippedT = (int) get_option('tpv_sync_push_skipped_total', 0);
            $errorsT  = (int) get_option('tpv_sync_push_errors_total', 0);
            delete_option('tpv_sync_push_offset');
            delete_option('tpv_sync_push_sent_total');
            delete_option('tpv_sync_push_skipped_total');
            delete_option('tpv_sync_push_errors_total');

            // Promover principal pending → definitiva al completar el push.
            $pending = get_option('tpv_sync_principal_pending', '');
            if (in_array($pending, ['tpv', 'wc'], true)) {
                update_option('tpv_sync_principal', $pending, false);
                delete_option('tpv_sync_principal_pending');
            }

            wp_send_json_success([
                'processed' => $processed,
                'total'     => $total,
                'sent'      => $sentT,
                'skipped'   => $skippedT,
                'errors'    => $errorsT,
                'done'      => true,
                'message'   => sprintf(
                    __('%d enviados · %d omitidos · %d errores (de %d total).', 'tpv-sync'),
                    $sentT, $skippedT, $errorsT, $total
                ),
                'errors_detail' => $errorMessages,
            ]);
            return;
        }

        update_option('tpv_sync_push_offset', $offset + $batchSize, false);

        wp_send_json_success([
            'processed' => $processed,
            'total'     => $total,
            'sent'      => $sent,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'done'      => false,
            'errors_detail' => $errorMessages,
        ]);
    }

    public function ajax_reset_sync(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        set_time_limit(600);

        $alsoDeleteInTpv = !empty($_POST['delete_in_tpv']);

        global $wpdb;
        $metaTable = $wpdb->postmeta;

        $pairs = $wpdb->get_results(
            "SELECT post_id, meta_value AS tpv_id FROM {$metaTable}
             WHERE meta_key = '_tpv_product_id'"
        );
        $totalLinked = count($pairs);

        $deletedInTpv = 0;
        $deleteErrors = 0;

        if ($alsoDeleteInTpv && $totalLinked > 0) {
            $api = new TPV_Sync_API_Client();
            foreach ($pairs as $p) {
                $tpvId = (int)$p->tpv_id;
                if ($tpvId <= 0) continue;
                try {
                    $r = $api->delete("/products/$tpvId");
                    if (empty($r['error']) && empty($r['errors']) && empty($r['type'])) {
                        $deletedInTpv++;
                    } else {
                        $deleteErrors++;
                    }
                } catch (Throwable $e) {
                    $deleteErrors++;
                }
                usleep(100000);
            }
        }

        $unlinked = $wpdb->query(
            "DELETE FROM {$metaTable} WHERE meta_key = '_tpv_product_id'"
        );

        $parts = [];
        $parts[] = sprintf(__('%d productos desvinculados', 'tpv-sync'), (int)$unlinked);
        if ($alsoDeleteInTpv) {
            $parts[] = sprintf(__('%d borrados en TPV', 'tpv-sync'), $deletedInTpv);
            if ($deleteErrors > 0) {
                $parts[] = sprintf(__('%d errores al borrar en TPV', 'tpv-sync'), $deleteErrors);
            }
        }

        wp_send_json_success([
            'message' => implode(' · ', $parts) . '. ' .
                         __('Ya puedes pulsar "Enviar ahora" para recrear los productos.', 'tpv-sync'),
            'unlinked'       => (int)$unlinked,
            'deleted_in_tpv' => $deletedInTpv,
            'delete_errors'  => $deleteErrors,
        ]);
    }

    /**
     * Conecta (o reconecta) la sincronización con el TPV.
     *
     * Diseño self-healing para que el cliente nunca tenga que tocar BD:
     *
     *   1. Generamos un secret HMAC LOCALMENTE antes de hablar con el TPV.
     *      Lo guardamos en wp_options y lo enviamos al TPV en el POST. Así
     *      cualquier red-trip que falle no nos deja en limbo (cliente y
     *      servidor saben qué secret deben usar antes incluso de la llamada).
     *
     *   2. Limpiamos `webhook_id`/`webhook_secret` locales SIEMPRE al inicio,
     *      independientemente de cómo termine la operación. Si el POST falla,
     *      el plugin queda en estado "Sin conectar" limpio (no en limbo
     *      con un secret muerto).
     *
     *   3. NO intentamos DELETE del webhook viejo: la API ya hace el
     *      "limpia y registra" en POST /webhooks (dedup por client+url).
     *      Antes hacíamos DELETE, pero si el HMAC estaba roto fallaba el
     *      DELETE y abortaba el reconectar entero. Ahora un POST basta.
     *
     *   4. El propio POST /webhooks devuelve el secret que pedimos, por si
     *      el TPV decidiese sobreescribir (versiones futuras). Tomamos el
     *      de la respuesta como fuente de verdad.
     */
    public function ajax_register_webhook(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }

        // Limpieza local SIEMPRE primero. Si algo falla más abajo el plugin
        // queda en estado "Sin conectar" coherente, no en limbo.
        delete_option('tpv_sync_webhook_id');
        delete_option('tpv_sync_webhook_secret');
        delete_option('tpv_sync_health_ok');
        delete_option('tpv_sync_health_checked_at');

        $api        = new TPV_Sync_API_Client();
        $webhookUrl = home_url('/tpv-webhook/');

        // Pre-acordamos el secret: lo generamos local y lo mandamos. Esto
        // elimina la ventana de race entre "TPV genera y guarda" y
        // "respuesta llega al cliente". 256 bits hex.
        $preAgreedSecret = bin2hex(random_bytes(32));

        try {
            $result = $api->post('/webhooks', [
                'url'    => $webhookUrl,
                'secret' => $preAgreedSecret,
                // Eventos válidos según api/v1/controllers/WebhookController.php::VALID_EVENTS.
                // No usar `order.status_changed` (la API no lo emite — usa
                // `order.payment_changed` para cambios de método de pago).
                'events' => array_values(array_filter([
                    tpv_sync_module_catalog() ? 'product.created'  : null,
                    tpv_sync_module_catalog() ? 'product.updated'  : null,
                    tpv_sync_module_catalog() ? 'product.deleted'  : null,
                    tpv_sync_module_catalog() ? 'stock.adjusted'   : null,
                    tpv_sync_module_catalog() ? 'special.created'  : null,
                    tpv_sync_module_catalog() ? 'special.deleted'  : null,
                    tpv_sync_module_catalog() ? 'variant.created'  : null,
                    tpv_sync_module_catalog() ? 'variants.updated' : null,
                    tpv_sync_module_catalog() ? 'csv.imported'     : null,
                    tpv_sync_module_orders()  ? 'order.created'         : null,
                    tpv_sync_module_orders()  ? 'order.payment_changed' : null,
                    tpv_sync_module_orders()  ? 'return.created'        : null,
                    tpv_sync_module_orders()  ? 'return.deleted'        : null,
                    'customer.created',
                    'customer.updated',
                    'customer.deleted',
                ])),
            ]);

            if (!empty($result['data']['webhook_id'])) {
                update_option('tpv_sync_webhook_id',     (int)$result['data']['webhook_id']);
                // Tomamos el secret de la respuesta como fuente de verdad
                // (debería coincidir con el pre-acordado, pero por si la API
                // futura decide rotarlo).
                $finalSecret = (string)($result['data']['secret'] ?? $preAgreedSecret);
                update_option('tpv_sync_webhook_secret', $finalSecret);

                // Si veníamos de un "Parar" reciente con >5 min de pausa,
                // disparamos reconciliación bidireccional silenciosa para
                // alinear cambios ocurridos durante la pausa. Best-effort:
                // si falla, seguimos respondiendo OK al usuario porque el
                // webhook YA está activo y los cambios futuros viajarán.
                $disconnectedAt = (int) get_option('tpv_sync_disconnected_at', 0);
                $reconStats = null;
                if ($disconnectedAt > 0 && (time() - $disconnectedAt) > 300) {
                    try {
                        $sync = new TPV_Sync_Product_Sync(new TPV_Sync_API_Client());
                        if (method_exists($sync, 'reconcileBidirectional')) {
                            $reconStats = $sync->reconcileBidirectional();
                        }
                    } catch (Throwable $e) {
                        // Log silencioso, no rompemos la respuesta al usuario.
                    }
                }
                update_option('tpv_sync_disconnected_at', 0, false);

                wp_send_json_success([
                    'status'    => 'ok',
                    'reconcile' => $reconStats,
                ]);
            } else {
                wp_send_json_error($result['errors'][0]['message'] ?? wp_json_encode($result));
            }
        } catch (Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Detiene la sincronización: borra el webhook en el TPV (best-effort) y
     * limpia las opciones locales. Las credenciales (URL, client id/secret)
     * se mantienen para que el usuario pueda reconectar con un click sin
     * tener que volver a teclearlas.
     */
    public function ajax_disconnect(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        $existingId = get_option('tpv_sync_webhook_id');
        if ($existingId) {
            try {
                (new TPV_Sync_API_Client())->delete("/webhooks/$existingId");
            } catch (Throwable $e) {
                // best-effort: si el TPV no responde o el webhook ya no existe,
                // seguimos limpiando local — lo importante es dejar de empujar.
            }
        }
        delete_option('tpv_sync_webhook_id');
        delete_option('tpv_sync_webhook_secret');
        delete_option('tpv_sync_health_ok');
        delete_option('tpv_sync_health_checked_at');
        // Timestamp de desconexión: al reconectar comprobamos si pasaron
        // >5 min para decidir si lanzar reconciliación bidireccional.
        update_option('tpv_sync_disconnected_at', time(), false);
        wp_send_json_success('ok');
    }

    /**
     * "Borrar credenciales locales y empezar de cero" — invocado por los
     * botones del wizard (paso 2 y paso 3) cuando el usuario quiere
     * abandonar la configuración. A diferencia de ajax_disconnect (que
     * mantiene credenciales para reconectar fácil), aquí limpiamos TODO:
     * webhook + credenciales + principal + flags de wizard. El plugin
     * vuelve al estado de instalación virgen → paso 1.
     *
     * Por qué dos endpoints distintos: en operativo "Detener" debe
     * preservar credenciales (caso típico: pausar sin perderlas). En
     * pleno wizard "Cancelar y empezar de cero" debe limpiarlas para que
     * el usuario realmente vuelva al principio. Mezclarlos confunde —
     * el bug del 2026-04-28 fue exactamente eso: un botón con texto
     * "borrar credenciales" que en realidad solo borraba el webhook.
     */
    public function ajax_full_disconnect(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        // Invalidar token cacheado en transient (puede contener un JWT viejo
        // de un cliente cuyo secret rotó). Sin esto, /auth/disconnect responde
        // 401 invalid_token y el conector queda huérfano en el TPV.
        delete_transient('tpv_sync_token');

        // Usar el TPV_Sync_API_Client (sabe descifrar secrets via filtros WP).
        try {
            $api = new TPV_Sync_API_Client();
        } catch (Throwable $e) {
            $api = null;
        }
        // 1) Borrar webhook remoto si lo había (best-effort).
        $existingId = get_option('tpv_sync_webhook_id');
        if ($existingId && $api && $api->isConfigured()) {
            try { $api->delete("/webhooks/$existingId"); } catch (Throwable $e) {}
        }
        // 2) Revocar el api_client en el TPV (POST /auth/disconnect).
        if ($api && $api->isConfigured()) {
            try { $api->post('/auth/disconnect', []); } catch (Throwable $e) {}
        }
        // 3) Limpieza total local — el plugin queda como recién instalado
        $keys = [
            'tpv_sync_api_url',
            'tpv_sync_client_id',
            'tpv_sync_client_secret',
            'tpv_sync_principal',
            'tpv_sync_webhook_id',
            'tpv_sync_webhook_secret',
            'tpv_sync_health_ok',
            'tpv_sync_health_checked_at',
            'tpv_sync_disconnected_at',
        ];
        foreach ($keys as $k) {
            delete_option($k);
        }
        wp_send_json_success('ok');
    }

    public function ajax_delete_orphans(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }
        $postIds = array_map('intval', (array)($_POST['post_ids'] ?? []));
        if (empty($postIds)) {
            wp_send_json_error(__('Ningún producto especificado.', 'tpv-sync'));
        }
        $deleted = (new TPV_Sync_Product_Sync(new TPV_Sync_API_Client()))->delete_orphans($postIds);
        wp_send_json_success($deleted);
    }

    /**
     * Carga datos para la pestaña Impuestos:
     *   - Clases WooCommerce (Standard implícita + las custom)
     *   - Clases TPV con sus rates (cacheado 24h)
     *   - Mapeo actual guardado
     *   - Aviso si wp_woocommerce_tax_rates está vacío
     */
    public function ajax_load_tax_mapping(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }

        // ── Clases WC: Standard siempre primero, luego las del cliente ──
        // wc_get_tax_classes() devuelve solo las custom (Standard es implícita).
        // El slug de Standard es '' (cadena vacía) — así lo guarda WC en
        // _tax_class. Lo añadimos a mano al principio.
        $wcClasses = [['slug' => '', 'name' => __('Estándar', 'tpv-sync')]];
        if (function_exists('WC_Tax::get_tax_classes')) {
            // Llamada estática vía class_exists guard
        }
        if (class_exists('WC_Tax')) {
            $names = WC_Tax::get_tax_classes();
            $slugs = WC_Tax::get_tax_class_slugs();
            // Ambos arrays vienen alineados por índice. Los vinculamos.
            foreach ($names as $i => $name) {
                $wcClasses[] = [
                    'slug' => (string)($slugs[$i] ?? sanitize_title($name)),
                    'name' => (string)$name,
                ];
            }
        }

        // ── Clases TPV: GET /tax-classes (con rates incluidos) ────────────
        // Cacheamos 24h porque el catálogo fiscal del TPV cambia rara vez.
        $tpvClasses = get_transient('tpv_sync_tax_classes_cache');
        if ($tpvClasses === false) {
            $tpvClasses = [];
            try {
                $api = new TPV_Sync_API_Client();
                if ($api->isConfigured()) {
                    $resp = $api->get('/tax-classes');
                    // GET /tax-classes devuelve {data: [{tax_class_id, title, description, rates: [...]}]}
                    $list = $resp['data'] ?? $resp;
                    if (is_array($list)) {
                        foreach ($list as $cls) {
                            if (!is_array($cls)) continue;
                            $tpvClasses[] = [
                                'tax_class_id' => (int)($cls['tax_class_id'] ?? 0),
                                'title'        => (string)($cls['title'] ?? ''),
                                'rates'        => array_values(array_map(
                                    static fn($r) => [
                                        'name' => (string)($r['name'] ?? ''),
                                        'rate' => (float)($r['rate'] ?? 0),
                                        'type' => (string)($r['type'] ?? 'P'),
                                    ],
                                    is_array($cls['rates'] ?? null) ? $cls['rates'] : []
                                )),
                            ];
                        }
                    }
                }
                set_transient('tpv_sync_tax_classes_cache', $tpvClasses, DAY_IN_SECONDS);
            } catch (Throwable $e) {
                // No persistir error en cache — reintentaremos al próximo load.
                $tpvClasses = [];
            }
        }

        // ── Aviso WC sin tasas configuradas ───────────────────────────────
        global $wpdb;
        $wcRatesCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates"
        );
        $taxesEnabled = get_option('woocommerce_calc_taxes', 'no') === 'yes';
        $warnEmptyRates = $taxesEnabled && $wcRatesCount === 0;

        // ── Mapeo actual ──────────────────────────────────────────────────
        $mapping = (array) get_option('tpv_sync_tax_class_mapping', []);

        wp_send_json_success([
            'wc_classes'        => $wcClasses,
            'tpv_classes'       => $tpvClasses,
            'mapping'           => $mapping,
            'warn_empty_rates'  => $warnEmptyRates,
            'wc_rates_count'    => $wcRatesCount,
            'taxes_enabled'     => $taxesEnabled,
            'wc_tax_settings_url' => admin_url('admin.php?page=wc-settings&tab=tax'),
        ]);
    }

    /**
     * Guarda el mapeo tax_class WC → tax_class_id TPV.
     * Body: mapping[wc_slug] = tpv_class_id (entero, 0 = "Sin impuestos").
     */
    public function ajax_save_tax_mapping(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }

        // El mapeo se envía como JSON (campo `mapping_json`) en vez de array
        // form-encoded. Razón: el slug de la clase Standard de WC es la
        // cadena vacía "" — jQuery la serializa como `mapping[]=13` y PHP la
        // recibe como índice numérico 0, perdiendo la asociación. JSON
        // preserva las claves vacías sin ambigüedad.
        $rawJson = isset($_POST['mapping_json']) ? wp_unslash((string)$_POST['mapping_json']) : '';
        $raw = json_decode($rawJson, true);
        if (!is_array($raw)) {
            wp_send_json_error('mapping_json must be a JSON object');
        }

        // Sanitización: clave es slug (string), valor int positivo.
        $clean = [];
        foreach ($raw as $slug => $tpvClassId) {
            $slug = sanitize_text_field((string)$slug);
            $id   = (int)$tpvClassId;
            if ($id < 0) $id = 0;
            // No guardamos mapeos a 0 (=sin impuestos), es el default.
            // Esto mantiene el array de options compacto.
            if ($id > 0) {
                $clean[$slug] = $id;
            }
        }

        update_option('tpv_sync_tax_class_mapping', $clean, false);
        wp_send_json_success(['mapping' => $clean]);
    }

    /**
     * Bulk push de clientes WC al TPV. Procesa por lotes con offset
     * persistido en options para soportar catálogos grandes sin timeout.
     *
     * Body POST:
     *   - reset=1   resetea offset a 0 (nueva ejecución).
     *   - batch=100 tamaño del lote (default 100, max 500).
     *
     * Response:
     *   {processed, sent, created, matched, skipped, errors,
     *    offset_next, total, done:bool}
     */
    public function ajax_push_customers(): void
    {
        check_ajax_referer('tpv_sync', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'tpv-sync'), 403);
        }

        $reset = !empty($_POST['reset']);
        $batch = max(1, min(500, (int)($_POST['batch'] ?? 100)));

        if ($reset) {
            delete_option('tpv_sync_push_customers_offset');
            delete_option('tpv_sync_push_customers_stats');
        }
        $offset = (int) get_option('tpv_sync_push_customers_offset', 0);

        // Total de usuarios con rol customer (para barra de progreso).
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
                 AND m.meta_key = '{$wpdb->prefix}capabilities'
                 AND m.meta_value LIKE '%customer%'"
        );

        $sync = TPV_Sync::instance();
        $stats = method_exists($sync, 'customers') || isset($sync->customers)
            ? $sync->customers->push_all_wc_users($batch, $offset)
            : ['sent' => 0, 'created' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => 0];

        $processed = $stats['sent'] + $stats['skipped'] + $stats['errors'];
        $offsetNext = $offset + $batch;
        $done = ($offsetNext >= $total);

        update_option('tpv_sync_push_customers_offset', $done ? 0 : $offsetNext, false);

        // Acumulado total de la ejecución (suma de todos los batches).
        $accumulated = (array) get_option('tpv_sync_push_customers_stats', []);
        foreach ($stats as $k => $v) {
            $accumulated[$k] = (int)($accumulated[$k] ?? 0) + (int)$v;
        }
        update_option('tpv_sync_push_customers_stats', $accumulated, false);

        wp_send_json_success([
            'processed_batch' => $processed,
            'batch'           => $stats,
            'accumulated'     => $accumulated,
            'offset_next'     => $done ? 0 : $offsetNext,
            'total'           => $total,
            'done'            => $done,
        ]);
    }
}
