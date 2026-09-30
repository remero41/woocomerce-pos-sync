<?php
declare(strict_types=1);
/**
 * Sincronización de pedidos WooCommerce ↔ TPV.
 *
 * Módulo Pedidos:
 *  - WC → TPV: crea pedido en el TPV cuando se procesa el pago
 *  - WC → TPV: propaga cambios de estado (cancelación, reembolso)
 *
 * El amo de un pedido online es la tienda: el TPV recibe la venta y lo que la
 * corrige desde la tienda, pero no la gestiona (decisión del 30-09-2026). No
 * hay camino TPV → WC para pedidos.
 */
defined('ABSPATH') || exit;

class TPV_Sync_Order_Sync
{
    private TPV_Sync_API_Client $api;
    private TPV_Sync_Product_Sync $products;

    const TPV_ORDER_META = '_tpv_order_id';

    /** Pedido retenido: alguna línea no tiene gemelo en el TPV. JSON {desde, faltan}. */
    const PENDIENTE_META = '_tpv_order_pendiente';

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

    /**
     * $products es obligatorio: sin él una línea sin enlace no se puede
     * asegurar en el TPV, y un Order_Sync «a medias» (el botón de reintentar
     * del admin construía uno así) retendría pedidos que sí se pueden enviar.
     */
    public function __construct(TPV_Sync_API_Client $api, TPV_Sync_Product_Sync $products)
    {
        $this->api      = $api;
        $this->products = $products;
    }

