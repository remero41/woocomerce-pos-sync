<?php
/**
 * Doble en memoria de TPV_Sync_Terminos que se porta como WordPress en lo que
 * importa (lo fija tests/test_terminos_en_memoria.php).
 */
final class TerminosEnMemoria extends TPV_Sync_Terminos
{
    /** @var array<int,array{parent:int,name:string,tpv_id:int}> */
    public array $t = [];
    /** @var array<int,list<int>> post_id => term_ids */
    public array $p = [];
    public int $defecto = 0;
    private int $sig = 100;

    public function todos(): array
    {
        $o = [];
        foreach ($this->t as $id => $x) {
            $o[] = ['term_id' => $id] + $x;
        }
        return $o;
    }
    public function porTpvId(int $tpvId): int
    {
        foreach ($this->t as $id => $x) {
            if ($tpvId > 0 && $x['tpv_id'] === $tpvId) return $id;
        }
        return 0;
    }
    public function tpvIdDe(int $termId): int { return $this->t[$termId]['tpv_id'] ?? 0; }
    public function nombreDe(int $termId): string { return $this->t[$termId]['name'] ?? ''; }
    public function padreDe(int $termId): int { return $this->t[$termId]['parent'] ?? 0; }
    public function enlazar(int $termId, int $tpvId): void
    {
        if (isset($this->t[$termId])) $this->t[$termId]['tpv_id'] = $tpvId;
    }
    public function crear(string $nombre, int $padre): int
    {
        foreach ($this->t as $x) {
            if ($x['parent'] === $padre
                && TPV_Sync_Categorias::normalizar($x['name']) === TPV_Sync_Categorias::normalizar($nombre)) {
                $this->ultimoError = 'term_exists';
                return 0;
            }
        }
        $id = ++$this->sig;
        $this->t[$id] = ['parent' => $padre, 'name' => trim($nombre), 'tpv_id' => 0];
        return $id;
    }
    public function actualizar(int $termId, string $nombre, int $padre): bool
    {
        if (!isset($this->t[$termId])) return false;
        $this->t[$termId]['name'] = trim($nombre);
        $this->t[$termId]['parent'] = $padre;
        return true;
    }
    public function borrar(int $termId): void
    {
        $madre = $this->t[$termId]['parent'] ?? 0;
        foreach ($this->t as $id => $x) {
            if ($x['parent'] === $termId) $this->t[$id]['parent'] = $madre;
        }
        unset($this->t[$termId]);
        foreach ($this->p as $pid => $ids) {
            $this->p[$pid] = array_values(array_diff($ids, [$termId]));
        }
    }
    public function deProducto(int $postId): array { return $this->p[$postId] ?? []; }
    public function ponerAProducto(int $postId, array $termIds): void
    {
        $this->p[$postId] = array_values(array_map('intval', $termIds));
    }
    public function porDefecto(): int { return $this->defecto; }
    public function olvidarEnlaces(): void
    {
        foreach ($this->t as $id => $x) {
            $this->t[$id]['tpv_id'] = 0;
        }
    }
}
