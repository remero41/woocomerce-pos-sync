<?php
declare(strict_types=1);
/**
 * Runner de los tests de integración con WordPress + WooCommerce reales.
 * Uso: php tests/integracion/run.php [/ruta/al/wordpress]   (tpv85 por defecto)
 */

require __DIR__ . '/arranque_wc_real.php';

final class IntegracionRunner
{
    public int $ok = 0;
    public array $fallos = [];

    public function test(string $nombre, callable $fn): void
    {
        $GLOBALS['__http'] = [];
        try {
            $fn($this);
            $this->ok++;
            echo "  \033[32m✓\033[0m $nombre\n";
        } catch (Throwable $e) {
            $this->fallos[] = "$nombre: " . $e->getMessage();
            echo "  \033[31m✗ $nombre\033[0m\n    " . $e->getMessage() . "\n";
        }
    }

    public function igual($esperado, $real, string $msg = ''): void
    {
        if ($esperado !== $real) {
            throw new RuntimeException(trim("$msg — esperado " . var_export($esperado, true)
                . ', obtenido ' . var_export($real, true), ' —'));
        }
    }

    public function cierto(bool $c, string $msg): void
    {
        if (!$c) throw new RuntimeException($msg);
    }
}

$t = new IntegracionRunner();
foreach (glob(__DIR__ . '/test_*.php') as $fichero) {
    require $fichero;
}
foreach (get_defined_functions()['user'] as $fn) {
    if (str_starts_with($fn, 'integracion_suite_')) {
        echo "\n\033[1;34m══ " . substr($fn, strlen('integracion_suite_')) . " ══\033[0m\n";
        $fn($t);
    }
}

echo "\n{$t->ok} OK, " . count($t->fallos) . " fallos\n";
exit($t->fallos ? 1 : 0);
