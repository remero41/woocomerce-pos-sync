<?php
declare(strict_types=1);
/**
 * Fallback queue local — persiste operaciones que no pudieron enviarse al TPV
 * y las reintenta con backoff exponencial.
 *
 * Diseño:
 *   - enqueue(): persiste payload en tabla wp_tpv_sync_queue con status=pending.
 *   - process(): cron cada minuto (Action Scheduler o WP-cron) procesa filas
 *     con next_retry_at <= NOW. Éxito → status=done. Fallo → attempts++,
 *     next_retry_at += backoff. >5 intentos → status=abandoned (avisar admin).
 *   - stats(): conteo por status para UI admin.
 *
 * Operations soportadas (ids string):
 *   - 'stock.push'     {tpv_product_id, delta, reason, comment}
 *   - 'stock.push_var' {tpv_product_id, pov_id, qty}
 *   - 'order.send'     {wc_order_id}
 *   - 'refund.send'    {wc_order_id, refund_id}
 *
 * Todos los operations son idempotency-aware: cada operation que llama al
 * TPV usa Idempotency-Key determinística (ver class-api-client), de modo
 * que un reintento que duplique no causa efecto doble.
 */
defined('ABSPATH') || exit;

class TPV_Sync_Queue
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_DONE      = 'done';
    public const STATUS_ABANDONED = 'abandoned';

    public const MAX_ATTEMPTS = 6;
    // Backoff exponencial: minutos hasta el siguiente reintento tras N fallos.
    public const BACKOFF_MINUTES = [1, 5, 15, 60, 240, 1440];

    private TPV_Sync_API_Client   $api;
    private TPV_Sync_Product_Sync $products;
    private TPV_Sync_Order_Sync   $orders;

    public function __construct(
        TPV_Sync_API_Client   $api,
        TPV_Sync_Product_Sync $products,
        TPV_Sync_Order_Sync   $orders
    ) {
        $this->api      = $api;
        $this->products = $products;
        $this->orders   = $orders;
    }

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tpv_sync_queue';
    }

    /** Ejecutable en activation hook. */
    public static function create_table(): void
    {
        global $wpdb;
        $t       = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $t (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operation       VARCHAR(32)     NOT NULL,
            payload         LONGTEXT        NOT NULL,
            attempts        TINYINT         NOT NULL DEFAULT 0,
            next_retry_at   DATETIME        NOT NULL,
            last_error      TEXT            DEFAULT NULL,
            status          VARCHAR(16)     NOT NULL DEFAULT 'pending',
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_retry (status, next_retry_at),
            KEY idx_operation (operation)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Añadir una operación a la cola.
     * @param string $operation Identificador (ver clase docblock).
     * @param array  $payload   Datos necesarios para reproducir la llamada.
     * @param string $reason    Motivo del encolado (error, red, 5xx...).
     */
    public function enqueue(string $operation, array $payload, string $reason = ''): int
    {
        global $wpdb;
        $wpdb->insert(self::table_name(), [
            'operation'     => $operation,
            'payload'       => wp_json_encode($payload),
            'attempts'      => 0,
            'next_retry_at' => current_time('mysql', true),
            'last_error'    => $reason,
            'status'        => self::STATUS_PENDING,
            'created_at'    => current_time('mysql', true),
        ]);
        return (int)$wpdb->insert_id;
    }

    /**
     * Procesar un lote de entradas pending.
     * Invocado por cron cada minuto.
     */
    public function process(int $batchSize = 20): array
    {
        global $wpdb;
        $t = self::table_name();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t
             WHERE status = %s AND next_retry_at <= UTC_TIMESTAMP()
             ORDER BY next_retry_at ASC
             LIMIT %d",
            self::STATUS_PENDING, $batchSize
        ));

        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'abandoned' => 0];

        foreach ($rows as $row) {
            $stats['processed']++;
            $payload = json_decode($row->payload, true) ?: [];
            $ok = false;
            $err = '';
            try {
                $ok = $this->execute((string)$row->operation, $payload);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }

            if ($ok) {
                $wpdb->update($t,
                    ['status' => self::STATUS_DONE, 'last_error' => null],
                    ['id' => $row->id]
                );
                $stats['succeeded']++;
            } else {
                $attempts = (int)$row->attempts + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $wpdb->update($t,
                        ['status' => self::STATUS_ABANDONED, 'attempts' => $attempts, 'last_error' => $err ?: 'max_attempts_reached'],
                        ['id' => $row->id]
                    );
                    $stats['abandoned']++;
                } else {
                    $minutes = self::BACKOFF_MINUTES[$attempts - 1] ?? 1440;
                    $next    = gmdate('Y-m-d H:i:s', time() + $minutes * 60);
                    $wpdb->update($t,
                        ['attempts' => $attempts, 'last_error' => $err ?: 'unknown', 'next_retry_at' => $next],
                        ['id' => $row->id]
                    );
                    $stats['failed']++;
                }
            }
        }
        return $stats;
    }

    /**
     * Reintentar manualmente una entrada (para botón "retry" en admin).
     */
    public function retry(int $id): bool
    {
        global $wpdb;
        $wpdb->update(self::table_name(), [
            'status'        => self::STATUS_PENDING,
            'next_retry_at' => current_time('mysql', true),
            'attempts'      => 0,
        ], ['id' => $id]);
        return (bool)$wpdb->rows_affected;
    }

    /** Estadísticas para UI/admin. */
    public function stats(): array
    {
        global $wpdb;
        $t = self::table_name();
        $out = ['pending' => 0, 'done' => 0, 'abandoned' => 0, 'total' => 0];
        $rows = $wpdb->get_results("SELECT status, COUNT(*) c FROM $t GROUP BY status");
        foreach ($rows as $r) {
            $out[$r->status] = (int)$r->c;
            $out['total']   += (int)$r->c;
        }
        return $out;
    }

    /** Limpia entradas done/abandoned más antiguas de N días. */
    public function purge(int $olderThanDays = 30): int
    {
        global $wpdb;
        $t = self::table_name();
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM $t
             WHERE status IN ('done', 'abandoned')
               AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $olderThanDays
        ));
    }

    // ─── Ejecutores concretos por tipo de operación ──────────────────────────

    private function execute(string $operation, array $payload): bool
    {
        switch ($operation) {

            case 'stock.push':
                $tpvId = (int)($payload['tpv_product_id'] ?? 0);
                $delta = (float)($payload['delta']         ?? 0);
                $reason = (string)($payload['reason']      ?? 'ajuste_manual');
                if (!$tpvId) return true; // payload corrupto → no reintentar
                $r = $this->api->patch("/products/$tpvId/stock", [
                    'quantity_change' => $delta,
                    'reason'          => $reason,
                    'comment'         => (string)($payload['comment'] ?? 'fallback queue'),
                ]);
                return empty($r['errors']) && empty($r['error']);

            case 'stock.push_var':
                $tpvProd = (int)($payload['tpv_product_id'] ?? 0);
                $povId   = (int)($payload['pov_id']         ?? 0);
                $qty     = (float)($payload['qty']          ?? 0);
                if (!$tpvProd || !$povId) return true;
                $r = $this->api->patch("/products/$tpvProd/variants/$povId", ['quantity' => $qty]);
                return empty($r['errors']) && empty($r['error']);

            case 'order.send':
                $wcOrderId = (int)($payload['wc_order_id'] ?? 0);
                if (!$wcOrderId) return true;
                if (get_post_meta($wcOrderId, TPV_Sync_Order_Sync::TPV_ORDER_META, true)) return true; // ya enviado
                $this->orders->send_to_tpv($wcOrderId);
                return (bool)get_post_meta($wcOrderId, TPV_Sync_Order_Sync::TPV_ORDER_META, true);

            case 'refund.send':
                $wcOrderId = (int)($payload['wc_order_id'] ?? 0);
                $refundId  = (int)($payload['refund_id']   ?? 0);
                if (!$wcOrderId || !$refundId) return true;
                if (get_post_meta($refundId, '_tpv_refund_synced', true)) return true; // ya sync
                $this->orders->on_wc_refund($wcOrderId, $refundId);
                return (bool)get_post_meta($refundId, '_tpv_refund_synced', true);

            default:
                return true; // op desconocida → no reintentar
        }
    }
}
