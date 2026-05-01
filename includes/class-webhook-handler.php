<?php
declare(strict_types=1);
/**
 * Receptor de webhooks del TPV.
 *
 * Endpoint: https://tpv85.catinfog.com/tpv-webhook/
 * Verifica firma HMAC-SHA256, despacha al handler correcto.
 *
 * Eventos manejados:
 *   product.created    → upsert producto en WC
 *   product.updated    → actualizar producto en WC
 *   product.deleted    → despublicar en WC
 *   stock.adjusted     → actualizar stock en WC
 *   special.created    → actualizar precio especial
 *   special.deleted    → eliminar precio especial
 *   order.created      → log (el pedido viene del TPV)
 *   return.created     → reembolso en WC si el pedido existe
 */
defined('ABSPATH') || exit;

class TPV_Sync_Webhook
{
    /**
     * Versiones del payload que este plugin sabe procesar. El dispatcher
     * envía X-Webhook-Version; si no coincide devolvemos 426 Upgrade Required.
     * Al bumpear la versión en el TPV, actualizar aquí tras validar que los
     * handlers soportan el nuevo shape.
     */
    private const SUPPORTED_VERSIONS = ['1'];

    private TPV_Sync_Product_Sync $products;
    private TPV_Sync_Order_Sync   $orders;
    private ?TPV_Sync_API_Client  $api;

    public function __construct(TPV_Sync_Product_Sync $products, TPV_Sync_Order_Sync $orders, ?TPV_Sync_API_Client $api = null)
    {
        $this->products = $products;
        $this->orders   = $orders;
        $this->api      = $api;
    }

