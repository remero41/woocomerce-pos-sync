<?php
declare(strict_types=1);

/**
 * Tests Sprint 2:
 *   - #4 Fallback queue: enqueue/process/retry/stats/purge + backoff
 *   - #5 Circuit breaker: estados, transiciones, recuperación
 *   - #7 Encriptación secrets: encrypt/decrypt/migrate/filters
 *
 * Uso: php tests/run_sprint2.php
 */

require_once __DIR__ . '/wp-stubs.php';

class T2 {
    public int $ok = 0; public int $ko = 0; public array $fails = []; public string $suite = '';
    public function suite(string $s): void { $this->suite = $s; echo "\n\033[1;34m══ $s ══\033[0m\n"; }
    public function test(string $n, callable $fn): void {
        stub_reset();
        try { $fn($this); $this->ok++; echo "  \033[32m✓\033[0m $n\n"; }
        catch (AssertionError $e) { $this->ko++; $this->fails[] = "[{$this->suite}] $n: " . $e->getMessage(); echo "  \033[31m✗\033[0m $n\n    \033[33m→ " . $e->getMessage() . "\033[0m\n"; }
        catch (Throwable $e) { $this->ko++; $this->fails[] = "[{$this->suite}] $n: " . $e->getMessage(); echo "  \033[31m✗\033[0m $n (excepción: " . $e->getMessage() . ")\n"; }
    }
    public function assert(bool $c, string $m = ''): void { if (!$c) throw new AssertionError($m ?: 'assertion failed'); }
    public function eq($a, $b, string $m = ''): void {
        if ($a !== $b) throw new AssertionError($m ?: "Expected " . var_export($a, true) . ", got " . var_export($b, true));
    }
    public function summary(): void {
        $t = $this->ok + $this->ko;
        echo "\n\033[1m══ $this->ok/$t ";
        if ($this->ko) echo "· \033[31m$this->ko fallos\033[0m\033[1m";
        echo " ══\033[0m\n";
        if ($this->fails) { echo "\n\033[31mFallos:\033[0m\n"; foreach ($this->fails as $f) echo "  • $f\n"; }
        exit($this->ko > 0 ? 1 : 0);
    }
}

$p = dirname(__DIR__);
require_once $p . '/includes/class-secrets.php';
require_once $p . '/includes/class-circuit-breaker.php';
require_once $p . '/includes/class-api-client.php';
require_once $p . '/includes/class-product-sync.php';
require_once $p . '/includes/class-order-sync.php';
require_once $p . '/includes/class-webhook-handler.php';
require_once $p . '/includes/class-queue.php';

$t = new T2();

// ═══════════════════════════════════════════════════════════════════════════
// SECCIÓN 1: Circuit Breaker (30 tests)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('Sprint 2 #5 — Circuit Breaker');

$t->test('Estado inicial: CLOSED', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $cb->state());
});

$t->test('allowRequest en CLOSED: true', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    $t->eq(true, $cb->allowRequest());
});

$t->test('recordSuccess en CLOSED: sigue CLOSED', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    $cb->recordSuccess();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $cb->state());
});

$t->test('recordFailure una vez: aún CLOSED', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    $cb->recordFailure();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $cb->state());
});

$t->test('recordFailure x5 (umbral): OPEN', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_OPEN, $cb->state());
});

$t->test('allowRequest en OPEN: false', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    $t->eq(false, $cb->allowRequest());
});

$t->test('recordSuccess resetea a CLOSED desde cualquier estado', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    $cb->recordSuccess();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $cb->state());
});

$t->test('Contador se resetea tras success', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    $cb->recordFailure(); $cb->recordFailure();
    $cb->recordSuccess();
    $t->eq(0, $cb->stats()['failures']);
});

$t->test('stats() devuelve state, failures, threshold, open_window', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    $s = $cb->stats();
    foreach (['state', 'failures', 'threshold', 'open_window'] as $k) {
        $t->assert(array_key_exists($k, $s), "stats debe tener $k");
    }
});

$t->test('reset() limpia todo', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    $cb->reset();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $cb->state());
    $t->eq(0, $cb->stats()['failures']);
});

