<?php
declare(strict_types=1);
/**
 * Quien manda al reconciliar Woo <-> TPV.
 *
 * Toda la DECISION vive aqui, y aqui no se escribe en ningun sitio: esta clase
 * recibe dos fotos (la de Woo y la del TPV), y devuelve que habria que hacer.
 * Quien ejecuta es class-product-sync.php. La separacion es a proposito: la
 * decision se puede probar entera sin WordPress, sin red y sin base de datos,
 * que es lo que permite fijar el caso de 2.510 productos sin tenerlos.
 *
 * Antes de esto habia DOS reconciliadores con el dueño cableado
 * (reconcile() = "TPV gana siempre"; reconcileBidirectional() = gana la fecha
 * de modificacion mas nueva) y uno se disparaba SOLO desde el panel. Con el
 * catalogo del TPV mal, eso podia machacar Woo, que era la fuente buena.
 *
 * EL DUEÑO ES POR DOMINIO, NO POR PLATAFORMA
 * -----------------------------------------
 * Es el patron de los conectores que funcionan, y no es una sutileza de
 * diseño: los dominios tienen dueños naturales distintos.
 *
 *   stock    -> manda quien vende fisicamente (normalmente el TPV). Es el
 *               unico dato que se decrementa por un hecho del mundo real:
 *               alguien se llevo la prenda del mostrador.
 *   catalogo -> manda quien lo mantiene (nombre, precio, sku, descripcion).
 *
 * Un unico interruptor "manda Woo" aplicado tambien al stock borraria el stock
 * real: subiria lo que Woo cree que queda por encima de lo que de verdad hay
 * tras vender en caja. Por eso los dos dominios se deciden por separado y el
 * catalogo NUNCA puede arrastrar `quantity`.
 *
 * Sobre la fecha de modificacion: se descarta como criterio por defecto. Miente
 * (una reindexacion de Woo toca post_modified sin que nadie edite nada) y el
 * resultado no es predecible para el comerciante.
 */
defined('ABSPATH') || defined('TPV_SYNC_TESTING') || exit;

class TPV_Sync_Reconciler
{
    /** Campos que pertenecen al catalogo. `quantity` NO esta, y no puede estar. */
    const CAMPOS_CATALOGO = ['name', 'price', 'sku'];

    /** Margen para no pelearse por redondeos de centimo al comparar precios. */
    const EPSILON = 0.0001;

    /**
     * El default tiene que ser el que no sorprende a nadie: el TPV sabe el
     * stock porque es donde se vende, y el catalogo lo mantiene quien lo edita.
     */
    public static function politicaPorDefecto(): array
    {
        return ['stock' => 'tpv', 'catalogo' => 'woo'];
    }

    /**
     * Traduce el ajuste que ya existe (`tpv_sync_principal`) a política por
     * dominio, con la posibilidad de afinarla para una pasada concreta.
     *
     * Se deriva en vez de añadir un ajuste nuevo: dos sitios para decir lo
     * mismo acaban discrepando. Valores del ajuste: '' (sin decidir, legacy =
     * manda WC), 'wc', 'tpv'.
     *
     * El ajuste habla del CATÁLOGO, no del stock. Lo dice el propio push que
     * ya existía: "El stock es bidireccional siempre — pero el stock no pasa
     * por aquí". Traducirlo como "manda en todo" le daría a Woo un poder
     * sobre el stock que hoy no tiene, y eso borra lo vendido en caja.
     *
     * $sobrescribe permite pedir otra cosa para una reconciliación concreta
     * (el caso de un catálogo que llegó mal y hay que rehacer desde Woo) sin
     * tocar la configuración global.
     */
    public static function politicaDesdePrincipal(string $principal, array $sobrescribe = []): array
    {
        // Cualquier valor que no sea 'tpv' cae al legacy conocido ('' y 'wc'
        // se comportan igual, que es como ya trataba el push a las
        // instalaciones antiguas).
        $catalogo = $principal === 'tpv' ? 'tpv' : 'woo';

        // El stock solo cambia de dueño si el TPV deja de ser el principal...
        // y ni aun así: quien vende físicamente sigue siendo el TPV. Solo se
        // mueve si se pide explícitamente.
        $politica = ['stock' => 'tpv', 'catalogo' => $catalogo];

        foreach (['stock', 'catalogo'] as $dominio) {
            if (!isset($sobrescribe[$dominio])) continue;
            $valor = (string) $sobrescribe[$dominio];
            // Un valor inválido se ignora: mejor seguir con el que tocaba que
            // dejar el dominio en un estado que nadie ha definido.
            if (in_array($valor, ['tpv', 'woo', 'ninguno'], true)) {
                $politica[$dominio] = $valor;
            }
        }

        return $politica;
    }

