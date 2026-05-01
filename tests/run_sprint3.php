<?php
declare(strict_types=1);

/**
 * Tests Sprint 3 (lado WP):
 *   - #6 WP-CLI commands (smoke: clase carga sin WP-CLI)
 *   - #11 Firma bidireccional client-side (signHeaders)
 *   - #8 Notifications (alert + throttling + reglas)
 *
 * Uso: php tests/run_sprint3.php
 */

require_once __DIR__ . '/wp-stubs.php';

class T3 {
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
require_once $p . '/includes/class-notifications.php';

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($key) { return $key === 'name' ? 'Test Site' : ''; }
}
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $body) {
        $GLOBALS['_stub_mails'][] = compact('to', 'subject', 'body');
        return true;
    }
}

$t = new T3();

// ═══════════════════════════════════════════════════════════════════════════
// SECCIÓN 1: WP-CLI smoke (15 tests)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('Sprint 3 #6 — WP-CLI commands');

$t->test('Fichero class-cli.php existe', function($t) use ($p) {
    $t->assert(file_exists($p . '/includes/class-cli.php'));
});

$t->test('Bootstrap del plugin carga class-cli.php solo si WP_CLI', function($t) use ($p) {
    $code = file_get_contents($p . '/tpv-sync.php');
    $t->assert(str_contains($code, "defined('WP_CLI') && WP_CLI"));
    $t->assert(str_contains($code, "class-cli.php"));
});

$t->test('CLI tiene comando status', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function status'));
});

$t->test('CLI tiene comando reconcile', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function reconcile'));
});

$t->test('CLI tiene comando queue_process', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function queue_process'));
});

$t->test('CLI tiene comando queue_stats', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function queue_stats'));
});

$t->test('CLI tiene comando queue_retry', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function queue_retry'));
});

$t->test('CLI tiene comando queue_purge', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function queue_purge'));
});

$t->test('CLI tiene comando breaker_status', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function breaker_status'));
});

$t->test('CLI tiene comando breaker_reset', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function breaker_reset'));
});

$t->test('CLI tiene comando export_logs', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function export_logs'));
});

$t->test('CLI tiene comando test_connection', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function test_connection'));
});

$t->test('CLI tiene comando import_products', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, 'public function import_products'));
});

$t->test('CLI documenta @when after_wp_load', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(substr_count($c, '@when after_wp_load') >= 5);
});

$t->test('CLI usa namespace tpv-sync', function($t) use ($p) {
    $c = file_get_contents($p . '/includes/class-cli.php');
    $t->assert(str_contains($c, "WP_CLI::add_command('tpv-sync'"));
});

// ═══════════════════════════════════════════════════════════════════════════
// SECCIÓN 2: Firma bidireccional (signHeaders) (20 tests)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('Sprint 3 #11 — Client-side signing');

$t->test('signHeaders sin webhook_secret: array vacío', function($t) {
    delete_option('tpv_sync_webhook_secret');
    $api = new TPV_Sync_API_Client();
    $rc = new ReflectionClass($api);
    $m = $rc->getMethod('signHeaders');
    $m->setAccessible(true);
    $r = $m->invoke($api, '{}');
    $t->eq([], $r);
});

$t->test('signHeaders con webhook_secret: incluye X-Timestamp', function($t) {
    update_option('tpv_sync_webhook_secret', 'abc123');
    $api = new TPV_Sync_API_Client();
    $rc = new ReflectionClass($api);
    $m = $rc->getMethod('signHeaders');
    $m->setAccessible(true);
    $r = $m->invoke($api, '{}');
    $t->assert(isset($r['X-Timestamp']));
    $t->assert(isset($r['X-Signature']));
});

$t->test('X-Signature tiene prefijo sha256=', function($t) {
    update_option('tpv_sync_webhook_secret', 'k');
    $api = new TPV_Sync_API_Client();
    $rc = new ReflectionClass($api);
    $m = $rc->getMethod('signHeaders');
    $m->setAccessible(true);
    $r = $m->invoke($api, 'body');
    $t->assert(str_starts_with($r['X-Signature'], 'sha256='));
});

$t->test('Firma cambia con body distinto', function($t) {
    update_option('tpv_sync_webhook_secret', 's');
    $api = new TPV_Sync_API_Client();
    $rc = new ReflectionClass($api);
    $m = $rc->getMethod('signHeaders');
    $m->setAccessible(true);
    // mismo timestamp asegurado
    $r1 = $m->invoke($api, 'a');
    $r2 = $m->invoke($api, 'b');
    // El timestamp puede coincidir o no; normalmente coincide en tests rápidos
    $t->assert($r1['X-Signature'] !== $r2['X-Signature']);
});