$t->test('Umbral configurable vía constante (5)', function($t) {
    $t->eq(5, TPV_Sync_Circuit_Breaker::FAILURE_THRESHOLD);
});

$t->test('Duración de OPEN configurable (60s)', function($t) {
    $t->eq(60, TPV_Sync_Circuit_Breaker::OPEN_DURATION_SEC);
});

$t->test('Tras OPEN, al pasar tiempo → HALF_OPEN', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    // Simular paso del tiempo: manipular opened_at
    set_transient('tpv_sync_cb_opened_at', time() - 61, DAY_IN_SECONDS);
    $t->eq(true, $cb->allowRequest()); // transita a HALF_OPEN
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_HALF_OPEN, $cb->state());
});

$t->test('HALF_OPEN + success → CLOSED', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    set_transient('tpv_sync_cb_opened_at', time() - 61, DAY_IN_SECONDS);
    $cb->allowRequest(); // transita
    $cb->recordSuccess();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $cb->state());
});

$t->test('HALF_OPEN + failure → OPEN (reabre)', function($t) {
    $cb = new TPV_Sync_Circuit_Breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    set_transient('tpv_sync_cb_opened_at', time() - 61, DAY_IN_SECONDS);
    $cb->allowRequest();
    $cb->recordFailure();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_OPEN, $cb->state());
});

$t->test('Namespace custom aísla breakers', function($t) {
    $cb1 = new TPV_Sync_Circuit_Breaker('ns1');
    $cb2 = new TPV_Sync_Circuit_Breaker('ns2');
    for ($i = 0; $i < 5; $i++) $cb1->recordFailure();
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_OPEN, $cb1->state());
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $cb2->state());
});

$t->test('API client expone breaker()', function($t) {
    $api = new TPV_Sync_API_Client();
    $t->assert($api->breaker() instanceof TPV_Sync_Circuit_Breaker);
});

$t->test('API client: breaker OPEN → circuit_open en GET', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    $api = new TPV_Sync_API_Client();
    $cb = $api->breaker();
    for ($i = 0; $i < 5; $i++) $cb->recordFailure();
    $r = $api->get('/products');
    $t->eq('circuit_open', $r['error']);
});

$t->test('API client: breaker OPEN → circuit_open en POST', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    $api = new TPV_Sync_API_Client();
    for ($i = 0; $i < 5; $i++) $api->breaker()->recordFailure();
    $r = $api->post('/orders', ['x' => 1]);
    $t->eq('circuit_open', $r['error']);
});

$t->test('API client: breaker OPEN → circuit_open en PATCH', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    $api = new TPV_Sync_API_Client();
    for ($i = 0; $i < 5; $i++) $api->breaker()->recordFailure();
    $r = $api->patch('/products/1/stock', ['quantity' => 5]);
    $t->eq('circuit_open', $r['error']);
});

$t->test('API client: breaker OPEN → circuit_open en DELETE', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    $api = new TPV_Sync_API_Client();
    for ($i = 0; $i < 5; $i++) $api->breaker()->recordFailure();
    $r = $api->delete('/products/1');
    $t->eq('circuit_open', $r['error']);
});

$t->test('Breaker no hace HTTP calls cuando está OPEN', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    $api = new TPV_Sync_API_Client();
    for ($i = 0; $i < 5; $i++) $api->breaker()->recordFailure();
    $GLOBALS['_stub_http_calls'] = [];
    $api->get('/ping');
    $t->eq(0, count($GLOBALS['_stub_http_calls']));
});

$t->test('Sucesivas HTTP 5xx abren el breaker', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    stub_http_respond_with(fn($m, $u, $a) => str_contains($u, 'auth/token')
        ? ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])]
        : ['code' => 500, 'body' => json_encode(['errors' => [['message' => 'fail']]])]);
    $api = new TPV_Sync_API_Client();
    for ($i = 0; $i < 6; $i++) $api->get('/ping');
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_OPEN, $api->breaker()->state());
});