    /**
     * Los identificadores que lleva una línea de pedido hacia el TPV.
     *
     * El TPV modela las variantes como valores de opción del producto padre,
     * así que la línea siempre va a nombre del padre (`product_id`) y, si se
     * vendió una talla concreta, la declara en `options`, que es la forma que
     * exige la API: OrderController lee `$line['options'][]` y por cada
     * entrada escribe order_option (la venta queda ATRIBUIDA a esa talla) y
     * mueve el stock de la variante. Un campo suelto en la línea lo ignoraría.
     *
     * Sin ese segundo campo el TPV descuenta del total del producto pero no
     * sabe qué talla salió, y el stock por talla se desincroniza (auditoría
     * del 22-09-2026). Con él, descuenta de la variante correcta.
     *
     * Cuando no hay variante —producto simple, o variación que aún no se ha
     * mapeado porque se creó en WC después del volcado— el campo NO se manda:
     * un `product_option_value_id` a 0 haría que el TPV buscase una variante
     * inexistente y rechazase la línea. Es preferible que descuente del padre
     * a que la venta entera falle.
     */
    public static function idsDeLinea(int $tpvProductId, ?int $tpvOptionValueId): array
    {
        $ids = ['product_id' => $tpvProductId];
        if ($tpvOptionValueId !== null && $tpvOptionValueId > 0) {
            $ids['options'] = [['product_option_value_id' => $tpvOptionValueId]];
        }
        return $ids;
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
        // Una línea sin enlace ya NO se descarta (llegaba al TPV un pedido a
        // medias, o ninguno): se asegura su producto en el TPV en el momento.
        // Si alguna sigue sin gemelo, el pedido entero se retiene.
        [$products, $faltan, $origen] = $this->lineasParaTpv($order);

        if (!empty($faltan)) {
            $this->retener($order, $faltan);
            return;
        }
        if (empty($products)) {
            // Sin líneas de producto (solo cargos, por ejemplo): no hay venta
            // que registrar. Retenerlo lo reintentaría para siempre.
            $this->log($wcOrderId, 'skip', 'Pedido sin líneas de producto');
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
            'products'       => [],
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

        // Un enlace a un producto (o talla) que el TPV ya no tiene hace que la
        // API rechace el pedido ENTERO con not_found. Se quita ese enlace, la
        // línea se vuelve a asegurar y se reenvía. Cada línea se repara una
        // sola vez: si el TPV la sigue rechazando, es otro problema y va a la
        // cola como cualquier error.
        $reparados = [];
        while (true) {
            $payload['products'] = $products;
            $result = $this->api->post('/orders', $payload, $idemKey);
            if (!empty($result['data']['order_id'])) {
                break;
            }
            $roto = self::enlaceRoto($result);
            if ($roto === null || !$this->quitarEnlaceRoto($wcOrderId, $roto, $origen, $reparados)) {
                break;
            }
            [$products, $faltan, $origen] = $this->lineasParaTpv($order);
            if (!empty($faltan)) {
                $this->retener($order, $faltan);
                return;
            }
        }

        if (!empty($result['data']['order_id'])) {
            $tpvOrderId = (int)$result['data']['order_id'];
            update_post_meta($wcOrderId, self::TPV_ORDER_META, $tpvOrderId);
            delete_post_meta($wcOrderId, self::PENDIENTE_META);
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

    /**
     * Las líneas del pedido hacia el TPV, las que no tienen gemelo, y de qué
     * post de Woo sale el enlace de cada una (para poder quitarlo si el TPV
     * lo rechaza).
     *
     * @return array{0: array, 1: string[], 2: array<int, array{post:int, product_id:int, variacion:int, pov:int}>}
     */
    private function lineasParaTpv($order): array
    {
        $products = [];
        $faltan   = [];
        $origen   = [];
        foreach ($order->get_items() as $item) {
            $tpvId = $this->products->asegurarEnTpv((int) $item->get_product_id());
            if (!$tpvId) {
                $faltan[] = $item->get_name() . ' (#' . (int) $item->get_product_id() . ')';
                continue;
            }

            $qty         = (float)$item->get_quantity();
            $lineNetTot  = (float)$item->get_total();       // neto línea
            // get_total_tax() es método estándar de WC_Order_Item_Product; en stubs
            // de test puede no existir. Fallback a 0 (legacy sin tax).
            $lineTaxTot  = method_exists($item, 'get_total_tax') ? (float)$item->get_total_tax() : 0.0;
            $qtySafe     = $qty > 0 ? $qty : 1.0;

            // Qué variante se vendió. get_product_id() devuelve el PADRE; la
            // talla concreta está en get_variation_id(), y su equivalente en
            // el TPV en el meta _tpv_option_value_id que dejó el volcado.
            $povId = 0;
            if (method_exists($item, 'get_variation_id') && (int) $item->get_variation_id() > 0) {
                $povId = (int) get_post_meta((int) $item->get_variation_id(), '_tpv_option_value_id', true);
            }

            $origen[] = ['post' => (int) $item->get_product_id(), 'product_id' => (int) $tpvId,
                         'variacion' => $povId > 0 ? (int) $item->get_variation_id() : 0, 'pov' => $povId];
            $products[] = self::idsDeLinea((int)$tpvId, $povId) + [
                'name'       => $item->get_name(),
                'quantity'   => $qty,
                'price'      => $lineNetTot / $qtySafe,     // unit net
                'tax'        => $lineTaxTot / $qtySafe,     // unit tax
                'total'      => $lineNetTot,                // net line total
            ];
        }

        return [$products, $faltan, $origen];
    }

    /**
     * Qué enlace ha roto el pedido, si el rechazo es por un producto o una
     * talla que el TPV ya no tiene: ['product_id'|'product_option_value_id', id].
     * El cliente negocia problem+json (`code`); se acepta también el formato
     * clásico (`errors[0].error`).
     */
    public static function enlaceRoto(array $r): ?array
    {
        $codigo = (string) ($r['code'] ?? $r['errors'][0]['error'] ?? '');
        if (!preg_match('/^not_found:(product_id|product_option_value_id):(\d+)$/', $codigo, $m)) {
            return null;
        }
        return [$m[1], (int) $m[2]];
    }

    /**
     * Quita el enlace roto de las líneas que lo llevan y que aún no se han
     * reparado en este envío. Sin enlace, la línea se vuelve a asegurar
     * (reenlazar o alta) y una talla va a nombre del padre. Devuelve si quitó
     * alguno: si no, reenviar daría el mismo error.
     */
    private function quitarEnlaceRoto(int $wcOrderId, array $roto, array $origen, array &$reparados): bool
    {
        [$campo, $id] = $roto;
        $quitado = false;
        foreach ($origen as $o) {
            if ($campo === 'product_id' && $o['product_id'] === $id && !isset($reparados['p' . $o['post']])) {
                delete_post_meta($o['post'], TPV_Sync_Product_Sync::TPV_ID_META);
                $reparados['p' . $o['post']] = true;
                $quitado = true;
            } elseif ($campo === 'product_option_value_id' && $o['pov'] === $id) {
                // Sin su meta la línea ya no lleva talla: no puede volver a fallar por ella.
                delete_post_meta($o['variacion'], '_tpv_option_value_id');
                $quitado = true;
            }
        }
        if ($quitado) {
            $this->log($wcOrderId, 'warn', "El TPV ya no tiene {$campo} {$id}: enlace quitado, se reenvía");
        }
        return $quitado;
    }

    /**
     * Retiene un pedido que no puede llegar entero al TPV. Nunca a medias: se
     * guarda como pendiente y lo reintenta reintentarPendientes(). La nota va
     * UNA vez (el primer intento), para que la comerciante lo vea sin que cada
     * reintento le llene el pedido.
     */
    private function retener($order, array $faltan): void
    {
        $wcOrderId = (int) $order->get_id();
        $lista     = implode(', ', $faltan);
        if ((string) get_post_meta($wcOrderId, self::PENDIENTE_META, true) === '') {
            $order->add_order_note(
                "Pendiente de enviar al TPV: no se pudo dar de alta en el TPV: {$lista}. "
                . 'Se reintentará solo en cuanto se pueda; no llegará incompleto.'
            );
        }
        update_post_meta($wcOrderId, self::PENDIENTE_META,
            wp_json_encode(['desde' => gmdate('c'), 'faltan' => $faltan]));
        $this->log($wcOrderId, 'pendiente', "Retenido: {$lista}");
    }

    /**
     * Recupera, UNA vez por instalación, los pedidos que el conector descartó
     * antes de que dejara de mandarlos a medias: los registrados como `skip`
     * «Sin productos mapeados al TPV», nunca se volvían a intentar.
     *
     * Solo los pagados (en proceso o completados) que siguen sin pedido en el
     * TPV: se marcan como retenidos y reintentarPendientes() los envía.
     * Decisión del usuario (30-09-2026): automático; si alguno se metió a mano
     * en el TPV y se duplica, lo asume la comerciante.
     */
    private function recuperarDescartados(): void
    {
        if (get_option('tpv_sync_recuperacion_descartados_v1')) {
            return;
        }
        update_option('tpv_sync_recuperacion_descartados_v1', 1, false);

        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT resource_id FROM {$wpdb->prefix}tpv_sync_log
             WHERE event_type = %s AND status = %s AND message = %s",
            'order_sync', 'skip', 'Sin productos mapeados al TPV'
        )));
        foreach ($ids as $wcOrderId) {
            if ($wcOrderId <= 0
                || get_post_meta($wcOrderId, self::TPV_ORDER_META, true)
                || (string) get_post_meta($wcOrderId, self::PENDIENTE_META, true) !== '') {
                continue;
            }
            $order = wc_get_order($wcOrderId);
            if (!$order || !in_array($order->get_status(), ['processing', 'completed'], true)) {
                continue;
            }
            $order->add_order_note('Recuperado: este pedido no llegó al TPV porque sus productos '
                . 'no estaban enlazados. Se envía ahora automáticamente.');
            update_post_meta($wcOrderId, self::PENDIENTE_META,
                wp_json_encode(['desde' => gmdate('c'), 'faltan' => [], 'recuperado' => true]));
            $this->log($wcOrderId, 'pendiente', 'Recuperado: descartado antes por falta de enlace');
        }
    }

