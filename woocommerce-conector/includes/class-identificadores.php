<?php
declare(strict_types=1);
/**
 * Los identificadores de un producto: que viaja como `model` y que como `sku`.
 *
 * El TPV exige `model` no vacío y único: es la columna "Modelo" del admin y lo
 * que se escanea con la pistola. Woo, en cambio, deja el SKU vacío con toda
 * normalidad — en pineapplemoda lo está casi siempre.
 *
 * Lo que había resolvía ese choque inventando un valor y metiéndolo en LOS DOS
 * campos, así que en el TPV aparecían SKU como "__WC__48853" que el
 * comerciante no había escrito nunca.
 *
 * Aquí se separan las dos cosas:
 *
 *     model -> GTIN, si no el SKU de Woo, si no un técnico (obligatorio)
 *     sku   -> el SKU de Woo, y VACÍO si Woo no tiene (es lo que se ve)
 *
 * Verificado contra la API antes de hacerlo (ProductController::validate):
 * basta con que uno de los dos venga relleno, y la comprobación de duplicados
 * solo corre sobre sku no vacíos. Un sku vacío no choca con nada.
 *
 * Esto se puede cambiar sin duplicar el catálogo porque el vínculo Woo<->TPV
 * no vive en el sku sino en api_external_mapping (client_external_id =
 * post_id de WP).
 *
 * Todo puro: cadenas a cadenas. Sin WordPress y sin red.
 */
defined('ABSPATH') || defined('TPV_SYNC_TESTING') || exit;

class TPV_Sync_Identificadores
{
    /**
     * Marca del model generado por el conector. Prefijo poco habitual a
     * propósito, para no chocar con códigos escritos a mano.
     */
    const PREFIJO_TECNICO = '__WC__';

    /**
     * Identificadores de un producto padre.
     *
     * @return array{model: string, sku: string}
     */
    public static function paraProducto(string $gtin, string $sku, int $postId): array
    {
        $gtin = trim($gtin);
        $sku  = trim($sku);

        if ($gtin !== '') {
            $model = $gtin;
        } elseif ($sku !== '') {
            $model = $sku;
        } else {
            // Único porque los post_id de WP lo son. Sin esto el producto no
            // se puede crear: la API responde "sku or model is required".
            $model = self::PREFIJO_TECNICO . $postId;
        }

        return [
            'model' => $model,
            // El cambio: si Woo no tiene sku, el TPV tampoco lo enseña.
            // Antes aquí se duplicaba el técnico y el comerciante veía
            // "__WC__48853" en la columna SKU.
            'sku'   => $sku,
        ];
    }

    /** ¿Este model lo generamos nosotros, o es un código de verdad? */
    public static function esModelTecnico(string $model): bool
    {
        return strpos(trim($model), self::PREFIJO_TECNICO) === 0;
    }

    /**
     * ¿Este sku es de los que dejó la versión anterior del conector?
     *
     * Sirve para limpiarlos sin tocar los que el comerciante escribió.
     */
    public static function esSkuTecnicoViejo(string $sku): bool
    {
        $sku = trim($sku);
        if ($sku === '') return false;

        return strpos($sku, self::PREFIJO_TECNICO) === 0;
    }

    /**
     * Qué productos del TPV hay que limpiar y cómo.
     *
     * Devuelve solo los que llevan un sku técnico, con el sku a vacío. El
     * `model` NO se incluye a propósito: es el identificador que sostiene el
     * vínculo y lo que se escanea. Vaciarlo dejaría productos sin
     * identificador y el siguiente volcado los duplicaría.
     *
     * @param  array<int,array{tpv_id:int,sku:string,model:string}> $productos
     * @return array<int,array{tpv_id:int,sku:string}>
     */
    public static function planLimpieza(array $productos): array
    {
        $plan = [];
        foreach ($productos as $p) {
            if (!self::esSkuTecnicoViejo((string) ($p['sku'] ?? ''))) continue;

            $plan[] = [
                'tpv_id' => (int) ($p['tpv_id'] ?? 0),
                'sku'    => '',
            ];
        }
        return $plan;
    }

    /**
     * Código de barras de una variante, de mejor a peor:
     *
     *   1. GTIN/EAN de la variación — el código real del producto.
     *   2. SKU escrito por el comerciante.
     *   3. El ID de la variación (su post_id en WP).
     *
     * El paso 3 es el que usa el cliente de pineapplemoda: los números que
     * Woo enseña junto a cada variación (#48907, #48906…) los imprime en la
     * etiqueta y los escanea en el mostrador. Son post_id, así que son únicos
     * en toda la tienda y estables mientras la variación exista, y como
     * cadena numérica valen para un Code128.
     *
     * Lo que NO se manda es el sku que Woo autogenera cuando el padre no
     * tiene sku ("__WC__48808-1"): ese texto no lo escanea ninguna pistola.
     * En su lugar va el ID, que sí.
     */
    public static function codigoVariante(string $gtin, string $sku, int $variationId = 0): string
    {
        $gtin = trim($gtin);
        if ($gtin !== '') return $gtin;

        $sku = trim($sku);
        if ($sku !== '' && !self::esSkuTecnicoViejo($sku)) return $sku;

        // Sin código propio: el ID de la variación. Antes esto devolvía vacío
        // y se perdía el identificador que el comerciante ya estaba usando.
        return $variationId > 0 ? (string) $variationId : '';
    }

    /**
     * Elige el mejor código para una combinación que ya tenía uno.
     *
     * Antes se guardaba el PRIMERO y punto, así que una variación posterior
     * con EAN de verdad no lo mejoraba nunca. Reglas:
     *
     *   - un GTIN gana siempre (es lo único que escanea en el mostrador);
     *   - entre dos no escaneables, se conserva el que ya estaba (estable);
     *   - un vacío nunca pisa un código bueno.
     */
    public static function mejorCodigo(
        string $actual, string $gtin, string $sku, int $variationId = 0
    ): string {
        $gtin = trim($gtin);
        if ($gtin !== '') return $gtin;

        $actual = trim($actual);
        if ($actual !== '') return $actual;

        return self::codigoVariante('', $sku, $variationId);
    }
}