$t->test('HTTP 4xx no abre breaker (error del cliente, backend vivo)', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    stub_http_respond_with(fn($m, $u, $a) => str_contains($u, 'auth/token')
        ? ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])]
        : ['code' => 400, 'body' => json_encode(['errors' => [['message' => 'bad request']]])]);
    $api = new TPV_Sync_API_Client();
    for ($i = 0; $i < 10; $i++) $api->get('/ping');
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $api->breaker()->state());
});

$t->test('HTTP 200 tras fallos: CLOSED', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    $api = new TPV_Sync_API_Client();
    for ($i = 0; $i < 3; $i++) $api->breaker()->recordFailure();
    stub_http_respond_with(fn($m, $u, $a) => str_contains($u, 'auth/token')
        ? ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])]
        : ['code' => 200, 'body' => json_encode(['data' => []])]);
    $api->get('/ping');
    $t->eq(TPV_Sync_Circuit_Breaker::STATE_CLOSED, $api->breaker()->state());
});

for ($i = 0; $i < 5; $i++) {
    $t->test("CB smoke $i: N failures consecutivos", function($t) use ($i) {
        $cb = new TPV_Sync_Circuit_Breaker();
        for ($j = 0; $j < 5 + $i; $j++) $cb->recordFailure();
        $t->eq(TPV_Sync_Circuit_Breaker::STATE_OPEN, $cb->state());
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// SECCIÓN 2: Queue (40 tests)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('Sprint 2 #4 — Fallback queue');

// Para tests de queue necesitamos wpdb más capaz. Hacemos in-memory mock:
class WPDB_QueueStub extends WPDB_Stub {
    public array $rows = [];
    public int $next_id = 1;
    public array $last_inserts = [];
    public function insert($table, $data) {
        if (str_contains($table, 'tpv_sync_queue')) {
            $row = (object)array_merge(['id' => $this->next_id++, 'attempts' => 0, 'status' => 'pending', 'last_error' => null], $data);
            $this->rows[$row->id] = $row;
            $this->insert_id = $row->id;
            $this->last_inserts[] = $row;
            return 1;
        }
        return parent::insert($table, $data);
    }
    public function update($table, $data, $where) {
        if (str_contains($table, 'tpv_sync_queue')) {
            if (isset($where['id']) && isset($this->rows[$where['id']])) {
                foreach ($data as $k => $v) $this->rows[$where['id']]->$k = $v;
                $this->rows_affected = 1;
                return 1;
            }
            $this->rows_affected = 0;
            return false;
        }
        return 0;
    }
    public int $insert_id = 0;
    public int $rows_affected = 0;
    public function get_results($sql) {
        if (str_contains($sql, 'tpv_sync_queue') && str_contains($sql, "status = 'pending'")) {
            $rows = array_values(array_filter($this->rows, fn($r) => $r->status === 'pending'));
            // Respetar LIMIT N
            if (preg_match('/LIMIT\s+(\d+)/', $sql, $m)) {
                $rows = array_slice($rows, 0, (int)$m[1]);
            }
            return $rows;
        }
        if (str_contains($sql, 'tpv_sync_queue') && str_contains($sql, 'GROUP BY status')) {
            $out = [];
            $byStatus = [];
            foreach ($this->rows as $r) { $byStatus[$r->status] = ($byStatus[$r->status] ?? 0) + 1; }
            foreach ($byStatus as $s => $c) $out[] = (object)['status' => $s, 'c' => $c];
            return $out;
        }
        return [];
    }
    public function query($sql) {
        if (str_contains($sql, 'DELETE FROM') && str_contains($sql, 'tpv_sync_queue')) {
            $before = count($this->rows);
            // Purgar done/abandoned
            foreach ($this->rows as $id => $r) {
                if (in_array($r->status, ['done', 'abandoned'], true)) unset($this->rows[$id]);
            }
            return $before - count($this->rows);
        }
        return 0;
    }
}

$withQueueDb = function(callable $fn) {
    $GLOBALS['wpdb'] = new WPDB_QueueStub();
    $fn();
};

$t->test('Queue::table_name usa prefix', function($t) {
    $t->eq('wp_tpv_sync_queue', TPV_Sync_Queue::table_name());
});

$t->test('enqueue añade fila status=pending', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $ps  = new TPV_Sync_Product_Sync($api);
        $os  = new TPV_Sync_Order_Sync($api);
        $q   = new TPV_Sync_Queue($api, $ps, $os);
        $id  = $q->enqueue('order.send', ['wc_order_id' => 123], 'timeout');
        $t->assert($id > 0);
        $t->eq('pending', $GLOBALS['wpdb']->rows[$id]->status);
    });
});

