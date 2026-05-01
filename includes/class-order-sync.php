<?php
declare(strict_types=1);
/**
 * Sincronización de pedidos WooCommerce ↔ TPV.
 *
 * Módulo Pedidos:
 *  - WC → TPV: crea pedido en el TPV cuando se procesa el pago
 *  - WC → TPV: propaga cambios de estado (cancelación, reembolso)
 *  - TPV → WC: el webhook handler llama a update_wc_status() cuando
 *              el TPV cambia el estado de un pedido online
 */
defined('ABSPATH') || exit;

class TPV_Sync_Order_Sync
{
    private TPV_Sync_API_Client $api;

    const TPV_ORDER_META = '_tpv_order_id';

    // Mapa WC status → TPV order_status_id
    // Los IDs corresponden a la tabla 2465_order_status
    const WC_TO_TPV_STATUS = [
        'pending'    => 1,  // Pendiente
        'processing' => 2,  // En proceso
        'on-hold'    => 1,  // Pendiente
        'completed'  => 5,  // Completado
        'cancelled'  => 7,  // Cancelado
        'refunded'   => 11, // Dinero devuelto
        'failed'     => 7,  // Cancelado
    ];

    // Mapa TPV order_status_id → WC status
    const TPV_TO_WC_STATUS = [
        1  => 'pending',
        2  => 'processing',
        3  => 'processing', // Enviado → en proceso en WC (WC no tiene "enviado" nativo)
        5  => 'completed',
        7  => 'cancelled',
        11 => 'refunded',
        14 => 'cancelled',  // Expirado → cancelado
    ];

    public function __construct(TPV_Sync_API_Client $api)
    {
        $this->api = $api;
    }

    // ─── WC → TPV: crear pedido ───────────────────────────────────────────────