    /**
     * Reintenta los pedidos retenidos, por tandas y con cursor (los que siguen
     * atascados no tapan a los de detrás). Lo llama el cron
     * `tpv_sync_pedidos_pendientes` cada 5 minutos. No va por la cola: la cola
     * abandona a las ~29 h y un producto puede tardar más en arreglarse.
     */
    public function reintentarPendientes(int $lote = 10): array
    {
        global $wpdb;
        $this->recuperarDescartados();
        $stats  = ['revisados' => 0, 'enviados' => 0, 'devoluciones' => 0];
        $cursor = (int) get_option('tpv_sync_pedidos_cursor', 0);
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '" . self::PENDIENTE_META . "' AND post_id > %d
             ORDER BY post_id ASC LIMIT %d",
            $cursor, $lote
        )));
        if (empty($ids)) {
            update_option('tpv_sync_pedidos_cursor', 0, false);
        } else {
            foreach ($ids as $wcOrderId) {
                $stats['revisados']++;
                $this->send_to_tpv($wcOrderId);
                if (get_post_meta($wcOrderId, self::TPV_ORDER_META, true)) {
                    $stats['enviados']++;
                }
            }
            update_option('tpv_sync_pedidos_cursor', end($ids), false);
        }
        // Después de los pedidos: la devolución que esperaba a su pedido sale
        // en la misma pasada.
        $stats['devoluciones'] = $this->reintentarDevolucionesPendientes($lote);
        return $stats;
    }

    // ─── WC → TPV: cambio de estado ───────────────────────────────────────────

    /**
     * Hook: woocommerce_order_status_changed
     * Propaga al TPV cuando el estado cambia en WooCommerce (una cancelación
     * tiene que devolver el stock y corregir la caja del TPV). Solo en este
     * sentido: el TPV no cambia el estado de un pedido de la tienda.
     */
    public function on_wc_status_changed(int $wcOrderId, string $from, string $to): void
    {
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
     */
    public function on_wc_refund(int $wcOrderId, int $refundId): void
    {
        // Idempotencia: ya propagado
        if (get_post_meta($refundId, '_tpv_refund_synced', true)) {
            return;
        }

        $refund = wc_get_order($refundId);
        if (!$refund) return;

        $tpvOrderId = (int)get_post_meta($wcOrderId, self::TPV_ORDER_META, true);
        if (!$tpvOrderId) {
            if ((string) get_post_meta($wcOrderId, self::PENDIENTE_META, true) !== '') {
                // El pedido está retenido (aún no está en el TPV): el reembolso
                // espera y sale cuando salga el pedido (reintentarPendientes).
                $this->retenerDevolucion($refund, $wcOrderId, ['el pedido aún no está en el TPV']);
                return;
            }
            // Venta anterior al conector: no hay nada que devolver en el TPV.
            $this->log($wcOrderId, 'skip', "Refund WC #{$refundId}: pedido sin mapeo en TPV");
            return;
        }

        // Líneas del pedido en el TPV: para devolver cada talla a SU línea.
        // Con dos líneas del mismo producto la API exige order_product_id.
        $tpvOrder  = $this->api->get("/orders/{$tpvOrderId}");
        $lineasTpv = (array) ($tpvOrder['data']['products'] ?? []);

        // Lo que ya entró de este reembolso (item id => return id). Un
        // reintento solo manda lo que falta: la idempotencia de la API caduca a
        // las 24 h y la cola reintenta hasta ~29 h, así que reenviarlo todo
        // podía devolver dos veces lo ya devuelto.
        $hechas = get_post_meta($refundId, '_tpv_refund_lineas', true);
        $hechas = is_array($hechas) ? $hechas : [];

        $errores   = 0;
        $faltan    = [];   // sin gemelo en el TPV: puede arreglarse
        $ausentes  = [];   // enlazado pero no vendido en ese pedido del TPV
        foreach ($refund->get_items() as $item) {
            $itemId = (int) $item->get_id();
            $qty    = abs((float) $item->get_quantity());   // en Woo, negativa
            if ($qty <= 0 || isset($hechas[$itemId])) continue;

            $tpvProductId = $this->products->asegurarEnTpv((int) $item->get_product_id());
            if (!$tpvProductId) {
                $faltan[] = $item->get_name() . ' (#' . (int) $item->get_product_id() . ')';
                continue;
            }
            $povId = (int) $item->get_variation_id() > 0
                ? (int) get_post_meta((int) $item->get_variation_id(), '_tpv_option_value_id', true)
                : 0;
            $opid = self::lineaADevolverTpv($lineasTpv, $tpvProductId, $povId);
            if ($opid === null) {
                $ausentes[] = $item->get_name() . ' (#' . (int) $item->get_product_id() . ')';
                continue;
            }

            // Sin return_status_id: la API da de alta la devolución ejecutada
            // (3) y rechaza cualquier otro (422 invalid_return_status desde el
            // 22-08). El plugin mandaba 1: NINGÚN reembolso llegaba al TPV.
            $body = [
                'product_id'       => $tpvProductId,
                'quantity'         => $qty,
                'product_name'     => $item->get_name(),
                'comment'          => 'Reembolso WC #' . $refundId
                                    . ($refund->get_reason() ? ' — ' . $refund->get_reason() : ''),
                'return_reason_id' => 0,
                'return_action_id' => 0,
            ];
            if ($opid > 0) {
                $body['order_product_id'] = $opid;
            }
            // Clave por LÍNEA del reembolso: con una por producto, dos tallas
            // del mismo producto compartían clave y la 2.ª se perdía.
            $result = $this->api->post("/orders/{$tpvOrderId}/returns", $body, "wc-refund-{$refundId}-{$itemId}");

            $returnId = (int) ($result['data']['return_id'] ?? $result['return_id'] ?? 0);
            if ($returnId > 0) {
                $hechas[$itemId] = $returnId;
            } else {
                $errores++;
                $msg = $result['errors'][0]['message'] ?? wp_json_encode($result);
                $this->log($wcOrderId, 'error', "Refund WC #{$refundId} línea {$itemId}: {$msg}");
            }
        }
        update_post_meta($refundId, '_tpv_refund_lineas', $hechas);

        if (!empty($ausentes)) {
            // No se arregla esperando: el pedido llegó al TPV sin esa línea
            // (antes de que el conector dejara de mandar pedidos a medias).
            $this->notaUnaVez($refund, '_tpv_refund_nota_ausente',
                'No se pudo registrar en el TPV la devolución de: ' . implode(', ', $ausentes)
                . ". Ese producto no está en el pedido #{$tpvOrderId} del TPV: revísalo a mano.");
            $this->log($wcOrderId, 'error', "Refund WC #{$refundId}: líneas ausentes del pedido TPV #{$tpvOrderId}");
        }
        if (!empty($faltan)) {
            $this->retenerDevolucion($refund, $wcOrderId, $faltan);
        }
        if ($errores > 0) {
            if (class_exists('TPV_Sync') && class_exists('TPV_Sync_Queue')) {
                TPV_Sync::instance()->queue->enqueue(
                    'refund.send',
                    ['wc_order_id' => $wcOrderId, 'refund_id' => $refundId],
                    "$errores line(s) failed in refund"
                );
            }
        }
        if ($errores === 0 && empty($faltan) && empty($ausentes)) {
            update_post_meta($refundId, '_tpv_refund_synced', 1);
            delete_post_meta($refundId, '_tpv_refund_pendiente');
            $this->log($wcOrderId, 'ok', "Refund WC #{$refundId} propagado a TPV order #{$tpvOrderId}");
        }
    }

    /**
     * La línea del pedido del TPV a la que vuelve una línea del reembolso.
     * Función pura.
     *
     * - con talla ($povId > 0): la línea de ese producto con esa talla;
     * - si no: 0, sin order_product_id. La API resuelve sola la única línea
     *   del producto, y si hay varias indistinguibles responde que lo
     *   necesita (no se adivina cuál vuelve);
     * - el producto no está en el pedido: null.
     */
    public static function lineaADevolverTpv(array $lineasTpv, int $tpvProductId, int $povId): ?int
    {
        $delProducto = array_values(array_filter($lineasTpv,
            fn ($l) => (int) ($l['product_id'] ?? 0) === $tpvProductId));
        if (empty($delProducto)) {
            return null;
        }
        if ($povId > 0) {
            foreach ($delProducto as $l) {
                foreach ((array) ($l['options'] ?? []) as $o) {
                    if ((int) ($o['product_option_value_id'] ?? 0) === $povId) {
                        return (int) $l['order_product_id'];
                    }
                }
            }
        }
        return 0;
    }

    /** Devolución que espera: pendiente + nota UNA vez; la reintenta reintentarPendientes(). */
    private function retenerDevolucion($refund, int $wcOrderId, array $motivos): void
    {
        $refundId = (int) $refund->get_id();
        $this->notaUnaVez($refund, '_tpv_refund_pendiente',
            'Devolución pendiente de registrar en el TPV (' . implode(', ', $motivos) . '). '
            . 'Se reintentará sola en cuanto se pueda.');
        update_post_meta($refundId, '_tpv_refund_pendiente',
            wp_json_encode(['pedido' => $wcOrderId, 'desde' => gmdate('c'), 'motivos' => $motivos]));
        $this->log($wcOrderId, 'pendiente', "Refund WC #{$refundId} retenido: " . implode(', ', $motivos));
    }

    /** Nota en el pedido/reembolso solo si $metaMarca aún no está puesta. */
    private function notaUnaVez($objeto, string $metaMarca, string $nota): void
    {
        $id = (int) $objeto->get_id();
        if ((string) get_post_meta($id, $metaMarca, true) === '') {
            $objeto->add_order_note($nota);
            if ($metaMarca !== '_tpv_refund_pendiente') {
                update_post_meta($id, $metaMarca, 1);
            }
        }
    }

    /**
     * Reintenta las devoluciones retenidas, por tandas con cursor. La llama
     * reintentarPendientes() después de los pedidos: una devolución que
     * esperaba a su pedido sale en la misma pasada que él.
     */
    public function reintentarDevolucionesPendientes(int $lote = 10): int
    {
        global $wpdb;
        $cursor = (int) get_option('tpv_sync_devoluciones_cursor', 0);
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_tpv_refund_pendiente' AND post_id > %d
             ORDER BY post_id ASC LIMIT %d",
            $cursor, $lote
        )));
        if (empty($ids)) {
            update_option('tpv_sync_devoluciones_cursor', 0, false);
            return 0;
        }
        $hechas = 0;
        foreach ($ids as $refundId) {
            $datos = json_decode((string) get_post_meta($refundId, '_tpv_refund_pendiente', true), true);
            $wcOrderId = (int) ($datos['pedido'] ?? 0);
            if ($wcOrderId <= 0) continue;
            $this->on_wc_refund($wcOrderId, $refundId);
            if (get_post_meta($refundId, '_tpv_refund_synced', true)) {
                $hechas++;
            }
        }
        update_option('tpv_sync_devoluciones_cursor', end($ids), false);
        return $hechas;
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