    /**
     * Decide que hacer con UN producto presente en los dos lados.
     *
     * $wc y $tpv pueden venir a null cuando el producto solo existe en un lado.
     *
     * Devuelve, por dominio: accion ('push' Woo->TPV, 'pull' TPV->Woo, 'nada'),
     * los campos afectados y el motivo legible. Nunca devuelve 'delete': una
     * reconciliacion no borra catalogo en ninguna de las dos puntas.
     */
    public static function decidir(?array $wc, ?array $tpv, array $politica): array
    {
        $politica += self::politicaPorDefecto();

        // Solo en un lado: se puede crear en el otro, nunca borrar. Borrar por
        // ausencia es como se pierden catalogos enteros cuando el que "falta"
        // en realidad estaba despublicado o fuera del filtro de la consulta.
        if ($wc === null || $tpv === null) {
            $falta   = $wc === null ? 'woo' : 'tpv';
            $accionC = self::accionParaFaltante($falta, (string) $politica['catalogo']);
            $accionS = self::accionParaFaltante($falta, (string) $politica['stock']);
            $motivo  = $falta === 'woo'
                ? 'solo existe en el TPV'
                : 'solo existe en Woo';

            return [
                'discrepa'  => true,
                'catalogo'  => ['accion' => $accionC, 'campos' => [], 'motivo' => $motivo],
                'stock'     => ['accion' => $accionS, 'campos' => [], 'motivo' => $motivo],
            ];
        }

        // ── Catalogo ────────────────────────────────────────────────────
        $camposDistintos = [];
        foreach (self::CAMPOS_CATALOGO as $campo) {
            if (self::difieren($campo, $wc[$campo] ?? null, $tpv[$campo] ?? null)) {
                $camposDistintos[] = $campo;
            }
        }

        $duenoCat = (string) $politica['catalogo'];
        $accionCat = ($camposDistintos === [] || $duenoCat === 'ninguno')
            ? 'nada'
            : ($duenoCat === 'woo' ? 'push' : 'pull');

        // ── Stock, por separado y SIEMPRE por separado ──────────────────
        $qWc  = (float) ($wc['quantity']  ?? 0);
        $qTpv = (float) ($tpv['quantity'] ?? 0);
        $stockDifiere = abs($qWc - $qTpv) > self::EPSILON;

        $duenoStock = (string) $politica['stock'];
        $accionStock = (!$stockDifiere || $duenoStock === 'ninguno')
            ? 'nada'
            : ($duenoStock === 'woo' ? 'push' : 'pull');

        return [
            'discrepa' => $camposDistintos !== [] || $stockDifiere,
            'catalogo' => [
                'accion' => $accionCat,
                // Solo los campos de catalogo. Que `quantity` no pueda colarse
                // aqui es lo que impide que "manda Woo" borre el stock vendido.
                'campos' => $accionCat === 'nada' ? [] : $camposDistintos,
                'motivo' => $camposDistintos === []
                    ? 'catalogo identico'
                    : 'difieren: ' . implode(', ', $camposDistintos),
            ],
            'stock' => [
                'accion' => $accionStock,
                'campos' => $accionStock === 'nada' ? [] : ['quantity'],
                'motivo' => $stockDifiere
                    ? sprintf('stock Woo=%s TPV=%s', $qWc, $qTpv)
                    : 'stock identico',
            ],
        ];
    }

