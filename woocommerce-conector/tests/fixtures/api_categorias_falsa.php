<?php
/**
 * API del TPV en memoria para categorías: GET/POST/PATCH/DELETE /categories y
 * GET/PATCH /products/{id} (solo categorías). Imita lo que fija la suite
 * categories_write de api_tpv: alta idempotente por client_external_id y por
 * nombre normalizado bajo la misma madre; 404 si no existe.
 */
class ApiCategoriasFalsa extends TPV_Sync_API_Client
{
    /** @var array<int,array{parent_id:int,name:string,status:int,external_id:?string}> */
    public array $c = [];
    /** @var array<int,list<int>> product_id del TPV => category_ids */
    public array $productos = [];
    /** @var list<string> «MÉTODO ruta cuerpo-json» de cada llamada */
    public array $llamadas = [];
    public int $sig = 1000;
    /** Si no es 0, toda llamada responde con este estado (p. ej. 403). */
    public int $statusForzado = 0;
    /** @var list<string> */
    public array $caps = ['categories_write'];

    public function __construct() { parent::__construct(); }

    public function tieneCapacidad(string $cap): bool { return in_array($cap, $this->caps, true); }

    private function r(int $s, $data = null): array
    {
        return ['data' => $data, '_status' => $s, '_ok' => $s >= 200 && $s < 300];
    }
    private function fila(int $id): array { return ['category_id' => $id] + $this->c[$id]; }

    public function get(string $path, array $params = []): array
    {
        $this->llamadas[] = "GET $path";
        if ($this->statusForzado) return $this->r($this->statusForzado);
        if ($path === '/categories' && !empty($params['todas'])) {
            return $this->r(200, array_map(fn ($id) => $this->fila($id), array_keys($this->c)));
        }
        if (preg_match('#^/categories/(\d+)$#', $path, $m)) {
            return isset($this->c[(int) $m[1]]) ? $this->r(200, $this->fila((int) $m[1])) : $this->r(404);
        }
        if (preg_match('#^/products/(\d+)$#', $path, $m) && isset($this->productos[(int) $m[1]])) {
            return $this->r(200, ['product_id' => (int) $m[1],
                'categories' => array_map(fn ($c) => ['category_id' => $c], $this->productos[(int) $m[1]])]);
        }
        return $this->r(404);
    }

    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $this->llamadas[] = "POST $path " . json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($this->statusForzado) return $this->r($this->statusForzado);
        $ext = (string) ($body['client_external_id'] ?? '');
        foreach ($this->c as $id => $x) {
            if ($ext !== '' && $x['external_id'] === $ext) return $this->r(200, $this->fila($id));
        }
        foreach ($this->c as $id => $x) {
            if ($x['parent_id'] === (int) ($body['parent_id'] ?? 0)
                && TPV_Sync_Categorias::normalizar($x['name']) === TPV_Sync_Categorias::normalizar((string) ($body['name'] ?? ''))) {
                if ($ext !== '') $this->c[$id]['external_id'] = $ext;
                return $this->r(200, $this->fila($id));
            }
        }
        $id = ++$this->sig;
        $this->c[$id] = ['parent_id' => (int) ($body['parent_id'] ?? 0), 'name' => trim((string) ($body['name'] ?? '')),
                         'status' => 1, 'external_id' => $ext !== '' ? $ext : null];
        return $this->r(201, $this->fila($id));
    }

    public function patch(string $path, array $body = []): array
    {
        $this->llamadas[] = "PATCH $path " . json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($this->statusForzado) return $this->r($this->statusForzado);
        if (preg_match('#^/categories/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (!isset($this->c[$id])) return $this->r(404);
            if (isset($body['name'])) $this->c[$id]['name'] = (string) $body['name'];
            if (array_key_exists('parent_id', $body)) $this->c[$id]['parent_id'] = (int) $body['parent_id'];
            if (isset($body['client_external_id'])) $this->c[$id]['external_id'] = (string) $body['client_external_id'];
            return $this->r(200, $this->fila($id));
        }
        if (preg_match('#^/products/(\d+)$#', $path, $m) && isset($body['category_ids'])) {
            $this->productos[(int) $m[1]] = array_values(array_map('intval', $body['category_ids']));
        }
        return $this->r(200, []);
    }

    public function delete(string $path): array
    {
        $this->llamadas[] = "DELETE $path";
        if (preg_match('#^/categories/(\d+)$#', $path, $m) && isset($this->c[(int) $m[1]])) {
            unset($this->c[(int) $m[1]]);
            return $this->r(204);
        }
        return $this->r(404);
    }
}
