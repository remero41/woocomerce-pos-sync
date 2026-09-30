<?php
declare(strict_types=1);
/**
 * El precio que tiene que cuadrar entre la web y la caja: el PVP.
 *
 * Funciones puras (sin WordPress) para poder fijarlas con la matriz de
 * configuraciones de WooCommerce (SPEC_pvp_tienda_caja).
 *
 * lulubeauty (30-09-2026): su web enseñaba «9,92 € PVP IVA incl.» y el TPV
 * cobraba 12,00 €. El conector calculaba el precio SIN IVA con la
 * configuración de impuestos de Woo, y con cualquier configuración distinta
 * de la ideal el TPV cobraba de más. Ahora el conector manda lo que Woo COBRA
 * a un cliente de España y el TPV calcula el precio sin IVA con su clase.
 */
defined('ABSPATH') || exit;

final class TPV_Sync_Precio_Pvp
{
    /**
     * Lo que Woo cobra por $precio (el introducido en la ficha) a un cliente
     * de la dirección base de la tienda.
     *
     *  - impuestos desactivados o producto no gravado: el precio tal cual;
     *  - precios introducidos CON impuestos: el precio tal cual (ya es el final);
     *  - precios introducidos SIN impuestos: el precio + las tarifas de la
     *    dirección base (si no hay tarifa, Woo no suma nada, y nosotros tampoco).
     */
    public static function deWoo(float $precio, bool $impuestosOn, bool $preciosConIva,
                                 bool $gravado, float $porcentajeBase, int $decimales = 2): float
    {
        if (!$impuestosOn || $preciosConIva || !$gravado || $porcentajeBase <= 0) {
            return round($precio, $decimales);
        }
        return round($precio * (1 + $porcentajeBase / 100), $decimales);
    }
}
