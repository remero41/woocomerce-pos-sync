<?php
declare(strict_types=1);
/**
 * WP-CLI commands del plugin tpv-sync.
 *
 * Comandos disponibles:
 *   wp tpv-sync status                      → estado general (conexión, queue, breaker, cron)
 *   wp tpv-sync test-connection             → intenta GET /health contra el TPV
 *   wp tpv-sync reconcile --limit=100       → reconcilia productos con el TPV
 *   wp tpv-sync import-products             → importa catálogo completo TPV → WC
 *   wp tpv-sync push-all [--dry-run]        → sube catálogo WC → TPV masivamente
 *   wp tpv-sync queue-process [--batch=20]  → procesa la fallback queue ahora
 *   wp tpv-sync queue-stats                 → estadísticas de la queue
 *   wp tpv-sync queue-retry <id>            → reintenta una entrada abandoned
 *   wp tpv-sync queue-purge [--days=30]     → purga entradas viejas
 *   wp tpv-sync breaker-status              → estado actual del circuit breaker
 *   wp tpv-sync breaker-reset               → cierra el circuit manualmente
 *   wp tpv-sync export-logs --days=7        → exporta logs a CSV (stdout)
 *
 * Uso cron Linux (cada hora, por ejemplo):
 *   0 * * * * cd /var/www/tpv85 && wp tpv-sync queue-process
 */
defined('ABSPATH') || exit;

if (!defined('WP_CLI') || !WP_CLI) return;

class TPV_Sync_CLI
{
    public static function register(): void
    {
        \WP_CLI::add_command('tpv-sync', self::class);
    }

    /**
     * Muestra el estado general del plugin.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Formato de salida (table|json|yaml). Por defecto: table.
     *
     * @when after_wp_load
     */
    public function status($args, $assoc): void
    {
        if (!class_exists('TPV_Sync')) {
            \WP_CLI::error('TPV_Sync no inicializado (¿WooCommerce activo?).');
        }
        $sync = TPV_Sync::instance();

        $stats = [
            ['metric' => 'api_url',         'value' => (string)get_option('tpv_sync_api_url', '')],
            ['metric' => 'api_configured',  'value' => $sync->api->isConfigured() ? 'yes' : 'no'],
            ['metric' => 'webhook_id',      'value' => (string)(get_option('tpv_sync_webhook_id') ?: 'not-registered')],
            ['metric' => 'module_catalog',  'value' => get_option('tpv_sync_module_catalog', 1) ? 'on' : 'off'],
            ['metric' => 'module_orders',   'value' => get_option('tpv_sync_module_orders',  0) ? 'on' : 'off'],
        ];
        $qs = $sync->queue->stats();
        foreach (['pending', 'done', 'abandoned', 'total'] as $k) {
            $stats[] = ['metric' => "queue_$k", 'value' => (string)($qs[$k] ?? 0)];
        }
        $bs = $sync->api->breaker()?->stats() ?? [];
        $stats[] = ['metric' => 'breaker_state',    'value' => (string)($bs['state']    ?? 'n/a')];
        $stats[] = ['metric' => 'breaker_failures', 'value' => (string)($bs['failures'] ?? 0)];

        // Próxima ejecución de crons
        foreach (['tpv_sync_reconcile', 'tpv_sync_queue_process', 'tpv_sync_queue_purge'] as $hook) {
            $next = wp_next_scheduled($hook);
            $stats[] = [
                'metric' => "cron_$hook",
                'value'  => $next ? gmdate('Y-m-d H:i:s', $next) . ' UTC' : 'not scheduled',
            ];
        }

        $format = $assoc['format'] ?? 'table';
        \WP_CLI\Utils\format_items($format, $stats, ['metric', 'value']);
    }

    /**
     * Testea la conexión con el TPV haciendo GET /health.
     *
     * @when after_wp_load
     */
    public function test_connection($args, $assoc): void
    {
        if (!class_exists('TPV_Sync')) \WP_CLI::error('TPV_Sync no inicializado.');
        $api = TPV_Sync::instance()->api;
        $r   = $api->get('/health');
        if (!empty($r['status']) && $r['status'] === 'ok') {
            \WP_CLI::success("Conexión OK. Service={$r['service']} version={$r['version']}");
            return;
        }
        $err = $r['error'] ?? wp_json_encode($r);
        \WP_CLI::error("Conexión FALLÓ: $err");
    }