    public static function idem_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tpv_sync_webhook_idem';
    }

    /**
     * Tabla de idempotencia atómica. La PK UNIQUE sobre `idempotency_key` + INSERT IGNORE
     * garantiza que solo un proceso concurrente "gana" la inserción — resto recibe
     * affected_rows=0 y responde con duplicate=true.
     */
    public static function create_idem_table(): void
    {
        global $wpdb;
        $t       = self::idem_table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $t (
            idempotency_key VARCHAR(191) NOT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (idempotency_key),
            KEY idx_created_at (created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Purga entradas antiguas de la tabla de idempotencia. Llamado desde el cron
     * tpv_sync_queue_purge (diario). 48h cubre con margen el backoff máx 24h del
     * dispatcher del TPV.
     */
    public static function purge_idem(int $hoursOld = 48): int
    {
        global $wpdb;
        $t = self::idem_table_name();
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $hoursOld
        ));
    }

    public function handle(): void
    {
        $raw       = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

        // Verificar firma — rechazar si no hay secret configurado o si la firma no coincide
        $secret = get_option('tpv_sync_webhook_secret', '');
        if (!$secret) {
            status_header(503);
            nocache_headers();
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Webhook not configured']);
            return;
        }
        // SEGURIDAD (HIGH): anti-replay — exige X-Webhook-Timestamp dentro de
        // ±5 min. Sin esto, un payload+firma capturado del wire (TLS roto, MITM
        // en proxy interno, log filtrado) se podría replicar indefinidamente
        // tras la purga de la tabla idempotency (48h por defecto).
        $ts = (int) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? 0);
        $now = time();
        if ($ts <= 0 || abs($now - $ts) > 300) {
            // Periodo de gracia: si el TPV todavía no envía timestamp (compat),
            // permitimos pasar pero solo si el endpoint está marcado en debug
            // y registramos la falta. Producción debe rechazar.
            if (!defined('WP_DEBUG') || !WP_DEBUG) {
                status_header(401);
                nocache_headers();
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Stale or missing timestamp']);
                return;
            }
        }
        if (!self::verify_signature($raw, $signature, $secret, $ts)) {
            status_header(401);
            nocache_headers();
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        // Rechazar versiones de payload no soportadas ANTES de decodificar —
        // evita que un cambio breaking en el dispatcher rompa silenciosamente
        // handlers que esperan otro shape.
        $version = $_SERVER['HTTP_X_WEBHOOK_VERSION'] ?? '1';
        if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            status_header(426);
            nocache_headers();
            header('Content-Type: application/json');
            echo json_encode([
                'error'     => 'Unsupported webhook version',
                'supported' => self::SUPPORTED_VERSIONS,
                'received'  => $version,
            ]);
            return;
        }

        $decoded = json_decode($raw, true);
        if (!$decoded) {
            status_header(400);
            nocache_headers();
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid payload']);
            return;
        }

        // BUG-017: el dispatcher puede enviar un único evento (objeto) o un
        // batch (array de eventos). Detectamos por la presencia de `events` en
        // la raíz, o por que el body sea lista numérica indexada.
        $events = $this->extract_events_from_body($decoded);
        if (empty($events)) {
            status_header(400);
            nocache_headers();
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid payload: no events']);
            return;
        }

        // Idempotencia atómica + filtro de duplicados ANTES de responder 200.
        // Con batch, descartamos los individualmente duplicados pero seguimos
        // procesando los nuevos.
        global $wpdb;
        $table = self::idem_table_name();
        $toProcess = [];
        $duplicates = 0;
        foreach ($events as $event) {
            if (empty($event['event_type'])) continue;
            $idemKey = (string)($event['idempotency_key'] ?? '');
            if ($idemKey !== '') {
                $inserted = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $table (idempotency_key, created_at) VALUES (%s, NOW())",
                    $idemKey
                ));
                if ((int)$inserted === 0) {
                    $duplicates++;
                    continue;
                }
            }
            $toProcess[] = $event;
        }

        // Si TODO eran duplicados, devolver 200 + duplicate=true (compat 1×1)
        if (empty($toProcess) && $duplicates > 0) {
            status_header(200);
            nocache_headers();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'duplicate' => true, 'count' => $duplicates]);
            return;
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: application/json');
        // X-Tpv-Batch-Supported: 1 → señal al dispatcher de que el plugin
        // entiende batches (para que se atreva a mandarlos).
        header('X-Tpv-Batch-Supported: 1');
        echo json_encode([
            'ok' => true,
            'processed'  => count($toProcess),
            'duplicates' => $duplicates,
        ]);

        // Procesar asíncronamente todos los eventos del batch.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        foreach ($toProcess as $event) {
            try {
                $this->dispatch($event);
            } catch (\Throwable $e) {
                $this->log('batch.error', (int)($event['resource_id'] ?? 0),
                    'Error en dispatch batch: ' . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Extrae la lista de eventos del body decodificado.
     *
     * Acepta 3 formatos:
     *   1. Objeto único (compat 1×1):  {"event_type":"...", ...}
     *   2. Envelope batch:             {"events":[{...},{...}]}
     *   3. Array desnudo (raro):       [{...},{...}]
     */
    private function extract_events_from_body(array $body): array
    {
        // Caso 1: objeto único (tiene event_type en raíz)
        if (isset($body['event_type'])) {
            return [$body];
        }
        // Caso 2: envelope con `events`
        if (isset($body['events']) && is_array($body['events'])) {
            return $body['events'];
        }
        // Caso 3: array desnudo (claves numéricas)
        if (array_keys($body) === range(0, count($body) - 1)) {
            return $body;
        }
        return [];
    }

    private function dispatch(array $payload): void
    {
        $eventType  = $payload['event_type'];
        $resourceId = (int)($payload['resource_id'] ?? 0);
        $fields     = $payload['changed_fields'] ?? [];

        $this->log($eventType, $resourceId, 'recibido');

        try {
            switch ($eventType) {

                case 'product.created':
                case 'product.updated':
                    if ($resourceId > 0) {
                        $this->products->update_from_tpv($resourceId);
                    }
                    break;

                case 'product.deleted':
                    if ($resourceId > 0) {
                        $this->products->delete_product($resourceId);
                    }
                    break;

                case 'stock.adjusted':
                    // El TPV manda cantidad absoluta tras el ajuste — WC la pisa
                    // sin dudas, salvo que el evento sea anterior al último aplicado.
                    $tpvId    = (int)($fields['product_id'] ?? $resourceId);
                    $quantity = (float)($fields['quantity'] ?? 0);
                    if ($tpvId > 0 && $this->accept_stock_event($tpvId, 'product', $payload)) {
                        $this->products->update_stock($tpvId, $quantity);
                    }
                    break;

                case 'variant.stock_adjusted':
                    // Actualización estricta de stock de una variante (tallas, colores, etc.).
                    $povId    = (int)($fields['product_option_value_id'] ?? $resourceId);
                    $quantity = (float)($fields['quantity'] ?? 0);
                    if ($povId > 0 && $this->accept_stock_event($povId, 'variant', $payload)) {
                        $this->products->update_variant_stock($povId, $quantity);
                    }
                    break;

                case 'special.created':
                    // Recargar el producto para obtener el precio especial actualizado
                    $tpvId = (int)($fields['product_id'] ?? $resourceId);
                    if ($tpvId > 0) {
                        $this->products->update_from_tpv($tpvId);
                    }
                    break;

                case 'special.deleted':
                    $tpvId = (int)($fields['product_id'] ?? $resourceId);
                    if ($tpvId > 0) {
                        $postId = $this->products->find_wc_post($tpvId);
                        if ($postId) {
                            delete_post_meta($postId, '_sale_price');
                            // Restaurar precio como regular_price
                            $regularPrice = get_post_meta($postId, '_regular_price', true);
                            update_post_meta($postId, '_price', $regularPrice);
                            wc_delete_product_transients($postId);
                        }
                    }
                    break;

                case 'variants.updated':
                case 'variant.created':
                case 'option.created':
                case 'option.deleted':
                    // Recargar producto para reflejar cambios en variantes
                    if ($resourceId > 0) {
                        $this->products->update_from_tpv($resourceId);
                    }
                    break;

                case 'order.created':
                    // Venta física del TPV — solo log, no requiere acción en WC
                    $this->log($eventType, $resourceId, 'Venta TPV registrada, no requiere acción en WC');
                    break;

                case 'order.status_changed':
                    // El TPV cambió el estado de un pedido online → actualizar WC
                    $tpvStatusId = (int)($fields['order_status_id'] ?? 0);
                    if ($resourceId > 0 && $tpvStatusId > 0 && tpv_sync_module_orders()) {
                        $this->orders->update_wc_status($resourceId, $tpvStatusId);
                    }
                    break;

                case 'return.created':
                    $this->handle_return($fields);
                    break;

                case 'csv.imported':
                    // CSV importado en TPV — programar re-sincronización parcial
                    wp_schedule_single_event(time() + 60, 'tpv_sync_import_all');
                    $this->log($eventType, 0, 'CSV importado en TPV — re-sync programada en 60s');
                    break;

                // ── Clientes ────────────────────────────────────────────────
                // El payload del webhook trae solo `resource_id` + array de
                // changed_fields (nombres). Hacemos GET /customers/{id} a la
                // API para traer los datos frescos antes de upsert en WC.
                // Anti-bucle: activar guardia para que los hooks WC
                // (user_register/profile_update/customer_save_address) NO
                // re-empujen al TPV mientras escribimos en wp_users.
                case 'customer.created':
                case 'customer.updated':
                    if ($resourceId <= 0) break;
                    $GLOBALS['tpv_sync_skip_wc_customer_push'] = true;
                    try {
                        $api = $this->api ?? new TPV_Sync_API_Client();
                        $r = $api->get("/customers/$resourceId");
                        $data = $r['data'] ?? null;
                        if (is_array($data) && !empty($data['email'])) {
                            $this->products->sync_customer_from_tpv($resourceId, $data);
                        } else {
                            $this->log($eventType, $resourceId,
                                'GET /customers/' . $resourceId . ' devolvió payload vacío'
                            );
                        }
                    } finally {
                        $GLOBALS['tpv_sync_skip_wc_customer_push'] = false;
                    }
                    break;

                case 'customer.deleted':
                    $GLOBALS['tpv_sync_skip_wc_customer_push'] = true;
                    try {
                        $this->products->delete_wc_customer_by_tpv_id($resourceId);
                    } finally {
                        $GLOBALS['tpv_sync_skip_wc_customer_push'] = false;
                    }
                    break;

                // ── Categorías ─────────────────────────────────────────────
                case 'category.created':
                case 'category.updated':
                    $this->products->sync_category_from_tpv($resourceId, $fields);
                    break;

                case 'category.deleted':
                    $this->products->delete_wc_category_by_tpv_id($resourceId);
                    break;

                default:
                    // Evento no manejado — ignorar silenciosamente
                    break;
            }

            $this->log($eventType, $resourceId, 'procesado ok');

        } catch (Throwable $e) {
            $this->log($eventType, $resourceId, 'error: ' . $e->getMessage(), 'error');
        }
    }

    private function handle_return(array $fields): void
    {
        // Buscar el pedido WC que corresponde al order_id del TPV
        $tpvOrderId = (int)($fields['order_id'] ?? 0);
        if (!$tpvOrderId) return;

        global $wpdb;
        $wcOrderId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_tpv_order_id' AND meta_value = %d LIMIT 1",
            $tpvOrderId
        ));

        if (!$wcOrderId) return;

        $order = wc_get_order((int)$wcOrderId);
        if (!$order) return;

        $total = (float)($fields['total'] ?? 0);
        if ($total <= 0) return;

        // Crear reembolso en WC marcando el origen para que on_wc_refund no
        // lo reenvíe al TPV (evita bucle).
        $refund = wc_create_refund([
            'amount'   => $total,
            'reason'   => 'Devolución procesada en TPV',
            'order_id' => (int)$wcOrderId,
        ]);
        if (!is_wp_error($refund) && $refund) {
            update_post_meta($refund->get_id(), '_tpv_refund_origin', 'tpv');
        }

        $order->add_order_note("Devolución de {$total}€ registrada desde el TPV.");
    }

    /**
     * Ordering guard: rechaza eventos de stock más antiguos que el último
     * aplicado para ese recurso. Usa el timestamp del payload (emitido por el
     * dispatcher) con un wrapper en usermeta para no tocar cada producto.
     *
     * @param int    $resourceId product_id (scope=product) o product_option_value_id (scope=variant)
     * @param string $scope      'product' | 'variant'
     * @param array  $payload    payload completo del webhook
     */
    /**
     * Descarta eventos de stock anteriores al último aplicado para ese recurso.
     *
     * BUG-015: prefiere `event_id` (auto_increment monotónico del TPV) sobre
     * `timestamp` (precisión 1 segundo, colisiona bajo carga). Namespace en
     * el option_key separa el contador event_id (~1) del timestamp Unix
     * (~1.7e9) para que la migración no descarte eventos legítimos.
     */
    private function accept_stock_event(int $resourceId, string $scope, array $payload): bool
    {
        $useEventId = isset($payload['event_id']) && (int) $payload['event_id'] > 0;
        if ($useEventId) {
            $incoming = (int) $payload['event_id'];
            $ns = 'eid';
        } else {
            $ts = $payload['timestamp'] ?? '';
            if (!$ts) return true;
            $incoming = strtotime($ts);
            if ($incoming === false) return true;
            $ns = 'ts';
        }

        $optionKey = "tpv_sync_last_stock_{$ns}_{$scope}_{$resourceId}";
        $last      = (int) get_option($optionKey, 0);

        if ($incoming <= $last) {
            $tag = $useEventId ? 'event_id' : 'ts';
            $this->log('stock.out_of_order', $resourceId,
                "Descartado: {$tag}={$incoming} <= last={$last} ({$scope})", 'skip');
            return false;
        }

        update_option($optionKey, $incoming, false); // autoload=false
        return true;
    }

    /**
     * Verifica la firma HMAC-SHA256 del webhook.
     *
     * Método público estático para permitir tests unitarios directos sin
     * instanciar la clase (que requiere WC cargado). Se usa también
     * internamente desde handle().
     */
    public static function verify_signature(string $payload, string $signature, string $secret, int $timestamp = 0): bool
    {
        if ($secret === '' || $signature === '') return false;
        // Si llega timestamp, lo incluimos en el material firmado (anti-replay).
        // Mantenemos compat con el formato legacy (firma solo del body) durante
        // la migración. El TPV emite ambas firmas; el receptor acepta cualquiera.
        if ($timestamp > 0) {
            $expectedTs = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $payload, $secret);
            if (hash_equals($expectedTs, $signature)) return true;
        }
        $expectedLegacy = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedLegacy, $signature);
    }

    private function log(string $eventType, int $resourceId, string $msg, string $status = 'ok'): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tpv_sync_log', [
            'event_type'  => $eventType,
            'resource'    => 'webhook',
            'resource_id' => $resourceId,
            'status'      => $status,
            'message'     => $msg,
        ]);
    }
}

// Cron hook para re-sincronización tras CSV import
add_action('tpv_sync_import_all', function () {
    if (class_exists('TPV_Sync')) {
        TPV_Sync::instance()->products->import_all();
    }
});
