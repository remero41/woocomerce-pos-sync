<?php
declare(strict_types=1);
/**
 * F4 — panel de estado de sincronizacion: los semaforos.
 *
 * El estandar que cita el informe (§11.3) es concreto:
 *
 *   "El diagnostico se hace con dos señales: marca de ultima sincronizacion +
 *    contador de pendientes en cola. Marca fresca + pendientes creciendo = el
 *    ejecutor va bien pero la cola lo desborda. Marca obsoleta = la
 *    sincronizacion esta rota."
 *
 * Lo que se prueba aqui son los UMBRALES, que es la parte con logica. El
 * render se comprueba mirando la pantalla, no con un test.
 *
 * Inventario previo (regla del plan: no afirmar ausencia sin contarla):
 *   - cola      : ya existia en render_queue_section(), pero enterrada en una
 *                 pestaña rotulada "solo util para diagnostico".
 *   - breaker   : la clase existia y NO se mostraba en ningun sitio del admin.
 *   - ultimo error : la tabla tpv_sync_log ya lo guarda.
 *   - marca de ultima sync correcta : NO EXISTIA. Es la señal que el estandar
 *                 pone primero y sin la cual las otras no se interpretan.
 */

require_once dirname(__DIR__) . '/includes/class-sync-health.php';

function run_sync_health_panel_tests(WooTestRunner $t): void
{
    $t->suite('F4 — semaforo de la marca de ultima sincronizacion');

    // 🟢 <15min · 🟡 <1h · 🔴 >1h — los umbrales del estandar.
    $t->test('sync hace 1 minuto ⇒ ok', function ($t) {
        $t->assertEquals('ok', TPV_Sync_Health::freshness_level(60));
    });
    $t->test('sync hace 14 minutos ⇒ ok (justo dentro)', function ($t) {
        $t->assertEquals('ok', TPV_Sync_Health::freshness_level(14 * 60));
    });
    $t->test('sync hace 15 minutos ⇒ warn (el borde cuenta como fuera)', function ($t) {
        $t->assertEquals('warn', TPV_Sync_Health::freshness_level(15 * 60));
    });
    $t->test('sync hace 59 minutos ⇒ warn', function ($t) {
        $t->assertEquals('warn', TPV_Sync_Health::freshness_level(59 * 60));
    });
    $t->test('sync hace 1 hora ⇒ err', function ($t) {
        $t->assertEquals('err', TPV_Sync_Health::freshness_level(3600));
    });
    $t->test('sync hace 3 dias ⇒ err', function ($t) {
        $t->assertEquals('err', TPV_Sync_Health::freshness_level(3 * 86400));
    });

    // El caso que mas importa: NUNCA se ha sincronizado. No es "hace mucho",
    // es que no hay marca. Un null tratado como 0 daria "hace 56 años" y el
    // nivel correcto por accidente; se distingue a proposito.
    $t->test('sin marca (null) ⇒ err, no una fecha absurda', function ($t) {
        $t->assertEquals('err', TPV_Sync_Health::freshness_level(null));
    });

    $t->suite('F4 — semaforo de la cola');

    $t->test('cola vacia ⇒ ok', function ($t) {
        $t->assertEquals('ok', TPV_Sync_Health::queue_level(0, 0));
    });
    $t->test('49 pendientes ⇒ ok', function ($t) {
        $t->assertEquals('ok', TPV_Sync_Health::queue_level(49, 0));
    });
    $t->test('50 pendientes ⇒ warn (el umbral del estandar)', function ($t) {
        $t->assertEquals('warn', TPV_Sync_Health::queue_level(50, 0));
    });
    $t->test('200 pendientes ⇒ warn', function ($t) {
        $t->assertEquals('warn', TPV_Sync_Health::queue_level(200, 0));
    });

    // Una entrada abandonada es peor que mil pendientes: las pendientes se
    // reintentan solas, las abandonadas ya no. Es dato perdido salvo accion
    // manual, asi que manda sobre el contador.
    $t->test('1 abandonada con cola vacia ⇒ err', function ($t) {
        $t->assertEquals('err', TPV_Sync_Health::queue_level(0, 1));
    });
    $t->test('abandonadas mandan sobre pendientes', function ($t) {
        $t->assertEquals('err', TPV_Sync_Health::queue_level(500, 3));
    });

    $t->suite('F4 — que cuenta como "sincronizacion correcta"');

    // La decision menos obvia del panel. La columna status de tpv_sync_log es
    // TEXTO LIBRE: cada llamante escribe lo que quiere, y hay 9 valores en uso.
    // Definir la marca como status='ok' dejaria fuera reconcile_done, stock o
    // batch, y daria "nunca sincronizado" en una instalacion que va bien.
    // Verificado en el banco vivo: NO tiene ni una fila 'ok' y si un
    // reconcile_done reciente. Por eso el criterio va por la lista de FALLOS,
    // que si es un conjunto cerrado.
    $t->test('la marca se define por los fallos, no por status=ok', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-sync-health.php');

        $t->assert(str_contains($src, 'FAILURE_STATUSES'),
            'debe existir la lista cerrada de estados de fallo');

        // El error concreto que se corrigio: filtrar por igualdad a 'ok'.
        $t->assert(!str_contains($src, "status = 'ok'"),
            "last_ok_age() no puede filtrar por status = 'ok': hay 9 valores en " .
            "uso y solo uno es 'ok' (verificado en el banco vivo)");

        // Mirar SOLO la constante, no el fichero entero: el comentario de
        // encima enumera los 9 estados para explicar el porque, y un
        // str_contains sobre todo el fuente los encontraba ahi. Mismo error
        // que ya se colo una vez con flagInvalidCredentials.
        preg_match('/FAILURE_STATUSES\s*=\s*\[(.*?)\];/s', $src, $m);
        $t->assert(!empty($m[1]), 'no se pudo leer la constante FAILURE_STATUSES');
        $lista = $m[1];

        // Los estados sanos que NO son 'ok' tienen que contar como correctos.
        foreach (['reconcile_done', 'stock', 'batch', 'skip', 'warn'] as $sano) {
            $t->assert(!str_contains($lista, "'" . $sano . "'"),
                "'$sano' es una sincronizacion correcta: no debe estar en " .
                'FAILURE_STATUSES');
        }

        // Los que si son fallo deben estar.
        foreach (['error', 'reconcile_error'] as $fallo) {
            $t->assert(str_contains($lista, "'" . $fallo . "'"),
                "'$fallo' debe contar como fallo");
        }
    });

    $t->suite('F4 — los textos que ve el comerciante');

    // Ambos defectos se vieron SOLO al poner datos malos en el banco (88
    // pendientes + 1 abandonada). Con la instalacion tranquila no salian.

    // 1) Concordancia: con 1 abandonada salia "1 abandonadas".
    $t->test('el contador de abandonadas concuerda en singular', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        // Lo que NO puede estar es el plural en un __() suelto, que ignora la
        // cantidad. Dentro de _n() la forma plural SI debe aparecer: es uno de
        // sus dos argumentos.
        $t->assert(!str_contains($src, "esc_html__(' · %d abandonadas'"),
            'texto fijo en plural: con 1 abandonada escribiria "1 abandonadas"');
        $t->assert(str_contains($src, "_n(' · %d abandonada', ' · %d abandonadas'"),
            'debe usar _n() con las dos formas para que 1 concuerde en singular');
    });

    // 2) La ruta que se le da al comerciante tiene que existir tal cual. El
    //    reintento NO esta en la pestaña Log a secas: esta dentro de un
    //    <details> PLEGADO rotulado "Diagnostico avanzado: cola de
    //    reintentos". Decir solo "pestaña Log" manda a una pantalla donde no
    //    se ve nada, y es el unico mensaje del panel que exige accion manual.
    $t->test('la ruta al reintento manual nombra el desplegable, no solo la pestaña', function ($t) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-admin.php');
        // El rotulo real del <details> que contiene render_queue_section().
        $t->assert(str_contains($src, 'Diagnóstico avanzado: cola de reintentos'),
            'el rotulo del desplegable debe seguir siendo este; si cambia, ' .
            'actualizar tambien el texto del diagnostico');
        $t->assert(!str_contains($src, 'reintento manual desde la pestaña Log'),
            'ruta incompleta: en la pestaña Log el bloque esta plegado');
    });

    $t->suite('F4 — el diagnostico combinado del estandar');

    // Las dos señales juntas son las que distinguen los dos fallos, que es
    // el motivo entero de que el estandar pida ambas.
    $t->test('marca fresca + cola creciendo ⇒ "la cola desborda al ejecutor"', function ($t) {
        $d = TPV_Sync_Health::diagnose(60, 300, 0);
        $t->assertEquals('backlog', $d['kind']);
    });
    $t->test('marca obsoleta ⇒ "la sincronizacion esta rota"', function ($t) {
        $d = TPV_Sync_Health::diagnose(7200, 0, 0);
        $t->assertEquals('stalled', $d['kind']);
    });
    $t->test('marca fresca + cola vacia ⇒ sano', function ($t) {
        $d = TPV_Sync_Health::diagnose(60, 0, 0);
        $t->assertEquals('healthy', $d['kind']);
    });
    $t->test('marca obsoleta Y cola creciendo ⇒ manda "rota"', function ($t) {
        // Si el ejecutor esta parado, el backlog es consecuencia, no la causa.
        $d = TPV_Sync_Health::diagnose(7200, 300, 0);
        $t->assertEquals('stalled', $d['kind']);
    });
    $t->test('abandonadas ⇒ pide intervencion aunque todo lo demas este verde', function ($t) {
        $d = TPV_Sync_Health::diagnose(60, 0, 2);
        $t->assertEquals('dropped', $d['kind']);
    });
}
