<?php
declare(strict_types=1);
/**
 * Cliente HTTP para la API TPV v1.
 * Gestiona autenticación OAuth2, caché del token y reintentos.
 */
defined('ABSPATH') || exit;

class TPV_Sync_API_Client
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $token      = null;
    private int     $tokenExp   = 0;
    private ?TPV_Sync_Circuit_Breaker $breaker = null;

    public function __construct()
    {
        $this->baseUrl      = rtrim(get_option('tpv_sync_api_url', 'https://tu-tpv.ejemplo.com/api/v1'), '/');
        // client_id se introduce desde el formulario del wizard (paso 1). El
        // fallback histórico era 'woocommerce' (cliente único compartido) —
        // lo mantenemos solo para retrocompat con instalaciones legacy que
        // se conectaron antes de que el form pidiera el cid. Nuevos installs
        // siempre traen su cid pegado del TPV (modelo multi-tenant).
        $this->clientId     = (string) apply_filters('tpv_sync_client_id', get_option('tpv_sync_client_id', '') ?: 'woocommerce');
        $this->clientSecret = get_option('tpv_sync_client_secret', '');
        if (class_exists('TPV_Sync_Circuit_Breaker')) {
            $this->breaker = new TPV_Sync_Circuit_Breaker();
        }
    }

    public function breaker(): ?TPV_Sync_Circuit_Breaker { return $this->breaker; }

    // ─── Token OAuth2 ────────────────────────────────────────────────────────

    private function getToken(): string
    {
        if ($this->token && time() < $this->tokenExp - 60) {
            return $this->token;
        }

        // Intentar recuperar de caché transitoria de WP
        $cached = get_transient('tpv_sync_token');
        if ($cached) {
            $this->token    = $cached['token'];
            $this->tokenExp = $cached['exp'];
            return $this->token;
        }

        $response = wp_remote_post($this->baseUrl . '/auth/token', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('TPV API auth error: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            throw new RuntimeException('TPV API: no se obtuvo token. ' . wp_remote_retrieve_body($response));
        }

        $this->token    = $body['access_token'];
        $this->tokenExp = time() + ($body['expires_in'] ?? 3600);

        set_transient('tpv_sync_token', [
            'token' => $this->token,
            'exp'   => $this->tokenExp,
        ], ($body['expires_in'] ?? 3600) - 60);

        return $this->token;
    }

    // ─── Métodos HTTP ─────────────────────────────────────────────────────────

    public function get(string $path, array $params = []): array
    {
        if ($this->breaker && !$this->breaker->allowRequest()) {
            return ['error' => 'circuit_open', 'errors' => [['error' => 'circuit_open', 'message' => 'Circuit breaker abierto — backend indispuesto']]];
        }
        $url = $this->baseUrl . $path;
        if ($params) $url .= '?' . http_build_query($params);

        $response = $this->doRequestWithRetry('GET', $path, function() use ($url) {
            return wp_remote_get($url, [
                'timeout' => 15,
                'headers' => $this->headers(),
            ]);
        });

        return $this->parse($response, 'GET', $path);
    }

    /**
     * POST con soporte para Idempotency-Key.
     * Si se pasa $idempotencyKey, se envía como header. El servidor cachea
     * la respuesta 24h: reintentos con el mismo key + mismo body devuelven
     * la respuesta previa. Cambio de body con mismo key → 409.
     */
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if ($this->breaker && !$this->breaker->allowRequest()) {
            return ['error' => 'circuit_open', 'errors' => [['error' => 'circuit_open', 'message' => 'Circuit breaker abierto — backend indispuesto']]];
        }
        $send = function() use ($path, $body, $idempotencyKey) {
            $headers = $this->headers();
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            $bodyStr = wp_json_encode($body);
            $headers = array_merge($headers, $this->signHeaders($bodyStr));
            $response = $this->doRequestWithRetry('POST', $path, function() use ($path, $headers, $bodyStr) {
                return wp_remote_post($this->baseUrl . $path, [
                    'timeout' => 15,
                    'headers' => $headers,
                    'body'    => $bodyStr,
                ]);
            });
            return $this->parse($response, 'POST', $path);
        };
        return $this->withHmacAutoRecovery($path, $send);
    }

    public function patch(string $path, array $body = []): array
    {
        if ($this->breaker && !$this->breaker->allowRequest()) {
            return ['error' => 'circuit_open', 'errors' => [['error' => 'circuit_open', 'message' => 'Circuit breaker abierto — backend indispuesto']]];
        }
        $send = function() use ($path, $body) {
            $bodyStr = wp_json_encode($body);
            $headers = array_merge($this->headers(), $this->signHeaders($bodyStr));
            $response = $this->doRequestWithRetry('PATCH', $path, function() use ($path, $headers, $bodyStr) {
                return wp_remote_request($this->baseUrl . $path, [
                    'method'  => 'PATCH',
                    'timeout' => 15,
                    'headers' => $headers,
                    'body'    => $bodyStr,
                ]);
            });
            return $this->parse($response, 'PATCH', $path);
        };
        return $this->withHmacAutoRecovery($path, $send);
    }

    public function delete(string $path): array
    {
        if ($this->breaker && !$this->breaker->allowRequest()) {
            return ['error' => 'circuit_open', 'errors' => [['error' => 'circuit_open', 'message' => 'Circuit breaker abierto — backend indispuesto']]];
        }
        $send = function() use ($path) {
            $headers = array_merge($this->headers(), $this->signHeaders(''));
            $response = $this->doRequestWithRetry('DELETE', $path, function() use ($path, $headers) {
                return wp_remote_request($this->baseUrl . $path, [
                    'method'  => 'DELETE',
                    'timeout' => 15,
                    'headers' => $headers,
                ]);
            });
            return $this->parse($response, 'DELETE', $path);
        };
        return $this->withHmacAutoRecovery($path, $send);
    }

    /**
     * Wrapper de auto-recovery silencioso: si la API responde 401 con
     * `signature_invalid`, significa que el HMAC secret de WC y TPV están
     * desincronizados. En vez de propagar el fallo (que el cliente vea
     * "Sincronización rota" en el chip), el plugin re-registra el webhook
     * automáticamente UNA vez y reintenta la operación. Si el reintento
     * también falla, devolvemos el error original para que la queue lo
     * procese normal.
     *
     * Solo dispara para llamadas que NO sean al endpoint de registrar webhook
     * (evitamos bucles infinitos) ni al `/auth/verify` (probe del chip).
     */
    private function withHmacAutoRecovery(string $path, callable $send): array
    {
        // Guard global por request: si ya estamos dentro de un recovery no
        // disparamos otro (evita bucles).
        if (!empty($GLOBALS['tpv_sync_in_recovery'])) {
            return $send();
        }
        // No reintentar para los endpoints de la propia recuperación.
        $skipRecovery = (
            $path === '/webhooks' ||
            str_starts_with($path, '/webhooks/') ||
            $path === '/auth/verify' ||
            $path === '/auth/token'
        );

        $r = $send();

        if ($skipRecovery || !$this->isHmacInvalid($r)) {
            return $r;
        }

        // Auto-recovery: re-registrar webhook con secret nuevo pre-acordado.
        $GLOBALS['tpv_sync_in_recovery'] = true;
        try {
            $recovered = $this->reRegisterWebhookSilently();
        } finally {
            unset($GLOBALS['tpv_sync_in_recovery']);
        }
        if (!$recovered) {
            return $r;
        }
        // Reintento UNA vez con el nuevo secret. Si vuelve a fallar, propaga.
        $this->log('ok', 'auto_recovery', 0, "HMAC desync detectado en $path — webhook re-registrado, reintentando");
        return $send();
    }

    private function isHmacInvalid(array $r): bool
    {
        if (empty($r)) return false;
        // Status 401 con type signature_invalid (formato problem+json)
        $type = (string)($r['type'] ?? '');
        if (str_contains($type, 'signature_invalid')) return true;
        // Algunas variantes en errors[]
        foreach (($r['errors'] ?? []) as $e) {
            if (($e['error'] ?? '') === 'signature_invalid') return true;
        }
        return false;
    }

    /**
     * Re-registra el webhook generando un secret nuevo y lo guarda.
     * Se ejecuta inline (sin AJAX) cuando detectamos un HMAC roto.
     * Devuelve true si todo OK, false si la recuperación también falló.
     */
    private function reRegisterWebhookSilently(): bool
    {
        $newSecret = bin2hex(random_bytes(32));
        $events = array_values(array_filter([
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
        ]));
        $r = $this->post('/webhooks', [
            'url'    => home_url('/tpv-webhook/'),
            'secret' => $newSecret,
            'events' => $events,
        ]);
        if (!empty($r['data']['webhook_id'])) {
            update_option('tpv_sync_webhook_id', (int)$r['data']['webhook_id']);
            update_option('tpv_sync_webhook_secret', (string)($r['data']['secret'] ?? $newSecret));
            // Invalidar caché del chip para que detecte el OK pronto.
            delete_option('tpv_sync_health_checked_at');
            return true;
        }
        return false;
    }

    /**
     * Ejecuta $fn() (una llamada HTTP) reintentando automáticamente ante 429
     * respetando el header Retry-After de la API. Tras MAX_ATTEMPTS devuelve
     * la última respuesta — parse() convertirá el 429 en error normal.
     *
     * Cap de espera total por request: 90s para no bloquear admin-ajax
     * indefinidamente, pero permitir al menos un ciclo completo de ventana
     * de rate limit (~60s en la API).
     *
     * NOTA: solo reintenta 429. 5xx los maneja el circuit breaker; los 4xx
     * restantes son errores legítimos del cliente (validación, etc.).
     */
    private function doRequestWithRetry(string $method, string $path, callable $fn)
    {
        $maxAttempts  = 3;
        $maxTotalWait = 90;
        $waited       = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $fn();
            if (is_wp_error($response)) return $response;

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 429) return $response;

            // Leer Retry-After: preferir segundos; si el servidor manda fecha HTTP, parsear.
            $retryAfter = (string) wp_remote_retrieve_header($response, 'retry-after');
            $sleep = 1;
            if ($retryAfter !== '') {
                if (ctype_digit($retryAfter)) {
                    $sleep = (int)$retryAfter;
                } else {
                    $ts = strtotime($retryAfter);
                    if ($ts !== false) $sleep = max(1, $ts - time());
                }
            }
            // Cap por intento: máx 60s (ventana completa). Jitter ±1s.
            $sleep = min(60, max(1, $sleep)) + random_int(0, 1);

            // No nos pasamos del presupuesto total ni hacemos un último intento inútil.
            if ($waited + $sleep > $maxTotalWait || $attempt === $maxAttempts) {
                return $response;
            }
            sleep($sleep);
            $waited += $sleep;
        }
        return $response;
    }

    // ─── Batch: N sub-requests en una llamada HTTP ───────────────────────────
    //
    // Aprovecha el endpoint POST /batch de la API TPV. Reduce ~10× la latencia
    // cuando hay que hacer N operaciones de golpe (refund multi-línea, import,
    // reconcile). El servidor ejecuta cada operación y devuelve un array de
    // resultados en el mismo orden.
    //
    // $operations: array de ['method'=>'GET|POST|PATCH', 'path'=>'/...', 'body'=>[...]]
    //
    // Retorno: array de ['index'=>int, 'status'=>int, 'body'=>mixed]
    public function batch(array $operations): array
    {
        if (empty($operations)) return ['results' => []];
        // Chunk en lotes de MAX_OPERATIONS (la API limita a 50)
        $max     = 50;
        $results = [];
        foreach (array_chunk($operations, $max) as $chunk) {
            $resp = $this->post('/batch', ['operations' => $chunk]);
            // La API envuelve la respuesta en {"data": {"results": [...]}}
            // (envelope estándar). Soportamos ambos formatos por seguridad:
            // si la API futura quitara el envelope, no rompemos retrocompat.
            // Bug previo (2026-04-28): solo leíamos $resp['results'] → batch
            // siempre devolvía [] → import_all reportaba "0 sincronizados"
            // sin error visible.
            $items = $resp['data']['results']
                  ?? $resp['results']
                  ?? null;
            if (is_array($items)) {
                $results = array_merge($results, $items);
            }
        }
        return ['results' => $results];
    }

    // ─── Paginación automática — recoge TODAS las páginas ────────────────────

    public function getAll(string $path, array $params = []): array
    {
        $params['per_page'] = 100;
        $all    = [];
        $cursor = null;

        do {
            if ($cursor) $params['cursor'] = $cursor;
            $result = $this->get($path, $params);
            $items  = $result['data'] ?? [];
            $all    = array_merge($all, $items);
            $cursor = $result['meta']['cursor'] ?? null;
        } while ($cursor && count($items) > 0);

        return $all;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function headers(): array
    {
        // Accept-Language: preferir locale de WP → idioma del error server.
        // `en_US` → `en`, `es_ES` → `es`. Para locales no soportados el TPV
        // cae automáticamente a castellano.
        $locale = get_locale();
        $lang   = strtolower(substr($locale, 0, 2));

        // Formato de precio que espera el plugin desde el TPV. Depende de
        // la configuración fiscal de WC para que el precio mostrado al
        // cliente coincida con lo que cobra el TPV físico:
        //
        //   - WC con prices_include_tax=yes  → 'gross' (precio con IVA)
        //   - WC con prices_include_tax=no   → 'net'  (sin IVA, WC suma)
        //   - WC con calc_taxes=no           → 'net'  (WC no aplica nada,
        //                                              guardamos el neto del TPV)
        //
        // Bug 2026-04-28: el plugin siempre pedía 'gross' aunque WC tuviera
        // impuestos OFF, resultando en precios inflados un 21% en la tienda.
        $priceFormat = 'gross';
        if (function_exists('get_option')) {
            $taxesOn        = get_option('woocommerce_calc_taxes', 'no') === 'yes';
            $pricesIncTax   = get_option('woocommerce_prices_include_tax', 'no') === 'yes';
            if (!$taxesOn || !$pricesIncTax) {
                // WC mostrará el precio TAL CUAL → conviene guardar el neto
                // del TPV para que coincida con lo cobrado en caja física.
                $priceFormat = 'net';
            }
        }

        return [
            'Authorization'   => 'Bearer ' . $this->getToken(),
            'Content-Type'    => 'application/json',
            // Aceptamos tanto formato legacy como RFC 7807 Problem Details;
            // si el TPV soporta problem+json, lo devuelve — si no, legacy.
            'Accept'          => 'application/problem+json, application/json',
            'Accept-Language' => $lang,
            'X-Price-Format'  => $priceFormat,
            'X-Client-Version'=> defined('TPV_SYNC_VERSION') ? TPV_SYNC_VERSION : '1.x',
            // X-Channel: el TPV usa este header para enrutar el matching
            // multi-canal vía api_external_mapping. Permite que un mismo TPV
            // sirva PS, WC y Shopify a la vez sin que sus mappings se pisen.
            'X-Channel'       => 'woocommerce',
        ];
    }

    /**
     * Consulta `GET /admin/feature-flags` para descubrir las features
     * activadas en el TPV. Cachea 5 min para no spamear.
     *
     * @return array<string, bool> ['feature_name' => enabled, ...]
     */
    public function featureFlags(): array
    {
        $cached = get_transient('tpv_sync_feature_flags');
        if (is_array($cached)) return $cached;

        try {
            $response = $this->get('/admin/feature-flags');
        } catch (Throwable $e) {
            // TPV sin endpoint o sin permisos → asumir sin features especiales
            set_transient('tpv_sync_feature_flags', [], 300);
            return [];
        }

        $flags = [];
        foreach ($response['data'] ?? [] as $row) {
            // Aplicable si es global '*' o específica para nuestro client_id
            $clientId = get_option('tpv_sync_client_id', '');
            if ($row['client_id'] === '*' || $row['client_id'] === $clientId) {
                $flags[$row['feature']] = ((int)$row['enabled'] === 1)
                                       && ((int)$row['rollout_pct'] >= 100 ||
                                           (hexdec(substr(hash('sha256', $row['feature'] . ':' . $clientId), 0, 8)) % 100
                                            < (int)$row['rollout_pct']));
            }
        }
        set_transient('tpv_sync_feature_flags', $flags, 300);
        return $flags;
    }

    /**
     * Comprueba una feature flag concreta.
     */
    public function isFeatureEnabled(string $feature): bool
    {
        $flags = $this->featureFlags();
        return !empty($flags[$feature]);
    }

    /**
     * Firma un body + timestamp con el webhook_secret compartido.
     * Formato: X-Signature: sha256=HMAC-SHA256(body + "\n" + timestamp, secret)
     * X-Timestamp: <unix epoch>
     *
     * Si no hay webhook_secret, no firma (retrocompat — solo Bearer).
     *
     * @return array ['X-Timestamp' => ..., 'X-Signature' => ...] o [] si no hay secret.
     */
    private function signHeaders(string $body): array
    {
        $secret = (string)get_option('tpv_sync_webhook_secret', '');
        if ($secret === '') return [];
        $ts  = (string)time();
        $mac = hash_hmac('sha256', $body . "\n" . $ts, $secret);
        return [
            'X-Timestamp' => $ts,
            'X-Signature' => 'sha256=' . $mac,
        ];
    }

    private function parse(mixed $response, string $method, string $path): array
    {
        if (is_wp_error($response)) {
            $this->log('error', 'http', 0, $method . ' ' . $path . ': ' . $response->get_error_message());
            if ($this->breaker) $this->breaker->recordFailure();
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?? [];

        if ($code >= 500) {
            // 5xx = error de servidor → breaker failure (backend inestable).
            $msg = $body['errors'][0]['message'] ?? "HTTP $code";
            $this->log('error', 'api', 0, "$method $path → $code: $msg");
            if ($this->breaker) $this->breaker->recordFailure();
        } elseif ($code >= 400) {
            // 4xx = error del cliente, pero el backend responde → breaker success.
            $msg = $body['errors'][0]['message'] ?? "HTTP $code";
            $this->log('error', 'api', 0, "$method $path → $code: $msg");
            if ($this->breaker) $this->breaker->recordSuccess();

            // 401 invalid_token / token revocado: el token cacheado en el
            // transient apunta a un cliente borrado o secret rotado. Borrar
            // el transient para que la siguiente request fuerce auth fresh.
            // Sin esto, el plugin entraba en bucle infinito reusando un token
            // muerto contra /batch durante todo el wizard. Bug 2026-04-28.
            if ($code === 401 && $path !== '/auth/token') {
                $errType = (string)($body['errors'][0]['error'] ?? $body['code'] ?? '');
                if (in_array($errType, ['invalid_token', 'invalid_client', 'token_expired'], true)
                    || str_contains((string)($body['type'] ?? ''), 'invalid_token')) {
                    delete_transient('tpv_sync_token');
                    $this->token    = null;
                    $this->tokenExp = 0;
                    $this->log('warn', 'auth', 0,
                        "Token invalidado tras 401 en $method $path — transient limpiado para reauth fresh"
                    );
                }
                // Si el TPV dice que las credenciales son inválidas, levantamos
                // un flag persistente para que el admin vea un banner claro
                // ("Tus credenciales ya no funcionan, pega de nuevo el secret")
                // en vez de un bucle silencioso 401 → 0 procesados → repeat.
                if ($errType === 'invalid_client'
                    && class_exists('TPV_Sync_Secrets')) {
                    TPV_Sync_Secrets::flagInvalidCredentials("$method $path");
                }
            }
        } else {
            if ($this->breaker) $this->breaker->recordSuccess();
        }

        return $body;
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret) && !empty($this->baseUrl);
    }

    private function log(string $status, string $resource, int $resourceId, string $msg): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tpv_sync_log', [
            'event_type'  => 'api_error',
            'resource'    => $resource,
            'resource_id' => $resourceId,
            'status'      => $status,
            'message'     => $msg,
        ]);
    }
}
