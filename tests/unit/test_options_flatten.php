<?php
/**
 * Tests standalone para build_options_for_tpv (incluye aplanamiento
 * multi-atributo y skip de precio negativo). No requiere PHPUnit ni WP.
 *
 * Ejecutar:
 *   php tests/unit/test_options_flatten.php
 */

declare(strict_types=1);

// Definimos PRIMERO nuestras versiones (con function_exists guard) para que
// cuando wp-stubs.php intente declarar las suyas, pierda. wp-stubs.php usa
// `if (!function_exists(...))` solo en algunas funciones; wc_get_product es
// una de ellas, así que esto sí gana.
$GLOBALS['_test_wc_products']  = [];
$GLOBALS['_test_attr_labels']  = [];

if (!function_exists('wc_get_product')) {
    function wc_get_product($id) {
        return $GLOBALS['_test_wc_products'][$id] ?? null;
    }
}
if (!function_exists('wc_attribute_label')) {
    function wc_attribute_label(string $name, $product = null): string {
        return $GLOBALS['_test_attr_labels'][$name] ?? ucfirst($name);
    }
}

// Cargar wp-stubs.php DESPUÉS — sus funciones sin guard (get_option, etc.)
// se declararán; las que tienen guard (wc_get_product) no nos pisan.
require_once __DIR__ . '/../wp-stubs.php';

require_once __DIR__ . '/../../includes/class-circuit-breaker.php';
require_once __DIR__ . '/../../includes/class-secrets.php';
require_once __DIR__ . '/../../includes/class-api-client.php';
require_once __DIR__ . '/../../includes/class-queue.php';
require_once __DIR__ . '/../../includes/class-product-sync.php';

// Mock product/variation
class FakeWcProduct {
    public function __construct(
        private int $id,
        private string $regularPrice,
        private array $children = [],
        private string $type = 'variable',
        private array $attributes = [],
        private ?int $stockQty = null,
        private string $sku = '',
        private string $status = 'publish'
    ) {}
    public function get_id(): int { return $this->id; }
    public function get_children(): array { return $this->children; }
    public function get_regular_price(): string { return $this->regularPrice; }
    public function get_attributes(): array { return $this->attributes; }
    public function get_stock_quantity() { return $this->stockQty; }
    public function get_sku(): string { return $this->sku; }
    public function get_status(): string { return $this->status; }
    public function get_gallery_image_ids(): array { return []; }
    public function is_type(string $t): bool { return $this->type === $t; }
}

class TestRunner {
    public int $passed = 0;
    public int $failed = 0;
    public array $failures = [];

