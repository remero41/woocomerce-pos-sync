<?php
declare(strict_types=1);
/**
 * Stock «en vuelo»: lo vendido en la web que Woo ya restó y el TPV aún no.
 *
 * El repaso de stock pone en Woo el número del TPV. Un pedido web restado en
 * Woo que todavía no ha llegado al TPV (transferencia «en espera», que no se
 * manda hasta cobrarla; pedido retenido por un producto sin enlace; un envío
 * que está reintentándose) no está en ese número. Copiarlo tal cual devolvía
 * esas unidades a la tienda y se podían vender dos veces. El repaso escribe
 * TPV − en vuelo (16-10-2026).
 *
 * Cuenta un pedido si Woo restó su stock, no tiene `_tpv_order_id`, y es
 * posterior al primer pedido que llegó al TPV o está retenido. Los anteriores
 * a conectar la tienda ya estaban descontados cuando el TPV tomó el stock de
 * Woo: contarlos restaría dos veces.
 */
defined('ABSPATH') || defined('TPV_SYNC_TESTING') || exit;

class TPV_Sync_En_Vuelo
{
    /** Estados en los que Woo ha restado el stock y el pedido sigue vivo. */
    const ESTADOS = ['on-hold', 'processing', 'completed'];

    /** Un pedido más viejo que esto ya no es «en vuelo»: es un pedido perdido. */
    const DIAS = 30;

    /**
     * Unidades en vuelo por artículo de Woo (la variación si la línea la
     * tiene, si no el producto).
     *
     * @param iterable $pedidos      objetos pedido de Woo (o equivalentes)
     * @param int      $primerEnviado id del primer pedido que llegó al TPV; 0 = ninguno
     * @return array<int, float> post_id => unidades
     */
    public static function dePedidos(iterable $pedidos, int $primerEnviado): array
    {
        $u = [];
        foreach ($pedidos as $o) {
            if ((string) $o->get_meta(TPV_Sync_Order_Sync::TPV_ORDER_META, true) !== '') {
                continue;   // ya está en el TPV: su stock ya está restado allí
            }
            $retenido = (string) $o->get_meta(TPV_Sync_Order_Sync::PENDIENTE_META, true) !== '';
            if (!$retenido && ($primerEnviado <= 0 || (int) $o->get_id() <= $primerEnviado)) {
                continue;   // anterior a la conexión: ya estaba en el stock que tomó el TPV
            }
            if (!self::stockRestado($o)) {
                continue;   // Woo no lo restó, o ya lo repuso al cancelar
            }
            foreach ($o->get_items('line_item') as $item) {
                $postId = (int) $item->get_variation_id() ?: (int) $item->get_product_id();
                if ($postId <= 0) continue;
                $u[$postId] = ($u[$postId] ?? 0.0) + (float) $item->get_quantity();
            }
        }
        return $u;
    }

    /** Lo mismo leyendo los pedidos de esta tienda. */
    public static function desdeWoo(): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $pedidos = wc_get_orders([
            'type'         => 'shop_order',   // sin esto vienen también los reembolsos
            'status'       => self::ESTADOS,
            'date_created' => '>' . (time() - self::DIAS * DAY_IN_SECONDS),
            // meta_key/meta_compare y NO meta_query: con los pedidos en entradas
            // (sin HPOS) wc_get_orders descarta un meta_query propio y devolvería
            // todos. Los dos almacenes aceptan el atajo.
            'meta_key'     => TPV_Sync_Order_Sync::TPV_ORDER_META,
            'meta_compare' => 'NOT EXISTS',
            'limit'        => -1,
        ]);
        return self::dePedidos((array) $pedidos, self::primerEnviado());
    }

    /** Id del primer pedido que llegó al TPV (0 si ninguno). No cambia: se recuerda. */
    private static function primerEnviado(): int
    {
        $guardado = (int) get_option('tpv_sync_primer_pedido_enviado', 0);
        if ($guardado > 0) {
            return $guardado;
        }
        $ids = wc_get_orders([
            'type'         => 'shop_order',
            'meta_key'     => TPV_Sync_Order_Sync::TPV_ORDER_META,
            'meta_compare' => 'EXISTS',
            'orderby'    => 'ID',
            'order'      => 'ASC',
            'limit'      => 1,
            'return'     => 'ids',
        ]);
        $primero = (int) ($ids[0] ?? 0);
        if ($primero > 0) {
            update_option('tpv_sync_primer_pedido_enviado', $primero, false);
        }
        return $primero;
    }

    private static function stockRestado($o): bool
    {
        if (method_exists($o, 'get_order_stock_reduced')) {
            return (bool) $o->get_order_stock_reduced();
        }
        return wc_string_to_bool((string) $o->get_meta('_order_stock_reduced', true));
    }
}
