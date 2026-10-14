<?php
/**
 * Categorías Woo ↔ TPV en los dos sentidos (SPEC_categorias_woo_tpv.md).
 *
 * Enlace por número: la API guarda (cliente, term_id) ↔ category_id y el
 * termmeta tpv_category_id es su caché local. La marca MARCA decide cuánto se
 * toca:
 *   ''         nada (ni términos ni categorías de productos);
 *   'arbol'    se aplican los avisos category.* y, en los productos, se JUNTA;
 *   'completa' un aviso de producto SUSTITUYE sus categorías.
 */
if (!defined('ABSPATH')) exit;

final class TPV_Sync_Categorias_Sync
{
    public const MARCA  = 'tpv_sync_categorias_unidas';
    public const AVISOS = 'tpv_sync_categorias_avisos';
    /** Anti-eco: mientras se escribe lo que llegó del TPV, los hooks de Woo no envían. */
    public const GUARDA = 'tpv_sync_skip_wc_category_push';
    /** post_id del último producto enlazado ya juntado en la unión. */
    public const CURSOR = 'tpv_sync_categorias_cursor';
    private const PROFUNDIDAD_MAX = 20;

    /** @var callable(int,int):list<array{post_id:int,tpv_id:int}> productos enlazados desde un post_id, por tandas */
    private $productosEnlazados;

    public function __construct(
        private TPV_Sync_API_Client $api,
        private TPV_Sync_Terminos $terminos,
        ?callable $productosEnlazados = null
    ) {
        $this->productosEnlazados = $productosEnlazados ?? static function (int $desde, int $lote): array {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, CAST(meta_value AS UNSIGNED) AS tpv_id FROM {$wpdb->postmeta}
                  WHERE meta_key = '_tpv_product_id' AND CAST(meta_value AS UNSIGNED) > 0 AND post_id > %d
                  ORDER BY post_id LIMIT %d", $desde, $lote), ARRAY_A);
            return array_map(static fn ($r) => ['post_id' => (int) $r['post_id'], 'tpv_id' => (int) $r['tpv_id']], (array) $rows);
        };
    }

    public static function fase(): string
    {
        $f = (string) get_option(self::MARCA, '');
        return in_array($f, ['arbol', 'completa'], true) ? $f : '';
    }

    /** Lo que el panel enseña en «Categorías sin pareja o sin permiso» (últimos 20). */
    public function avisar(string $texto): void
    {
        $l = (array) get_option(self::AVISOS, []);
        $l[] = gmdate('Y-m-d H:i') . ' — ' . $texto;
        update_option(self::AVISOS, array_slice($l, -20), false);
    }

    /** term_id de la categoría del TPV, creándola (con sus madres) si falta. 0 si no se pudo. */
    public function asegurarEnWoo(int $tpvId, int $profundidad = 0): int
    {
        if ($tpvId <= 0 || $profundidad > self::PROFUNDIDAD_MAX) return 0;
        $r = $this->api->get("/categories/$tpvId");
        if (!TPV_Sync_API_Client::fueBien($r) || empty($r['data'])) return 0;
        return $this->escribirEnWoo((array) $r['data'], $profundidad);
    }

    /** Crea o actualiza el término de una fila de la API, con la guarda anti-eco puesta. */
    private function escribirEnWoo(array $c, int $profundidad): int
    {
        $tpvId    = (int) $c['category_id'];
        $madreTpv = (int) ($c['parent_id'] ?? 0);
        $madre    = $madreTpv > 0 ? $this->terminos->porTpvId($madreTpv) : 0;
        if ($madreTpv > 0 && $madre === 0) {
            $madre = $this->asegurarEnWoo($madreTpv, $profundidad + 1);
        }
        $nombre = trim((string) ($c['name'] ?? ''));
        $GLOBALS[self::GUARDA] = true;
        try {
            $termId = $this->terminos->porTpvId($tpvId);
            if ($termId > 0) {
                $this->terminos->actualizar($termId, $nombre, $madre);
                return $termId;
            }
            $termId = $this->terminos->crear($nombre, $madre);
            if ($termId === 0) {
                // Hay otra igual al mismo nivel y ya tiene dueña: no se le roba.
                $this->avisar(sprintf(
                    __('Categoría del TPV «%s» sin pareja en la tienda: ya hay otra igual al mismo nivel (%s).', 'tpv-sync'),
                    $nombre, $this->terminos->ultimoError));
                return 0;
            }
            $this->terminos->enlazar($termId, $tpvId);
        } finally {
            $GLOBALS[self::GUARDA] = false;
        }
        $this->api->patch("/categories/$tpvId", ['client_external_id' => (string) $termId]);
        return $termId;
    }

