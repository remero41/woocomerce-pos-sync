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
    private const PROFUNDIDAD_MAX = 20;

    public function __construct(private TPV_Sync_API_Client $api, private TPV_Sync_Terminos $terminos) {}

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
}
