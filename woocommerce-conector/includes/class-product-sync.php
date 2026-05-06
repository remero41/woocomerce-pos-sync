<?php
declare(strict_types=1);
/**
 * Sincronización de productos TPV → WooCommerce.
 *
 * - Importación inicial: trae todos los productos del TPV y los crea/actualiza en WC.
 * - Actualizaciones via webhook: product.updated, stock.adjusted, special.created, etc.
 */
defined('ABSPATH') || exit;

class TPV_Sync_Product_Sync
{
    private TPV_Sync_API_Client $api;

    // Meta key para mapear product_id del TPV con el post_id de WC
    const TPV_ID_META           = '_tpv_product_id';
    const TPV_CUSTOMER_META     = '_tpv_customer_id';
    const TPV_CATEGORY_TERM_META = 'tpv_category_id'; // termmeta

    public function __construct(TPV_Sync_API_Client $api)
    {
        $this->api = $api;
    }

    // ─── Customer sync desde TPV → WC ────────────────────────────────────────

    /**
     * Upsert de un cliente TPV en WP. Si ya existe por email → update.
     * Si no → crea usuario WC con meta _tpv_customer_id.
     */
    public function sync_customer_from_tpv(int $tpvCustomerId, array $fields): void
    {
        if ($tpvCustomerId <= 0) return;
        $email = sanitize_email($fields['email'] ?? '');
        if (!$email) {
            $this->log('skip', $tpvCustomerId, 'customer sin email');
            return;
        }

        // Buscar por meta TPV primero, después por email.
        global $wpdb;
        $userId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::TPV_CUSTOMER_META, $tpvCustomerId
        ));
        if (!$userId) {
            $existing = get_user_by('email', $email);
            if ($existing) $userId = $existing->ID;
        }

        $userData = [
            'user_email'   => $email,
            'first_name'   => sanitize_text_field($fields['firstname'] ?? ''),
            'last_name'    => sanitize_text_field($fields['lastname']  ?? ''),
            'display_name' => trim(($fields['firstname'] ?? '') . ' ' . ($fields['lastname'] ?? '')),
        ];

        if ($userId) {
            wp_update_user(array_merge(['ID' => $userId], $userData));
        } else {
            $userData['user_login'] = $this->unique_login_from_email($email);
            $userData['user_pass']  = wp_generate_password(20, true);
            $userData['role']       = 'customer';
            $userId = wp_insert_user($userData);
            if (is_wp_error($userId)) {
                $this->log('error', $tpvCustomerId, 'wp_insert_user: ' . $userId->get_error_message());
                return;
            }
        }

        update_user_meta($userId, self::TPV_CUSTOMER_META, $tpvCustomerId);
        update_user_meta($userId, 'billing_email',     $email);
        update_user_meta($userId, 'billing_first_name', $fields['firstname'] ?? '');
        update_user_meta($userId, 'billing_last_name',  $fields['lastname']  ?? '');
        update_user_meta($userId, 'billing_phone',      $fields['telephone'] ?? '');
        if (!empty($fields['id_tax'])) {
            update_user_meta($userId, 'billing_vat', $fields['id_tax']);
        }

        $this->log('ok', $tpvCustomerId, "Customer WC user_id={$userId} sincronizado (email={$email})");
    }

    public function delete_wc_customer_by_tpv_id(int $tpvCustomerId): void
    {
        if ($tpvCustomerId <= 0) return;
        global $wpdb;
        $userId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::TPV_CUSTOMER_META, $tpvCustomerId
        ));
        if (!$userId) return;

        // Política conservadora: NO borrar el WP user (puede tener pedidos,
        // suscripciones). Solo quita el mapeo y marca inactive.
        delete_user_meta($userId, self::TPV_CUSTOMER_META);
        update_user_meta($userId, '_tpv_customer_deleted_at', current_time('mysql'));
        $this->log('ok', $tpvCustomerId, "Customer WC user_id={$userId} desenlazado (TPV lo borró)");
    }

    private function unique_login_from_email(string $email): string
    {
        $base  = sanitize_user(current(explode('@', $email)), true);
        if ($base === '' || username_exists($base)) {
            $base = sanitize_user($email, true);
        }
        $login = $base;
        $n = 1;
        while (username_exists($login)) {
            $login = $base . '_' . $n++;
            if ($n > 100) { $login = $base . '_' . wp_generate_password(6, false); break; }
        }
        return $login;
    }

    // ─── Category sync desde TPV → WC ────────────────────────────────────────

    public function sync_category_from_tpv(int $tpvCategoryId, array $fields): void
    {
        if ($tpvCategoryId <= 0) return;
        $name = sanitize_text_field($fields['name'] ?? '');
        if ($name === '') return;

        // Buscar term existente por termmeta
        global $wpdb;
        $termId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta}
             WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::TPV_CATEGORY_TERM_META, $tpvCategoryId
        ));

        $taxonomy = 'product_cat';

        if ($termId) {
            wp_update_term($termId, $taxonomy, ['name' => $name]);
        } else {
            // Buscar también por nombre para no duplicar
            $existing = get_term_by('name', $name, $taxonomy);
            if ($existing) {
                $termId = (int)$existing->term_id;
            } else {
                $result = wp_insert_term($name, $taxonomy);
                if (is_wp_error($result)) {
                    $this->log('error', $tpvCategoryId, 'wp_insert_term: ' . $result->get_error_message());
                    return;
                }
                $termId = (int)$result['term_id'];
            }
        }
        update_term_meta($termId, self::TPV_CATEGORY_TERM_META, $tpvCategoryId);

        $this->log('ok', $tpvCategoryId, "Category WC term_id={$termId} sincronizada (name={$name})");
    }

    public function delete_wc_category_by_tpv_id(int $tpvCategoryId): void
    {
        if ($tpvCategoryId <= 0) return;
        global $wpdb;
        $termId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta}
             WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::TPV_CATEGORY_TERM_META, $tpvCategoryId
        ));
        if (!$termId) return;
        wp_delete_term($termId, 'product_cat');
        $this->log('ok', $tpvCategoryId, "Category WC term_id={$termId} eliminada");
    }

    // ─── Importación completa ─────────────────────────────────────────────────

    /**
     * Importa todos los productos del TPV. Llamado manualmente desde el panel.
     * Devuelve ['created'=>N, 'updated'=>N, 'errors'=>N]
     */
    public function import_all(array $options = []): array
    {
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        $offset = max(0, (int) ($options['offset'] ?? 0));
        $limit  = (int) ($options['limit'] ?? 0); // 0 = sin límite

        $stats = ['created' => 0, 'updated' => 0, 'errors' => 0, 'orphans' => [], 'total_seen' => 0, 'processed' => 0, 'next_offset' => 0];

        // Cache de IDs por request (entre lotes del wizard se persiste en
        // wp_option). Antes: getAll() en cada lote → 27 páginas × N lotes.
        // Ahora: 27 páginas en el primer lote, 0 en los siguientes.
        $cacheTs = (int) get_option('tpv_sync_pull_ids_cache_ts', 0);
        $cached  = get_option('tpv_sync_pull_ids_cache', '');
        $tpvIds  = [];
        if ($cached !== '' && (time() - $cacheTs) < 1800) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) { $tpvIds = array_map('intval', $decoded); }
        }
        if (empty($tpvIds)) {
            $stubs = $this->api->getAll('/products', ['status' => 1, 'fields' => 'product_id']);
            foreach ($stubs as $stub) {
                $id = (int) ($stub['product_id'] ?? 0);
                if ($id) $tpvIds[] = $id;
            }
            update_option('tpv_sync_pull_ids_cache', json_encode($tpvIds), false);
            update_option('tpv_sync_pull_ids_cache_ts', time(), false);
        }
        $stats['total_seen'] = count($tpvIds);
        $allIds = $tpvIds;

        // Slice según offset/limit del lote actual.
        if ($offset > 0)  { $tpvIds = array_slice($tpvIds, $offset); }
        if ($limit > 0)   { $tpvIds = array_slice($tpvIds, 0, $limit); }

        // Procesamos en chunks de 50 vía /batch (1 RTT por 50 GETs).
        foreach (array_chunk($tpvIds, 50) as $chunk) {
            $ops = [];
            foreach ($chunk as $pid) {
                $ops[] = ['method' => 'GET', 'path' => '/products/' . $pid];
            }
            $resp = $this->api->batch($ops);

            // Si batch devolvió results vacío PERO el chunk tenía items,
            // algo falló a nivel HTTP (401, 500, network) — propagamos
            // como excepción para que ajax_import devuelva error real al JS
            // en vez de "success:true, processed:0" (que causaba bucle
            // infinito de reintentos sin avanzar). Bug 2026-04-28.
            if (empty($resp['results'] ?? []) && !empty($chunk)) {
                throw new RuntimeException(
                    'API batch sin resultados (probable 401 / token revocado / conector borrado). '
                    . 'Verifica las credenciales en el TPV.'
                );
            }

            foreach ($resp['results'] ?? [] as $i => $r) {
                $tpvId = $chunk[$i] ?? 0;
                $stats['processed']++;
                if (($r['status'] ?? 0) !== 200 || empty($r['body']['data'])) {
                    $stats['errors']++;
                    $this->log('error', (int) $tpvId,
                        'batch HTTP ' . ($r['status'] ?? 0) . ': ' . substr((string) json_encode($r['body'] ?? null), 0, 200)
                    );
                    continue;
                }
                try {
                    $result = $this->upsert($r['body']['data']);
                    $stats[$result] = ($stats[$result] ?? 0) + 1;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->log('error', (int) $tpvId, 'import: ' . $e->getMessage());
                }
            }
        }
        // Reasignar tpvIds para el cálculo de huérfanos al final.
        $tpvIds = $allIds;

        $stats['next_offset'] = $offset + $stats['processed'];
        if ($stats['next_offset'] >= $stats['total_seen']) {
            $stats['next_offset'] = 0; // terminado → fase orphans + cleanup
        }

        // Detectar huérfanos: productos WC con _tpv_product_id que ya no existe en el TPV
        if (!empty($tpvIds)) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($tpvIds), '%d'));
            $orphans = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value as tpv_id, p.post_title
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status != 'trash'
                 WHERE pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) NOT IN ($placeholders)",
                array_merge([self::TPV_ID_META], $tpvIds)
            ));
            foreach ($orphans as $o) {
                $stats['orphans'][] = [
                    'post_id' => (int)$o->post_id,
                    'tpv_id'  => (int)$o->tpv_id,
                    'name'    => $o->post_title,
                ];
            }
        }

        $this->log('ok', 0, "Importación completa: {$stats['created']} creados, {$stats['updated']} actualizados, {$stats['errors']} errores, " . count($stats['orphans']) . " huérfanos");
        return $stats;
    }

    /**
     * Elimina (pone en borrador) los productos WC huérfanos por sus post_ids.
     */
    public function delete_orphans(array $postIds): int
    {
        $deleted = 0;
        foreach ($postIds as $postId) {
            $postId = (int)$postId;
            if (!$postId) continue;
            wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
            $deleted++;
        }
        $this->log('ok', 0, "$deleted productos huérfanos despublicados");
        return $deleted;
    }

    // ─── Upsert único ─────────────────────────────────────────────────────────

    /**
     * Crea o actualiza un producto WC a partir de datos del TPV.
     * Devuelve 'created' o 'updated'.
     */
    public function upsert(array $p): string
    {
        // Activar guarda anti-bucle durante todo el upsert: evita que
        // wp_insert/update_post dispare woocommerce_new/update_product y
        // re-empuje al TPV creando eco infinito.
        $GLOBALS['tpv_sync_skip_wc_product_push'] = true;
        try {
            return $this->_upsert_inner($p);
        } finally {
            $GLOBALS['tpv_sync_skip_wc_product_push'] = false;
        }
    }

    private function _upsert_inner(array $p): string
    {
        $tpvId  = (int)$p['product_id'];
        $postId = $this->find_wc_post($tpvId);

        // External mapping reverse: si el TPV nos envió `external_id` (es
        // nuestro post_id que recordaba de una sincronización previa) y NO
        // teníamos mapping local, reconstruimos desde él. Evita la duplicación
        // tras reset: TPV manda los productos → no los reconocemos por
        // tpv_id → los crearíamos todos como nuevos en WC, duplicando el
        // catálogo. Con esto, recuperamos la asociación.
        if ($postId === 0 && isset($p['external_id'])) {
            $candidate = (int) $p['external_id'];
            if ($candidate > 0) {
                $exists = get_post($candidate);
                $alreadyMapped = (int) get_post_meta($candidate, self::TPV_ID_META, true);
                if ($exists && $exists->post_type === 'product' && $alreadyMapped === 0) {
                    $postId = $candidate;
                    update_post_meta($postId, self::TPV_ID_META, $tpvId);
                    $this->log('ok', $tpvId,
                        "Mapping reconstruido vía external_id (WC post=$postId ↔ TPV id=$tpvId)"
                    );
                } elseif ($exists && $alreadyMapped > 0 && $alreadyMapped !== $tpvId) {
                    $this->log('skip', $tpvId,
                        "external_id=$candidate ya mapeado a TPV id=$alreadyMapped — saltamos adopción"
                    );
                }
            }
        }

        // La API devuelve precios ya con impuesto incluido (X-Price-Format: gross)
        $regularPrice = (float)$p['price'];
        $price        = $p['special_price'] !== null ? (float)$p['special_price'] : $regularPrice;

        $data = [
            'post_title'   => wp_strip_all_tags($p['name'] ?? ''),
            'post_status'  => (int)($p['status'] ?? 0) === 1 ? 'publish' : 'draft',
            'post_type'    => 'product',
            'post_content' => wp_kses_post($p['description'] ?? ''),
        ];

        if ($postId) {
            $data['ID'] = $postId;
            wp_update_post($data);
            $created = false;
        } else {
            $postId  = wp_insert_post($data);
            $created = true;
            if (is_wp_error($postId)) {
                throw new RuntimeException($postId->get_error_message());
            }
        }

        // Meta WooCommerce
        update_post_meta($postId, '_price',         wc_format_decimal($price));
        update_post_meta($postId, '_regular_price', wc_format_decimal($regularPrice));
        if ($p['special_price'] !== null) {
            update_post_meta($postId, '_sale_price', wc_format_decimal($p['special_price']));
        } else {
            delete_post_meta($postId, '_sale_price');
        }

        // Mapeo simétrico al que hace push_wc_product_to_tpv (pero en sentido
        // TPV → Woo): el `model` del TPV puede ser un GTIN/EAN/UPC (solo
        // dígitos, 8-14 chars) o un SKU alfanumérico. Si parece GTIN lo
        // guardamos en `_global_unique_id` de WC (pestaña Inventory → GTIN)
        // y dejamos el `_sku` con el valor del campo sku del TPV (o con el
        // GTIN si sku está vacío, como fallback de identificación). Si el
        // `model` no es numérico lo tratamos como SKU textual y lo ponemos
        // en `_sku`.
        $modelTpv = trim((string)($p['model'] ?? ''));
        $skuTpv   = trim((string)($p['sku']   ?? ''));

        if ($modelTpv !== '' && preg_match('/^\d{8,14}$/', $modelTpv)) {
            // model TPV es un barcode numérico → va al campo GTIN de WC
            update_post_meta($postId, '_global_unique_id', $modelTpv);
            // El SKU de WC guarda el sku del TPV si existe; si no, duplica el GTIN
            // para que haya algún identificador textual (compat con flujos legacy).
            update_post_meta($postId, '_sku', $skuTpv !== '' ? $skuTpv : $modelTpv);
        } else {
            // model TPV es alfanumérico (ej. "WC-1234", "CAMISA-NARCISO") → SKU textual
            $skuForWc = $modelTpv !== '' ? $modelTpv : $skuTpv;
            update_post_meta($postId, '_sku', $skuForWc);
            // No tocamos _global_unique_id: si el admin de WC lo puso a mano,
            // respetamos su valor. Si no existía, sigue sin existir.
        }
        update_post_meta($postId, '_manage_stock',  'yes');
        update_post_meta($postId, '_stock',         (int)($p['quantity'] ?? 0));
        update_post_meta($postId, '_stock_status',  ($p['quantity'] ?? 0) > 0 ? 'instock' : 'outofstock');
        update_post_meta($postId, self::TPV_ID_META, $tpvId);

        // Mapeo inverso de impuestos TPV → WC.
        // El payload incluye `tax_class_id` directo (numérico) o un objeto
        // `tax: {tax_class_id, ...}` según endpoint. Tomamos el primero que
        // exista.  Si el id viene a 0 → '_tax_status' = 'none'.  Si tiene un
        // mapeo inverso conocido → setea ese slug en _tax_class. Si el id
        // existe en el TPV pero no está mapeado en plugin, lo dejamos como
        // Standard ('') con _tax_status='taxable' y logueamos warning para
        // que el admin sepa que tiene un caso sin mapear.
        $tpvTaxClassId = (int)(
            $p['tax_class_id']
            ?? ($p['tax']['tax_class_id'] ?? 0)
        );
        $this->applyReverseTaxClass($postId, $tpvTaxClassId, $tpvId);

        // Imágenes: usar images[] (detalle) si disponible, sino image (listado)
        if (!empty($p['images']) && is_array($p['images'])) {
            $this->sync_images($postId, $p['images'], $tpvId);
        } elseif (!empty($p['image'])) {
            $this->maybe_set_image($postId, $p['image'], $tpvId);
        }

        // Categorías
        if (!empty($p['categories']) && is_array($p['categories'])) {
            $this->sync_categories($postId, $p['categories']);
        }

        // Opciones/variantes
        if (!empty($p['options']) && is_array($p['options'])) {
            $this->sync_variations($postId, $p['options'], $tpvId, $regularPrice);
            // Producto variable: el stock lo gestionan las variaciones
            delete_post_meta($postId, '_stock');
            delete_post_meta($postId, '_stock_status');
            delete_post_meta($postId, '_manage_stock');
            wp_set_object_terms($postId, 'variable', 'product_type');
        } else {
            // Sin opciones: producto simple
            wp_set_object_terms($postId, 'simple', 'product_type');
        }

        // Vaciar caché WC
        wc_delete_product_transients($postId);
        clean_post_cache($postId);

        return $created ? 'created' : 'updated';
    }

    // ─── Descontar stock en TPV cuando se vende en WC (módulo Catálogo sin Pedidos) ──

    /**
     * Cuando se procesa un pago en WC, descuenta el stock en el TPV
     * producto a producto vía PATCH /products/{id}/stock.
     * Solo se llama si el módulo Pedidos está desactivado.
     */
    public function deduct_stock_from_wc_order(int $wcOrderId): void
    {
        // Evitar procesar dos veces
        if (get_post_meta($wcOrderId, '_tpv_stock_deducted', true)) return;

        $order = wc_get_order($wcOrderId);
        if (!$order) return;

        foreach ($order->get_items() as $item) {
            $tpvId = get_post_meta($item->get_product_id(), TPV_Sync_Product_Sync::TPV_ID_META, true);
            if (!$tpvId) continue;

            $qty = (int)$item->get_quantity();
            if ($qty <= 0) continue;

            $this->api->patch("/products/$tpvId/stock", [
                'quantity_change' => -$qty,
                'reason'          => 'venta',
                'comment'         => 'Venta WooCommerce #' . $wcOrderId,
            ]);
        }

        update_post_meta($wcOrderId, '_tpv_stock_deducted', 1);
        $this->log('ok', 0, "Stock descontado en TPV por venta WC #{$wcOrderId}");
    }

    // ─── WC → TPV: empujar cambios de stock manuales (edición en WC) ─────────

    /**
     * Hook: woocommerce_product_object_updated_props
     * Cuando cambia el stock_quantity de un producto simple o de una variación
     * desde WC (edición manual, otros plugins, etc.), enviamos el valor
     * absoluto al TPV para que ambos lados queden sincronizados.
     *
     * Protegido contra bucles por $GLOBALS['tpv_sync_skip_wc_stock_push']:
     * el webhook handler activa ese flag antes de escribir stock en WC.
     */
    public function push_wc_stock_change($product, array $updated_props): void
    {
        if (!empty($GLOBALS['tpv_sync_skip_wc_stock_push'])) return;
        if (!in_array('stock_quantity', $updated_props, true)) return;
        if (!$product instanceof WC_Product) return;

        // Si manage_stock está desactivado, stock_quantity=null — no empujar:
        // interpretarlo como 0 arrasaría el stock del TPV.
        $rawStock = $product->get_stock_quantity();
        if ($rawStock === null || $rawStock === '') return;
        $newStock = (float)$rawStock;

        if ($product->is_type('variation')) {
            $povId    = (int)get_post_meta($product->get_id(), '_tpv_option_value_id', true);
            $parentId = (int)$product->get_parent_id();
            $tpvProd  = (int)get_post_meta($parentId, self::TPV_ID_META, true);
            if (!$povId || !$tpvProd) return;

            // PATCH /products/{pid}/variants/{pov_id} acepta {quantity}
            $this->api->patch("/products/$tpvProd/variants/$povId", [
                'quantity' => $newStock,
            ]);
        } else {
            $tpvId = (int)get_post_meta($product->get_id(), self::TPV_ID_META, true);
            if (!$tpvId) return;

            // El endpoint adjustStock usa delta — calculamos a partir del stock
            // actual del TPV para que ambos lados queden exactos.
            //
            // Si la API devuelve error (token caducado, 5xx, timeout), NO empujamos:
            // calcular delta contra tpvQty=0 por asumir ausencia daría un delta
            // enorme que rompería el stock. Registramos y salimos.
            $current = $this->api->get("/products/$tpvId/stock");
            $tpvQty  = null;
            if (isset($current['total']['quantity'])) {
                $tpvQty = (float)$current['total']['quantity'];
            } elseif (isset($current['data']['quantity'])) {
                $tpvQty = (float)$current['data']['quantity'];
            }
            if ($tpvQty === null) {
                $this->log('error', $tpvId, "push_wc_stock_change: no se pudo leer stock TPV — encolando para reintento");
                // Encolar: stock se aplicará cuando TPV vuelva. Reason='ajuste_manual'.
                if (class_exists('TPV_Sync') && class_exists('TPV_Sync_Queue')) {
                    TPV_Sync::instance()->queue->enqueue(
                        'stock.push',
                        [
                            'tpv_product_id' => $tpvId,
                            // delta lo recalculamos al procesar la queue (stock TPV vs WC actual),
                            // pero guardamos el newStock absoluto aquí como referencia:
                            'absolute_target' => $newStock,
                            'delta'           => 0,  // se recalcula
                            'reason'          => 'ajuste_manual',
                            'comment'         => 'WC product #' . $product->get_id() . ' (reintento)',
                        ],
                        'stock read failed'
                    );
                }
                return;
            }
            $delta = $newStock - $tpvQty;
            if (abs($delta) < 0.0001) return; // nada que cambiar

            $this->api->patch("/products/$tpvId/stock", [
                'quantity_change' => $delta,
                'reason'          => 'ajuste_manual',
                'comment'         => 'WC product #' . $product->get_id(),
            ]);
        }
    }

    // ─── Actualizar solo stock ────────────────────────────────────────────────

    public function update_stock(int $tpvId, float $quantity): void
    {
        $postId = $this->find_wc_post($tpvId);
        if (!$postId) return;

        // Evitar eco al TPV: este cambio lo originó el propio TPV.
        $GLOBALS['tpv_sync_skip_wc_stock_push'] = true;
        try {
            $isVariable = has_term('variable', 'product_type', $postId);
            if ($isVariable) {
                wc_delete_product_transients($postId);
                if (class_exists('WC_Product_Variable')) {
                    WC_Product_Variable::sync($postId);
                }
                return;
            }
            update_post_meta($postId, '_stock', $quantity);
            update_post_meta($postId, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
            wc_delete_product_transients($postId);
        } finally {
            $GLOBALS['tpv_sync_skip_wc_stock_push'] = false;
        }
    }

    /**
     * Actualización estricta de stock por variante (product_option_value_id).
     * Localiza la variación WC por su meta `_tpv_option_value_id` y le pone
     * el stock absoluto que viene del TPV.
     */
    public function update_variant_stock(int $tpvOptionValueId, float $quantity): void
    {
        global $wpdb;
        $varId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_tpv_option_value_id' AND meta_value = %d LIMIT 1",
            $tpvOptionValueId
        ));
        if (!$varId) return;

        $GLOBALS['tpv_sync_skip_wc_stock_push'] = true;
        try {
            update_post_meta($varId, '_manage_stock', 'yes');
            update_post_meta($varId, '_stock', $quantity);
            update_post_meta($varId, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');

            $parentId = wp_get_post_parent_id($varId);
            if ($parentId) {
                wc_delete_product_transients($parentId);
                if (class_exists('WC_Product_Variable')) {
                    WC_Product_Variable::sync($parentId);
                }
            }
        } finally {
            $GLOBALS['tpv_sync_skip_wc_stock_push'] = false;
        }
    }

    // ─── Reconciliación periódica ─────────────────────────────────────────────

    /**
     * Compara stock TPV vs WC para un lote de productos y corrige los que
     * discrepan, usando el TPV como fuente autoritativa. Pensado para cron.
     *
     * @param int $limit máximo de productos a revisar (0 = todos los activos)
     * @return array stats con keys: checked, fixed, variant_fixed, skipped, errors
     */
    public function reconcile(int $limit = 100): array
    {
        $stats = ['checked' => 0, 'fixed' => 0, 'variant_fixed' => 0, 'skipped' => 0, 'errors' => 0];

        $params = ['status' => 1];
        if ($limit > 0) $params['per_page'] = min($limit, 100);

        $products = $limit > 0
            ? array_slice($this->api->getAll('/products', $params), 0, $limit)
            : $this->api->getAll('/products', $params);

        // Batch GETs de detalle en lotes de 50 para reducir RTTs.
        // En vez de 100 requests individuales (con token + auth cada una),
        // hacemos 2 batches de 50.
        $details = [];
        $stubsMapped = [];
        foreach ($products as $stub) {
            $tpvId = (int)($stub['product_id'] ?? 0);
            if (!$tpvId) continue;
            if (!$this->find_wc_post($tpvId)) { $stats['skipped']++; continue; }
            $stubsMapped[] = $tpvId;
        }

        foreach (array_chunk($stubsMapped, 50) as $chunk) {
            $ops = [];
            foreach ($chunk as $pid) {
                $ops[] = ['method' => 'GET', 'path' => "/products/$pid"];
            }
            $resp = $this->api->batch($ops);
            foreach ($resp['results'] ?? [] as $i => $r) {
                if (($r['status'] ?? 0) === 200 && !empty($r['body']['data'])) {
                    $data = $r['body']['data'];
                    $pid  = (int)($data['product_id'] ?? $chunk[$i]);
                    $details[$pid] = $data;
                }
            }
        }

        foreach ($stubsMapped as $tpvId) {
            $postId = $this->find_wc_post($tpvId);
            $stats['checked']++;

            try {
                $data = $details[$tpvId] ?? null;
                if (!$data) { $stats['errors']++; continue; }

                $tpvQty = (float)($data['quantity'] ?? 0);
                $isVar  = !empty($data['options']);

                if ($isVar) {
                    // Reconciliar variante a variante
                    foreach ($data['options'] ?? [] as $opt) {
                        foreach ($opt['values'] ?? [] as $val) {
                            $povId = (int)($val['product_option_value_id'] ?? 0);
                            $qty   = (float)($val['quantity'] ?? 0);
                            if (!$povId) continue;

                            global $wpdb;
                            $varId = (int)$wpdb->get_var($wpdb->prepare(
                                "SELECT post_id FROM {$wpdb->postmeta}
                                 WHERE meta_key = '_tpv_option_value_id' AND meta_value = %d LIMIT 1",
                                $povId
                            ));
                            if (!$varId) continue;
                            $wcQty = (float)get_post_meta($varId, '_stock', true);
                            if (abs($wcQty - $qty) > 0.0001) {
                                $this->update_variant_stock($povId, $qty);
                                $stats['variant_fixed']++;
                                $this->log('reconcile_fix', $povId,
                                    "variant pov={$povId} WC={$wcQty} → TPV={$qty}");
                            }
                        }
                    }
                } else {
                    $wcQty = (float)get_post_meta($postId, '_stock', true);
                    if (abs($wcQty - $tpvQty) > 0.0001) {
                        $this->update_stock($tpvId, $tpvQty);
                        $stats['fixed']++;
                        $this->log('reconcile_fix', $tpvId,
                            "product tpv_id={$tpvId} WC={$wcQty} → TPV={$tpvQty}");
                    }
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->log('reconcile_error', $tpvId, $e->getMessage());
            }
        }

        $this->log('reconcile_done', 0,
            "checked={$stats['checked']} fixed={$stats['fixed']} variant_fixed={$stats['variant_fixed']}"
            . " skipped={$stats['skipped']} errors={$stats['errors']}");

        return $stats;
    }

    // ─── Actualizar datos básicos (precio, nombre, estado) ───────────────────

    public function update_from_tpv(int $tpvId): void
    {
        $result = $this->api->get("/products/$tpvId");
        if (empty($result['data'])) return;
        $this->upsert($result['data']);
    }

    // ─── Eliminar producto ────────────────────────────────────────────────────

    public function delete_product(int $tpvId): void
    {
        $postId = $this->find_wc_post($tpvId);
        if (!$postId) return;
        // Guarda anti-bucle: el webhook TPV→WC llega aquí; no debemos
        // re-empujar el cambio al TPV vía los hooks de WC.
        $GLOBALS['tpv_sync_skip_wc_product_push'] = true;
        try {
            wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
        } finally {
            $GLOBALS['tpv_sync_skip_wc_product_push'] = false;
        }
        $this->log('ok', $tpvId, "Producto despublicado (eliminado en TPV)");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WC → TPV — empuje bidireccional del catálogo
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Hook: woocommerce_new_product / woocommerce_update_product
     *
     * Cuando el admin crea/edita un producto en WC, empujamos al TPV:
     *   - Si ya tiene _tpv_product_id → PATCH /products/{id}
     *   - Si NO lo tiene → POST /products (auto-generamos SKU si falta)
     *
     * Campos que mandamos: name, description, price (regular), sku, status.
     * NO mandamos stock — el stock lo gestiona push_wc_stock_change con la
     * protección anti-bucle propia.
     *
     * Anti-bucle: $GLOBALS['tpv_sync_skip_wc_product_push'] lo activa el
     * webhook handler cuando está escribiendo desde TPV hacia WC.
     */
    /**
     * Push masivo de hasta 100 productos WC al TPV en una sola petición HTTP
     * usando POST /products/bulk. Sustituye al bucle de push_wc_product_to_tpv
     * para acelerar la primera sincronización en catálogos grandes.
     *
     * Productos con variantes caen al fallback singular automáticamente
     * (el bulk no maneja `options` por ahora).
     *
     * Devuelve ['sent', 'created', 'updated', 'errors', 'fallback'].
     */
    public function push_wc_products_bulk(array $postIds): array
    {
        $stats = ['sent' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0, 'fallback' => 0];
        $bulkPayloads = [];
        $bulkPostIds = [];

        foreach ($postIds as $postId) {
            $postId = (int) $postId;
            $payload = $this->buildPushPayload($postId);
            if ($payload === null) { $stats['errors']++; continue; }
            // Si tiene variantes, va por singular (preserva options + mapping).
            if (!empty($payload['options'])) {
                $ok = $this->push_wc_product_to_tpv($postId);
                if ($ok) { $stats['sent']++; } else { $stats['errors']++; }
                $stats['fallback']++;
                continue;
            }
            // Eliminar el helper interno antes de mandar al TPV.
            unset($payload['__post_id']);
            $bulkPayloads[] = $payload;
            $bulkPostIds[]  = $postId;
        }

        if (empty($bulkPayloads)) { return $stats; }

        $resp = $this->api->post('/products/bulk', ['items' => $bulkPayloads]);
        if (!empty($resp['error']) || !empty($resp['errors'])) {
            // Bulk falló entero → fallback singular para no perder catálogo.
            $err0 = $resp['errors'][0] ?? [];
            $msg = ($err0['field'] ?? '') !== ''
                ? ($err0['field'] . ': ' . ($err0['message'] ?? 'unknown'))
                : ($err0['message'] ?? $resp['error'] ?? 'unknown');
            $this->log('error', 0, "POST /products/bulk falló: $msg — fallback a singular");
            foreach ($bulkPostIds as $pid) {
                $ok = $this->push_wc_product_to_tpv($pid);
                if ($ok) { $stats['sent']++; } else { $stats['errors']++; }
                $stats['fallback']++;
            }
            return $stats;
        }

        // Persistir mapeos con los TPV ids devueltos.
        $results = $resp['data']['results'] ?? [];
        foreach ($results as $r) {
            $idx = (int) ($r['index'] ?? -1);
            $tpvId = (int) ($r['product_id'] ?? 0);
            if ($idx < 0 || $idx >= count($bulkPostIds) || $tpvId === 0) { continue; }
            $postId = $bulkPostIds[$idx];
            update_post_meta($postId, self::TPV_ID_META, $tpvId);
            $action = (string) ($r['action'] ?? '');
            if ($action === 'created')      { $stats['created']++; }
            elseif ($action === 'updated')  { $stats['updated']++; }
            $stats['sent']++;
        }
        return $stats;
    }

    /**
     * Construye el payload WC → TPV. Extraído de push_wc_product_to_tpv para
     * que push_wc_products_bulk reuse la misma lógica de mapeo.
     *
     * Devuelve null si el producto no es válido. El array incluye un campo
     * helper `__post_id` que el caller debe eliminar antes de mandar.
     */
    private function buildPushPayload(int $postId): ?array
    {
        $post = get_post($postId);
        if (!$post || !in_array($post->post_type, ['product'], true)) return null;
        $product = function_exists('wc_get_product') ? wc_get_product($postId) : null;
        if (!$product) return null;
        if ($product->is_type('variation')) return null;

        $gtin = trim((string) get_post_meta($postId, '_global_unique_id', true));
        $sku  = trim((string) $product->get_sku());
        $fallback = '__WC__' . $postId;

        if ($gtin !== '')   { $model = $gtin; }
        elseif ($sku !== '') { $model = $sku; }
        else                 { $model = $fallback; }

        $skuForTpv = $sku !== '' ? $sku : $fallback;

        // Mismo guard que push_wc_product_to_tpv: precio negativo no soportado.
        // En el bulk path, retornar null hace que el caller (push_wc_products_bulk)
        // cuente el item como "errors" — no hay otro side-effect.
        $rawPrice = (float) ($product->get_regular_price() ?: 0);
        if ($rawPrice < 0) {
            $this->log('skip', $postId,
                "Producto con precio negativo (" . $rawPrice . " €) no soportado por el TPV. Skip en bulk push."
            );
            return null;
        }

        $netPrice = $this->priceForTpv($product, $rawPrice);

        $payload = [
            'name'        => $post->post_title,
            'description' => $post->post_content,
            'price'       => $netPrice,
            'model'       => $model,
            'sku'         => $skuForTpv,
            'status'      => $post->post_status === 'publish' ? 1 : 0,
            // client_external_id: nuestro post_id local. El TPV lo guarda en
            // api_external_mapping (channel='woocommerce') y nos permite
            // reconstruir mappings tras un reset sin duplicar productos.
            'client_external_id' => (string) $postId,
            '__post_id'   => $postId,
        ];

        $taxClassId = $this->resolveTpvTaxClassId($product);
        if ($taxClassId > 0) $payload['tax_class_id'] = $taxClassId;

        if ($product->is_type('variable')) {
            $options = $this->build_options_for_tpv($product);
            if (!empty($options)) {
                $payload['options'] = $options;
            }
        }
        return $payload;
    }

    /**
     * Resuelve el `tax_class_id` del TPV a partir del producto WC.
     * Lee `_tax_status` y `_tax_class` del meta y mira el mapeo guardado en
     * `tpv_sync_tax_class_mapping` (slug → id).
     *
     * Reglas:
     *   - `_tax_status = 'none'`         → 0 (Sin impuestos en TPV).
     *   - `_tax_class = 'parent'`        → ignorar (variación heredando del
     *                                      padre — no debería llegar aquí
     *                                      porque solo pusheamos productos
     *                                      padre, pero defensivo).
     *   - `_tax_class = ''`              → mapeo de "Standard" (slug '').
     *   - `_tax_class = 'reduced-rate'`  → mapeo del slug.
     *   - Sin mapeo guardado para ese slug → 0 (Sin impuestos, default).
     */
    private function resolveTpvTaxClassId($product): int
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) return 0;
        $postId = (int) $product->get_id();

        $taxStatus = (string) get_post_meta($postId, '_tax_status', true);
        if ($taxStatus === 'none') return 0;

        $taxClass = (string) get_post_meta($postId, '_tax_class', true);
        if ($taxClass === 'parent') return 0; // defensive

        $mapping = (array) get_option('tpv_sync_tax_class_mapping', []);
        $id      = (int) ($mapping[$taxClass] ?? 0);
        return $id > 0 ? $id : 0;
    }

    /**
     * Convierte el precio de WC al formato esperado por la API del TPV (NETO).
     *
     * La API exige `price` sin IVA: el TPV recalcula el bruto al imprimir
     * ticket aplicando la `tax_class_id` del producto. Si el cliente WC tiene
     * `prices_include_tax = yes`, su `_regular_price` es BRUTO y debe
     * convertirse — `wc_get_price_excluding_tax` lo hace usando las rates
     * configuradas en WC.
     *
     * Casos:
     *   1. is_taxable=false (ej. calc_taxes=no o producto con tax_status=none):
     *      WC devuelve el price tal cual → ok, ya es el valor "puro".
     *   2. prices_include_tax=no: WC devuelve tal cual → es neto, ok.
     *   3. prices_include_tax=yes + rates configuradas: WC descuenta el IVA → neto, ok.
     *   4. prices_include_tax=yes + SIN rates en BD: WC devuelve el bruto sin
     *      tocarlo (no tiene rate que descontar). Esto provoca doble IVA
     *      latente en TPV, pero es coherente con lo que WC también hace en su
     *      shop. El banner del plugin avisa expresamente este caso.
     *
     * Si por algún motivo el cálculo de WC no se puede completar (versión
     * vieja, función no presente), caemos al rawPrice — comportamiento
     * pre-fix que es "best effort".
     */
    private function priceForTpv($product, float $rawPrice): float
    {
        if (!function_exists('wc_get_price_excluding_tax') || !is_object($product)) {
            return $rawPrice;
        }
        $net = wc_get_price_excluding_tax($product, ['price' => $rawPrice]);
        if (!is_numeric($net)) return $rawPrice;
        // Redondeo defensivo a 4 decimales — la API valida `price` numérico
        // pero al final InnoDB guarda DECIMAL(15,4) en oc_product.
        return round((float)$net, 4);
    }

    /**
     * Mapeo inverso: el TPV nos manda un `tax_class_id`, lo traducimos al
     * `_tax_class` slug correspondiente en WC y al `_tax_status`. Llamado
     * desde `_upsert_inner` cada vez que llega un producto del TPV.
     *
     *   - id=0          → `_tax_status='none'` (Sin impuestos).
     *   - id mapeado    → `_tax_class=<slug>`, `_tax_status='taxable'`.
     *   - id no mapeado → log 'warn' + `_tax_class=''` (Standard) como
     *                     fallback razonable. El admin lo verá en logs y
     *                     puede actualizar el mapeo en la pestaña Impuestos.
     */
    private function applyReverseTaxClass(int $postId, int $tpvTaxClassId, int $tpvIdForLog): void
    {
        if ($tpvTaxClassId === 0) {
            update_post_meta($postId, '_tax_status', 'none');
            // No tocamos _tax_class: WC lo respeta como Standard si está vacío.
            return;
        }

        $mapping = (array) get_option('tpv_sync_tax_class_mapping', []);
        // mapping es slug => id; invertimos.
        $reverse = [];
        foreach ($mapping as $slug => $id) {
            $reverse[(int)$id] = (string)$slug;
        }
        if (isset($reverse[$tpvTaxClassId])) {
            update_post_meta($postId, '_tax_class', $reverse[$tpvTaxClassId]);
            update_post_meta($postId, '_tax_status', 'taxable');
            return;
        }

        // No hay mapeo inverso. Log y fallback a Standard.
        update_post_meta($postId, '_tax_class', '');
        update_post_meta($postId, '_tax_status', 'taxable');
        $this->log('warn', $tpvIdForLog,
            "Producto TPV con tax_class_id=$tpvTaxClassId sin mapeo inverso en plugin — asignado a 'Estándar'. "
            . "Configura el mapeo en TPV Sync → Impuestos."
        );
    }

    /**
     * Catálogo del TPV indexado por model y sku — cargado UNA vez por request
     * para evitar GET /products?search por cada producto sin mapeo local.
     * En pushes iniciales con catálogos grandes esta optimización convierte
     * O(N×search_RTT) en O(1×N/per_page páginas).
     */
    private array $tpvCatalogIndex = [];
    private bool $tpvCatalogIndexLoaded = false;

    private function getTpvCatalogIndex(): array
    {
        if ($this->tpvCatalogIndexLoaded) return $this->tpvCatalogIndex;
        $this->tpvCatalogIndexLoaded = true;
        $this->tpvCatalogIndex = ['by_model' => [], 'by_sku' => []];

        try {
            $cursor = null;
            $pageGuard = 0;
            do {
                $params = ['per_page' => 200, 'fields' => 'product_id,model,sku'];
                if ($cursor !== null) { $params['cursor'] = $cursor; }
                $r = $this->api->get('/products', $params);
                foreach (($r['data'] ?? []) as $row) {
                    $tid = (int) ($row['product_id'] ?? 0);
                    if ($tid <= 0) continue;
                    $m = (string) ($row['model'] ?? '');
                    $s = (string) ($row['sku'] ?? '');
                    if ($m !== '' && !isset($this->tpvCatalogIndex['by_model'][$m])) {
                        $this->tpvCatalogIndex['by_model'][$m] = $tid;
                    }
                    if ($s !== '' && !isset($this->tpvCatalogIndex['by_sku'][$s])) {
                        $this->tpvCatalogIndex['by_sku'][$s] = $tid;
                    }
                }
                $cursor = $r['meta']['cursor'] ?? null;
                $pageGuard++;
            } while ($cursor !== null && $pageGuard < 100);
        } catch (Throwable $e) {
            $this->log('error', 0, 'precarga TPV catalog: ' . $e->getMessage());
        }
        return $this->tpvCatalogIndex;
    }

    /**
     * Empuja un producto WC al TPV (create o update según tenga meta _tpv_product_id).
     *
     * Devuelve true si el producto llegó al TPV (201/200), false en cualquier
     * fallo (validación, rate limit, emoji-utf8mb3, HTTP error, post no válido).
     * Los hooks de WP ignoran el retorno — lo usa el bulk push de la UI admin
     * para reportar contadores reales en vez de "0 errors" engañosos.
     */
    public function push_wc_product_to_tpv($productOrId): bool
    {
        if (!empty($GLOBALS['tpv_sync_skip_wc_product_push'])) return false;

        $postId = is_object($productOrId) && method_exists($productOrId, 'get_id')
            ? (int)$productOrId->get_id()
            : (int)$productOrId;
        if (!$postId) return false;

        // Modo Principal: si manda el TPV, los cambios iniciados en WC sobre
        // productos sincronizados deben REVERTIRSE (re-leer desde el TPV) y
        // los cambios en islas (productos solo-WC) NO deben propagarse al TPV.
        // El stock es bidireccional siempre — pero el stock no pasa por aquí
        // (lo gestiona push_wc_stock_change).
        $principal = (string) get_option('tpv_sync_principal', '');
        if ($principal === 'tpv') {
            $tpvId = (int) get_post_meta($postId, self::TPV_ID_META, true);
            if ($tpvId === 0) {
                // Isla en WC: cambio local, no se propaga.
                return false;
            }
            // Producto sincronizado: revertimos al estado del TPV.
            $GLOBALS['tpv_sync_skip_wc_product_push'] = true;
            try {
                $this->update_from_tpv($tpvId);
            } finally {
                $GLOBALS['tpv_sync_skip_wc_product_push'] = false;
            }
            return false;
        }

        // Solo productos simples o configurables, no variaciones (se manejan
        // aparte con push_wc_stock_change / PATCH variants).
        $post = get_post($postId);
        if (!$post || !in_array($post->post_type, ['product'], true)) return false;

        $product = function_exists('wc_get_product') ? wc_get_product($postId) : null;
        if (!$product) return false;
        // Variaciones individuales se saltan: se envían como opciones del padre
        // cuando el padre se pushea (ver build_options_for_tpv abajo).
        if ($product->is_type('variation')) return false;

        // ──────────────────────────────────────────────────────────────────
        // Mapeo de identificadores WC → TPV
        // ──────────────────────────────────────────────────────────────────
        // El TPV necesita OBLIGATORIAMENTE un campo `model` no vacío y único
        // (es lo que muestra como columna "Modelo" en el admin, lo que se
        // escanea con la pistola de barcode, y su valor no puede repetirse).
        //
        // Preferencias (de mejor a peor):
        //
        //   1. `model` ← `_global_unique_id` (GTIN/EAN/UPC/ISBN) de WC 8.3+
        //      Es el estándar de WooCommerce para códigos de barras. Si el
        //      cliente lo rellena, el producto se puede escanear en el TPV.
        //
        //   2. `model` ← `_sku` (SKU de WC) si el GTIN está vacío.
        //      El SKU suele ser alfanumérico, sirve como identificador único
        //      aunque no sea barcode escaneable.
        //
        //   3. `model` ← "WC-<post_id>" como último recurso.
        //      Garantiza unicidad global (IDs de WP son únicos) para que el
        //      producto al menos exista en el TPV y se pueda editar luego.
        //
        // Para el campo `sku` del TPV, usamos el `_sku` de WC si existe, o el
        // fallback "WC-<post_id>". NUNCA autogeneramos un slug del título y
        // lo grabamos en WC (hacerlo corrompía el catálogo del cliente al
        // sobrescribir SKUs reales que estaban vacíos por otros motivos).
        $gtin = trim((string)get_post_meta($postId, '_global_unique_id', true));
        $sku  = trim((string)$product->get_sku());

        // Fallback técnico: prefijo inusual ("__WC__") para minimizar colisiones
        // con SKUs escritos a mano. Si aun así otro producto usa exactamente ese
        // valor como SKU real, el TPV responderá 422 "sku already exists" y el
        // push fallará — es el único caso en que el cliente tendría que renombrar.
        $fallback = '__WC__' . $postId;

        if ($gtin !== '') {
            $model = $gtin;
        } elseif ($sku !== '') {
            $model = $sku;
        } else {
            $model = $fallback;
        }

        $skuForTpv = $sku !== '' ? $sku : $fallback;

        // Guard: el TPV exige price >= 0. Algunos clientes WC modelan
        // descuentos manuales como un "producto" con precio negativo (ej.
        // "RESTA DE BOLSA" a -20€). Eso sería válido en WC pero el TPV lo
        // rechaza con 422 — no hay forma honesta de mapearlo (un voucher
        // sería el equivalente real). Skip explícito con mensaje claro.
        $rawPrice = (float)($product->get_regular_price() ?: 0);
        if ($rawPrice < 0) {
            $this->log('skip', $postId,
                "Producto con precio negativo (" . $rawPrice . " €) no soportado: el TPV no acepta precios negativos. "
                . "Si lo usas como descuento, conviértelo a un cupón/voucher o a producto a 0€ con el descuento aparte. "
                . "Este producto NO se sincronizará."
            );
            return false;
        }

        $netPrice = $this->priceForTpv($product, $rawPrice);

        $payload = [
            'name'        => $post->post_title,
            'description' => $post->post_content,
            'price'       => $netPrice,
            'model'       => $model,
            'sku'         => $skuForTpv,
            'status'      => $post->post_status === 'publish' ? 1 : 0,
            // client_external_id: nuestro post_id local. Permite al TPV
            // reconstruir mappings tras un reset sin duplicar productos.
            'client_external_id' => (string) $postId,
        ];

        $taxClassId = $this->resolveTpvTaxClassId($product);
        if ($taxClassId > 0) $payload['tax_class_id'] = $taxClassId;

        // Si el producto es variable con variaciones, construimos la estructura
        // `options` que la API del TPV entiende (ver ProductController::syncOptions).
        // Cada atributo (Talla, Color...) se convierte en una opción; cada
        // variación real contribuye con su quantity/price a option_values.
        if ($product->is_type('variable')) {
            $options = $this->build_options_for_tpv($product);
            if (!empty($options)) {
                $payload['options'] = $options;
            }
        }

        $tpvId = (int)get_post_meta($postId, self::TPV_ID_META, true);

        // Si no hay vínculo local PERO existe ya un producto en el TPV con el
        // mismo `model` o `sku`, lo reconciliamos: recuperamos su product_id,
        // guardamos el meta `_tpv_product_id` y hacemos PATCH en vez de POST.
        // Esto evita el 422 "sku already exists" cuando alguien borró el meta
        // a mano o reimportó WC sin preservar el mapeo.
        if ($tpvId <= 0) {
            // Optimización: usamos el catalog cache (1 carga inicial) en lugar
            // de un GET /products?search=$needle por cada producto sin map.
            // Para 4000 productos sin mapping, eso reduce de 4000 RTTs a 0.
            $catalog = $this->getTpvCatalogIndex();
            $found = $catalog['by_model'][$model] ?? $catalog['by_sku'][$skuForTpv] ?? 0;
            if ($found > 0) {
                $tpvId = (int) $found;
                update_post_meta($postId, self::TPV_ID_META, $tpvId);
                $this->log('ok', $tpvId, "Reconciliado post=$postId con TPV (cache index)");
            }
            // Mantenemos el código viejo como fallback comentado por si la
            // cache falla y el cliente quiere reactivarlo. NUNCA debería
            // alcanzarse en condiciones normales.
            if (false && $tpvId <= 0 && ($model !== '' || $skuForTpv !== '')) {
                $needle = $model !== '' ? $model : $skuForTpv;
                try {
                    $r = $this->api->get('/products', ['search' => $needle]);
                    foreach (($r['data'] ?? []) as $candidate) {
                        $cModel = (string)($candidate['model'] ?? '');
                        $cSku   = (string)($candidate['sku']   ?? '');
                        if ($cModel === $model || $cSku === $skuForTpv) {
                            $tpvId = (int)($candidate['product_id'] ?? 0);
                            if ($tpvId > 0) {
                                update_post_meta($postId, self::TPV_ID_META, $tpvId);
                                $this->log('ok', $tpvId, "Reconciliado post=$postId (fallback search)");
                                break;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // Si la búsqueda falla, continuamos al flujo normal (crear).
                    // El 422 posterior lo capturaremos abajo.
                }
            }
        }

        if ($tpvId > 0) {
            // Edit
            $r = $this->api->patch("/products/$tpvId", $payload);
            // Si el TPV ya no tiene este producto (404 → type contiene
            // "not_found"), el meta local apunta a un mapeo huérfano: el TPV
            // se reseteó, el producto se borró manualmente, o el cliente
            // reinstaló el TPV contra el mismo WC. Limpiamos el meta y caemos
            // al flujo POST de abajo para recrear el producto en el TPV.
            $isNotFound = !empty($r['type']) && str_contains((string)$r['type'], 'not_found');
            if ($isNotFound) {
                delete_post_meta($postId, self::TPV_ID_META);
                $this->log('warn', $tpvId, "PATCH /products/$tpvId devolvió 404 — meta local huérfano, recreando en TPV (post=$postId)");
                $tpvId = 0; // fall through al flujo de creación
            } elseif (!empty($r['error']) || !empty($r['errors']) || !empty($r['type'])) {
                $apiError = $r['error'] ?? '';
                if (!empty($r['errors']) && is_array($r['errors'])) {
                    $details = [];
                    foreach ($r['errors'] as $err) {
                        if (is_array($err)) {
                            $f = $err['field'] ?? '?';
                            $m = $err['message'] ?? json_encode($err);
                            $details[] = "$f: $m";
                        } else {
                            $details[] = (string)$err;
                        }
                    }
                    $apiError = ($apiError !== '' ? $apiError . ' — ' : '') . implode('; ', $details);
                }
                if ($apiError === '') {
                    $apiError = substr(json_encode($r), 0, 400);
                }
                $this->log('error', $tpvId, "PATCH TPV falló (post=$postId model=$model): $apiError");
                return false;
            } else {
                $this->push_images_to_tpv($postId, $product, $tpvId);
                $this->log('ok', $tpvId, "Producto actualizado en TPV desde WC (post=$postId model=$model)");
                return true;
            }
        }

        // Create — la API genera product_id, lo guardamos como meta
        $payload['quantity'] = (float)($product->get_stock_quantity() ?? 0);
        $r = $this->api->post('/products', $payload);
        $newId = (int)($r['data']['product_id'] ?? 0);
        if ($newId === 0) {
            // Mensaje de error enriquecido. Para validation_error la API
            // devuelve `errors: [{field, message}, ...]` — extraemos los
            // pares field/message para diagnosticar QUÉ regla rompió. Sin
            // esto el log decía solo "validation_error" y había que adivinar.
            $apiError = $r['error'] ?? '';
            if (!empty($r['errors']) && is_array($r['errors'])) {
                $details = [];
                foreach ($r['errors'] as $err) {
                    if (is_array($err)) {
                        $f = $err['field'] ?? '?';
                        $m = $err['message'] ?? json_encode($err);
                        $details[] = "$f: $m";
                    } else {
                        $details[] = (string)$err;
                    }
                }
                $apiError = ($apiError !== '' ? $apiError . ' — ' : '') . implode('; ', $details);
            }
            if ($apiError === '') {
                $apiError = substr(json_encode($r), 0, 400);
            }
            $this->log(
                'error',
                $postId,
                "POST TPV falló al crear (post=$postId model=$model sku=$skuForTpv): $apiError"
            );
            return false;
        }
        update_post_meta($postId, self::TPV_ID_META, $newId);
        $this->push_images_to_tpv($postId, $product, $newId);
        $this->log('ok', $newId, "Producto creado en TPV (post $postId) model=$model sku=$skuForTpv");
        return true;
    }

    /**
     * Construye la estructura `options` que la API del TPV acepta a partir
     * de un producto variable de WooCommerce y sus variaciones.
     *
     * Una variación WC tiene uno o más attributes (ej. Talla=S, Color=Rojo).
     * El TPV modela las variantes como UNA opción con M valores (no soporta
     * combinaciones nativas tipo matriz Talla×Color).
     *
     * Estrategia:
     *   - 1 atributo (Talla) → mapeo directo: opción "Talla", valores "S","M","L".
     *     Stock por valor = suma de variaciones con ese valor.
     *   - >1 atributo (Talla×Color) → APLANAMOS combinaciones reales que
     *     existen como variación en WC. Genera UNA opción "Talla-Color" con
     *     valores "S-Rojo","M-Verde",... preservando stock y barcode por
     *     combinación. Esto evita la sobreventa que provocaría sumar stock
     *     por atributo independiente (BUG-010), y respeta el catálogo real
     *     del cliente (no genera variantes fantasma del producto cartesiano
     *     completo cuando solo algunas combinaciones existen).
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_options_for_tpv($product): array
    {
        $children = $product->get_children();
        if (empty($children)) return [];

        $variations = [];
        foreach ($children as $childId) {
            $variation = wc_get_product($childId);
            if (!$variation || $variation->get_status() === 'private') continue;
            $variations[] = $variation;
        }
        if (empty($variations)) return [];

        // Detectar cuántos atributos de variación distintos hay.
        $attrNames = [];
        foreach ($variations as $v) {
            foreach ($v->get_attributes() as $attrName => $valueSlug) {
                if ($valueSlug !== '') $attrNames[$attrName] = true;
            }
        }

        if (count($attrNames) > 1) {
            return $this->build_options_flattened($product, $variations);
        }
        return $this->build_options_single_attr($product, $variations);
    }

    /**
     * Caso simple: 1 atributo de variación (ej. solo "Talla" o solo "Sabor").
     * Suma stock de variaciones con el mismo valor del atributo.
     */
    private function build_options_single_attr($product, array $variations): array
    {
        $attrs     = []; // attrLabel => ['type' => ..., 'values' => [valueLabel => meta]]
        $basePrice = (float)($product->get_regular_price() ?: 0);

        foreach ($variations as $v) {
            $vAttrs = $v->get_attributes();
            $vQty   = max(0, (int)$v->get_stock_quantity());
            $vPrice = (float)($v->get_regular_price() ?: $basePrice);
            $priceDiff = $vPrice - $basePrice;

            // Código de barras de la variación: GTIN/EAN/UPC primero, SKU como
            // fallback. Si ambos vacíos, la variante no se podrá escanear.
            $vGtin = trim((string) get_post_meta($v->get_id(), '_global_unique_id', true));
            $vSku  = trim((string) $v->get_sku());
            $vBarcode = $vGtin !== '' ? $vGtin : $vSku;

            foreach ($vAttrs as $attrName => $valueSlug) {
                if ($valueSlug === '') continue;
                $attrLabel  = wc_attribute_label($attrName, $product);
                $valueLabel = $this->resolve_attribute_value_label($attrName, $valueSlug);

                if (!isset($attrs[$attrLabel])) {
                    $attrs[$attrLabel] = ['type' => 'select', 'values' => []];
                }
                if (!isset($attrs[$attrLabel]['values'][$valueLabel])) {
                    $attrs[$attrLabel]['values'][$valueLabel] = [
                        'quantity'   => 0,
                        'price_diff' => $priceDiff,
                        'barcode'    => '',
                    ];
                }
                $attrs[$attrLabel]['values'][$valueLabel]['quantity'] += $vQty;
                $attrs[$attrLabel]['values'][$valueLabel]['price_diff'] = $priceDiff;
                if ($vBarcode !== '' && $attrs[$attrLabel]['values'][$valueLabel]['barcode'] === '') {
                    $attrs[$attrLabel]['values'][$valueLabel]['barcode'] = $vBarcode;
                }
            }
        }

        return $this->attrsToTpvOptions($attrs);
    }

    /**
     * Caso multi-atributo (Talla × Color, ...): aplanar a UNA sola opción
     * con valores compuestos "Talla-Color". Solo se incluyen las
     * combinaciones que existen como variación real en WC (Opción A —
     * preserva el catálogo, no genera el producto cartesiano completo).
     *
     * El nombre de la opción combinada es el join con guion de los nombres
     * de atributos en el orden en que aparecen en la primera variación
     * (orden estable: WC mantiene la posición de atributos por producto).
     */
    private function build_options_flattened($product, array $variations): array
    {
        $basePrice = (float)($product->get_regular_price() ?: 0);

        // Determinar orden estable de atributos: tomamos el orden de la
        // primera variación que tenga atributos. Las variaciones siguientes
        // se reindexan a ese mismo orden para que "S-Rojo" no aparezca
        // también como "Rojo-S" en otra variación.
        $attrOrder = [];
        foreach ($variations as $v) {
            foreach ($v->get_attributes() as $attrName => $valueSlug) {
                if ($valueSlug !== '' && !in_array($attrName, $attrOrder, true)) {
                    $attrOrder[] = $attrName;
                }
            }
            if (!empty($attrOrder)) break;
        }
        if (empty($attrOrder)) return [];

        $combinedLabel = implode('-', array_map(
            fn($n) => wc_attribute_label($n, $product),
            $attrOrder
        ));

        $values = []; // comboLabel => meta
        foreach ($variations as $v) {
            $vAttrs = $v->get_attributes();
            // Saltamos variaciones que no aporten valor en alguno de los
            // atributos del orden — son combinaciones "Any" en WC, que el
            // TPV no puede modelar como combinación específica.
            $parts = [];
            foreach ($attrOrder as $attrName) {
                $slug = $vAttrs[$attrName] ?? '';
                if ($slug === '') { $parts = null; break; }
                $parts[] = $this->resolve_attribute_value_label($attrName, $slug);
            }
            if ($parts === null) continue;

            $comboLabel = implode('-', $parts);
            $vQty   = max(0, (int)$v->get_stock_quantity());
            $vPrice = (float)($v->get_regular_price() ?: $basePrice);
            $priceDiff = $vPrice - $basePrice;

            $vGtin = trim((string) get_post_meta($v->get_id(), '_global_unique_id', true));
            $vSku  = trim((string) $v->get_sku());
            $vBarcode = $vGtin !== '' ? $vGtin : $vSku;

            if (!isset($values[$comboLabel])) {
                $values[$comboLabel] = [
                    'quantity'   => 0,
                    'price_diff' => $priceDiff,
                    'barcode'    => '',
                ];
            }
            // Stock por COMBINACIÓN (no por atributo): así no se duplica.
            // Si dos variaciones WC tuvieran el mismo combo (raro pero
            // posible si el cliente duplicó), sumamos.
            $values[$comboLabel]['quantity'] += $vQty;
            $values[$comboLabel]['price_diff'] = $priceDiff;
            if ($vBarcode !== '' && $values[$comboLabel]['barcode'] === '') {
                $values[$comboLabel]['barcode'] = $vBarcode;
            }
        }

        if (empty($values)) return [];

        return $this->attrsToTpvOptions([
            $combinedLabel => ['type' => 'select', 'values' => $values],
        ]);
    }

    /**
     * Convierte el array intermedio attrs[label][values][label] => meta
     * al formato esperado por el endpoint POST /products/.../syncOptions.
     */
    private function attrsToTpvOptions(array $attrs): array
    {
        $result = [];
        foreach ($attrs as $attrName => $data) {
            $values = [];
            foreach ($data['values'] as $valueLabel => $meta) {
                $value = [
                    'name'         => $valueLabel,
                    'quantity'     => (int)$meta['quantity'],
                    'price'        => abs($meta['price_diff']),
                    'price_prefix' => $meta['price_diff'] < 0 ? '-' : '+',
                    'subtract'     => 1,
                ];
                if ($meta['barcode'] !== '') $value['barcode'] = $meta['barcode'];
                $values[] = $value;
            }
            $result[] = [
                'name'     => $attrName,
                'type'     => $data['type'],
                'required' => true,
                'values'   => $values,
            ];
        }
        return $result;
    }

    /**
     * Convierte un slug de valor de atributo WC a su nombre legible.
     * Para atributos globales (pa_*) busca el term; para atributos custom
     * devuelve el slug (WC los guarda tal cual).
     */
    private function resolve_attribute_value_label(string $attrName, string $valueSlug): string
    {
        // Atributos globales de WC empiezan por pa_
        if (strpos($attrName, 'pa_') === 0) {
            $term = get_term_by('slug', $valueSlug, $attrName);
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
        }
        // Custom attributes: el "slug" es el propio nombre, a veces con guiones
        return str_replace(['-', '_'], ' ', $valueSlug);
    }

    /**
     * Genera un SKU desde el slug del título. Garantiza unicidad consultando
     * si ya existe en WC (si coincide con otro post_id, añade sufijo numérico).
     */
    public function generate_sku_from_slug(int $postId, string $title): string
    {
        $base = function_exists('sanitize_title') ? sanitize_title($title) : preg_replace('/[^a-z0-9]+/i', '-', strtolower($title));
        $base = strtoupper($base);
        if ($base === '' || strlen($base) < 3) {
            // Fallback si el título no produce algo útil
            return 'WC-' . $postId;
        }
        // Truncar a 40 chars para dejar sitio al sufijo y no reventar columnas
        if (strlen($base) > 40) $base = substr($base, 0, 40);

        $candidate = $base;
        $n = 0;
        global $wpdb;
        while (true) {
            $n++;
            // Limit 10 intentos antes de fallback a WC-<postid>
            if ($n > 10) return 'WC-' . $postId;

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND p.ID <> %d LIMIT 1",
                $candidate, $postId
            ));
            if (!$existing) return $candidate;
            $candidate = $base . '-' . $n;
        }
    }

    /**
     * Hook: wp_trash_post — producto movido a papelera.
     * Marcamos status=0 en TPV (visible en listados pero oculto en caja).
     */
    public function push_wc_trash_to_tpv(int $postId): void
    {
        if (!empty($GLOBALS['tpv_sync_skip_wc_product_push'])) return;
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'product') return;

        $tpvId = (int)get_post_meta($postId, self::TPV_ID_META, true);
        if (!$tpvId) return;

        $this->api->patch("/products/$tpvId", ['status' => 0]);
        $this->log('ok', $tpvId, "Producto marcado status=0 (papelera WC post $postId)");
    }

    /**
     * Hook: untrashed_post — producto restaurado desde papelera.
     * Restablecemos status=1 en TPV.
     */
    public function push_wc_untrash_to_tpv(int $postId): void
    {
        if (!empty($GLOBALS['tpv_sync_skip_wc_product_push'])) return;
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'product') return;

        $tpvId = (int)get_post_meta($postId, self::TPV_ID_META, true);
        if (!$tpvId) return;

        $this->api->patch("/products/$tpvId", ['status' => 1]);
        $this->log('ok', $tpvId, "Producto restaurado status=1 (untrash WC post $postId)");
    }

    /**
     * Hook: before_delete_post — borrado permanente en WC.
     * Llama DELETE /products/{id}. El TPV internamente preserva el registro
     * si tiene ventas históricas (ProductController::delete hace soft-delete
     * si detecta foreign refs).
     */
    public function push_wc_delete_to_tpv(int $postId): void
    {
        if (!empty($GLOBALS['tpv_sync_skip_wc_product_push'])) return;
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'product') return;

        $tpvId = (int)get_post_meta($postId, self::TPV_ID_META, true);
        if (!$tpvId) return;

        // Modo Principal: si manda el TPV, un delete en WC NO debe propagarse.
        // El TPV sigue siendo dueño del producto; un reconcile posterior lo
        // recreará en WC si vuelve a hacer falta. La UI del editor avisa al
        // admin con un banner read-only para que no llegue aquí por error.
        $principal = (string) get_option('tpv_sync_principal', '');
        if ($principal === 'tpv') {
            return;
        }

        $this->api->delete("/products/$tpvId");
        $this->log('ok', $tpvId, "Producto DELETE (permanente WC post $postId)");
    }

    /**
     * Envía las imágenes del producto WC al TPV via POST /products/{id}/images
     * con `image_url`. La API descarga el archivo (validando dominio).
     *
     * Estrategia idempotente: marcamos cada URL ya enviada con un meta para
     * no reenviar la misma imagen en cada update (la imagen no cambia con
     * los attrs del producto). Si el cliente cambia la imagen destacada en
     * WC, la URL nueva se detecta como no-enviada y se sincroniza.
     *
     * Solo se envía la imagen destacada (post_thumbnail) y la galería WC.
     * Las imágenes de variaciones individuales no se envían (el TPV no
     * tiene un campo "imagen por variante" — usa la del producto padre).
     */
    private function push_images_to_tpv(int $postId, $product, int $tpvId): void
    {
        if ($tpvId <= 0) return;

        $alreadySent = (array) get_post_meta($postId, '_tpv_images_sent', true);
        if (!is_array($alreadySent)) $alreadySent = [];
        $sent = $alreadySent;

        // 1. Imagen destacada → is_main=true en TPV
        $thumbId = (int) get_post_thumbnail_id($postId);
        if ($thumbId > 0) {
            $thumbUrl = (string) wp_get_attachment_url($thumbId);
            if ($thumbUrl !== '' && empty($sent[$thumbUrl])) {
                $r = $this->api->post("/products/$tpvId/images", [
                    'image_url' => $thumbUrl,
                    'is_main'   => true,
                    'sort_order' => 0,
                ]);
                if (!empty($r['data'])) {
                    $sent[$thumbUrl] = (string) ($r['data']['image'] ?? '1');
                    $this->log('ok', $tpvId, "Imagen principal sincronizada al TPV (post=$postId)");
                } else {
                    $this->log('warn', $tpvId, "POST imagen principal falló: " . substr(json_encode($r), 0, 200));
                }
            }
        }

        // 2. Galería del producto WC → entradas en oc_product_image
        $galleryIds = $product && method_exists($product, 'get_gallery_image_ids')
            ? (array) $product->get_gallery_image_ids() : [];
        $sortOrder = 1;
        foreach ($galleryIds as $gid) {
            $gid = (int) $gid;
            if ($gid <= 0) continue;
            $gUrl = (string) wp_get_attachment_url($gid);
            if ($gUrl === '' || !empty($sent[$gUrl])) {
                $sortOrder++;
                continue;
            }
            $r = $this->api->post("/products/$tpvId/images", [
                'image_url'  => $gUrl,
                'is_main'    => false,
                'sort_order' => $sortOrder++,
            ]);
            if (!empty($r['data'])) {
                $sent[$gUrl] = (string) ($r['data']['image'] ?? '1');
            } else {
                $this->log('warn', $tpvId, "POST imagen galería falló: " . substr(json_encode($r), 0, 200));
            }
        }

        if ($sent !== $alreadySent) {
            update_post_meta($postId, '_tpv_images_sent', $sent);
        }
    }

    // ─── Buscar post WC por tpv_product_id ───────────────────────────────────

    public function find_wc_post(int $tpvId): int
    {
        global $wpdb;
        $postId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::TPV_ID_META, $tpvId
        ));
        return (int)$postId;
    }

    // ─── Imagen: solo importar si no existe ya ────────────────────────────────

    private function maybe_set_image(int $postId, string $imageUrl, int $tpvId): void
    {
        if (has_post_thumbnail($postId)) return;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmpFile = $this->download_image($imageUrl);
        if (!$tmpFile) return;

        $ext     = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $attachId = media_handle_sideload(['name' => "tpv-product-$tpvId.$ext", 'tmp_name' => $tmpFile], $postId);
        if (!is_wp_error($attachId)) {
            set_post_thumbnail($postId, $attachId);
        }
    }

    /**
     * Descarga una imagen via wp_remote_get (evita las restricciones de download_url).
     * Devuelve la ruta al archivo temporal o null si falla.
     */
    private function download_image(string $url): ?string
    {
        $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
        if (is_wp_error($response)) {
            error_log("tpv-sync: download_image failed for $url — " . $response->get_error_message());
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log("tpv-sync: download_image HTTP $code for $url");
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) return null;

        // Validar que el contenido es realmente una imagen
        $contentType = wp_remote_retrieve_header($response, 'content-type');
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = strtolower(strtok($contentType, ';'));
        if ($contentType && !in_array($mime, $allowedMime, true)) {
            error_log("tpv-sync: download_image rechazado por MIME '$mime' para $url");
            return null;
        }

        $tmpFile = wp_tempnam($url);
        file_put_contents($tmpFile, $body);
        return $tmpFile;
    }

    // ─── Sincronizar galería completa ─────────────────────────────────────────

    /**
     * Importa todas las imágenes de un producto (principal + galería).
     * Usa la URL como clave para evitar reimportar la misma imagen.
     */
    private function sync_images(int $postId, array $images, int $tpvId): void
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // URLs ya adjuntas a este post (para no reimportar)
        $existing = [];
        foreach (get_attached_media('image', $postId) as $att) {
            $existing[] = get_post_meta($att->ID, '_tpv_image_url', true);
        }

        $galleryIds = [];
        $mainId     = null;

        foreach ($images as $img) {
            $url = $img['url'] ?? '';
            if (!$url) continue;

            // Saltar si ya está importada
            if (in_array($url, $existing, true)) {
                // Recuperar su attachment ID para reconstruir la galería
                global $wpdb;
                $attId = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = '_tpv_image_url' AND meta_value = %s LIMIT 1",
                    $url
                ));
                if ($attId) {
                    if ($img['is_main']) $mainId = $attId;
                    else $galleryIds[] = $attId;
                }
                continue;
            }

            $tmpFile = $this->download_image($url);
            if (!$tmpFile) continue;

            $ext     = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $suffix  = $img['is_main'] ? 'main' : ('gallery-' . $img['sort_order']);
            $fileArr = [
                'name'     => "tpv-{$tpvId}-{$suffix}.{$ext}",
                'tmp_name' => $tmpFile,
            ];

            $attId = media_handle_sideload($fileArr, $postId);
            if (is_wp_error($attId)) continue;

            // Guardar URL original para detectar duplicados en el futuro
            update_post_meta($attId, '_tpv_image_url', $url);

            if ($img['is_main']) $mainId = $attId;
            else $galleryIds[] = $attId;
        }

        if ($mainId) {
            set_post_thumbnail($postId, $mainId);
        }
        if (!empty($galleryIds)) {
            update_post_meta($postId, '_product_image_gallery', implode(',', $galleryIds));
        }
    }

    // ─── Categorías ───────────────────────────────────────────────────────────

    private function sync_categories(int $postId, array $categories): void
    {
        $termIds = [];
        foreach ($categories as $cat) {
            $name = sanitize_text_field($cat['name'] ?? '');
            if (!$name) continue;

            $term = get_term_by('name', $name, 'product_cat');
            if (!$term) {
                $result = wp_insert_term($name, 'product_cat');
                if (is_wp_error($result)) continue;
                $termIds[] = (int)$result['term_id'];
            } else {
                $termIds[] = (int)$term->term_id;
            }
        }

        if ($termIds) {
            wp_set_object_terms($postId, $termIds, 'product_cat');
        }
    }

    // ─── Variaciones de producto ──────────────────────────────────────────────

    /**
     * Crea o actualiza las variaciones de un producto variable en WooCommerce
     * a partir de las opciones del TPV.
     *
     * Cada opción del TPV (ej: "Talla") con sus valores (S, M, L...) se convierte
     * en un atributo de variación. Cada valor es una variación con su propio
     * stock y precio.
     */
    private function sync_variations(int $postId, array $options, int $tpvId, float $basePrice = 0): void
    {
        // ── 1. Registrar taxonomías globales y construir atributos WC ─────────
        $wcAttributes = [];
        foreach ($options as $position => $opt) {
            $name = sanitize_text_field($opt['option_name'] ?? '');
            if (!$name) continue;

            $values = array_filter(array_map(
                fn($v) => sanitize_text_field($v['value_name'] ?? ''),
                $opt['values'] ?? []
            ));
            if (empty($values)) continue;

            $taxonomy = 'pa_' . sanitize_title($name);

            // Registrar la taxonomy si no existe
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, 'product', ['label' => $name]);
            }

            // Asegurarse de que existe en woocommerce_attribute_taxonomies
            $this->ensure_wc_attribute($name, $taxonomy);

            // Crear/obtener los terms y asignarlos al producto
            $termIds = [];
            foreach ($values as $val) {
                $term = get_term_by('name', $val, $taxonomy);
                if (!$term) {
                    $result = wp_insert_term($val, $taxonomy);
                    if (!is_wp_error($result)) {
                        $termIds[] = (int)$result['term_id'];
                    }
                } else {
                    $termIds[] = (int)$term->term_id;
                }
            }
            wp_set_object_terms($postId, $termIds, $taxonomy);

            $wcAttributes[$taxonomy] = [
                'name'         => $taxonomy,
                'value'        => '',        // vacío cuando is_taxonomy=1 (los valores van en wp_term_relationships)
                'position'     => $position,
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 1,
            ];
        }

        if (empty($wcAttributes)) return;
        update_post_meta($postId, '_product_attributes', $wcAttributes);

        // ── 2. Crear/actualizar variaciones ───────────────────────────────────
        // Mapa: tpv_option_value_id → variation post_id existente
        global $wpdb;
        $existingMap = [];
        $existingVars = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value as tpv_vid
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_tpv_option_value_id'
             WHERE p.post_parent = %d AND p.post_type = 'product_variation'",
            $postId
        ));
        foreach ($existingVars as $v) {
            $existingMap[(int)$v->tpv_vid] = (int)$v->ID;
        }

        $processedVarIds = [];

        foreach ($options as $opt) {
            $attrKey = 'pa_' . sanitize_title($opt['option_name'] ?? '');

            foreach ($opt['values'] ?? [] as $val) {
                $valueName = sanitize_text_field($val['value_name'] ?? '');
                if (!$valueName) continue;

                $tpvValId    = (int)($val['product_option_value_id'] ?? 0);
                $quantity    = (int)($val['quantity'] ?? 0);
                $optionPrice = (float)($val['price'] ?? 0);
                $prefix      = $val['price_prefix'] ?? '+';

                // Precio real de la variación = base ± ajuste de la opción
                $varPrice = $basePrice;
                if ($optionPrice > 0) {
                    $varPrice = $prefix === '-'
                        ? $basePrice - $optionPrice
                        : $basePrice + $optionPrice;
                }

                // Buscar o crear la variación
                if (isset($existingMap[$tpvValId])) {
                    $varId = $existingMap[$tpvValId];
                } else {
                    $varId = wp_insert_post([
                        'post_parent' => $postId,
                        'post_type'   => 'product_variation',
                        'post_status' => 'publish',
                        'post_title'  => "Variation #{$tpvValId}",
                    ]);
                    if (is_wp_error($varId)) continue;
                    update_post_meta($varId, '_tpv_option_value_id', $tpvValId);
                }

                $processedVarIds[] = $varId;

                // Atributo de esta variación (slug del term para taxonomía global)
                $termObj = get_term_by('name', $valueName, $attrKey);
                $termSlug = $termObj ? $termObj->slug : sanitize_title($valueName);
                update_post_meta($varId, 'attribute_' . $attrKey, $termSlug);

                // Stock
                update_post_meta($varId, '_manage_stock',  'yes');
                update_post_meta($varId, '_stock',         $quantity);
                update_post_meta($varId, '_stock_status',  $quantity > 0 ? 'instock' : 'outofstock');

                // Precio de la variación
                update_post_meta($varId, '_price',         wc_format_decimal($varPrice));
                update_post_meta($varId, '_regular_price', wc_format_decimal($varPrice));
            }
        }

        // ── 3. Eliminar variaciones huérfanas (ya no existen en el TPV) ───────
        foreach ($existingMap as $tpvValId => $varId) {
            if (!in_array($varId, $processedVarIds, true)) {
                wp_delete_post($varId, true);
            }
        }

        // ── 4. Sincronizar precios mín/máx del producto padre ─────────────────
        WC_Product_Variable::sync($postId);
        wc_delete_product_transients($postId);
    }

    // ─── Registrar atributo global en WooCommerce ─────────────────────────────

    /**
     * Asegura que el atributo existe en woocommerce_attribute_taxonomies.
     * Sin esto WC no muestra el selector de variación en el frontend.
     */
    private function ensure_wc_attribute(string $name, string $taxonomy): void
    {
        global $wpdb;
        $slug = str_replace('pa_', '', $taxonomy);
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
            $slug
        ));
        if (!$exists) {
            $wpdb->insert(
                $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                [
                    'attribute_name'    => $slug,
                    'attribute_label'   => $name,
                    'attribute_type'    => 'select',
                    'attribute_orderby' => 'menu_order',
                    'attribute_public'  => 0,
                ],
                ['%s', '%s', '%s', '%s', '%d']
            );
            // Invalidar caché de WC
            delete_transient('wc_attribute_taxonomies');
        }
    }

    // ─── Log ──────────────────────────────────────────────────────────────────

    private function log(string $status, int $resourceId, string $msg): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tpv_sync_log', [
            'event_type'  => 'product_sync',
            'resource'    => 'product',
            'resource_id' => $resourceId,
            'status'      => $status,
            'message'     => $msg,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  Reconciliación bidireccional (post-reconnect)
    // ═══════════════════════════════════════════════════════════════════════════
    //
    // Llamada cuando el cliente pulsa "Reconectar" tras una pausa >5 min.
    // Compara catálogos TPV ↔ WC y aplica solo los deltas. Política:
    //
    //   1. Producto en TPV pero no en WC  → upsert (crear en WC).
    //   2. Producto en WC  pero no en TPV → push (crear en TPV).
    //   3. Producto en ambos pero distinto → gana el lado con `updated_at`
    //      más reciente (TPV: products.date_modified; WC: post.post_modified).
    //
    // Best-effort: si falla algún producto, log + sigue. NUNCA propagar
    // excepción al flujo de "Reconectar".

    /**
     * @return array{checked:int,synced:int,fixed:int,skipped:int,errors:int}
     */
    public function reconcileBidirectional(): array
    {
        global $wpdb;
        $stats = ['checked' => 0, 'synced' => 0, 'fixed' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            $tpvList = $this->api->getAll('/products', ['per_page' => 200]);
        } catch (Throwable $e) {
            $this->log('error',0, 'getAll TPV: ' . $e->getMessage());
            $stats['errors']++;
            return $stats;
        }

        // Snapshot WC: posts publicados con SKU/GTIN para matching por model.
        $wcRows = $wpdb->get_results(
            "SELECT p.ID AS post_id, p.post_modified,
                    pm_sku.meta_value AS sku,
                    pm_gtin.meta_value AS gtin
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_sku  ON pm_sku.post_id  = p.ID AND pm_sku.meta_key = '_sku'
             LEFT JOIN {$wpdb->postmeta} pm_gtin ON pm_gtin.post_id = p.ID AND pm_gtin.meta_key = '_global_unique_id'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             LIMIT 5000",
            ARRAY_A
        ) ?: [];

        $wcByModel = [];
        foreach ($wcRows as $row) {
            $key = trim((string) ($row['gtin'] ?? '')) ?: trim((string) ($row['sku'] ?? ''));
            if ($key !== '') {
                $wcByModel[$key] = $row;
            }
        }

        foreach ($tpvList as $p) {
            $tpvId = (int) ($p['product_id'] ?? 0);
            if (!$tpvId) continue;
            $stats['checked']++;
            $postId = $this->find_wc_post($tpvId);

            if ($postId === 0) {
                $model = trim((string) ($p['model'] ?? ''));
                if ($model !== '' && isset($wcByModel[$model])) {
                    // Vínculo por model: solo asociar, no reimportar.
                    $matchPostId = (int) $wcByModel[$model]['post_id'];
                    update_post_meta($matchPostId, '_tpv_product_id', $tpvId);
                    $stats['synced']++;
                    continue;
                }
                try {
                    $r = $this->upsert($p);
                    if ($r === 'created' || $r === 'updated') {
                        $stats['synced']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (Throwable $e) {
                    $this->log('error',$tpvId, 'upsert: ' . $e->getMessage());
                    $stats['errors']++;
                }
                continue;
            }

            // Existe en ambos → comparar updated_at.
            $tpvUpdated = strtotime((string) ($p['date_modified'] ?? ''));
            $postModified = $wpdb->get_var($wpdb->prepare(
                "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d",
                $postId
            ));
            $wcUpdated = $postModified ? strtotime((string) $postModified) : 0;

            // Stock: TPV gana siempre (no se modifica con post_modified).
            $tpvQty = (float) ($p['quantity'] ?? 0);
            $wcQty  = (float) get_post_meta($postId, '_stock', true);
            if (abs($wcQty - $tpvQty) > 0.0001) {
                $this->update_stock($tpvId, (int) $tpvQty);
                $stats['fixed']++;
            }

            if ($tpvUpdated > 0 && $tpvUpdated > $wcUpdated + 60) {
                try {
                    $this->upsert($p);
                    $stats['fixed']++;
                } catch (Throwable $e) {
                    $this->log('error',$tpvId, 'upsert (TPV gana): ' . $e->getMessage());
                    $stats['errors']++;
                }
            } elseif ($wcUpdated > 0 && $wcUpdated > $tpvUpdated + 60) {
                try {
                    if ($this->push_wc_product_to_tpv($postId)) {
                        $stats['fixed']++;
                    }
                } catch (Throwable $e) {
                    $this->log('error',$tpvId, 'push (WC gana): ' . $e->getMessage());
                    $stats['errors']++;
                }
            }
        }

        // Productos WC sin vínculo → push al TPV.
        $orphans = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_tpv_product_id'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')
             LIMIT 1000"
        ) ?: [];
        foreach ($orphans as $postId) {
            $postId = (int) $postId;
            try {
                if ($this->push_wc_product_to_tpv($postId)) {
                    $stats['synced']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $this->log('error',$postId, 'push huérfano: ' . $e->getMessage());
                $stats['errors']++;
            }
        }

        $this->log('ok', 0, sprintf(
            'reconcile_bi: checked=%d synced=%d fixed=%d skipped=%d errors=%d',
            $stats['checked'], $stats['synced'], $stats['fixed'], $stats['skipped'], $stats['errors']
        ));
        return $stats;
    }
}