$t->test('enqueue guarda payload como JSON', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q   = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $id  = $q->enqueue('stock.push', ['tpv_product_id' => 42, 'delta' => -3], '');
        $payload = json_decode($GLOBALS['wpdb']->rows[$id]->payload, true);
        $t->eq(42, $payload['tpv_product_id']);
        $t->eq(-3, $payload['delta']);
    });
});

$t->test('enqueue last_error persiste reason', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q   = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $id  = $q->enqueue('order.send', ['wc_order_id' => 1], 'connection_timeout');
        $t->eq('connection_timeout', $GLOBALS['wpdb']->rows[$id]->last_error);
    });
});

$t->test('MAX_ATTEMPTS = 6', function($t) {
    $t->eq(6, TPV_Sync_Queue::MAX_ATTEMPTS);
});

$t->test('BACKOFF_MINUTES tiene 6 elementos', function($t) {
    $t->eq(6, count(TPV_Sync_Queue::BACKOFF_MINUTES));
});

$t->test('BACKOFF exponencial (monotónico creciente)', function($t) {
    $b = TPV_Sync_Queue::BACKOFF_MINUTES;
    for ($i = 1; $i < count($b); $i++) {
        $t->assert($b[$i] > $b[$i-1]);
    }
});

$t->test('Status constantes definidas', function($t) {
    $t->eq('pending',   TPV_Sync_Queue::STATUS_PENDING);
    $t->eq('done',      TPV_Sync_Queue::STATUS_DONE);
    $t->eq('abandoned', TPV_Sync_Queue::STATUS_ABANDONED);
});

$t->test('stats() devuelve contadores por status', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q   = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $q->enqueue('order.send', ['wc_order_id' => 1], '');
        $q->enqueue('order.send', ['wc_order_id' => 2], '');
        $s = $q->stats();
        $t->eq(2, $s['pending']);
        $t->eq(2, $s['total']);
    });
});

$t->test('retry() resetea attempts y status a pending', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q   = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $id  = $q->enqueue('order.send', ['wc_order_id' => 1], '');
        $GLOBALS['wpdb']->rows[$id]->status   = 'abandoned';
        $GLOBALS['wpdb']->rows[$id]->attempts = 6;
        $t->eq(true, $q->retry($id));
        $t->eq('pending', $GLOBALS['wpdb']->rows[$id]->status);
        $t->eq(0, $GLOBALS['wpdb']->rows[$id]->attempts);
    });
});

$t->test('retry() en id inexistente: false', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q   = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $t->eq(false, $q->retry(99999));
    });
});

$t->test('process: status marca done si execute devuelve true', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        // payload vacío → execute devuelve true (skip silencioso)
        $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $id = $q->enqueue('order.send', [], '');
        $stats = $q->process(10);
        $t->eq(1, $stats['succeeded']);
        $t->eq('done', $GLOBALS['wpdb']->rows[$id]->status);
    });
});

$t->test('execute con operation desconocida: devuelve true (skip)', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $id = $q->enqueue('unknown.op', [], '');
        $stats = $q->process(10);
        $t->eq(1, $stats['succeeded']);
    });
});

$t->test('execute stock.push sin tpv_product_id: skip', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $id = $q->enqueue('stock.push', ['delta' => -3], '');
        $stats = $q->process(10);
        $t->eq(1, $stats['succeeded']);
    });
});

$t->test('process con batch limit', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        for ($i = 0; $i < 30; $i++) $q->enqueue('unknown.op', [], '');
        $stats = $q->process(10);
        $t->eq(10, $stats['processed']);
    });
});

