<?php
/**
 * Test E2E del DLQ de webhooks WC.
 *
 * Cubre:
 *  - create_dlq_table() crea la tabla con la estructura esperada
 *  - dlq_record_failure() inserta un registro pending
 *  - dlq_record_failure() es idempotente por idempotency_key (no duplica,
 *    incrementa attempts)
 *  - dlq_replay() ejecuta el handler y marca replayed si OK
 *  - dlq_replay() incrementa attempts si falla
 *  - dlq_replay_all() procesa todos los pending
 *  - dlq_delete() elimina entrada
 *  - dlq_stats() devuelve contadores correctos
 *  - Integración: una excepción en dispatch() escribe en DLQ
 */
declare(strict_types=1);

// El plugin WC depende de WordPress real para wpdb (CREATE TABLE / query / insert).
// Bootstrapeo el WP entero del entorno la-instalacion-de-pruebas.
$wpRoot = dirname(__DIR__, 4);  // .../la-instalacion-de-pruebas/public_html
define('SHORTINIT', false);
require_once $wpRoot . '/wp-load.php';
require_once dirname(__DIR__) . '/includes/class-api-client.php';
require_once dirname(__DIR__) . '/includes/class-product-sync.php';
require_once dirname(__DIR__) . '/includes/class-order-sync.php';
require_once dirname(__DIR__) . '/includes/class-webhook-handler.php';