    /**
     * Hook: woocommerce_payment_complete / woocommerce_order_status_processing
     * Envía el pedido al TPV cuando se procesa el pago en WooCommerce.
     */
    public function send_to_tpv(int $wcOrderId): void
    {
        if (get_post_meta($wcOrderId, self::TPV_ORDER_META, true)) return;

        $order = wc_get_order($wcOrderId);
        if (!$order) return;

        // ── Construir líneas con desglose fiscal ─────────────────────────────
        // La API TPV espera por línea:
        //   price: precio unitario NETO (sin IVA)
        //   tax:   IVA unitario (no total línea)
        //   total: total línea NETO (price * quantity)
        //
        // WooCommerce proporciona estos campos:
        //   $item->get_total()      = total línea SIN IVA (tras descuentos)
        //   $item->get_total_tax()  = IVA total de la línea
        //   $item->get_quantity()   = unidades
        //
        // Importante: get_total() de WC devuelve NETO aunque la tienda tenga
        // "Prices entered with tax = yes" — WC internamente desglosa al guardar
        // el pedido. No depende de display settings.
        $products = [];
        foreach ($order->get_items() as $item) {
            $tpvId = get_post_meta($item->get_product_id(), TPV_Sync_Product_Sync::TPV_ID_META, true);
            if (!$tpvId) continue;

            $qty         = (float)$item->get_quantity();
            $lineNetTot  = (float)$item->get_total();       // neto línea
            // get_total_tax() es método estándar de WC_Order_Item_Product; en stubs
            // de test puede no existir. Fallback a 0 (legacy sin tax).
            $lineTaxTot  = method_exists($item, 'get_total_tax') ? (float)$item->get_total_tax() : 0.0;
            $qtySafe     = $qty > 0 ? $qty : 1.0;

            $products[] = [
                'product_id' => (int)$tpvId,
                'name'       => $item->get_name(),
                'quantity'   => $qty,
                'price'      => $lineNetTot / $qtySafe,     // unit net
                'tax'        => $lineTaxTot / $qtySafe,     // unit tax
                'total'      => $lineNetTot,                // net line total
            ];
        }

        if (empty($products)) {
            $this->log($wcOrderId, 'skip', 'Sin productos mapeados al TPV');
            return;
        }

        // ── Cupones WC → vouchers[] para la API TPV ──────────────────────────
        // Cada coupon item de WC (order_item_type='coupon') se traduce a una
        // línea 'vouchers' del payload. La API los guarda como filas
        // model='DISCOUNT' negativas en order_product con el código en `comment`
        // (convención del TPV nativo).
        //
        // Importes: WC separa `discount` (base imponible del descuento) de
        // `discount_tax` (IVA del descuento). La API trata el amount como GROSS
        // (sale directamente del total con IVA), así que sumamos ambos.
        //
        // Cupones de envío gratis (free_shipping) aparecen con discount=0 y se
        // gestionan por otro camino en WC — los filtramos aquí.
        $vouchers = [];
        foreach ($order->get_items('coupon') as $couponItem) {
            // get_discount() puede no existir en stubs/test; fallback a 0.
            $discount    = method_exists($couponItem, 'get_discount')     ? (float)$couponItem->get_discount()     : 0.0;
            $discountTax = method_exists($couponItem, 'get_discount_tax') ? (float)$couponItem->get_discount_tax() : 0.0;
            $gross       = round($discount + $discountTax, 2);
            if ($gross <= 0) continue; // envío gratis u otros sin importe

            $code = method_exists($couponItem, 'get_code') ? (string)$couponItem->get_code() : '';
            $vouchers[] = [
                'code'   => $code,
                'amount' => $gross,
            ];
        }

        // Idempotency-Key determinística por pedido WC: si WC reintenta el hook
        // (timeout, requeue), la API TPV deduplica devolviendo la respuesta previa.
        $idemKey = 'wc-order-' . $wcOrderId;

        // ── Datos fiscales del cliente ───────────────────────────────────────
        // NIF/CIF/NIE español. Diferentes plugins usan diferentes meta_keys;
        // probamos los más comunes por orden de popularidad.
        $idTax = $this->resolve_customer_tax_id($order);

        // ── Direcciones payment + shipping ───────────────────────────────────
        $payment  = $this->build_address_from_order($order, 'billing');
        $shipping = $this->build_address_from_order($order, 'shipping');

        // El 'total' que envía el plugin al TPV incluye IVA (gross) — es lo que
        // el cliente pagó realmente y debe cuadrar con order_payment.amount.
        // La API no re-usa este valor si el desglose de líneas es coherente:
        // internamente recalcula subTotal + totalTax desde las líneas.
        $payload = [
            'products'       => $products,
            'payment_method' => $order->get_payment_method_title() ?: 'online',
            'total'          => (float)$order->get_total(),  // gross — con IVA
            'comment'        => 'WooCommerce #' . $wcOrderId,
            'firstname'      => $order->get_billing_first_name(),
            'lastname'       => $order->get_billing_last_name(),
            'email'          => $order->get_billing_email(),
            'telephone'      => $order->get_billing_phone(),
        ];
        if ($idTax !== '')        $payload['id_tax']   = $idTax;
        if (!empty($payment))     $payload['payment']  = $payment;
        if (!empty($shipping))    $payload['shipping'] = $shipping;
        if (!empty($vouchers))    $payload['vouchers'] = $vouchers;

        $result = $this->api->post('/orders', $payload, $idemKey);

        if (!empty($result['data']['order_id'])) {
            $tpvOrderId = (int)$result['data']['order_id'];
            update_post_meta($wcOrderId, self::TPV_ORDER_META, $tpvOrderId);
            $order->add_order_note("Registrado en TPV (#{$tpvOrderId}).");
            $this->log($wcOrderId, 'ok', "Creado en TPV order_id={$tpvOrderId}");
        } else {
            // Detectar insufficient_stock (409) — el TPV vendió la última unidad
            // mientras WC procesaba el pago. Mejor poner on-hold y avisar al admin
            // que reembolsar automáticamente (puede ser producto reponible).
            $code  = $result['errors'][0]['error']   ?? '';
            $error = $result['errors'][0]['message'] ?? wp_json_encode($result);
            if ($code === 'insufficient_stock' || str_contains((string)$error, 'insufficient_stock')) {
                $order->update_status('on-hold',
                    'Pago recibido pero el TPV no tiene stock disponible. Revisar: reponer o reembolsar.');
                $order->add_order_note(
                    "El TPV rechazó el pedido por falta de stock (409). Cliente pagado pero sin stock físico. Reponer o reembolsar manualmente."
                );
                $this->log($wcOrderId, 'insufficient_stock', "TPV devolvió 409: {$error}");
                // NO encolar: 409 insufficient_stock no es error transitorio.
            } else {
                $order->add_order_note("Error al registrar en TPV: {$error}");
                $this->log($wcOrderId, 'error', "Error: {$error}");
                // Encolar en fallback queue para reintento con backoff.
                if (class_exists('TPV_Sync') && class_exists('TPV_Sync_Queue')) {
                    TPV_Sync::instance()->queue->enqueue(
                        'order.send',
                        ['wc_order_id' => $wcOrderId],
                        substr((string)$error, 0, 500)
                    );
                }
            }
        }
    }

    // ─── WC → TPV: cambio de estado ───────────────────────────────────────────