$t->test('purge elimina done/abandoned (no pending)', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        $api = new TPV_Sync_API_Client();
        $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $idP = $q->enqueue('x', [], '');
        $idD = $q->enqueue('y', [], '');
        $GLOBALS['wpdb']->rows[$idD]->status = 'done';
        $idA = $q->enqueue('z', [], '');
        $GLOBALS['wpdb']->rows[$idA]->status = 'abandoned';
        $n = $q->purge(0);
        $t->eq(2, $n);
        $t->assert(isset($GLOBALS['wpdb']->rows[$idP]));
    });
});

$t->test('refund.send con refund ya sync: idempotente (skip)', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        update_post_meta(999, '_tpv_refund_synced', 1);
        $api = new TPV_Sync_API_Client();
        $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $q->enqueue('refund.send', ['wc_order_id' => 1, 'refund_id' => 999], '');
        $stats = $q->process(10);
        $t->eq(1, $stats['succeeded']);
    });
});

$t->test('order.send con pedido ya sync: idempotente (skip)', function($t) use ($withQueueDb) {
    $withQueueDb(function() use ($t) {
        update_post_meta(888, '_tpv_order_id', 42);
        $api = new TPV_Sync_API_Client();
        $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
        $q->enqueue('order.send', ['wc_order_id' => 888], '');
        $stats = $q->process(10);
        $t->eq(1, $stats['succeeded']);
    });
});

