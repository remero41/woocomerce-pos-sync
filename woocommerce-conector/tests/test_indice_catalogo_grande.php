<?php
declare(strict_types=1);
/**
 * Catálogos grandes: el índice del TPV que usan el enlace y la autocuración
 * se cortaba a 100 páginas. La API recorta per_page a 100 (no a 200 como se
 * pedía), así que veía 10.000 productos. Un producto de Woo cuyo gemelo del
 * TPV estaba más allá no se encontraba y asegurarEnTpv() lo daba de ALTA:
 * un segundo producto con el mismo código de barras en la caja (la API no
 * rechaza un model repetido), o un 422 por sku y el pedido retenido para
 * siempre.
 */
require_once __DIR__ . '/test_pedidos_sin_enlace.php';

class ApiCatalogoGrande extends TPV_Sync_API_Client
{
    public array $altas = [];
    public array $busquedas = [];
    public int $paginas = 0;
    public function __construct(private int $total, private array $extra = []) {}
    public function get(string $path, array $params = []): array
    {
        if ($path !== '/products') return [];
        if (isset($params['search'])) {
            $this->busquedas[] = $params['search'];
            $hits = array_values(array_filter($this->extra, fn ($r) => $r['model'] === $params['search'] || $r['sku'] === $params['search']));
            return TPV_Sync_API_Client::decide(200, ['data' => $hits, 'meta' => ['cursor' => null]]);
        }
        $this->paginas++;
        $desde = (int) ($params['cursor'] ?? 0);
        $porPagina = min(100, (int) ($params['per_page'] ?? 20));   // como Input::pagination
        $filas = [];
        for ($id = $desde + 1; $id <= min($this->total, $desde + $porPagina); $id++) {
            $filas[] = ['product_id' => $id, 'model' => "M$id", 'sku' => ''];
        }
        $siguiente = $desde + $porPagina < $this->total ? (string) ($desde + $porPagina) : null;
        return TPV_Sync_API_Client::decide(200, ['data' => $filas, 'meta' => ['cursor' => $siguiente]]);
    }
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        if ($path === '/products') { $this->altas[] = $body; return TPV_Sync_API_Client::decide(201, ['data' => ['product_id' => 99999]]); }
        return [];
    }
    public function patch(string $path, array $body = []): array { return TPV_Sync_API_Client::decide(200, ['data' => []]); }
}

function run_indice_catalogo_grande_tests(WooTestRunner $t): void
{
    $t->suite('Catálogo grande: el enlace encuentra el gemelo, no lo duplica');

    $t->test('un gemelo en la página 120 (producto 12.000) se enlaza sin dar de alta', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wc_products'][8310] = new ProductoWooFalso(8310, 'M12000', '3.12', true, 5.0);
        $api = new ApiCatalogoGrande(12500);
        $s = new TPV_Sync_Product_Sync($api);
        $tpvId = $s->asegurarEnTpv(8310);
        $t->assertEquals(12000, $tpvId, 'enlazado a su gemelo del TPV');
        $t->assertEquals([], $api->altas, 'sin alta: habría duplicado el código de barras');
        $t->assert($api->paginas >= 125, 'recorre el catálogo entero (' . $api->paginas . ' páginas)');
    });

    $t->test('si el índice no lo ve, se pregunta por su código antes de dar de alta', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wc_products'][8310] = new ProductoWooFalso(8310, 'NUEVO-1', '3.12', true, 5.0);
        // El índice (100 productos) no lo tiene, pero la búsqueda sí (alta en el TPV hace un momento).
        $api = new ApiCatalogoGrande(100, [['product_id' => 777, 'model' => 'NUEVO-1', 'sku' => '']]);
        $tpvId = (new TPV_Sync_Product_Sync($api))->asegurarEnTpv(8310);
        $t->assertEquals(777, $tpvId);
        $t->assertEquals([], $api->altas);
        $t->assertEquals(['NUEVO-1'], $api->busquedas);
    });

    $t->test('una coincidencia parcial de la búsqueda no vale: se da de alta', function ($t) {
        pedidos_tienda();
        $GLOBALS['__wc_products'][8310] = new ProductoWooFalso(8310, 'ABC', '3.12', true, 5.0);
        $api2 = new class(10) extends ApiCatalogoGrande {
            public function get(string $path, array $params = []): array {
                if (isset($params['search'])) { $this->busquedas[] = $params['search'];
                    return TPV_Sync_API_Client::decide(200, ['data' => [['product_id' => 5, 'model' => 'ABC-2', 'sku' => 'ABCD']], 'meta' => []]); }
                return parent::get($path, $params);
            }
        };
        (new TPV_Sync_Product_Sync($api2))->asegurarEnTpv(8310);
        $t->assertEquals(1, count($api2->altas), 'LIKE no es igualdad: alta');
    });
}