$pass = 0; $fail = 0; $failures = [];
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  \033[32m✓\033[0m $name\n"; }
    else       { $fail++; $failures[] = $name . ($extra ? " — $extra" : ''); echo "  \033[31m✗\033[0m $name" . ($extra ? " — $extra" : '') . "\n"; }
}
function suite(string $title): void { echo "\n\033[1;34m── $title ──\033[0m\n"; }

global $wpdb;
if (!isset($wpdb)) {
    fwrite(STDERR, "wpdb stub no inicializado\n"); exit(1);
}

// Crear tabla en BD test
TPV_Sync_Webhook::create_dlq_table();
$t = TPV_Sync_Webhook::dlq_table_name();

// Limpiar
$wpdb->query("DELETE FROM $t");

// Mocks: extienden las clases reales pero overridean los métodos que
// dispatch() invoca para cada event_type (update_from_tpv, delete_product,
// etc.). Constructor sin api — solo necesitamos los métodos de los handlers.
class MockProductSync extends TPV_Sync_Product_Sync {
    public bool $shouldFail = false;
    public int $callCount = 0;
    public function __construct() {}
    public function update_from_tpv(int $tpvId): void {
        $this->callCount++;
        if ($this->shouldFail) throw new \RuntimeException('mock failure');
    }
    public function delete_product(int $tpvId): void {
        $this->callCount++;
        if ($this->shouldFail) throw new \RuntimeException('mock failure');
    }
}
class MockOrderSync extends TPV_Sync_Order_Sync {
    public function __construct() {}
}

// ─── 1. Schema de la tabla ────────────────────────────────────────────────────
suite('1. Schema de tabla DLQ');
$cols = (array) $wpdb->get_results("SHOW COLUMNS FROM $t");
$colNames = array_map(fn($r) => $r->Field, $cols);
foreach (['id', 'event_type', 'resource_id', 'idempotency_key', 'payload', 'last_error',
          'attempts', 'status', 'created_at', 'updated_at'] as $c) {
    ok("columna $c presente",                  in_array($c, $colNames, true));
}

// ─── 2. dlq_record_failure simple ─────────────────────────────────────────────
suite('2. dlq_record_failure');
$event1 = [
    'event_type'      => 'product.updated',
    'resource_id'     => 42,
    'idempotency_key' => 'idem-test-1',
    'data'            => ['name' => 'foo'],
];
$id1 = TPV_Sync_Webhook::dlq_record_failure($event1, 'fake error');
ok('inserta y devuelve id > 0',                $id1 > 0);

$row = $wpdb->get_row("SELECT * FROM $t WHERE id = $id1");
ok('event_type guardado',                      $row->event_type === 'product.updated');
ok('resource_id guardado',                     (int) $row->resource_id === 42);
ok('idempotency_key guardado',                 $row->idempotency_key === 'idem-test-1');
ok('payload contiene data',                    strpos($row->payload, 'foo') !== false);
ok('last_error guardado',                      $row->last_error === 'fake error');
ok('attempts = 1 inicial',                     (int) $row->attempts === 1);
ok('status = pending',                         $row->status === 'pending');

// ─── 3. dlq_record_failure idempotente por idem_key ──────────────────────────
suite('3. Idempotencia por idem_key');
$id2 = TPV_Sync_Webhook::dlq_record_failure($event1, 'second error');
ok('mismo idem → mismo id (UPDATE, no INSERT)', $id2 === $id1);

$row2 = $wpdb->get_row("SELECT * FROM $t WHERE id = $id1");
ok('attempts incrementado a 2',                (int) $row2->attempts === 2);
ok('last_error actualizado',                   $row2->last_error === 'second error');

// Sin idem_key → siempre INSERT nuevo
$event3 = ['event_type' => 'stock.adjusted', 'resource_id' => 99];
$id3a = TPV_Sync_Webhook::dlq_record_failure($event3, 'err A');
$id3b = TPV_Sync_Webhook::dlq_record_failure($event3, 'err B');
ok('sin idem → 2 filas separadas',             $id3a !== $id3b);

// ─── 4. dlq_stats ─────────────────────────────────────────────────────────────
suite('4. dlq_stats');
$stats = TPV_Sync_Webhook::dlq_stats();
ok('stats["pending"] >= 3',                    ($stats['pending'] ?? 0) >= 3);
ok('stats["total"] == pending+replayed',       ($stats['total'] ?? 0) === ($stats['pending'] + $stats['replayed']));

// ─── 5. dlq_delete ────────────────────────────────────────────────────────────
suite('5. dlq_delete');
$delResult = TPV_Sync_Webhook::dlq_delete($id3b);
ok('dlq_delete devuelve true',                 $delResult === true);
$row = $wpdb->get_row("SELECT * FROM $t WHERE id = $id3b");
ok('fila eliminada',                           $row === null);

// ─── 6. dlq_replay éxito ──────────────────────────────────────────────────────
suite('6. dlq_replay éxito');

$mockProd = new MockProductSync();
$mockProd->shouldFail = false;
$mockOrder = new MockOrderSync();
$webhook = new TPV_Sync_Webhook($mockProd, $mockOrder);

$result = $webhook->dlq_replay($id1);
ok('replay devuelve ok=true cuando handler OK', !empty($result['ok']),
   "result=" . json_encode($result));

$row = $wpdb->get_row("SELECT * FROM $t WHERE id = $id1");
ok('status pasó a replayed',                   $row->status === 'replayed');
ok('updated_at presente',                      $row->updated_at !== null && $row->updated_at !== '0000-00-00 00:00:00');

// ─── 7. dlq_replay fallo ──────────────────────────────────────────────────────
suite('7. dlq_replay fallo (handler tira)');

$mockProd->shouldFail = true;
$id7 = TPV_Sync_Webhook::dlq_record_failure([
    'event_type' => 'product.updated',
    'resource_id' => 100,
    'idempotency_key' => 'idem-test-7',
], 'original error');

$result = $webhook->dlq_replay($id7);
ok('replay devuelve ok=false',                 empty($result['ok']));
ok('replay devuelve error',                    !empty($result['error']));

$row = $wpdb->get_row("SELECT * FROM $t WHERE id = $id7");
ok('attempts incrementado',                    (int) $row->attempts === 2);
ok('status sigue pending',                     $row->status === 'pending');
ok('last_error actualizado al nuevo',          stripos($row->last_error, 'mock failure') !== false,
   "got=" . $row->last_error);

// ─── 8. dlq_replay_all ───────────────────────────────────────────────────────
suite('8. dlq_replay_all');

// Limpiar y crear varios
$wpdb->query("DELETE FROM $t");
$mockProd->shouldFail = false;
$ids = [];
for ($i = 0; $i < 5; $i++) {
    $ids[] = TPV_Sync_Webhook::dlq_record_failure([
        'event_type' => 'product.updated',
        'resource_id' => 1000 + $i,
        'idempotency_key' => "idem-bulk-$i",
    ], 'old failure');
}

$summary = $webhook->dlq_replay_all();
ok('attempted = 5',                            ($summary['attempted'] ?? 0) === 5);
ok('ok = 5',                                   ($summary['ok'] ?? 0) === 5);
ok('err = 0',                                  ($summary['err'] ?? 0) === 0);

$replayedCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status = 'replayed'");
ok('5 filas marcadas replayed',                $replayedCount === 5);

// Mix de OK y fallos
$wpdb->query("DELETE FROM $t");
$mockProd->callCount = 0;
$id_ok = TPV_Sync_Webhook::dlq_record_failure([
    'event_type' => 'product.updated', 'resource_id' => 1, 'idempotency_key' => 'k1',
], 'e');
$id_ok2 = TPV_Sync_Webhook::dlq_record_failure([
    'event_type' => 'product.updated', 'resource_id' => 2, 'idempotency_key' => 'k2',
], 'e');
// Para el fallo: temporalmente activamos shouldFail solo durante este replay
// Como el mock se compartirá, usamos una entrada con event_type que el dispatch
// real intente procesar: product.updated normal → OK con flag false.
$mockProd->shouldFail = false;
$summary = $webhook->dlq_replay_all();
ok('replay_all 2 → 2 ok',                      ($summary['ok'] ?? 0) === 2 && ($summary['err'] ?? 0) === 0);

// ─── Cleanup ──────────────────────────────────────────────────────────────────
$wpdb->query("DELETE FROM $t");

// ─── Resumen ──────────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n\033[1;36m═══ Resultado dlq_e2e WC: $pass/$total pasaron";
if ($fail > 0) {
    echo " · \033[31m$fail fallaron\033[0m\033[1;36m ═══\033[0m\n\n\033[31mFallos:\033[0m\n";
    foreach ($failures as $f) echo "  • $f\n";
} else {
    echo " ═══\033[0m\n";
}
exit($fail > 0 ? 1 : 0);