    public function run(string $name, callable $fn): void {
        try {
            $fn($this);
            $this->passed++;
            echo "  \033[32m✓\033[0m $name\n";
        } catch (Throwable $e) {
            $this->failed++;
            $this->failures[] = "$name: " . $e->getMessage();
            echo "  \033[31m✗\033[0m $name\n    \033[33m→ " . $e->getMessage() . "\033[0m\n";
        }
    }
    public function eq($expected, $actual, string $msg = ''): void {
        if ($expected !== $actual) {
            throw new RuntimeException(($msg ? $msg . ': ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
    public function assert(bool $c, string $msg = ''): void {
        if (!$c) throw new RuntimeException($msg ?: 'Assertion failed');
    }
}

function invokePrivate(object $obj, string $method, array $args) {
    $r = new ReflectionMethod($obj, $method);
    $r->setAccessible(true);
    return $r->invoke($obj, ...$args);
}

$t = new TestRunner();

$api  = new TPV_Sync_API_Client();
$sync = new TPV_Sync_Product_Sync($api);

echo "\n\033[1m── Test Suite: build_options_for_tpv ──\033[0m\n";

// Caso 1: 1 atributo (Sabor) — pasa por build_options_single_attr
$t->run('1 atributo: 3 sabores con barcode', function ($t) use ($sync) {
    $GLOBALS['_test_attr_labels'] = ['sabor' => 'Sabor'];
    $GLOBALS['_stub_postmeta'] = [
        201 => ['_global_unique_id' => 'GTIN-AGUACATE'],
        202 => ['_global_unique_id' => 'GTIN-MELOCOTON'],
        203 => [],
    ];
    $GLOBALS['_test_wc_products'] = [
        201 => new FakeWcProduct(201, '0', [], 'variation', ['sabor' => 'aguacate'], 5, 'SKU-201'),
        202 => new FakeWcProduct(202, '0', [], 'variation', ['sabor' => 'melocoton'], 5, 'SKU-202'),
        203 => new FakeWcProduct(203, '0', [], 'variation', ['sabor' => 'mora'], 4, ''),
    ];
    $parent = new FakeWcProduct(200, '3.5', [201, 202, 203], 'variable');

    $opts = invokePrivate($sync, 'build_options_for_tpv', [$parent]);

    $t->eq(1, count($opts), '1 sola opción');
    $t->eq('Sabor', $opts[0]['name']);
    $t->eq(3, count($opts[0]['values']), '3 valores');

    $byName = array_column($opts[0]['values'], null, 'name');
    $t->eq('GTIN-AGUACATE', $byName['aguacate']['barcode'] ?? null);
    $t->eq('GTIN-MELOCOTON', $byName['melocoton']['barcode'] ?? null);
    $t->assert(!isset($byName['mora']['barcode']), 'mora sin GTIN ni SKU → sin barcode');

    $t->eq(5, $byName['aguacate']['quantity']);
    $t->eq(4, $byName['mora']['quantity']);
});

// Caso 2: 2 atributos (Talla × Color) — APLANA combinaciones reales
$t->run('Multi-atributo aplana a 1 opción combinada con guion', function ($t) use ($sync) {
    $GLOBALS['_test_attr_labels'] = ['talla' => 'Talla', 'color' => 'Color'];
    $GLOBALS['_stub_postmeta'] = [
        301 => ['_global_unique_id' => 'BC-XS-ROJO'],
        302 => ['_global_unique_id' => 'BC-S-VERDE'],
        303 => ['_global_unique_id' => 'BC-M-ROJO'],
    ];
    $GLOBALS['_test_wc_products'] = [
        301 => new FakeWcProduct(301, '0', [], 'variation', ['talla' => 'xs', 'color' => 'rojo'],   3, 'XS-R'),
        302 => new FakeWcProduct(302, '0', [], 'variation', ['talla' => 's',  'color' => 'verde'],  2, 'S-V'),
        303 => new FakeWcProduct(303, '0', [], 'variation', ['talla' => 'm',  'color' => 'rojo'],   1, 'M-R'),
    ];
    $parent = new FakeWcProduct(300, '10', [301, 302, 303], 'variable');

    $opts = invokePrivate($sync, 'build_options_for_tpv', [$parent]);

    $t->eq(1, count($opts), '1 opción combinada');
    $t->eq('Talla-Color', $opts[0]['name'], 'Nombre combinado con guion');
    $t->eq(3, count($opts[0]['values']), '3 combinaciones reales (no producto cartesiano de 6)');

    $byName = array_column($opts[0]['values'], null, 'name');
    $t->assert(isset($byName['xs-rojo']),  'xs-rojo existe');
    $t->assert(isset($byName['s-verde']),  's-verde existe');
    $t->assert(isset($byName['m-rojo']),   'm-rojo existe');
    $t->assert(!isset($byName['xs-verde']), 'xs-verde NO existe (no había variación WC)');
    $t->assert(!isset($byName['s-rojo']),   's-rojo NO existe');
    $t->assert(!isset($byName['m-verde']),  'm-verde NO existe');

    $t->eq(3, $byName['xs-rojo']['quantity']);
    $t->eq('BC-XS-ROJO', $byName['xs-rojo']['barcode']);
    $t->eq('BC-M-ROJO',  $byName['m-rojo']['barcode']);
});

// Caso 3: orden estable de atributos — independiente del orden en variaciones
$t->run('Orden de atributos estable según primera variación', function ($t) use ($sync) {
    $GLOBALS['_test_attr_labels'] = ['talla' => 'Talla', 'color' => 'Color'];
    $GLOBALS['_stub_postmeta'] = [];
    $GLOBALS['_test_wc_products'] = [
        401 => new FakeWcProduct(401, '0', [], 'variation', ['talla'=>'s','color'=>'rojo'], 1),
        402 => new FakeWcProduct(402, '0', [], 'variation', ['color'=>'verde','talla'=>'m'], 1),
    ];
    $parent = new FakeWcProduct(400, '5', [401, 402], 'variable');

    $opts = invokePrivate($sync, 'build_options_for_tpv', [$parent]);

    $t->eq('Talla-Color', $opts[0]['name'], 'Orden estable: talla primero, color segundo');
    $byName = array_column($opts[0]['values'], null, 'name');
    $t->assert(isset($byName['s-rojo']),   'Combinación s-rojo (orden talla-color)');
    $t->assert(isset($byName['m-verde']),  'Combinación m-verde (NO verde-m)');
});

// Caso 4: variación con atributo "Any" (slug vacío) en multi-atributo se
// salta — el TPV no puede modelar wildcards de combinación.
$t->run('Multi-atributo con valor Any se saltea esa variación', function ($t) use ($sync) {
    $GLOBALS['_test_attr_labels'] = ['talla' => 'Talla', 'color' => 'Color'];
    $GLOBALS['_stub_postmeta'] = [];
    $GLOBALS['_test_wc_products'] = [
        501 => new FakeWcProduct(501, '0', [], 'variation', ['talla'=>'s','color'=>'rojo'],   2),
        502 => new FakeWcProduct(502, '0', [], 'variation', ['talla'=>'m','color'=>''],       3),
        503 => new FakeWcProduct(503, '0', [], 'variation', ['talla'=>'l','color'=>'verde'],  4),
    ];
    $parent = new FakeWcProduct(500, '5', [501, 502, 503], 'variable');

    $opts = invokePrivate($sync, 'build_options_for_tpv', [$parent]);
    $t->eq(2, count($opts[0]['values']), 'La variación Any-color se omite');
    $byName = array_column($opts[0]['values'], null, 'name');
    $t->assert(isset($byName['s-rojo']));
    $t->assert(isset($byName['l-verde']));
});

// Caso 5: stock por combinación NO se duplica
$t->run('Stock por combinación es del valor de la variación, no se duplica entre atributos', function ($t) use ($sync) {
    // Verifica el bug histórico BUG-010: con 2 atributos sumando stock
    // por atributo independiente, "S" tendría 5 (S-R 3 + S-V 2) y "Rojo"
    // 4 (S-R 3 + M-R 1), totalizando 9 unidades cuando solo hay 4 reales.
    $GLOBALS['_test_attr_labels'] = ['talla' => 'Talla', 'color' => 'Color'];
    $GLOBALS['_stub_postmeta'] = [];
    $GLOBALS['_test_wc_products'] = [
        601 => new FakeWcProduct(601, '0', [], 'variation', ['talla'=>'s','color'=>'rojo'],  3),
        602 => new FakeWcProduct(602, '0', [], 'variation', ['talla'=>'s','color'=>'verde'], 2),
        603 => new FakeWcProduct(603, '0', [], 'variation', ['talla'=>'m','color'=>'rojo'],  1),
    ];
    $parent = new FakeWcProduct(600, '5', [601, 602, 603], 'variable');

    $opts = invokePrivate($sync, 'build_options_for_tpv', [$parent]);
    $totalStock = array_sum(array_column($opts[0]['values'], 'quantity'));
    $t->eq(6, $totalStock, 'Stock total = 3+2+1 = 6 (sin duplicar entre atributos)');
});

echo "\n\033[1m── Test Suite: mapeo de impuestos ──\033[0m\n";

$t->run('resolveTpvTaxClassId: tax_status=none → 0', function ($t) use ($sync) {
    $GLOBALS['_stub_postmeta'] = [
        700 => ['_tax_status' => 'none', '_tax_class' => ''],
    ];
    $GLOBALS['_stub_options']['tpv_sync_tax_class_mapping'] = ['' => 13];
    $product = new FakeWcProduct(700, '10', [], 'simple');
    $id = invokePrivate($sync, 'resolveTpvTaxClassId', [$product]);
    $t->eq(0, $id, 'tax_status=none ignora cualquier mapeo');
});

$t->run('resolveTpvTaxClassId: Standard (slug vacio) con mapeo → id mapeado', function ($t) use ($sync) {
    $GLOBALS['_stub_postmeta'] = [
        701 => ['_tax_status' => 'taxable', '_tax_class' => ''],
    ];
    $GLOBALS['_stub_options']['tpv_sync_tax_class_mapping'] = ['' => 13];
    $product = new FakeWcProduct(701, '10', [], 'simple');
    $id = invokePrivate($sync, 'resolveTpvTaxClassId', [$product]);
    $t->eq(13, $id, 'Standard mapeada a iva 21% (id=13)');
});

$t->run('resolveTpvTaxClassId: clase sin mapeo → 0 fallback', function ($t) use ($sync) {
    $GLOBALS['_stub_postmeta'] = [
        702 => ['_tax_status' => 'taxable', '_tax_class' => 'reduced-rate'],
    ];
    $GLOBALS['_stub_options']['tpv_sync_tax_class_mapping'] = ['' => 13];
    $product = new FakeWcProduct(702, '10', [], 'simple');
    $id = invokePrivate($sync, 'resolveTpvTaxClassId', [$product]);
    $t->eq(0, $id, 'reduced-rate sin mapeo → fallback 0 = sin impuestos');
});

$t->run('resolveTpvTaxClassId: parent (variación) → 0 defensive', function ($t) use ($sync) {
    $GLOBALS['_stub_postmeta'] = [
        703 => ['_tax_status' => 'taxable', '_tax_class' => 'parent'],
    ];
    $GLOBALS['_stub_options']['tpv_sync_tax_class_mapping'] = ['' => 13];
    $product = new FakeWcProduct(703, '10', [], 'simple');
    $id = invokePrivate($sync, 'resolveTpvTaxClassId', [$product]);
    $t->eq(0, $id, 'parent (variación heredando) → 0');
});

$t->run('applyReverseTaxClass: id=0 → tax_status=none', function ($t) use ($sync) {
    $GLOBALS['_stub_postmeta'] = [800 => []];
    $GLOBALS['_stub_options']['tpv_sync_tax_class_mapping'] = ['' => 13];
    invokePrivate($sync, 'applyReverseTaxClass', [800, 0, 999]);
    $t->eq('none', get_post_meta(800, '_tax_status', true));
});

$t->run('applyReverseTaxClass: id mapeado → slug correspondiente', function ($t) use ($sync) {
    $GLOBALS['_stub_postmeta'] = [801 => []];
    $GLOBALS['_stub_options']['tpv_sync_tax_class_mapping'] = [
        ''            => 13, // Standard → iva 21%
        'reduced-rate' => 12, // Reduced → iva 10%
    ];
    invokePrivate($sync, 'applyReverseTaxClass', [801, 12, 999]);
    $t->eq('reduced-rate', get_post_meta(801, '_tax_class', true));
    $t->eq('taxable', get_post_meta(801, '_tax_status', true));
});

$t->run('applyReverseTaxClass: id no mapeado → fallback Standard + warn', function ($t) use ($sync) {
    $GLOBALS['_stub_postmeta'] = [802 => []];
    $GLOBALS['_stub_options']['tpv_sync_tax_class_mapping'] = ['' => 13];
    invokePrivate($sync, 'applyReverseTaxClass', [802, 99, 1234]);
    $t->eq('', get_post_meta(802, '_tax_class', true), 'Standard slug vacío como fallback');
    $t->eq('taxable', get_post_meta(802, '_tax_status', true));
});

// Resumen
$total = $t->passed + $t->failed;
echo "\n\033[1m══ Resultado: {$t->passed}/{$total} pasaron";
if ($t->failed > 0) echo " · \033[31m{$t->failed} fallaron\033[0m\033[1m";
echo " ══\033[0m\n";

if ($t->failures) {
    echo "\nFallos:\n";
    foreach ($t->failures as $f) echo "  • $f\n";
}

exit($t->failed > 0 ? 1 : 0);
