<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regresión: todas las operations pasadas a $queue->enqueue() en el plugin
 * deben estar manejadas en Queue::execute(). Si alguien añade un enqueue con
 * un string nuevo y olvida actualizar execute(), el job se marcaría "done"
 * silenciosamente porque execute devuelve true por defecto — y el usuario
 * nunca sabe que la operación no se procesó.
 *
 * Este test analiza el código fuente (sin ejecutarlo) para detectar ese drift.
 */
class QueueOperationsRegressionTest extends TestCase
{
    private const INCLUDES_DIR = __DIR__ . '/../../../woocommerce-conector/includes/';

    public function testAllEnqueuedOperationsAreHandledInExecute(): void
    {
        $enqueued = $this->extractEnqueuedOperations();
        $handled  = $this->extractHandledOperations();

        $this->assertNotEmpty($enqueued, 'No se encontró ningún enqueue() en el código. ¿Grep roto?');
        $this->assertNotEmpty($handled,  'No se encontró el switch en execute(). ¿Refactor?');

        $missing = array_diff($enqueued, $handled);
        $this->assertSame([], array_values($missing),
            'Operations encoladas pero no manejadas en execute(): ' . implode(', ', $missing) .
            "\nEnqueued: " . implode(', ', $enqueued) .
            "\nHandled: "  . implode(', ', $handled));
    }

    public function testAllHandledOperationsMakeSense(): void
    {
        $handled = $this->extractHandledOperations();
        // Las 4 operations esperadas de la arquitectura actual.
        // Si alguien añade una nueva, este test falla — es buena señal: obliga
        // a documentar el cambio y actualizar la lista.
        $expected = ['stock.push', 'stock.push_var', 'order.send', 'refund.send'];
        sort($handled);
        sort($expected);
        $this->assertSame($expected, $handled,
            'Operations soportadas han cambiado. Si es intencional, actualiza este test.');
    }

    /** @return list<string> */
    private function extractEnqueuedOperations(): array
    {
        $ops = [];
        $files = glob(self::INCLUDES_DIR . '*.php');
        foreach ($files as $file) {
            if (!is_string($file)) continue;
            $src = file_get_contents($file);
            if ($src === false) continue;
            // Match patrón: ->enqueue( ... 'operation_name', ... )
            if (preg_match_all('/->enqueue\s*\(\s*[\r\n\s]*[\'"]([a-z._]+)[\'"]/m', $src, $m)) {
                foreach ($m[1] as $op) $ops[] = $op;
            }
        }
        return array_values(array_unique($ops));
    }

    /** @return list<string> */
    private function extractHandledOperations(): array
    {
        $src = file_get_contents(self::INCLUDES_DIR . 'class-queue.php');
        if ($src === false) return [];
        // Extraer el cuerpo de execute() y buscar los 'case xxx:'
        if (!preg_match('/private function execute\s*\([^)]*\):\s*bool\s*\{(.+?)^\s*\}/sm', $src, $bodyMatch)) {
            return [];
        }
        $body = $bodyMatch[1];
        if (!preg_match_all('/case\s+[\'"]([a-z._]+)[\'"]\s*:/', $body, $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }
}