    /**
     * Hook: woocommerce_order_status_changed
     * Propaga al TPV cuando el estado cambia en WooCommerce.
     * No propaga si el cambio fue iniciado desde el TPV (evita bucle).
     */
    public function on_wc_status_changed(int $wcOrderId, string $from, string $to): void
    {
        // Si el cambio lo inició el TPV, no lo devolvemos (evita bucle)
        if (get_post_meta($wcOrderId, '_tpv_status_origin', true) === 'tpv') {
            delete_post_meta($wcOrderId, '_tpv_status_origin');
            return;
        }

        $tpvOrderId = (int)get_post_meta($wcOrderId, self::TPV_ORDER_META, true);
        if (!$tpvOrderId) return;

        $tpvStatus = self::WC_TO_TPV_STATUS[$to] ?? null;
        if (!$tpvStatus) return;

        $this->api->patch("/orders/{$tpvOrderId}/status", [
            'order_status_id' => $tpvStatus,
            'comment'         => "Estado actualizado desde WooCommerce: {$to}",
        ]);

        $this->log($wcOrderId, 'ok', "Estado WC '{$to}' → TPV status_id={$tpvStatus}");
    }

    // ─── WC → TPV: reembolso ──────────────────────────────────────────────────

    /**
     * Hook: woocommerce_order_refunded
     * Cuando se crea un reembolso en WC (total o parcial), registramos la
     * devolución en el TPV como return nativo por cada línea refundada.
     *
     * Un origen TPV (flag `_tpv_refund_origin`=tpv) corta el bucle: ese refund
     * lo creó el webhook handler y no debe reenviarse.
     */
    public function on_wc_refund(int $wcOrderId, int $refundId): void
    {
        // 1) origen TPV — lo creó el webhook handler, no reenviar
        if (get_post_meta($refundId, '_tpv_refund_origin', true) === 'tpv') {
            delete_post_meta($refundId, '_tpv_refund_origin');
            return;
        }
        // 2) idempotencia: ya propagado
        if (get_post_meta($refundId, '_tpv_refund_synced', true)) {
            return;
        }

        $tpvOrderId = (int)get_post_meta($wcOrderId, self::TPV_ORDER_META, true);
        if (!$tpvOrderId) {
            // El pedido original no está en TPV (venta previa al plugin, por ejemplo)
            $this->log($wcOrderId, 'skip', "Refund WC #{$refundId}: pedido sin mapeo en TPV");
            return;
        }

        $refund = wc_get_order($refundId);
        if (!$refund) return;

        $errors = 0;
        foreach ($refund->get_items() as $item) {
            $tpvProductId = (int)get_post_meta($item->get_product_id(), TPV_Sync_Product_Sync::TPV_ID_META, true);
            if (!$tpvProductId) continue;

            // En WC los items de un refund tienen quantity negativa
            $qty = abs((float)$item->get_quantity());
            if ($qty <= 0) continue;

            // Idempotency-Key por línea de refund (refundId + productId).
            // Incluye el product_id para que refunds multi-línea no colisionen.
            $idemKey = 'wc-refund-' . $refundId . '-' . $tpvProductId;

            $result = $this->api->post("/orders/{$tpvOrderId}/returns", [
                'product_id'       => $tpvProductId,
                'quantity'         => $qty,
                'product_name'     => $item->get_name(),
                'comment'          => 'Reembolso WC #' . $refundId
                                    . ($refund->get_reason() ? ' — ' . $refund->get_reason() : ''),
                'return_reason_id' => 0,
                'return_action_id' => 0,
                'return_status_id' => 1,
            ], $idemKey);

            if (empty($result['data']['return_id']) && empty($result['return_id'])) {
                $errors++;
                $msg = $result['errors'][0]['message'] ?? wp_json_encode($result);
                $this->log($wcOrderId, 'error', "Refund WC #{$refundId} producto {$tpvProductId}: {$msg}");
            }
        }

        if ($errors === 0) {
            update_post_meta($refundId, '_tpv_refund_synced', 1);
            $this->log($wcOrderId, 'ok', "Refund WC #{$refundId} propagado a TPV order #{$tpvOrderId}");
        } else {
            // Encolar para reintento: al menos una línea del refund falló.
            if (class_exists('TPV_Sync') && class_exists('TPV_Sync_Queue')) {
                TPV_Sync::instance()->queue->enqueue(
                    'refund.send',
                    ['wc_order_id' => $wcOrderId, 'refund_id' => $refundId],
                    "$errors line(s) failed in refund"
                );
            }
        }
    }

    // ─── TPV → WC: actualizar estado ─────────────────────────────────────────