    /** Avisos category.created / updated / deleted del TPV. */
    public function aplicarAviso(string $evento, int $tpvId): void
    {
        if (self::fase() === '' || $tpvId <= 0) return;
        if ($evento === 'category.deleted') {
            $termId = $this->terminos->porTpvId($tpvId);
            if ($termId > 0) {
                $GLOBALS[self::GUARDA] = true;
                try {
                    $this->terminos->borrar($termId);
                } finally {
                    $GLOBALS[self::GUARDA] = false;
                }
            }
            return;
        }
        $this->asegurarEnWoo($tpvId);
    }

    /** Las categorías de un producto que llegan del TPV: juntar o sustituir. */
    public function ponerCategoriasDesdeTpv(int $postId, array $tpvIds, bool $juntar): void
    {
        $terms = [];
        foreach ($tpvIds as $tid) {
            $termId = $this->terminos->porTpvId((int) $tid) ?: $this->asegurarEnWoo((int) $tid);
            if ($termId > 0) {
                $terms[] = $termId;
            }
        }
        $actuales = TPV_Sync_Categorias::juntar($this->terminos->deProducto($postId));
        $final = $juntar ? TPV_Sync_Categorias::juntar($actuales, $terms) : TPV_Sync_Categorias::juntar($terms);
        if ($final === $actuales) return;
        $GLOBALS[self::GUARDA] = true;
        try {
            $this->terminos->ponerAProducto($postId, $final);
        } finally {
            $GLOBALS[self::GUARDA] = false;
        }
    }

    /** category_id del término, creándolo (con sus madres) en el TPV si falta. 0 si no se pudo. */
    public function asegurarEnTpv(int $termId, int $profundidad = 0): int
    {
        if ($termId <= 0 || $termId === $this->terminos->porDefecto() || $profundidad > self::PROFUNDIDAD_MAX) return 0;
        $ya = $this->terminos->tpvIdDe($termId);
        if ($ya > 0) return $ya;
        return $this->escribirEnTpv($termId, $profundidad);
    }

    /**
     * POST (alta) o PATCH (cambio) del término en el TPV. Un PATCH con 404
     * (el TPV ya no la tiene) recrea y reenlaza; un 409 (choca con una
     * hermana en el TPV) se avisa y el enlace sigue; un fallo transitorio
     * deja el enlace como estaba.
     */
    private function escribirEnTpv(int $termId, int $profundidad): int
    {
        $padreWoo = $this->terminos->padreDe($termId);
        $madre = ($padreWoo > 0 && $padreWoo !== $this->terminos->porDefecto())
            ? $this->asegurarEnTpv($padreWoo, $profundidad + 1) : 0;
        $cuerpo = ['name' => $this->terminos->nombreDe($termId), 'parent_id' => $madre,
                   'client_external_id' => (string) $termId];
        $tpvId = $this->terminos->tpvIdDe($termId);
        if ($tpvId > 0) {
            $r = $this->api->patch("/categories/$tpvId", $cuerpo);
            $estado = (int) ($r['_status'] ?? 0);
            if ($estado !== 404) {
                if ($estado === 409) {
                    $this->avisar(sprintf(
                        __('La categoría «%s» no se pudo cambiar en el TPV: ya hay otra igual al mismo nivel.', 'tpv-sync'),
                        $cuerpo['name']));
                }
                return $tpvId;
            }
        }
        $r = $this->api->post('/categories', $cuerpo);
        if (!TPV_Sync_API_Client::fueBien($r) || empty($r['data']['category_id'])) return 0;
        $tpvId = (int) $r['data']['category_id'];
        $this->terminos->enlazar($termId, $tpvId);
        return $tpvId;
    }

    /** Hooks created_product_cat / edited_product_cat. */
    public function alGuardarTermino(int $termId): void
    {
        if (!empty($GLOBALS[self::GUARDA]) || self::fase() === '' || $termId === $this->terminos->porDefecto()) return;
        $this->escribirEnTpv($termId, 0);
    }

    /** Hook pre_delete_term: el termmeta aún existe (en delete_product_cat ya no). */
    public function antesDeBorrarTermino(int $termId, string $taxonomia): void
    {
        if ($taxonomia !== TPV_Sync_Terminos::TAX || !empty($GLOBALS[self::GUARDA]) || self::fase() === '') return;
        $tpvId = $this->terminos->tpvIdDe($termId);
        if ($tpvId > 0) {
            $this->api->delete("/categories/$tpvId");   // 404 = ya no estaba: hecho
        }
    }

