<?php
declare(strict_types=1);
/**
 * EL SKU CON "__WC__" DELANTE.
 *
 * Visto en pineapplemoda el 25-09-2026: productos sincronizados desde Woo que
 * en el TPV aparecen con un SKU que el comerciante no ha escrito nunca
 * ("__WC__48808-1" en la captura de variaciones).
 *
 * No es que se le añada un prefijo a un SKU existente: es un SKU INVENTADO.
 * En este catálogo el campo SKU de Woo está vacío casi siempre, y el TPV exige
 * `model` no vacío y único, así que el conector se inventaba
 *
 *     $fallback  = '__WC__' . $postId;
 *     $model     = $gtin ?: ($sku ?: $fallback);
 *     $skuForTpv = $sku !== '' ? $sku : $fallback;   // <- el problema
 *
 * El `model` SÍ necesita ese valor técnico: sin él el producto no se puede
 * crear (la API responde "sku or model is required"). Lo que sobra es
 * duplicarlo también en `sku`, que es la columna que el comerciante ve.
 *
 * Verificado en la API (ProductController::validate, líneas 1665-1700):
 *   - basta con que UNO de los dos venga relleno;
 *   - la comprobación de "sku already exists" solo corre con `!empty($sku)`,
 *     así que un sku vacío no choca con nada.
 *
 * Se puede cambiar SIN duplicar el catálogo porque el vínculo Woo<->TPV no
 * vive en el sku: vive en api_external_mapping (client_external_id = post_id
 * de WP). Eso es lo que hace barata esta migración.
 *
 * LA OTRA MITAD: el sku de las VARIANTES. No es que no se mandara — se manda
 * como `barcode`, pero solo el PRIMERO de cada combinación. Dos variaciones
 * que colapsan al mismo nombre (el TPV deduplica por nombre en syncOptions)
 * dejaban a la segunda sin código, que es lo que se ve en la captura del TPV:
 * dos de las tres variantes con la columna CÓDIGO vacía.
 */

require_once dirname(__DIR__) . '/includes/class-identificadores.php';