    /**
     * Llamado desde el webhook handler cuando el TPV cambia el estado
     * de un pedido que vino de WooCommerce.
     */
    public function update_wc_status(int $tpvOrderId, int $tpvStatusId): void
    {
        global $wpdb;

        $wcOrderId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::TPV_ORDER_META, $tpvOrderId
        ));

        if (!$wcOrderId) return;

        $wcStatus = self::TPV_TO_WC_STATUS[$tpvStatusId] ?? null;
        if (!$wcStatus) return;

        $order = wc_get_order($wcOrderId);
        if (!$order) return;

        // Marcar que el cambio viene del TPV para no crear bucle
        update_post_meta($wcOrderId, '_tpv_status_origin', 'tpv');
        $order->update_status($wcStatus, 'Estado actualizado desde el TPV.');

        $this->log($wcOrderId, 'ok', "Estado TPV {$tpvStatusId} → WC '{$wcStatus}'");
    }

    // ─── Log ──────────────────────────────────────────────────────────────────

    private function log(int $orderId, string $status, string $msg): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tpv_sync_log', [
            'event_type'  => 'order_sync',
            'resource'    => 'order',
            'resource_id' => $orderId,
            'status'      => $status,
            'message'     => $msg,
        ]);
    }

    /**
     * Extrae el NIF/CIF del pedido o del usuario. Plugins españoles comunes:
     *   - WooCommerce NIF/CIF/NIE (oscarthbault):  _billing_nif
     *   - WooCommerce EU VAT Number:               _billing_vat / _vat_number
     *   - Sequra/otros:                            _billing_dni / _billing_cif
     *   - ES-Spain Nif/Cif (ANS):                  billing_myfield
     * Filtro `tpv_sync_customer_tax_id` disponible para casos a medida.
     */
    private function resolve_customer_tax_id($order): string
    {
        $wcOrderId = $order->get_id();
        $candidates = [
            '_billing_nif', '_billing_cif', '_billing_nie',
            '_billing_dni', '_billing_vat', '_billing_vat_number',
            '_vat_number', '_billing_eu_vat_number',
        ];
        foreach ($candidates as $k) {
            $v = get_post_meta($wcOrderId, $k, true);
            if (is_string($v) && $v !== '') {
                return apply_filters('tpv_sync_customer_tax_id', trim($v), $order);
            }
        }
        // Fallback: meta del usuario (usuarios registrados que guardan su NIF).
        $userId = $order->get_customer_id();
        if ($userId) {
            foreach ($candidates as $k) {
                $v = get_user_meta($userId, $k, true);
                if (is_string($v) && $v !== '') {
                    return apply_filters('tpv_sync_customer_tax_id', trim($v), $order);
                }
            }
        }
        return (string)apply_filters('tpv_sync_customer_tax_id', '', $order);
    }

    /**
     * Normaliza billing/shipping de WC al formato que espera la API TPV.
     * Tipo = 'billing' o 'shipping'. Devuelve [] si no hay address_1.
     *
     * Campos OpenCart: address_1, address_2, city, postcode, company,
     * country (nombre), country_id (numérico OC), zone (nombre), zone_id.
     *
     * Como WC no conoce los IDs de oc_country/oc_zone del TPV, mandamos
     * solo nombres (country="Spain", zone="Madrid") y la API los resolverá
     * contra sus tablas cuando lo necesite. IDs van a 0.
     */
    private function build_address_from_order($order, string $type): array
    {
        $getter = fn(string $field) => method_exists($order, "get_{$type}_{$field}")
            ? (string)$order->{"get_{$type}_{$field}"}()
            : '';

        $a1 = $getter('address_1');
        if ($a1 === '') return [];

        return [
            'company'     => $getter('company'),
            'address_1'   => $a1,
            'address_2'   => $getter('address_2'),
            'city'        => $getter('city'),
            'postcode'    => $getter('postcode'),
            'country'     => WC()->countries ? WC()->countries->countries[$getter('country')] ?? $getter('country') : $getter('country'),
            'country_id'  => 0,
            'zone'        => $this->resolve_state_name($getter('country'), $getter('state')),
            'zone_id'     => 0,
        ];
    }

    /**
     * Convierte un código de estado WC (ej. 'M' para Madrid en ES) al nombre
     * legible que OpenCart espera. Si no hay mapa, devuelve el código tal cual.
     */
    private function resolve_state_name(string $countryCode, string $stateCode): string
    {
        if ($stateCode === '' || $countryCode === '') return $stateCode;
        if (!WC()->countries) return $stateCode;
        $states = WC()->countries->get_states($countryCode);
        return is_array($states) && isset($states[$stateCode]) ? (string)$states[$stateCode] : $stateCode;
    }
}
