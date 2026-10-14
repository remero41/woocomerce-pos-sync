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
}
