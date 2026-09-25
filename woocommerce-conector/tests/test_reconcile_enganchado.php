<?php
declare(strict_types=1);
/**
 * QUE EL RECONCILIADOR ESTE ENCHUFADO, NO SOLO ESCRITO.
 *
 * Es la leccion que ya mordio dos veces en esta misma sesion: se puede tener
 * una clase perfectamente probada y perfectamente desconectada. En F1 un
 * mutante quito las dos llamadas al calculo de precios y los 287 tests
 * siguieron verdes.
 *
 * Aqui se fija que reconcileBidirectional() decide con TPV_Sync_Reconciler y
 * no con lo que hacia antes: comparar `post_modified` contra `date_modified`
 * con 60 segundos de margen. Ese criterio se descarta como decisor por
 * defecto porque las fechas mienten — una reindexacion de Woo toca
 * post_modified sin que nadie haya editado nada — y el comerciante no puede
 * predecir el resultado.
 *
 * Sin WordPress no se puede ejecutar reconcileBidirectional() de verdad (lee
 * $wpdb y llama a la API), asi que lo que se asserta es sobre el fuente. No es
 * lo ideal, pero es honesto: fija exactamente la propiedad que los mutantes
 * demostraron que se pierde en silencio.
 */

function run_reconcile_enganchado_tests(WooTestRunner $t): void
{
    $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-product-sync.php');

    $t->suite('La reconciliacion usa el reconciliador, no la fecha');

    $t->test('reconcileBidirectional pide la politica en vez de suponerla', function ($t) use ($src) {
        $t->assert(
            (bool) preg_match('/TPV_Sync_Reconciler::politicaDesdePrincipal\s*\(/', $src),
            'la pasada tiene que arrancar de una politica explicita'
        );
    });

    $t->test('quien decide por producto es el reconciliador', function ($t) use ($src) {
        $t->assert(
            (bool) preg_match('/TPV_Sync_Reconciler::decidir\s*\(/', $src),
            'la decision por producto vive en el reconciliador, no repartida por aqui'
        );
    });

    $t->test('ya no se decide comparando fechas de modificacion', function ($t) use ($src) {
        // El criterio viejo: $tpvUpdated > $wcUpdated + 60 / al reves.
        $t->assert(
            !preg_match('/\$tpvUpdated\s*>\s*\$wcUpdated\s*\+\s*60/', $src),
            'las fechas mienten: una reindexacion de Woo toca post_modified sin que nadie edite'
        );
        $t->assert(
            !preg_match('/\$wcUpdated\s*>\s*\$tpvUpdated\s*\+\s*60/', $src),
            'y por el otro lado igual'
        );
    });

    $t->test('el stock deja de estar cableado a "TPV gana siempre"', function ($t) use ($src) {
        // Antes: el stock se corregia sin mirar politica ninguna, con el
        // comentario "Stock: TPV gana siempre (no se modifica con
        // post_modified)". Que el TPV sea el default esta bien; que sea
        // incondicional impide el modo auditoria y cualquier otra eleccion.
        $t->assert(
            !preg_match('/Stock:\s*TPV gana siempre/i', $src),
            'el default puede ser el TPV, pero tiene que pasar por la politica'
        );
    });

    $t->test('la reconciliacion admite simular sin escribir', function ($t) use ($src) {
        $t->assert(
            (bool) preg_match('/function reconcileBidirectional\s*\([^)]*dryRun/i', $src),
            'sobre 2.510 productos hay que poder ver el plan antes de aplicarlo'
        );
    });

    $t->test('simular de verdad no escribe: el dry-run corta antes', function ($t) use ($src) {
        // Que exista el parametro no basta; hay que usarlo para cortar.
        $t->assert(
            (bool) preg_match('/if\s*\(\s*\$dryRun\s*\)/', $src),
            'un parametro que no se lee es un interruptor pintado'
        );
    });

    // ── La propiedad de verdad, no el texto ──────────────────────────────
    //
    // El test de arriba se conforma con que exista UN corte. Con cuatro en la
    // funcion, se podia romper el del bucle principal —el que protege los
    // 2.510 productos— y seguia verde: un mutante sobrevivio exactamente asi,
    // y era el peor de todos (una "simulacion" que escribe).
    //
    // Lo que hay que fijar es que NINGUNA escritura quede por delante de su
    // corte. Se comprueba recorriendo el cuerpo de la funcion: dentro de cada
    // bloque, la primera escritura tiene que ir despues de un `if ($dryRun)`.
    $t->test('ninguna escritura del reconciliador queda por delante de su corte', function ($t) use ($src) {
        preg_match('/function reconcileBidirectional\(.*?\n    \}/s', $src, $m);
        $fn = $m[0] ?? '';
        $t->assert($fn !== '', 'no se encontro la funcion');

        // Se recorre linea a linea llevando la cuenta de si el flujo actual ya
        // paso por un corte. Cada `foreach` abre un recorrido nuevo, asi que
        // reinicia la proteccion: cada bucle tiene que cortar por su cuenta.
        //
        // (Un preg_split por bloques NO vale: devolvia un unico bloque de 199
        // lineas, con lo que bastaba UNA escritura y UN corte en cualquier
        // orden. Por eso el mutante sobrevivia.)
        $escrituras = '/(update_post_meta\(|\$this->upsert\(|\$this->update_stock\(|'
                    . '\$this->push_wc_product_to_tpv\(|\$this->api->patch\()/';

        // La proteccion se reinicia con cada `foreach` Y con cada `continue`
        // que cierra una iteracion: un corte solo protege al camino que pasa
        // por el. Sin lo segundo, el corte de la rama "producto nuevo" (que
        // termina en continue) parecia cubrir tambien al bucle principal, y
        // ahi se escondia el mutante.
        $sinProteger = [];
        $protegido   = false;
        foreach (explode("\n", $fn) as $n => $linea) {
            $t_ = trim($linea);
            if (preg_match('/^foreach\s*\(/', $t_))      { $protegido = false; }
            if (strpos($t_, 'if ($dryRun) continue;') !== false) { $protegido = true; }
            elseif ($t_ === 'continue;')                 { $protegido = false; }

            if (!preg_match($escrituras, $linea, $mm)) continue;
            if (!$protegido) {
                $sinProteger[] = trim($mm[1]) . ' (linea ' . ($n + 1) . ' de la funcion)';
            }
        }

        $t->assert($sinProteger === [],
            'una simulacion que escribe es peor que no tener simulacion; '
            . 'escriben sin pasar por el corte: ' . implode(' · ', $sinProteger));
    });
}