    /**
     * AVISO: hoy NADIE llama a esta función.
     *
     * Quien reconcilia de verdad es reconcileBidirectional(), que hace su
     * propio recorrido (necesita $wpdb y la API página a página para no
     * reventar la memoria con catálogos grandes) y decide con decidir(), que
     * es donde está la regla.
     *
     * Se conserva porque es el recorrido en memoria equivalente, útil para
     * fijar con tests el comportamiento del conjunto sobre catálogos enteros
     * — el caso de los 2.510 productos se prueba aquí sin tener 2.510
     * productos. Si algún día deja de usarse también desde los tests, hay que
     * borrarla: una función probada que nadie llama parece trabajo hecho y no
     * lo es.
     *
     * Recorre los dos catalogos y arma el plan.
     *
     * Con $dryRun (el modo con el que SIEMPRE se entra) no emite ni una sola
     * operacion: cuenta, resume y enseña una muestra. Escribir 2.510 productos
     * sin que nadie haya visto antes que va a cambiar no es reconciliar, es
     * apostar.
     *
     * El emparejamiento es por id externo (client_external_id = post_id de WP,
     * que la API guarda en api_external_mapping), NUNCA por sku. Es lo que
     * permite arreglar los sku sin que los productos se conviertan en huerfanos
     * y se dupliquen en el siguiente volcado.
     */
    public static function planificar(
        array $productosWc,
        array $productosTpv,
        array $politica,
        bool $dryRun = true,
        int $maxEjemplos = 10
    ): array {
        $politica += self::politicaPorDefecto();

        $tpvPorExterno = [];
        foreach ($productosTpv as $p) {
            $ext = (string) ($p['external_id'] ?? $p['tpv_id'] ?? '');
            if ($ext !== '') $tpvPorExterno[$ext] = $p;
        }

        $plan = [
            'dry_run'       => $dryRun,
            'politica'      => $politica,
            'total_cambios' => 0,
            'solo_en_woo'   => 0,
            'solo_en_tpv'   => 0,
            'operaciones'   => [],
            'ejemplos'      => [],
        ];

        $vistos = [];
        foreach ($productosWc as $wc) {
            // El emparejamiento es SOLO por id externo (el post_id de WP, que
            // la API guarda en api_external_mapping). Nada de casar por
            // posicion de array ni por sku: por posicion basta con que un
            // catalogo venga ordenado distinto o le falte uno para emparejar
            // productos que no tienen nada que ver y sobrescribir el que no
            // era; por sku, arreglar los sku convertiria en huerfano a todo el
            // catalogo y el siguiente volcado lo duplicaria.
            $ext = (string) ($wc['external_id'] ?? '');
            $tpv = $ext !== '' ? ($tpvPorExterno[$ext] ?? null) : null;
            if ($tpv !== null) $vistos[(string) ($tpv['tpv_id'] ?? $ext)] = true;

            if ($tpv === null) $plan['solo_en_woo']++;

            self::acumular($plan, $wc, $tpv, $politica, $dryRun, $maxEjemplos);
        }

        foreach ($productosTpv as $tpv) {
            $clave = (string) ($tpv['tpv_id'] ?? '');
            if ($clave !== '' && isset($vistos[$clave])) continue;
            $ext = (string) ($tpv['external_id'] ?? '');
            if ($ext !== '' && isset($vistos[$ext])) continue;
            $plan['solo_en_tpv']++;
            self::acumular($plan, null, $tpv, $politica, $dryRun, $maxEjemplos);
        }

        return $plan;
    }

    // ─── Interno ────────────────────────────────────────────────────────

    private static function acumular(
        array &$plan, ?array $wc, ?array $tpv,
        array $politica, bool $dryRun, int $maxEjemplos
    ): void {
        $d = self::decidir($wc, $tpv, $politica);

        $acciones = [];
        foreach (['catalogo', 'stock'] as $dominio) {
            if ($d[$dominio]['accion'] !== 'nada') $acciones[$dominio] = $d[$dominio];
        }
        if ($acciones === []) return;

        $plan['total_cambios']++;

        if (count($plan['ejemplos']) < $maxEjemplos) {
            $plan['ejemplos'][] = [
                'external_id' => (string) ($wc['external_id'] ?? ''),
                'tpv_id'      => (int) ($tpv['tpv_id'] ?? 0),
                'nombre'      => (string) ($wc['name'] ?? $tpv['name'] ?? ''),
                'motivo'      => implode(' · ', array_map(
                    fn($k) => $k . ': ' . $acciones[$k]['motivo'],
                    array_keys($acciones)
                )),
            ];
        }

        // En simulacion no se emite ni una operacion. Es la diferencia entre
        // enseñar el plan y ejecutarlo.
        if ($dryRun) return;

        foreach ($acciones as $dominio => $a) {
            $plan['operaciones'][] = [
                'dominio'     => $dominio,
                'accion'      => $a['accion'],
                'campos'      => $a['campos'],
                'external_id' => (string) ($wc['external_id'] ?? ''),
                'tpv_id'      => (int) ($tpv['tpv_id'] ?? 0),
            ];
        }
    }

    /**
     * Que hacer con un producto que falta en un lado. Crear si lo manda el que
     * lo tiene; si no, nada. Jamas 'delete'.
     */
    private static function accionParaFaltante(string $ladoQueFalta, string $dueno): string
    {
        if ($dueno === 'ninguno') return 'nada';
        if ($ladoQueFalta === 'woo')  return $dueno === 'tpv' ? 'pull' : 'nada';
        return $dueno === 'woo' ? 'push' : 'nada';
    }

    private static function difieren(string $campo, $a, $b): bool
    {
        if ($campo === 'price') {
            return abs((float) $a - (float) $b) > self::EPSILON;
        }
        return trim((string) $a) !== trim((string) $b);
    }
}