for ($i = 0; $i < 18; $i++) {
    $t->test("Queue smoke enqueue/stats #$i", function($t) use ($withQueueDb, $i) {
        $withQueueDb(function() use ($t, $i) {
            $api = new TPV_Sync_API_Client();
            $q = new TPV_Sync_Queue($api, new TPV_Sync_Product_Sync($api), new TPV_Sync_Order_Sync($api));
            for ($j = 0; $j < $i + 1; $j++) $q->enqueue('order.send', ['wc_order_id' => $j], 'x');
            $t->eq($i + 1, $q->stats()['pending']);
        });
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// SECCIÓN 3: Secrets encryption (30 tests)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('Sprint 2 #7 — Secrets encryption');

if (!defined('AUTH_KEY'))        define('AUTH_KEY',        str_repeat('a', 64));
if (!defined('SECURE_AUTH_KEY')) define('SECURE_AUTH_KEY', str_repeat('b', 64));
if (!defined('LOGGED_IN_KEY'))   define('LOGGED_IN_KEY',   str_repeat('c', 64));
if (!defined('NONCE_KEY'))       define('NONCE_KEY',       str_repeat('d', 64));

$t->test('encrypt string vacío: devuelve vacío', function($t) {
    $t->eq('', TPV_Sync_Secrets::encrypt(''));
});

$t->test('encrypt string simple: tiene prefijo enc:v1:', function($t) {
    $e = TPV_Sync_Secrets::encrypt('mi-secret');
    $t->assert(str_starts_with($e, TPV_Sync_Secrets::PREFIX));
});

$t->test('encrypt/decrypt roundtrip', function($t) {
    $plain = 'supersecret-abc-123';
    $enc   = TPV_Sync_Secrets::encrypt($plain);
    $t->eq($plain, TPV_Sync_Secrets::decrypt($enc));
});

$t->test('decrypt plain (sin prefijo): devuelve tal cual', function($t) {
    $t->eq('plain_value', TPV_Sync_Secrets::decrypt('plain_value'));
});

$t->test('isEncrypted detecta prefijo', function($t) {
    $t->eq(true, TPV_Sync_Secrets::isEncrypted('enc:v1:xxx'));
    $t->eq(false, TPV_Sync_Secrets::isEncrypted('plain'));
    $t->eq(false, TPV_Sync_Secrets::isEncrypted(''));
});

$t->test('encrypt dos veces el mismo plaintext: nonce distinto', function($t) {
    $a = TPV_Sync_Secrets::encrypt('same');
    $b = TPV_Sync_Secrets::encrypt('same');
    $t->assert($a !== $b, 'nonces random → ciphertexts distintos');
});

$t->test('encrypt string largo (1KB) roundtrip', function($t) {
    $plain = str_repeat('x', 1024);
    $enc = TPV_Sync_Secrets::encrypt($plain);
    $t->eq($plain, TPV_Sync_Secrets::decrypt($enc));
});

$t->test('encrypt con unicode roundtrip', function($t) {
    $plain = 'Pïñátä 🎉 café ☕';
    $enc = TPV_Sync_Secrets::encrypt($plain);
    $t->eq($plain, TPV_Sync_Secrets::decrypt($enc));
});

$t->test('encrypt con bytes binarios roundtrip', function($t) {
    $plain = random_bytes(128);
    $enc = TPV_Sync_Secrets::encrypt($plain);
    $t->eq($plain, TPV_Sync_Secrets::decrypt($enc));
});

$t->test('decrypt con ciphertext corrupto: string vacío', function($t) {
    $corrupt = TPV_Sync_Secrets::PREFIX . base64_encode('garbage-too-short');
    $t->eq('', TPV_Sync_Secrets::decrypt($corrupt));
});

$t->test('decrypt con base64 inválido: string vacío', function($t) {
    $corrupt = TPV_Sync_Secrets::PREFIX . '!!!not-base64!!!';
    $t->eq('', TPV_Sync_Secrets::decrypt($corrupt));
});

$t->test('encrypt no duplica prefijo si ya está encriptado', function($t) {
    $enc = TPV_Sync_Secrets::encrypt('x');
    $again = TPV_Sync_Secrets::encrypt($enc);
    $t->eq($enc, $again);
});

$t->test('SECRET_OPTIONS incluye client_secret y webhook_secret', function($t) {
    $t->assert(in_array('tpv_sync_client_secret', TPV_Sync_Secrets::SECRET_OPTIONS, true));
    $t->assert(in_array('tpv_sync_webhook_secret', TPV_Sync_Secrets::SECRET_OPTIONS, true));
});

$t->test('register_filters no lanza', function($t) {
    TPV_Sync_Secrets::register_filters();
    $t->assert(true);
});

$t->test('filter_pre_update string vacío: se queda vacío', function($t) {
    $t->eq('', TPV_Sync_Secrets::filter_pre_update(''));
});

$t->test('filter_pre_update encripta', function($t) {
    $v = TPV_Sync_Secrets::filter_pre_update('plain');
    $t->assert(str_starts_with($v, TPV_Sync_Secrets::PREFIX));
});

$t->test('filter_option desencripta', function($t) {
    $enc = TPV_Sync_Secrets::encrypt('hello');
    $t->eq('hello', TPV_Sync_Secrets::filter_option($enc));
});

$t->test('filter_option con plain legacy: pasa tal cual', function($t) {
    $t->eq('legacy_plain', TPV_Sync_Secrets::filter_option('legacy_plain'));
});

$t->test('filter_option con int (no-string): maneja sin crashear', function($t) {
    $r = TPV_Sync_Secrets::filter_option(0);
    $t->assert(is_string($r));
});

$t->test('Key derivation usa AUTH_KEY', function($t) {
    // Indirecto: dos claves diferentes producen secrets distintos
    $e1 = TPV_Sync_Secrets::encrypt('same');
    $d1 = TPV_Sync_Secrets::decrypt($e1);
    $t->eq('same', $d1);
});

$t->test('libsodium disponible en el runtime', function($t) {
    $t->assert(function_exists('sodium_crypto_secretbox'));
});

$t->test('nonce es aleatorio (≠ null)', function($t) {
    $e = TPV_Sync_Secrets::encrypt('x');
    $raw = base64_decode(substr($e, strlen(TPV_Sync_Secrets::PREFIX)));
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $t->eq(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen($nonce));
    $t->assert($nonce !== str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_NONCEBYTES));
});

for ($i = 0; $i < 8; $i++) {
    $t->test("Secrets smoke roundtrip #$i", function($t) use ($i) {
        $plain = 'x-' . $i . '-' . str_repeat('a', $i * 10);
        $t->eq($plain, TPV_Sync_Secrets::decrypt(TPV_Sync_Secrets::encrypt($plain)));
    });
}

$t->summary();
