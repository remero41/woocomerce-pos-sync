<?php
declare(strict_types=1);
/**
 * EL PRODUCTO A 0 € Y LAS VARIANTES CON TODO EL PRECIO COMO EXTRA.
 *
 * Visto en pineapplemoda el 25-09-2026, producto 1331 "Tamaño-Color":
 *
 *     Precio (impuestos incluidos): 0,00 €
 *     Talla Unica-Kaki          stock 0   PRECIO EXTRA 24,14   (+24,14 €)
 *     Talla Unica-Marron oscuro stock 1   PRECIO EXTRA 24,14   (+24,14 €)
 *     Talla Unica-Negro         stock 1   PRECIO EXTRA 24,14   (+24,14 €)
 *
 * Causa: en WooCommerce un producto VARIABLE no tiene precio propio — el
 * precio vive en cada variacion. El conector hacia
 *
 *     $basePrice = $product->get_regular_price()   // vacio -> 0
 *     $priceDiff = $vPrice - $basePrice            // = el precio entero
 *
 * asi que el padre viajaba a 0 y cada variante mandaba su precio COMPLETO
 * como sobreprecio. En el TPV el producto queda a 0 €: si alguien vende el
 * padre sin elegir variante, cobra cero.
 *
 * La regla que se fija aqui es la de OpenCart (que es lo que hay debajo del
 * TPV) y la que pidio el usuario:
 *
 *     precio del padre = el MAS BARATO de las variantes
 *     extra de cada variante = su precio - ese minimo   (nunca negativo)
 *
 * Con eso el ejemplo de arriba queda: padre 24,14 € y las tres variantes a +0.
 *
 * SEGUNDO FALLO, que aparecio al leer el codigo para arreglar el primero: el
 * precio del padre pasa por priceForTpv() (que le quita el IVA) pero los
 * extras de las variantes NO pasaban por ningun sitio. Con IVA 21% —el que
 * tiene pineapplemoda— un extra de 10 € con IVA se mandaba como 10 € sin IVA,
 * y el TPV le volvia a sumar el impuesto encima. Los dos lados tienen que
 * medirse en la misma moneda.
 *
 * Tests puros: sin WordPress, sin red. Prueban las funciones que deciden el
 * numero, que es donde vive el fallo.
 */

require_once dirname(__DIR__) . '/includes/class-precio-variantes.php';

