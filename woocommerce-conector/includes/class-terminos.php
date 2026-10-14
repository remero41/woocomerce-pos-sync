<?php
/**
 * Las categorías de producto de WooCommerce (taxonomía product_cat), detrás de
 * una interfaz pequeña para poder probar la sincronización sin WordPress
 * (doble en tests/fixtures/terminos_en_memoria.php).
 *
 * El termmeta tpv_category_id es una CACHÉ del enlace: la fuente es la API
 * (api_external_mapping_category). SPEC_categorias_woo_tpv.md §1.
 */
if (!defined('ABSPATH')) exit;

class TPV_Sync_Terminos
{
    public const META = 'tpv_category_id';
    public const TAX  = 'product_cat';

    /** Código del último rechazo de WordPress (p. ej. term_exists). */
    public string $ultimoError = '';

    /** @return list<array{term_id:int,parent:int,name:string,tpv_id:int}> */
    public function todos(): array
    {
        $out = [];
        $terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false]);
        foreach (is_array($terms) ? $terms : [] as $t) {
            $out[] = [
                'term_id' => (int) $t->term_id,
                'parent'  => (int) $t->parent,
                'name'    => (string) $t->name,
                'tpv_id'  => (int) get_term_meta((int) $t->term_id, self::META, true),
            ];
        }
        return $out;
    }

    public function porTpvId(int $tpvId): int
    {
        if ($tpvId <= 0) return 0;
        $ids = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'ids', 'number' => 1,
                          'meta_query' => [['key' => self::META, 'value' => $tpvId]]]);
        return is_array($ids) && $ids ? (int) $ids[0] : 0;
    }

    public function tpvIdDe(int $termId): int
    {
        return (int) get_term_meta($termId, self::META, true);
    }

    public function nombreDe(int $termId): string
    {
        $t = get_term($termId, self::TAX);
        return $t && !is_wp_error($t) ? (string) $t->name : '';
    }

    public function padreDe(int $termId): int
    {
        $t = get_term($termId, self::TAX);
        return $t && !is_wp_error($t) ? (int) $t->parent : 0;
    }

    public function enlazar(int $termId, int $tpvId): void
    {
        update_term_meta($termId, self::META, $tpvId);
    }

    /** term_id nuevo, o 0 si WordPress lo rechaza (motivo en $ultimoError). */
    public function crear(string $nombre, int $padre): int
    {
        $r = wp_insert_term($nombre, self::TAX, ['parent' => $padre]);
        if (is_wp_error($r)) {
            $this->ultimoError = (string) $r->get_error_code();
            return 0;
        }
        return (int) $r['term_id'];
    }

    public function actualizar(int $termId, string $nombre, int $padre): bool
    {
        $r = wp_update_term($termId, self::TAX, ['name' => $nombre, 'parent' => $padre]);
        if (is_wp_error($r)) {
            $this->ultimoError = (string) $r->get_error_code();
            return false;
        }
        return true;
    }

    /** WordPress sube las hijas a la madre del borrado y lo quita de los productos. */
    public function borrar(int $termId): void
    {
        wp_delete_term($termId, self::TAX);
    }

    /** @return list<int> */
    public function deProducto(int $postId): array
    {
        $ids = wp_get_object_terms($postId, self::TAX, ['fields' => 'ids']);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public function ponerAProducto(int $postId, array $termIds): void
    {
        wp_set_object_terms($postId, array_values(array_map('intval', $termIds)), self::TAX, false);
    }

    /** La categoría por defecto de Woo («Sin categorizar»). */
    public function porDefecto(): int
    {
        return (int) get_option('default_product_cat', 0);
    }

    public function olvidarEnlaces(): void
    {
        delete_metadata('term', 0, self::META, '', true);
    }
}