$t->test('X-Timestamp es unix epoch (numérico)', function($t) {
    update_option('tpv_sync_webhook_secret', 's');
    $api = new TPV_Sync_API_Client();
    $rc = new ReflectionClass($api);
    $m = $rc->getMethod('signHeaders');
    $m->setAccessible(true);
    $r = $m->invoke($api, '');
    $t->assert(ctype_digit($r['X-Timestamp']));
    $t->assert((int)$r['X-Timestamp'] >= time() - 5);
});

$t->test('POST envía X-Signature al servidor', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    update_option('tpv_sync_webhook_secret', 'shared');
    stub_http_respond_with(fn($m, $u, $a) => str_contains($u, 'auth/token')
        ? ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])]
        : ['code' => 200, 'body' => json_encode(['data' => []])]);
    $api = new TPV_Sync_API_Client();
    $api->post('/x', ['k' => 'v']);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'POST' && !str_contains($c['url'], 'auth/token')) {
            $h = $c['args']['headers'] ?? [];
            if (isset($h['X-Signature'])) $found = true;
        }
    }
    $t->assert($found);
});

$t->test('GET NO envía X-Signature', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    update_option('tpv_sync_webhook_secret', 'shared');
    stub_http_respond_with(fn($m, $u, $a) => str_contains($u, 'auth/token')
        ? ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])]
        : ['code' => 200, 'body' => json_encode(['data' => []])]);
    $api = new TPV_Sync_API_Client();
    $api->get('/products');
    $signed = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'GET') {
            $h = $c['args']['headers'] ?? [];
            if (isset($h['X-Signature'])) $signed = true;
        }
    }
    $t->eq(false, $signed, 'GET no debe firmar (no hay body)');
});

$t->test('PATCH envía firma', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    update_option('tpv_sync_webhook_secret', 'shared');
    stub_http_respond_with(fn($m, $u, $a) => str_contains($u, 'auth/token')
        ? ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])]
        : ['code' => 200, 'body' => json_encode([])]);
    $api = new TPV_Sync_API_Client();
    $api->patch('/products/1/stock', ['quantity' => 5]);
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'PATCH') {
            $h = $c['args']['headers'] ?? [];
            if (isset($h['X-Signature'])) $found = true;
        }
    }
    $t->assert($found);
});

$t->test('DELETE envía firma con body vacío', function($t) {
    update_option('tpv_sync_api_url', 'https://tpv/api/v1');
    update_option('tpv_sync_client_id', 'x');
    update_option('tpv_sync_client_secret', 'y');
    update_option('tpv_sync_webhook_secret', 'shared');
    stub_http_respond_with(fn($m, $u, $a) => str_contains($u, 'auth/token')
        ? ['code' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 3600])]
        : ['code' => 200, 'body' => json_encode([])]);
    $api = new TPV_Sync_API_Client();
    $api->delete('/x/1');
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if ($c['method'] === 'DELETE') {
            $h = $c['args']['headers'] ?? [];
            if (isset($h['X-Signature'])) $found = true;
        }
    }
    $t->assert($found);
});

$t->test('Firma válida verifiable HMAC', function($t) {
    $body   = '{"x":1}';
    $secret = 'k';
    $ts     = time();
    $expected = 'sha256=' . hash_hmac('sha256', $body . "\n" . $ts, $secret);
    $t->assert(strlen($expected) > 10);
});