function run_precio_variantes_tests(WooTestRunner $t): void
{
    $t->suite('Precio de variantes: el padre vale lo que la mas barata');

    // ── El caso real, clavado ────────────────────────────────────────────
    $t->test('padre sin precio y tres variantes a 24,14 -> padre 24,14 y extras a 0', function ($t) {
        $base = TPV_Sync_Precio_Variantes::precioBase([24.14, 24.14, 24.14], 0.0);
        $t->assertEquals(24.14, $base, 'el padre deja de valer 0');

        foreach ([24.14, 24.14, 24.14] as $p) {
            $e = TPV_Sync_Precio_Variantes::extra($p, $base);
            $t->assertEquals(0.0, $e['price'], 'si todas valen igual, no hay sobreprecio');
            $t->assertEquals('+', $e['price_prefix'], 'el prefijo sigue siendo +');
        }
    });

    $t->test('variantes a 20, 25 y 30 -> padre 20 y extras 0, 5 y 10', function ($t) {
        $base = TPV_Sync_Precio_Variantes::precioBase([25.0, 20.0, 30.0], 0.0);
        $t->assertEquals(20.0, $base, 'manda la mas barata, no la primera de la lista');

        $t->assertEquals(0.0,  TPV_Sync_Precio_Variantes::extra(20.0, $base)['price']);
        $t->assertEquals(5.0,  TPV_Sync_Precio_Variantes::extra(25.0, $base)['price']);
        $t->assertEquals(10.0, TPV_Sync_Precio_Variantes::extra(30.0, $base)['price']);
    });

    // ── Invariante: el TPV no puede recibir un extra negativo ────────────
    $t->test('ningun extra sale negativo', function ($t) {
        $base = TPV_Sync_Precio_Variantes::precioBase([10.0, 50.0], 0.0);
        foreach ([10.0, 50.0] as $p) {
            $e = TPV_Sync_Precio_Variantes::extra($p, $base);
            $t->assert($e['price'] >= 0, "extra negativo para $p: " . $e['price']);
            $t->assertEquals('+', $e['price_prefix'], 'siempre suma, nunca resta');
        }
    });

    $t->test('una variante por debajo del base no genera un extra en negativo', function ($t) {
        // Defensa: si por lo que sea llega un base mas alto que una variante
        // (precio del padre puesto a mano, por ejemplo), el extra se queda en
        // 0 en vez de mandar un "-" que descuadraria el ticket.
        $e = TPV_Sync_Precio_Variantes::extra(15.0, 20.0);
        $t->assertEquals(0.0, $e['price'], 'se corta en 0');
        $t->assertEquals('+', $e['price_prefix'], 'nunca prefijo -');
    });

    // ── No romper lo que ya funcionaba ───────────────────────────────────
    $t->test('si el padre YA tiene precio propio y es menor, gana el suyo', function ($t) {
        // Producto variable con precio de padre puesto a mano a 15 y variantes
        // mas caras: el padre vale 15 y los extras se miden desde ahi.
        $base = TPV_Sync_Precio_Variantes::precioBase([20.0, 25.0], 15.0);
        $t->assertEquals(15.0, $base, 'el precio propio del padre manda si es el menor');
        $t->assertEquals(5.0, TPV_Sync_Precio_Variantes::extra(20.0, $base)['price']);
    });

    $t->test('si el padre tiene un precio MAS ALTO que sus variantes, gana la mas barata', function ($t) {
        $base = TPV_Sync_Precio_Variantes::precioBase([20.0, 25.0], 40.0);
        $t->assertEquals(20.0, $base,
            'un padre mas caro que todas sus variantes dejaria extras negativos');
    });

    $t->test('un producto sin variantes conserva el precio del padre', function ($t) {
        $base = TPV_Sync_Precio_Variantes::precioBase([], 12.5);
        $t->assertEquals(12.5, $base, 'sin variantes no hay minimo que calcular');
    });

    $t->test('variantes sin precio no arrastran el base a 0', function ($t) {
        // Una variacion sin precio en WC no significa "gratis": significa que
        // no lo han rellenado. Si contara como 0, el padre volveria a valer 0
        // y estariamos en el bug original.
        $base = TPV_Sync_Precio_Variantes::precioBase([0.0, 24.14, 30.0], 0.0);
        $t->assertEquals(24.14, $base, 'el 0 de una variante sin rellenar se ignora');
    });

    $t->test('si TODAS las variantes estan a 0 el producto vale 0', function ($t) {
        // Caso legitimo: un producto regalo. No se inventa un precio.
        $base = TPV_Sync_Precio_Variantes::precioBase([0.0, 0.0], 0.0);
        $t->assertEquals(0.0, $base, 'si de verdad todo esta a 0, se respeta');
    });

    // ── Centimos ─────────────────────────────────────────────────────────
    $t->test('los decimales no acumulan error de centimo', function ($t) {
        $base = TPV_Sync_Precio_Variantes::precioBase([19.99, 29.99], 0.0);
        $t->assertEquals(19.99, $base);
        $e = TPV_Sync_Precio_Variantes::extra(29.99, $base);
        // 29.99 - 19.99 en coma flotante da 10.000000000000002 si no se redondea.
        $t->assertEquals(10.0, $e['price'], 'el extra tiene que ser 10,00 exacto');
    });

    $t->test('el extra se redondea a 4 decimales, como la columna del TPV', function ($t) {
        // oc_product_option_value.price es DECIMAL(15,4).
        $e = TPV_Sync_Precio_Variantes::extra(10.123456, 5.0);
        $t->assertEquals(5.1235, $e['price'], 'mas de 4 decimales los corta la BD, no nosotros');
    });

    // ── El segundo fallo: los impuestos ──────────────────────────────────
    $t->suite('Precio de variantes: el extra viaja en la misma moneda que el padre');

    $t->test('si el padre viaja sin IVA, el extra tambien', function ($t) {
        // pineapplemoda tiene IVA 21%. El padre pasaba por priceForTpv() y
        // llegaba neto; el extra iba con IVA incluido. Mezclar los dos hace
        // que el TPV cobre el impuesto dos veces sobre el sobreprecio.
        $t->assert(
            TPV_Sync_Precio_Variantes::extraNecesitaMismoTratamientoQueBase(),
            'el extra y el precio base tienen que medirse igual: o los dos con IVA o los dos sin él'
        );
    });

    // ── Que la conversion este CABLEADA ──────────────────────────────────
    //
    // Y que se haga con WooCommerce, no a mano. priceForTpv() ya envuelve
    // wc_get_price_excluding_tax(), que aplica las reglas fiscales REALES del
    // producto (clase de impuesto, si los precios incluyen IVA, las rates
    // configuradas). Un porcentaje escrito a mano acertaria en el 21% de
    // pineapplemoda y fallaria en cuanto hubiera un reducido, un exento o un
    // envio a otro pais.
    $t->test('los precios de variante se pasan a neto antes de calcular nada', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        preg_match('/private function basePriceForVariants\(.*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';
        $t->assert($fn !== '', 'no se encontro basePriceForVariants');

        // Las DOS lecturas: la de cada variante y la del padre. Exigir solo
        // "que aparezca priceForTpv" no basta — un mutante que devolvia la
        // lista de variantes en bruto sobrevivia, porque la conversion del
        // padre seguia ahi y la aserción se daba por satisfecha.
        $t->assert(
            (bool) preg_match('/\$precios\[\]\s*=\s*\$this->priceForTpv\s*\(\s*\$v\s*,/', $fn),
            'cada precio de variante tiene que pasar a neto antes de buscar el minimo'
        );
        $t->assert(
            (bool) preg_match('/\$precioPadre\s*=\s*\$this->priceForTpv\s*\(\s*\$product\s*,/', $fn),
            'el precio propio del padre compite con los de las variantes: en la misma moneda'
        );
        $t->assert(!preg_match('/\$precios\[\]\s*=\s*\(float\)\s*\(\s*\$v->get_regular_price/', $fn),
            'leer el precio en bruto aqui hace que el TPV aplique el IVA dos veces sobre el sobreprecio');
    });

    $t->test('el precio de CADA variante se convierte, no solo el del padre', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        // Las dos rutas (un atributo y multi-atributo) calculan $vPrice para
        // restarlo del base: las dos tienen que convertirlo igual.
        $usos = preg_match_all('/\$vPrice\s*=\s*[^;]*priceForTpv\s*\(\s*\$v\s*,/', $src);
        $t->assertEquals(2, $usos,
            'un atributo y multi-atributo deben medir el precio de la variante en la misma moneda');

        // Y que el fallback no se convierta dos veces: cuando la variación no
        // tiene precio propio se hereda $basePrice, que YA viene neto.
        $t->assert(!preg_match('/priceForTpv\(\s*\$v\s*,\s*\(float\)\s*\(\s*\$v->get_regular_price\(\)\s*\?:\s*\$basePrice/', $src),
            'convertir el fallback le quitaria el IVA por segunda vez');
    });

    $t->test('la conversion usa las reglas de WooCommerce, no un porcentaje a mano', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        // aNeto() con un tipo fijo seria adivinar: el tipo real depende de la
        // clase de impuesto del producto y de la configuracion de la tienda.
        $t->assert(!preg_match('/TPV_Sync_Precio_Variantes::aNeto\s*\(/', $src),
            'el tipo impositivo no se adivina: lo sabe WooCommerce, que ya lo calcula por producto');
    });

    // ── Que la regla esté CABLEADA, no solo escrita ──────────────────────
    //
    // Los tests de arriba prueban que el número sale bien, pero no que el
    // push lo use: quitando las dos líneas que llaman a la clase desde
    // class-product-sync.php seguían los 287 en verde. Un mutante sobrevivió
    // exactamente así. Sin red, sin WP y sin poder instanciar el producto de
    // WC, la forma honesta de fijarlo es sobre el propio fuente.
    $t->suite('Precio de variantes: la regla está enchufada al push');

    $t->test('el precio base sale de las variantes, no del padre vacio', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        // Las DOS rutas de push (la normal y la bulk) tienen que corregir el
        // precio. Arreglar solo una deja el bug vivo según por dónde entre el
        // producto, que es justo como este plugin ya se rompió antes.
        //
        // Se cuentan las LLAMADAS ($this->...), no las apariciones del nombre:
        // contando el nombre a secas, la definición del método valía por una y
        // un mutante que quitaba una de las dos llamadas sobrevivía.
        $usos = preg_match_all(
            '/\$this->basePriceForVariableProduct\s*\(\s*\$product\s*\)/', $src
        );
        $t->assertEquals(2, $usos,
            'las dos rutas de push (normal y bulk) deben corregir el precio del variable');
    });

    $t->test('los sobreprecios se calculan con la clase, no restando a mano', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

        $t->assert(!preg_match('/\$priceDiff\s*=\s*\$vPrice\s*-\s*\$basePrice/', $src),
            'restar a mano se salta el corte de negativos y el redondeo a 4 decimales');
        $usos = preg_match_all('/TPV_Sync_Precio_Variantes::extra\s*\(/', $src);
        $t->assert($usos >= 2,
            "un atributo y multi-atributo deben usar la misma regla; encontradas: $usos");
    });
}