    /**
     * Reconcilia los top-N productos con el TPV.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Nº máximo de productos (default 100).
     *
     * @when after_wp_load
     */
    public function reconcile($args, $assoc): void
    {
        $limit = (int)($assoc['limit'] ?? 100);
        \WP_CLI::log("Reconciliando hasta $limit productos...");
        $stats = TPV_Sync::instance()->products->reconcile($limit);
        foreach ($stats as $k => $v) {
            \WP_CLI::log("  $k: " . (is_scalar($v) ? $v : wp_json_encode($v)));
        }
        \WP_CLI::success('Reconciliación completada.');
    }

    /**
     * Importa el catálogo completo desde el TPV.
     *
     * @when after_wp_load
     */
    public function import_products($args, $assoc): void
    {
        \WP_CLI::log('Importando catálogo TPV → WC...');
        $stats = TPV_Sync::instance()->products->import_all();
        foreach ($stats as $k => $v) {
            if ($k === 'orphans') continue;
            \WP_CLI::log("  $k: $v");
        }
        \WP_CLI::log('  orphans: ' . count($stats['orphans'] ?? []));
        \WP_CLI::success('Importación completada.');
    }

    /**
     * Sube todos los productos WC al TPV (bulk push inicial).
     *
     * Útil al instalar el plugin sobre un WooCommerce con catálogo existente:
     * la sincronización automática solo dispara en ediciones futuras, así que
     * los productos previos hay que empujarlos explícitamente.
     *
     * Reutiliza push_wc_product_to_tpv() — el mismo flujo que usa el hook de
     * edición. Si el producto ya tiene `_tpv_product_id` en su meta, hace PATCH;
     * si no, crea en TPV y guarda el ID. Idempotente: seguro ejecutarlo varias
     * veces.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : No llama al TPV; solo reporta cuántos productos se procesarían.
     *
     * [--status=<status>]
     * : Estados WC a incluir (coma-separado). Default: publish,draft.
     *
     * [--limit=<n>]
     * : Limita el número de productos (para pruebas). 0 = sin límite (default).
     *
     * [--skip-synced]
     * : Ignora productos que ya tienen _tpv_product_id (solo pushea los nuevos).
     *
     * ## EXAMPLES
     *
     *   wp tpv-sync push-all --dry-run
     *   wp tpv-sync push-all --status=publish --limit=50
     *   wp tpv-sync push-all --skip-synced
     *
     * @when after_wp_load
     */
    public function push_all($args, $assoc): void
    {
        if (!class_exists('TPV_Sync')) {
            \WP_CLI::error('TPV_Sync no inicializado (¿WooCommerce activo?).');
        }

        $dryRun     = isset($assoc['dry-run']);
        $skipSynced = isset($assoc['skip-synced']);
        $limit      = (int)($assoc['limit'] ?? 0);
        $statuses   = array_map('trim', explode(',', (string)($assoc['status'] ?? 'publish,draft')));

        $sync = TPV_Sync::instance();
        if (!$sync->api->isConfigured()) {
            \WP_CLI::error('API del TPV no configurada (falta URL o credenciales en ajustes).');
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        $postIds = get_posts($args);
        $total   = count($postIds);

        if ($limit > 0) {
            $postIds = array_slice($postIds, 0, $limit);
        }

        \WP_CLI::log(sprintf(
            'Encontrados %d productos WC (estados: %s)%s',
            $total, implode(',', $statuses),
            $limit > 0 ? " — procesando los primeros $limit" : ''
        ));
        if ($dryRun) {
            \WP_CLI::success('Dry-run: nada enviado al TPV.');
            return;
        }

        $stats = ['pushed' => 0, 'skipped' => 0, 'errors' => 0];
        $progress = class_exists('\WP_CLI\Utils\make_progress_bar')
            ? \WP_CLI\Utils\make_progress_bar('Push WC → TPV', count($postIds))
            : null;

        foreach ($postIds as $postId) {
            if ($skipSynced && get_post_meta($postId, '_tpv_product_id', true)) {
                $stats['skipped']++;
                $progress?->tick();
                continue;
            }
            try {
                $sync->products->push_wc_product_to_tpv($postId);
                $stats['pushed']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                \WP_CLI::warning("post_id=$postId error: " . $e->getMessage());
            }
            $progress?->tick();
        }
        $progress?->finish();

        foreach ($stats as $k => $v) \WP_CLI::log("  $k: $v");
        \WP_CLI::success("Push WC → TPV completado ({$stats['pushed']} enviados, {$stats['errors']} errores).");
    }

    /**
     * Procesa la fallback queue inmediatamente.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Nº máximo de items a procesar (default 20).
     *
     * @when after_wp_load
     */
    public function queue_process($args, $assoc): void
    {
        $batch = (int)($assoc['batch'] ?? 20);
        $stats = TPV_Sync::instance()->queue->process($batch);
        \WP_CLI::log("processed={$stats['processed']} succeeded={$stats['succeeded']} failed={$stats['failed']} abandoned={$stats['abandoned']}");
        \WP_CLI::success('Queue process OK.');
    }

    /**
     * Estadísticas de la queue.
     *
     * @when after_wp_load
     */
    public function queue_stats($args, $assoc): void
    {
        $s = TPV_Sync::instance()->queue->stats();
        $rows = [];
        foreach ($s as $k => $v) $rows[] = ['status' => $k, 'count' => $v];
        \WP_CLI\Utils\format_items('table', $rows, ['status', 'count']);
    }

    /**
     * Reintenta una entrada de la queue manualmente.
     *
     * ## OPTIONS
     *
     * <id>
     * : ID de la fila en la queue.
     *
     * @when after_wp_load
     */
    public function queue_retry($args, $assoc): void
    {
        $id = (int)($args[0] ?? 0);
        if (!$id) \WP_CLI::error('Falta <id>.');
        $ok = TPV_Sync::instance()->queue->retry($id);
        $ok ? \WP_CLI::success("Retry OK para id=$id.") : \WP_CLI::error("No existe id=$id.");
    }

    /**
     * Purga entradas done/abandoned de la queue.
     *
     * ## OPTIONS
     *
     * [--days=<n>]
     * : Edad mínima para purgar (default 30).
     *
     * @when after_wp_load
     */
    public function queue_purge($args, $assoc): void
    {
        $days = (int)($assoc['days'] ?? 30);
        $n = TPV_Sync::instance()->queue->purge($days);
        \WP_CLI::success("Purged $n entries older than $days days.");
    }

    /**
     * Estado del circuit breaker.
     *
     * @when after_wp_load
     */
    public function breaker_status($args, $assoc): void
    {
        $cb = TPV_Sync::instance()->api->breaker();
        if (!$cb) \WP_CLI::error('Circuit breaker no instanciado.');
        $rows = [];
        foreach ($cb->stats() as $k => $v) $rows[] = ['field' => $k, 'value' => (string)$v];
        \WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
    }

    /**
     * Resetea el circuit breaker a CLOSED.
     *
     * @when after_wp_load
     */
    public function breaker_reset($args, $assoc): void
    {
        $cb = TPV_Sync::instance()->api->breaker();
        if (!$cb) \WP_CLI::error('Circuit breaker no instanciado.');
        $cb->reset();
        \WP_CLI::success('Circuit breaker reseteado a CLOSED.');
    }

    /**
     * Exporta tpv_sync_log en CSV a stdout.
     *
     * ## OPTIONS
     *
     * [--days=<n>]
     * : Últimos N días (default 7).
     *
     * [--status=<s>]
     * : Filtrar por status (ok, error, skip, insufficient_stock...).
     *
     * @when after_wp_load
     */
    public function export_logs($args, $assoc): void
    {
        global $wpdb;
        $days   = (int)($assoc['days'] ?? 7);
        $status = $assoc['status'] ?? null;
        $table  = $wpdb->prefix . 'tpv_sync_log';
        $where  = "WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . (int)$days . " DAY)";
        if ($status) $where .= $wpdb->prepare(" AND status = %s", $status);
        $rows = $wpdb->get_results("SELECT * FROM $table $where ORDER BY id DESC LIMIT 10000");
        fputcsv(STDOUT, ['id', 'event_type', 'resource', 'resource_id', 'status', 'message', 'created_at']);
        foreach ($rows as $r) {
            fputcsv(STDOUT, [$r->id, $r->event_type, $r->resource, $r->resource_id, $r->status, $r->message, $r->created_at]);
        }
    }
}

TPV_Sync_CLI::register();