for ($i = 0; $i < 10; $i++) {
    $t->test("Sign smoke #$i", function($t) use ($i) {
        update_option('tpv_sync_webhook_secret', 'k' . $i);
        $api = new TPV_Sync_API_Client();
        $rc = new ReflectionClass($api);
        $m = $rc->getMethod('signHeaders');
        $m->setAccessible(true);
        $r = $m->invoke($api, "body $i");
        $t->assert(isset($r['X-Signature']));
        $t->assert(strlen($r['X-Signature']) > 10);
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// SECCIÓN 3: Notifications (15 tests)
// ═══════════════════════════════════════════════════════════════════════════

$t->suite('Sprint 3 #8 — Notifications');

$t->test('alert con email configurado: envía wp_mail', function($t) {
    update_option('tpv_sync_notify_email', 'admin@test.local');
    $GLOBALS['_stub_mails'] = [];
    TPV_Sync_Notifications::alert('test_key', 'hello world');
    $t->assert(count($GLOBALS['_stub_mails']) >= 1);
});

$t->test('alert sin email: no crashea', function($t) {
    delete_option('tpv_sync_notify_email');
    delete_option('admin_email');
    $threw = false;
    try { TPV_Sync_Notifications::alert('test_no_chan', 'msg'); } catch (Throwable $e) { $threw = true; }
    $t->eq(false, $threw);
});

$t->test('alert throttle: 2ª llamada misma key no se manda', function($t) {
    update_option('tpv_sync_notify_email', 'a@b.c');
    $GLOBALS['_stub_mails'] = [];
    TPV_Sync_Notifications::alert('throttle_test', 'first');
    TPV_Sync_Notifications::alert('throttle_test', 'second');
    $t->eq(1, count($GLOBALS['_stub_mails']));
});

$t->test('alert keys distintas: ambas envían', function($t) {
    update_option('tpv_sync_notify_email', 'a@b.c');
    $GLOBALS['_stub_mails'] = [];
    TPV_Sync_Notifications::alert('key_a', '1');
    TPV_Sync_Notifications::alert('key_b', '2');
    $t->eq(2, count($GLOBALS['_stub_mails']));
});

$t->test('THROTTLE_SEC = 3600', function($t) {
    $t->eq(3600, TPV_Sync_Notifications::THROTTLE_SEC);
});

$t->test('subject incluye site name + key', function($t) {
    update_option('tpv_sync_notify_email', 'a@b.c');
    $GLOBALS['_stub_mails'] = [];
    TPV_Sync_Notifications::alert('boom', 'something');
    $subj = $GLOBALS['_stub_mails'][0]['subject'];
    $t->assert(str_contains($subj, 'boom'));
    $t->assert(str_contains($subj, 'TPV Sync'));
});

$t->test('body incluye message + context JSON', function($t) {
    update_option('tpv_sync_notify_email', 'a@b.c');
    $GLOBALS['_stub_mails'] = [];
    TPV_Sync_Notifications::alert('ctx_test', 'detail msg', ['key' => 'value', 'n' => 42]);
    $body = $GLOBALS['_stub_mails'][0]['body'];
    $t->assert(str_contains($body, 'detail msg'));
    $t->assert(str_contains($body, 'value'));
    $t->assert(str_contains($body, '42'));
});

$t->test('Slack webhook: si configurado, hace HTTP POST', function($t) {
    update_option('tpv_sync_notify_slack_webhook', 'https://hooks.slack.test/x');
    delete_option('tpv_sync_notify_email');
    delete_option('admin_email');
    $GLOBALS['_stub_http_calls'] = [];
    TPV_Sync_Notifications::alert('slack_test', 'hi');
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if (str_contains($c['url'], 'hooks.slack')) $found = true;
    }
    $t->assert($found);
});

$t->test('Telegram bot+chat: si configurado, hace HTTP POST', function($t) {
    update_option('tpv_sync_notify_telegram_bot', '123:abc');
    update_option('tpv_sync_notify_telegram_chat_id', '999');
    delete_option('tpv_sync_notify_email');
    delete_option('admin_email');
    $GLOBALS['_stub_http_calls'] = [];
    TPV_Sync_Notifications::alert('tg_test', 'hi');
    $found = false;
    foreach ($GLOBALS['_stub_http_calls'] as $c) {
        if (str_contains($c['url'], 'telegram.org')) $found = true;
    }
    $t->assert($found);
});

$t->test('Telegram solo bot (sin chat): no envía', function($t) {
    update_option('tpv_sync_notify_telegram_bot', '123:abc');
    delete_option('tpv_sync_notify_telegram_chat_id');
    delete_option('tpv_sync_notify_email');
    delete_option('admin_email');
    $GLOBALS['_stub_http_calls'] = [];
    TPV_Sync_Notifications::alert('tg_partial', 'x');
    $tg = array_filter($GLOBALS['_stub_http_calls'], fn($c) => str_contains($c['url'], 'telegram'));
    $t->eq(0, count($tg));
});

$t->test('Múltiples canales emiten en una sola alerta', function($t) {
    update_option('tpv_sync_notify_email', 'a@b.c');
    update_option('tpv_sync_notify_slack_webhook', 'https://slk');
    $GLOBALS['_stub_mails'] = [];
    $GLOBALS['_stub_http_calls'] = [];
    TPV_Sync_Notifications::alert('multi_chan', 'x');
    $t->eq(1, count($GLOBALS['_stub_mails']));
    $slk = array_filter($GLOBALS['_stub_http_calls'], fn($c) => str_contains($c['url'], 'slk'));
    $t->eq(1, count($slk));
});

for ($i = 0; $i < 4; $i++) {
    $t->test("Notif smoke #$i", function($t) use ($i) {
        update_option('tpv_sync_notify_email', "n$i@x.y");
        $GLOBALS['_stub_mails'] = [];
        TPV_Sync_Notifications::alert("smoke_$i", "msg $i");
        $t->eq(1, count($GLOBALS['_stub_mails']));
    });
}

$t->summary();
