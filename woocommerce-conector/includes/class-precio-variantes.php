<?php
declare(strict_types=1);
/**
 * El precio de un producto variable y el sobreprecio de cada variante.
 *
 * En WooCommerce un producto VARIABLE no tiene precio propio: el precio vive
 * en cada variacion. En el TPV (OpenCart debajo) es al reves: el producto
 * tiene un precio y cada valor de opcion lleva un SOBREPRECIO sobre el.
 *
 * Traducir mal esa diferencia es lo que dejo el catalogo de pineapplemoda con
 * productos a 0 € y variantes cargando el precio entero como extra (+24,14 €
 * las tres). Si alguien vende el padre sin elegir variante, cobra cero.
 *
 * La regla:
 *
 *     precio del padre = la variante MAS BARATA
 *     extra de cada variante = su precio - ese minimo, nunca negativo
 *
 * Todo lo de aqui es puro: numeros a numeros. Sin WordPress y sin red, para
 * poder fijar el caso real con tests.
 */
defined('ABSPATH') || defined('TPV_SYNC_TESTING') || exit;

class TPV_Sync_Precio_Variantes
{
    /** oc_product_option_value.price es DECIMAL(15,4): mas decimales los corta la BD. */
    const DECIMALES = 4;

    /**
     * Precio que se manda como precio del producto padre.
     *
     * Es el menor entre las variantes y el precio propio del padre (si lo
     * tiene). Coger el minimo no es una preferencia estetica: cualquier otro
     * valor obligaria a mandar extras NEGATIVOS para las variantes por debajo,
     * y un sobreprecio en negativo descuadra el ticket.
     *
     * Las variantes a 0 se ignoran: en WC una variacion sin precio rellenado
     * vale 0, y tomarla en serio devolveria el producto a 0 € — el bug de
     * partida. Solo si TODAS estan a 0 se acepta el 0 (producto regalo).
     */
    public static function precioBase(array $preciosVariantes, float $precioPadre): float
    {
        $candidatos = [];

        foreach ($preciosVariantes as $p) {
            $p = (float) $p;
            if ($p > 0) $candidatos[] = $p;
        }
        if ($precioPadre > 0) $candidatos[] = $precioPadre;

        if ($candidatos === []) return 0.0;

        return round(min($candidatos), self::DECIMALES);
    }

    /**
     * Sobreprecio de una variante respecto al precio base.
     *
     * Devuelve ['price' => float, 'price_prefix' => '+'] tal como lo espera
     * syncOptions de la API. El prefijo es SIEMPRE '+': si el calculo diera
     * negativo (base mas alto que la variante), se corta en 0 en vez de
     * mandar un '-'. Un extra en negativo es siempre un sintoma de que el
     * precio base esta mal elegido, y propagarlo al TPV convierte un error de
     * calculo en dinero mal cobrado.
     */
    public static function extra(float $precioVariante, float $precioBase): array
    {
        $diff = round($precioVariante - $precioBase, self::DECIMALES);
        if ($diff < 0) $diff = 0.0;

        return ['price' => $diff, 'price_prefix' => '+'];
    }

    /**
     * El precio base y los extras tienen que medirse igual: o los dos con
     * impuesto o los dos sin él. Existe para que el test pueda fijar la regla
     * como contrato explícito y no como comentario.
     *
     * Quién hace la conversión: priceForTpv() en class-product-sync.php, que
     * envuelve wc_get_price_excluding_tax() de WooCommerce. Aquí NO hay un
     * aNeto() con un porcentaje a mano a propósito — el tipo real depende de
     * la clase de impuesto de cada producto y de la configuración de la
     * tienda, y eso ya lo sabe WooCommerce.
     */
    public static function extraNecesitaMismoTratamientoQueBase(): bool
    {
        return true;
    }
}
