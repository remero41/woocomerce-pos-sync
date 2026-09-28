<?php
declare(strict_types=1);
/**
 * «Gestionar stock» de WooCommerce ↔ «restar stock» (`subtract`) del TPV.
 *
 * Son el mismo concepto en los dos lados y hasta el 28-09-2026 no se
 * traducían. Un producto que Woo no cuenta («Hay existencias», sin número)
 * llegaba al TPV como «cuento unidades y tengo 0». De vuelta, cualquier aviso
 * del TPV forzaba `_manage_stock=yes` en Woo con ese 0 y el producto quedaba
 * «Agotado» en la tienda online (lulubeauty, 847 productos).
 *
 * Con `subtract=0` el TPV vende sin descontar, y su bridge no emite
 * stock.adjusted por ese producto: Woo no se entera porque no hay nada que
 * contar.
 *
 * Todo puro: sin WordPress y sin red.
 */
defined('ABSPATH') || defined('TPV_SYNC_TESTING') || exit;

class TPV_Sync_Stock
{
    /**
     * Campos de stock que viajan Woo → TPV.
     *
     * $conCantidad: mandar también la cantidad. Solo tiene sentido como stock
     * INICIAL (alta, o el volcado en bloque, donde la API la usa solo al
     * crear). En una edición NO se manda: el stock de un producto que ya
     * existe se mueve por sus propios caminos (ventas, ajustes), auditados, y
     * pisarlo desde el catálogo borraría lo vendido en caja.
     */
    public static function haciaTpv(bool $wooGestiona, ?float $cantidad, bool $conCantidad): array
    {
        if (!$wooGestiona) {
            // El _stock que Woo conserve de cuando sí gestionaba no significa
            // nada: no viaja.
            return ['subtract' => 0];
        }
        $campos = ['subtract' => 1];
        if ($conCantidad) {
            $campos['quantity'] = (float) ($cantidad ?? 0);
        }
        return $campos;
    }

    /**
     * Metas de stock que se escriben en Woo a partir de un producto del TPV.
     *
     * $wooGestiona: si Woo gestiona hoy el stock de ese producto. Solo se usa
     * cuando la API no dice si el TPV cuenta (versión anterior al campo
     * `subtract`): en la duda se respeta lo que Woo tenga, porque forzar la
     * gestión con un 0 es justo lo que dejaba la tienda «Agotado».
     *
     * @return array<string, string|int> meta_key => valor
     */
    public static function haciaWoo(array $tpv, bool $wooGestiona): array
    {
        if (array_key_exists('subtract', $tpv)) {
            $cuenta = (bool) $tpv['subtract'];
        } else {
            $cuenta = $wooGestiona;
            if (!$cuenta) {
                return [];
            }
        }

        if (!$cuenta) {
            return ['_manage_stock' => 'no', '_stock_status' => 'instock'];
        }

        $cantidad = (int) ($tpv['quantity'] ?? 0);
        return [
            '_manage_stock' => 'yes',
            '_stock'        => $cantidad,
            '_stock_status' => $cantidad > 0 ? 'instock' : 'outofstock',
        ];
    }

    /**
     * ¿Hay un número de stock que sincronizar entre los dos lados?
     *
     * Solo si Woo lo gestiona Y el TPV lo cuenta. Si cualquiera de los dos no
     * cuenta, su número es ruido: copiarlo al otro lado inventa un agotado o
     * un stock que nadie ha contado.
     */
    public static function seCuenta(bool $wooGestiona, array $tpv): bool
    {
        if (!$wooGestiona) {
            return false;
        }
        if (array_key_exists('subtract', $tpv) && !(bool) $tpv['subtract']) {
            return false;
        }
        return true;
    }
}