    /** @return list<int> category_id del TPV de las categorías del producto, sin «Sin categorizar». */
    public function categoryIdsParaTpv(int $postId): array
    {
        $out = [];
        foreach ($this->terminos->deProducto($postId) as $termId) {
            $tid = $this->asegurarEnTpv((int) $termId);
            if ($tid > 0) {
                $out[] = $tid;
            }
        }
        return TPV_Sync_Categorias::juntar($out);
    }

    /**
     * Lo que se añade al cuerpo de un envío de producto Woo → TPV. Sin marca,
     * nada; en 'arbol', solo en las altas (en una actualización el TPV
     * conserva las suyas hasta que la unión las junte); en 'completa', siempre.
     */
    public function paraPayload(int $postId, bool $esAlta): array
    {
        $f = self::fase();
        if ($f === '' || ($f === 'arbol' && !$esAlta)) return [];
        return ['category_ids' => $this->categoryIdsParaTpv($postId)];
    }

    // ─── Unión inicial (spec §4) ─────────────────────────────────────────────

    /**
     * Lo que llama el cron (autocurar) en cada vuelta hasta 'completa'. Sin
     * la capacidad en /health no pregunta nada: la API todavía no sabe.
     */
    public function pasoDeUnion(int $lote = 100): void
    {
        if (self::fase() === 'completa') return;
        if (!$this->api->tieneCapacidad('categories_write')) return;
        if (self::fase() === '' && !$this->unirArbol()) return;
        $this->unirProductos($lote);
    }

    /** Une los dos árboles (solo añade) y pone la marca 'arbol'. false si no se pudo. */
    public function unirArbol(): bool
    {
        $r = $this->api->get('/categories', ['todas' => 1]);
        if ((int) ($r['_status'] ?? 0) === 403) {
            $this->avisar(__('La conexión con el TPV no tiene el permiso categories:write: las categorías no se sincronizan hasta que se actualice la API del TPV.', 'tpv-sync'));
            return false;
        }
        if (!TPV_Sync_API_Client::fueBien($r)) return false;
        $tpv  = array_values((array) ($r['data'] ?? []));
        $plan = TPV_Sync_Categorias::planUnion($tpv, $this->terminos->todos(), $this->terminos->porDefecto());
        foreach ($plan['parejas'] as $par) {
            $this->terminos->enlazar($par['term'], $par['tpv']);
            $this->api->patch('/categories/' . $par['tpv'], ['client_external_id' => (string) $par['term']]);
        }
        $porId = array_column($tpv, null, 'category_id');
        foreach ($plan['crearEnWoo'] as $tid) {
            if (isset($porId[$tid])) {
                $this->escribirEnWoo($porId[$tid], 0);
            }
        }
        foreach ($plan['crearEnTpv'] as $termId) {
            $this->asegurarEnTpv((int) $termId);
        }
        update_option(self::MARCA, 'arbol', false);
        update_option(self::CURSOR, 0, false);
        return true;
    }

    /**
     * Una tanda de productos enlazados: junta sus categorías en los dos lados.
     * true cuando no quedan (y pone 'completa').
     */
    public function unirProductos(int $lote): bool
    {
        $desde = (int) get_option(self::CURSOR, 0);
        $tanda = ($this->productosEnlazados)($desde, $lote);
        foreach ($tanda as $e) {
            $r = $this->api->get('/products/' . $e['tpv_id']);
            if (TPV_Sync_API_Client::fueBien($r)) {
                $deTpv = array_map(static fn ($c) => (int) ($c['category_id'] ?? 0), (array) ($r['data']['categories'] ?? []));
                $this->ponerCategoriasDesdeTpv($e['post_id'], $deTpv, true);
                $suma = TPV_Sync_Categorias::juntar($deTpv, $this->categoryIdsParaTpv($e['post_id']));
                if ($suma !== TPV_Sync_Categorias::juntar($deTpv)) {
                    $this->api->patch('/products/' . $e['tpv_id'], ['category_ids' => $suma]);
                }
            }
            update_option(self::CURSOR, $e['post_id'], false);
        }
        if (count($tanda) < $lote) {
            update_option(self::MARCA, 'completa', false);
            return true;
        }
        return false;
    }

    /** Reset o desconexión: los enlaces eran de esa conexión. */
    public function olvidar(): void
    {
        $this->terminos->olvidarEnlaces();
        update_option(self::MARCA, '', false);
        update_option(self::CURSOR, 0, false);
    }
}
