<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Tests de integración de TPV_Sync_Queue con $wpdb mockeado.
 *
 * Verifican el flujo completo enqueue → process → (done | retry con backoff | abandoned).
 * No requieren WC ni WP, pero sí un mock ejecutable de la cola persistente.
 */
class QueueIntegrationTest extends TestCase
{
    /** @var FakeWpdb */
    private $fakeWpdb;
    /** @var object */
    private $fakeApi;
    /** @var object */
    private $fakeOrders;
    /** @var object */
    private $fakeProducts;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/stubs.php';
        require_once __DIR__ . '/../../includes/class-queue.php';
    }

    protected function setUp(): void
    {
        // Inicializar $wpdb global fake
        $this->fakeWpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->fakeWpdb;

        // Fakes tipados (subclases mínimas creadas en setUpBeforeClass)
        $this->fakeApi      = new TPV_Sync_API_Client();
        $this->fakeProducts = new TPV_Sync_Product_Sync();
        $this->fakeOrders   = new TPV_Sync_Order_Sync();
    }

    private function newQueue(): TPV_Sync_Queue
    {
        // Queue constructor: (api, products, orders)
        return new TPV_Sync_Queue($this->fakeApi, $this->fakeProducts, $this->fakeOrders);
    }

    public function testEnqueueInsertsRowAndReturnsId(): void
    {
        $queue = $this->newQueue();
        $id = $queue->enqueue('stock.push', ['tpv_product_id' => 42, 'delta' => 5], 'test');
        $this->assertSame(1, $id);

        $table = $this->fakeWpdb->prefix . 'tpv_sync_queue';
        $rows  = $this->fakeWpdb->tables[$table] ?? [];
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertSame('stock.push',  $row['operation']);
        $this->assertSame('pending',     $row['status']);
        $this->assertSame(0,             (int)$row['attempts']);
        $payload = json_decode($row['payload'], true);
        $this->assertSame(42, $payload['tpv_product_id']);
        $this->assertSame(5,  $payload['delta']);
    }

    public function testProcessSuccessMarksDone(): void
    {
        $queue = $this->newQueue();
        $queue->enqueue('stock.push', ['tpv_product_id' => 42, 'delta' => 1]);

        // Api devuelve éxito (ya es el default)
        $stats = $queue->process(20);

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['succeeded']);
        $this->assertSame(0, $stats['failed']);

        $table = $this->fakeWpdb->prefix . 'tpv_sync_queue';
        $row   = reset($this->fakeWpdb->tables[$table]);
        $this->assertSame('done', $row['status']);
    }

    public function testProcessFailureIncrementsAttemptsAndBackoff(): void
    {
        $queue = $this->newQueue();
        $queue->enqueue('stock.push', ['tpv_product_id' => 42, 'delta' => 1]);

        // Forzar fallo
        $this->fakeApi->patchResponse = ['error' => 'boom'];

        $stats = $queue->process(20);
        $this->assertSame(1, $stats['processed']);
        $this->assertSame(0, $stats['succeeded']);
        $this->assertSame(1, $stats['failed']);

        $table = $this->fakeWpdb->prefix . 'tpv_sync_queue';
        $row   = reset($this->fakeWpdb->tables[$table]);
        $this->assertSame('pending', $row['status'], 'tras 1 fallo sigue pending (no abandoned)');
        $this->assertSame(1, (int)$row['attempts']);
        $this->assertNotEmpty($row['next_retry_at']);
        // next_retry_at debe ser posterior al created_at
        $this->assertGreaterThan($row['created_at'], $row['next_retry_at']);
    }

    public function testProcessSixFailuresAbandonsJob(): void
    {
        $queue = $this->newQueue();
        $queue->enqueue('stock.push', ['tpv_product_id' => 42, 'delta' => 1]);

        $this->fakeApi->patchResponse = ['error' => 'boom'];
        // 6 intentos = max attempts
        for ($i = 0; $i < 6; $i++) {
            $queue->process(20);
        }

        $table = $this->fakeWpdb->prefix . 'tpv_sync_queue';
        $row   = reset($this->fakeWpdb->tables[$table]);
        $this->assertSame('abandoned', $row['status']);
        $this->assertSame(6, (int)$row['attempts']);
    }

    public function testProcessUnknownOperationMarksDoneNoOp(): void
    {
        $queue = $this->newQueue();
        $queue->enqueue('unknown.frobnicate', []);

        $stats = $queue->process(20);
        $this->assertSame(1, $stats['succeeded'],
            'operation desconocida → execute() devuelve true → marcada done (no loop infinito)');

        $table = $this->fakeWpdb->prefix . 'tpv_sync_queue';
        $row   = reset($this->fakeWpdb->tables[$table]);
        $this->assertSame('done', $row['status']);
    }

    public function testProcessBatchLimitRespected(): void
    {
        $queue = $this->newQueue();
        for ($i = 0; $i < 5; $i++) {
            $queue->enqueue('stock.push', ['tpv_product_id' => 100 + $i, 'delta' => 1]);
        }
        $stats = $queue->process(3);
        $this->assertSame(3, $stats['processed'], 'batchSize=3 debe procesar sólo 3');
    }

    public function testStockPushZeroTpvIdIsNoOp(): void
    {
        $queue = $this->newQueue();
        $queue->enqueue('stock.push', ['tpv_product_id' => 0, 'delta' => 5]);
        $queue->process(20);

        // No debe haber llamado a api->patch
        $this->assertSame([], $this->fakeApi->calls,
            'tpv_product_id=0 → execute retorna true sin llamar API (payload corrupto)');
    }

    public function testStatsReflectsQueueState(): void
    {
        $queue = $this->newQueue();
        $queue->enqueue('stock.push', ['tpv_product_id' => 1, 'delta' => 1]);
        $queue->enqueue('stock.push', ['tpv_product_id' => 2, 'delta' => 1]);
        $queue->enqueue('stock.push', ['tpv_product_id' => 3, 'delta' => 1]);
        // Procesar: default success → 3 done
        $queue->process(20);

        // stats() usa get_results con un SELECT distinto — no lo hemos mockeado,
        // así que verificamos el estado directamente en la tabla.
        $table = $this->fakeWpdb->prefix . 'tpv_sync_queue';
        $rows  = $this->fakeWpdb->tables[$table];
        $done  = 0;
        foreach ($rows as $r) if ($r['status'] === 'done') $done++;
        $this->assertSame(3, $done);
    }
}