function run_sku_mapeo_tests(WooTestRunner $t): void
{
    $t->suite('SKU del producto: el TPV necesita model, no un sku inventado');

    $t->test('con GTIN y SKU rellenos manda el GTIN como model', function ($t) {
        $m = TPV_Sync_Identificadores::paraProducto('8412345678905', 'CAM-001', 48853);
        $t->assertEquals('8412345678905', $m['model'], 'el GTIN es el codigo escaneable');
        $t->assertEquals('CAM-001', $m['sku'], 'y el sku del comerciante se respeta');
    });

    $t->test('sin GTIN, el SKU del comerciante hace de model', function ($t) {
        $m = TPV_Sync_Identificadores::paraProducto('', 'CAM-001', 48853);
        $t->assertEquals('CAM-001', $m['model']);
        $t->assertEquals('CAM-001', $m['sku']);
    });

    // ── El fix ──────────────────────────────────────────────────────────
    $t->test('sin GTIN ni SKU, el sku viaja VACIO y solo el model lleva el tecnico', function ($t) {
        $m = TPV_Sync_Identificadores::paraProducto('', '', 48853);

        $t->assertEquals('', $m['sku'],
            'el comerciante no escribio ningun sku: el TPV no debe mostrar uno inventado');
        $t->assert($m['model'] !== '',
            'el model NO puede ir vacio: la API responde "sku or model is required"');
    });

    $t->test('el model tecnico es distinto para cada producto', function ($t) {
        $a = TPV_Sync_Identificadores::paraProducto('', '', 100);
        $b = TPV_Sync_Identificadores::paraProducto('', '', 101);
        $t->assert($a['model'] !== $b['model'],
            'si dos productos comparten model, el segundo choca al crearse');
    });

    $t->test('el model tecnico se puede reconocer como generado por el conector', function ($t) {
        $m = TPV_Sync_Identificadores::paraProducto('', '', 48853);
        $t->assert(TPV_Sync_Identificadores::esModelTecnico($m['model']),
            'hay que poder distinguirlo de un codigo de verdad para no enseñarlo como tal');
        $t->assert(!TPV_Sync_Identificadores::esModelTecnico('8412345678905'),
            'un EAN de verdad no es un model tecnico');
        $t->assert(!TPV_Sync_Identificadores::esModelTecnico('CAM-001'),
            'ni un sku escrito a mano');
    });

    $t->test('los espacios sobrantes no crean identificadores fantasma', function ($t) {
        $m = TPV_Sync_Identificadores::paraProducto('   ', "  \t ", 7);
        $t->assertEquals('', $m['sku'], 'un sku de solo espacios es un sku vacio');
        $t->assert(TPV_Sync_Identificadores::esModelTecnico($m['model']),
            'y el model cae al tecnico, no a una cadena de espacios');
    });

    $t->test('un sku con espacios alrededor se limpia, no se descarta', function ($t) {
        $m = TPV_Sync_Identificadores::paraProducto('', '  CAM-001  ', 7);
        $t->assertEquals('CAM-001', $m['sku'], 'se respeta el sku real, sin los espacios');
        $t->assertEquals('CAM-001', $m['model']);
    });

    // ── La migracion: no se puede duplicar el catalogo ──────────────────
    $t->suite('SKU del producto: limpiar los __WC__ sin duplicar nada');

    $t->test('un sku tecnico viejo se reconoce para poder limpiarlo', function ($t) {
        $t->assert(TPV_Sync_Identificadores::esSkuTecnicoViejo('__WC__48853'),
            'hay que reconocer lo que dejo la version anterior para poder limpiarlo');
        $t->assert(TPV_Sync_Identificadores::esSkuTecnicoViejo('__WC__48808-1'),
            'incluido el de las variaciones que Woo autogenera');
        $t->assert(!TPV_Sync_Identificadores::esSkuTecnicoViejo('CAM-001'),
            'un sku de verdad NO se toca jamas');
        $t->assert(!TPV_Sync_Identificadores::esSkuTecnicoViejo(''),
            'un sku vacio no hay que limpiarlo');
    });

    $t->test('limpiar el sku no toca el model: el vinculo no se rompe', function ($t) {
        // El model es lo que el TPV usa como columna Modelo y lo que se
        // escanea. Si la limpieza lo vaciara, los productos quedarian sin
        // identificador y el siguiente volcado los duplicaria.
        $plan = TPV_Sync_Identificadores::planLimpieza([
            ['tpv_id' => 1, 'sku' => '__WC__48853', 'model' => '__WC__48853'],
        ]);
        $t->assertEquals(1, count($plan), 'hay uno que limpiar');
        $t->assertEquals('', $plan[0]['sku'], 'el sku se vacia');
        $t->assert(!array_key_exists('model', $plan[0]),
            'el model NO se toca: es el identificador que sostiene el vinculo');
    });

    $t->test('los productos con sku de verdad se quedan fuera del plan', function ($t) {
        $plan = TPV_Sync_Identificadores::planLimpieza([
            ['tpv_id' => 1, 'sku' => 'CAM-001',     'model' => 'CAM-001'],
            ['tpv_id' => 2, 'sku' => '__WC__7',     'model' => '__WC__7'],
            ['tpv_id' => 3, 'sku' => '',            'model' => '8412345678905'],
        ]);
        $t->assertEquals(1, count($plan), 'solo el tecnico entra en el plan');
        $t->assertEquals(2, $plan[0]['tpv_id'], 'y es el que lleva __WC__');
    });

    // ── Las variantes ───────────────────────────────────────────────────
    $t->suite('SKU de las variantes: ninguna se queda sin codigo');

    $t->test('el GTIN de la variante manda sobre su sku', function ($t) {
        $c = TPV_Sync_Identificadores::codigoVariante('8412345678905', 'VAR-1');
        $t->assertEquals('8412345678905', $c, 'el codigo escaneable primero');
    });

    $t->test('sin GTIN vale el sku de la variante', function ($t) {
        $t->assertEquals('VAR-1', TPV_Sync_Identificadores::codigoVariante('', 'VAR-1'));
    });

    $t->test('un sku autogenerado por Woo NO se manda tal cual', function ($t) {
        // "__WC__48808-1" es lo que rellena Woo solo cuando el padre no tiene
        // sku. Mandarlo entero al TPV llena la columna CODIGO de un texto que
        // ninguna pistola escanea.
        $c = TPV_Sync_Identificadores::codigoVariante('', '__WC__48808-1', 48907);
        $t->assert(strpos($c, '__WC__') === false,
            'el prefijo tecnico no puede acabar en la columna CODIGO del TPV');
    });

    // ── El ID de la variacion como codigo ────────────────────────────────
    //
    // Correccion (25-09-2026, el usuario): dejar el codigo VACIO cuando no hay
    // gtin ni sku era perder informacion. Su cliente usa los numeros que Woo
    // enseña junto a cada variacion (#48907, #48906...) como codigo de barras:
    // los imprime en la etiqueta y los escanea en el mostrador.
    //
    // Son los post_id de cada variacion, asi que son unicos en toda la tienda
    // y estables mientras la variacion exista. Como codigo de barras valen:
    // un Code128 admite cualquier cadena numerica.
    $t->test('sin gtin ni sku, el codigo es el ID de la variacion', function ($t) {
        $t->assertEquals('48907', TPV_Sync_Identificadores::codigoVariante('', '', 48907),
            'el numero que Woo enseña junto a la variacion es lo que el cliente imprime y escanea');
    });

    $t->test('con un sku autogenerado tambien manda el ID, no el texto', function ($t) {
        $t->assertEquals('48907',
            TPV_Sync_Identificadores::codigoVariante('', '__WC__48808-1', 48907),
            'entre un texto que no escanea y el ID que si, gana el ID');
    });

    $t->test('un sku de verdad sigue ganando al ID', function ($t) {
        $t->assertEquals('VAR-1', TPV_Sync_Identificadores::codigoVariante('', 'VAR-1', 48907),
            'si el comerciante escribio un sku, es el suyo el que manda');
    });

    $t->test('el gtin sigue ganando a todo', function ($t) {
        $t->assertEquals('8412345678905',
            TPV_Sync_Identificadores::codigoVariante('8412345678905', 'VAR-1', 48907),
            'el EAN real es el mejor codigo escaneable');
    });

    $t->test('sin ID utilizable no se inventa un codigo', function ($t) {
        $t->assertEquals('', TPV_Sync_Identificadores::codigoVariante('', '', 0),
            'un 0 no identifica ninguna variacion');
    });

    $t->test('dos variaciones distintas reciben codigos distintos', function ($t) {
        $a = TPV_Sync_Identificadores::codigoVariante('', '', 48907);
        $b = TPV_Sync_Identificadores::codigoVariante('', '', 48906);
        $t->assert($a !== $b, 'si dos variantes comparten codigo, la pistola cobra la que no es');
    });

    $t->test('dos variantes distintas conservan cada una su codigo', function ($t) {
        // El bug: se guardaba solo el PRIMER barcode por combinacion y el
        // resto se perdia. En el TPV se veia como variantes con la columna
        // CODIGO vacia.
        $previo = '';
        $a = TPV_Sync_Identificadores::mejorCodigo($previo, '', 'VAR-1');
        $t->assertEquals('VAR-1', $a, 'la primera pone su codigo');

        $b = TPV_Sync_Identificadores::mejorCodigo($a, '', 'VAR-2');
        $t->assertEquals('VAR-1', $b,
            'dentro de la MISMA combinacion no se pisa el que ya habia');
    });

    $t->test('una variante sin codigo no borra el que ya tenia la combinacion', function ($t) {
        $t->assertEquals('VAR-1', TPV_Sync_Identificadores::mejorCodigo('VAR-1', '', ''),
            'un vacio nunca pisa un codigo bueno');
    });

    $t->test('un GTIN que llega despues mejora un sku que estaba puesto', function ($t) {
        // Si la combinacion tenia el sku y luego aparece una variacion con
        // EAN de verdad, el escaneable gana: es mas util en el mostrador.
        $t->assertEquals('8412345678905',
            TPV_Sync_Identificadores::mejorCodigo('VAR-1', '8412345678905', ''),
            'un codigo escaneable mejora a uno que no lo es');
    });

    // ── Que el fix este ENCHUFADO, no solo escrito ──────────────────────
    $t->suite('SKU: la regla esta enchufada al push');

    $t->test('las dos rutas de push usan la MISMA funcion de identificadores', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        // La logica estaba copiada en dos sitios (push normal :1229 y bulk
        // :955). Arreglar solo una deja el bug vivo segun por donde entre el
        // producto — este plugin ya se rompio asi antes.
        $usos = preg_match_all('/TPV_Sync_Identificadores::paraProducto\s*\(/', $src);
        $t->assertEquals(2, $usos,
            'las dos rutas de push deben mapear los identificadores igual');
    });

    $t->test('ya no queda el fallback __WC__ escrito a mano en el push', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');
        $t->assert(!preg_match('/\$fallback\s*=\s*[\x27"]__WC__[\x27"]/', $src),
            'el literal duplicado es lo que hacia que el fix se aplicara a medias');
    });

    $t->test('las dos rutas de variantes eligen el codigo con la misma regla', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        // Hay dos: un solo atributo ("Talla") y multi-atributo ("Talla-Color",
        // que es el del caso real de pineapplemoda). Revertir SOLO la segunda
        // pasaba desapercibido: un mutante sobrevivió exactamente así.
        $usos = preg_match_all('/TPV_Sync_Identificadores::mejorCodigo\s*\(/', $src);
        $t->assertEquals(2, $usos,
            'un atributo y multi-atributo deben elegir el codigo igual');

        // Y que no quede rastro del "se queda el primero y ya", que es lo que
        // dejaba variantes con la columna CODIGO vacia en el TPV.
        $t->assert(!preg_match('/\[[\x27"]barcode[\x27"]\]\s*===\s*[\x27"][\x27"]/', $src),
            'comprobar que el barcode esta vacio antes de ponerlo = no mejorarlo nunca');
    });

    $t->test('el codigo de variante se calcula con la clase en las dos rutas', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');
        $usos = preg_match_all('/TPV_Sync_Identificadores::codigoVariante\s*\(/', $src);
        $t->assertEquals(2, $usos,
            'las dos rutas deben filtrar igual los sku autogenerados por Woo');
    });

    $t->test('las dos rutas pasan el ID de la variacion, no solo gtin y sku', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        // Sin el ID, una variacion sin gtin ni sku se queda SIN codigo y el
        // comerciante pierde el identificador que ya estaba usando para
        // imprimir sus etiquetas.
        // Se cuentan las LLAMADAS que reciben el ID, no las apariciones de
        // $v->get_id() en el fichero: contando apariciones, un mutante que
        // quitaba el ID de UNA ruta sobrevivia porque las otras lo mantenian.
        $cv = preg_match_all(
            '/codigoVariante\s*\(\s*\$vGtin\s*,\s*\$vSku\s*,\s*\(int\)\s*\$v->get_id\(\)/', $src);
        $t->assertEquals(2, $cv,
            'las dos rutas deben pasar el ID a codigoVariante');

        $mc = preg_match_all(
            '/mejorCodigo\s*\([^;]*\(int\)\s*\$v->get_id\(\)/s', $src);
        $t->assertEquals(2, $mc,
            'las dos rutas deben pasar el ID tambien al elegir el mejor codigo');
    });

    // ── La limpieza de los que YA se subieron ────────────────────────────
    //
    // Los productos nuevos salen limpios, pero los que ya estan en el TPV
    // conservan su "__WC__48853" hasta que alguien lo quite. La funcion que
    // decide QUE limpiar existia y estaba probada... y no la llamaba nadie:
    // codigo muerto que parece trabajo hecho.
    $t->suite('SKU: la limpieza se puede ejecutar de verdad');

    $t->test('hay una funcion que ejecuta la limpieza contra el TPV', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');
        $t->assert((bool) preg_match('/function limpiarSkusTecnicos\s*\(/', $src),
            'sin ejecutor, planLimpieza() es una lista que nadie aplica');
        $t->assert((bool) preg_match('/TPV_Sync_Identificadores::planLimpieza\s*\(/', $src),
            'y tiene que decidir con la funcion ya probada, no con otro criterio');
    });

    $t->test('la limpieza tambien se puede simular antes de aplicarla', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');
        preg_match('/function limpiarSkusTecnicos\(.*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';
        $t->assert($fn !== '', 'no se encontro limpiarSkusTecnicos');

        $t->assert((bool) preg_match('/\$dryRun/', $fn),
            'toca el catalogo de 2.510 productos: hay que poder mirar antes');
        // Y que el corte vaya ANTES de escribir.
        $posCorte  = strpos($fn, 'if ($dryRun)');
        $posEscribe = strpos($fn, '$this->api->patch(');
        $t->assert($posCorte !== false && $posEscribe !== false && $posCorte < $posEscribe,
            'el corte tiene que estar antes de la escritura, no despues');
    });

    $t->test('la limpieza NO toca WooCommerce', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');
        preg_match('/function limpiarSkusTecnicos\(.*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';

        // Regla dura: Woo es la fuente buena, el plugin solo lee de ahi.
        $t->assert(!preg_match('/update_post_meta|wp_update_post|->set_sku\(/', $fn),
            'limpiar el sku del TPV no puede modificar el catalogo de Woo');
    });

    $t->test('la limpieza solo manda el sku, nunca el model', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');
        preg_match('/function limpiarSkusTecnicos\(.*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';

        // El model sostiene el vinculo y es lo que se escanea: vaciarlo
        // dejaria productos sin identificador y el siguiente volcado los
        // duplicaria.
        //
        // Se mira lo que se ESCRIBE (el cuerpo del patch), no el array que se
        // construye para leer — ahi el model aparece legitimamente.
        preg_match_all('/->patch\([^,]+,\s*(\[[^\]]*\])/', $fn, $patches);
        foreach ($patches[1] ?? [] as $cuerpo) {
            $t->assert(!preg_match('/[\x27"]model[\x27"]\s*=>/', $cuerpo),
                'el model no se manda nunca: es el identificador que sostiene el vinculo');
        }
        $t->assert(!empty($patches[1]), 'se esperaba al menos una escritura via patch');
    });
}
