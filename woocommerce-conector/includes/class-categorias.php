<?php
/**
 * Piezas puras de la sincronización de categorías Woo ↔ TPV
 * (SPEC_categorias_woo_tpv.md). Sin WordPress ni API: se prueban sin arnés.
 */
if (!defined('ABSPATH')) exit;

final class TPV_Sync_Categorias
{
    /**
     * La regla ÚNICA para decir si dos nombres de categoría son «el mismo».
     * Copia exacta de Api\V1\NombreCategoria::normalizar() (api_tpv): los dos
     * tests usan los mismos vectores. WordPress ya pliega así al comparar
     * nombres de términos (colación), así que esta regla no le contradice.
     */
    public static function normalizar(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = preg_replace('/\p{Mn}+/u', '', $d) ?? $d;
            }
        } else {
            $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
                'ê' => 'e', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
                'ô' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        }
        return $s;
    }

    /**
     * Suma de listas de ids sin repetidos ni ceros, ordenada: la forma de
     * «juntar» categorías de los dos lados sin quitar ninguna.
     *
     * @return list<int>
     */
    public static function juntar(array ...$listas): array
    {
        $todo = [];
        foreach ($listas as $l) {
            foreach ($l as $v) {
                if ((int) $v > 0) {
                    $todo[(int) $v] = true;
                }
            }
        }
        $out = array_keys($todo);
        sort($out);
        return $out;
    }

    /**
     * Unión inicial, SOLO aditiva (spec §4): empareja y dice qué crear en cada
     * lado. No mueve ni renombra nada: dos emparejadas en sitios distintos se
     * quedan así hasta que alguien mueva una. Pasadas, en orden:
     *   (a) enlace ya conocido: termmeta de Woo o external_id de la API;
     *   (b) mismo nombre y madre con el mismo nombre (raíz = raíz);
     *   (c) mismo nombre solo.
     * Cada una se empareja como mucho una vez. «Sin categorizar» no cuenta, y
     * sus hijas se tratan como si estuvieran en la raíz.
     *
     * @param list<array{category_id:int,parent_id:int,name:string,external_id:?string}> $tpv
     * @param list<array{term_id:int,parent:int,name:string,tpv_id:int}> $woo
     * @return array{parejas: list<array{tpv:int,term:int}>, crearEnWoo: list<int>, crearEnTpv: list<int>}
     */
    public static function planUnion(array $tpv, array $woo, int $porDefecto): array
    {
        $woo = array_values(array_filter($woo, fn ($w) => (int) $w['term_id'] !== $porDefecto));
        usort($tpv, fn ($a, $b) => (int) $a['category_id'] <=> (int) $b['category_id']);
        usort($woo, fn ($a, $b) => (int) $a['term_id'] <=> (int) $b['term_id']);

        $nombreTpv = [];
        $padreTpv  = [];
        foreach ($tpv as $c) {
            $nombreTpv[(int) $c['category_id']] = self::normalizar((string) $c['name']);
            $padreTpv[(int) $c['category_id']]  = (int) $c['parent_id'];
        }
        $nombreWoo = [];
        $padreWoo  = [];
        foreach ($woo as $w) {
            $nombreWoo[(int) $w['term_id']] = self::normalizar((string) $w['name']);
            $padreWoo[(int) $w['term_id']]  = (int) $w['parent'] === $porDefecto ? 0 : (int) $w['parent'];
        }
        $madreTpv = fn (int $id): string => $nombreTpv[$padreTpv[$id] ?? 0] ?? '';
        $madreWoo = fn (int $id): string => $nombreWoo[$padreWoo[$id] ?? 0] ?? '';

        $par    = [];   // category_id => term_id
        $usadoW = [];   // term_id => true
        $emparejar = function (int $c, int $w) use (&$par, &$usadoW): void {
            if (!isset($par[$c]) && !isset($usadoW[$w])) {
                $par[$c] = $w;
                $usadoW[$w] = true;
            }
        };
        // (a) enlace ya conocido
        foreach ($woo as $w) {
            $tid = (int) $w['tpv_id'];
            if ($tid > 0 && isset($nombreTpv[$tid])) {
                $emparejar($tid, (int) $w['term_id']);
            }
        }
        foreach ($tpv as $c) {
            $ext = (int) ($c['external_id'] ?? 0);
            if ($ext > 0 && isset($nombreWoo[$ext])) {
                $emparejar((int) $c['category_id'], $ext);
            }
        }
        // (b) nombre + madre, (c) solo nombre
        foreach ([true, false] as $conMadre) {
            foreach ($tpv as $c) {
                $cid = (int) $c['category_id'];
                if (isset($par[$cid])) {
                    continue;
                }
                foreach ($woo as $w) {
                    $wid = (int) $w['term_id'];
                    if (isset($usadoW[$wid]) || $nombreWoo[$wid] !== $nombreTpv[$cid]) {
                        continue;
                    }
                    if ($conMadre && $madreWoo($wid) !== $madreTpv($cid)) {
                        continue;
                    }
                    $emparejar($cid, $wid);
                    break;
                }
            }
        }

        $parejas = [];
        foreach ($par as $c => $w) {
            $parejas[] = ['tpv' => $c, 'term' => $w];
        }
        return [
            'parejas'    => $parejas,
            'crearEnWoo' => self::madresPrimero(array_diff(array_keys($nombreTpv), array_keys($par)), $padreTpv),
            'crearEnTpv' => self::madresPrimero(array_diff(array_keys($nombreWoo), array_keys($usadoW)), $padreWoo),
        ];
    }

    /**
     * Ordena ids para que cada madre que esté en la lista vaya antes que sus
     * hijas. Corta ciclos: en un ciclo el orden da igual, lo que importa es
     * terminar con todas.
     *
     * @param array<int,int> $padres id => id de la madre
     * @return list<int>
     */
    private static function madresPrimero(array $ids, array $padres): array
    {
        $pendiente = array_flip(array_map('intval', $ids));
        ksort($pendiente);
        $out = [];
        $poner = function (int $id, array $camino) use (&$poner, &$pendiente, &$out, $padres): void {
            if (!isset($pendiente[$id]) || isset($camino[$id])) {
                return;
            }
            $camino[$id] = true;
            $madre = $padres[$id] ?? 0;
            if ($madre > 0) {
                $poner($madre, $camino);
            }
            if (isset($pendiente[$id])) {
                unset($pendiente[$id]);
                $out[] = $id;
            }
        };
        foreach (array_keys($pendiente) as $id) {
            $poner((int) $id, []);
        }
        return $out;
    }
}
