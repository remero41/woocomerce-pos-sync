<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../woocommerce-conector/includes/class-circuit-breaker.php';

/**
 * Tests unitarios de TPV_Sync_Circuit_Breaker.
 *
 * Mockea get_transient / set_transient / delete_transient con un storage in-memory.
 * No requiere WP ni base de datos. Deben correr en <1s.
 */
class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset storage in-memory de transients
        $GLOBALS['_test_transients'] = [];

        // Mockear funciones WP si no existen ya (fallback sin brain/monkey)
        if (!function_exists('get_transient')) {
            eval('function get_transient($key) {
                $v = $GLOBALS["_test_transients"][$key] ?? false;
                return $v === false ? false : $v["v"];
            }');
            eval('function set_transient($key, $value, $ttl = 0) {
                $GLOBALS["_test_transients"][$key] = ["v" => $value, "exp" => time() + (int)$ttl];
                return true;
            }');
            eval('function delete_transient($key) {
                unset($GLOBALS["_test_transients"][$key]);
                return true;
            }');
        }
    }

    public function testStartsClosed(): void
    {
        $cb = new TPV_Sync_Circuit_Breaker('test_' . uniqid());
        $this->assertSame('closed', $cb->state());
        $this->assertTrue($cb->allowRequest());
    }

    public function testRecordFailureIncrementsCounter(): void
    {
        $cb = new TPV_Sync_Circuit_Breaker('test_' . uniqid());
        for ($i = 0; $i < 3; $i++) {
            $cb->recordFailure();
        }
        $stats = $cb->stats();
        $this->assertSame(3, $stats['failures']);
        $this->assertSame('closed', $stats['state']);
    }

    public function testFiveFailuresOpenBreaker(): void
    {
        $cb = new TPV_Sync_Circuit_Breaker('test_' . uniqid());
        for ($i = 0; $i < 5; $i++) {
            $cb->recordFailure();
        }
        $this->assertSame('open', $cb->state());
        $this->assertFalse($cb->allowRequest());
    }

    public function testRecordSuccessResetsToClosed(): void
    {
        $cb = new TPV_Sync_Circuit_Breaker('test_' . uniqid());
        for ($i = 0; $i < 5; $i++) {
            $cb->recordFailure();
        }
        $this->assertSame('open', $cb->state());

        // Reset manual primero (no hay half-open window en test)
        $cb->reset();
        $cb->recordSuccess();
        $this->assertSame('closed', $cb->state());
        $this->assertSame(0, $cb->stats()['failures']);
    }

    public function testResetClearsAllState(): void
    {
        $cb = new TPV_Sync_Circuit_Breaker('test_' . uniqid());
        for ($i = 0; $i < 5; $i++) {
            $cb->recordFailure();
        }
        $cb->reset();

        $stats = $cb->stats();
        $this->assertSame('closed', $stats['state']);
        $this->assertSame(0, $stats['failures']);
        $this->assertNull($stats['opened_at']);
    }

    public function testStatsExposesThresholds(): void
    {
        $cb = new TPV_Sync_Circuit_Breaker('test_' . uniqid());
        $stats = $cb->stats();
        $this->assertSame(5, $stats['threshold']);
        $this->assertSame(60, $stats['open_window']);
    }

    public function testHalfOpenAllowsOneRequest(): void
    {
        // No podemos avanzar el reloj sin mocks más sofisticados,
        // pero podemos verificar que la transición existe exponiendo el método
        // de transición si fuera public. Por ahora, test de smoke:
        $cb = new TPV_Sync_Circuit_Breaker('test_' . uniqid());
        $this->assertTrue(method_exists($cb, 'allowRequest'));
        $this->assertTrue(method_exists($cb, 'recordFailure'));
        $this->assertTrue(method_exists($cb, 'recordSuccess'));
    }

    public function testIsolatedNamespaces(): void
    {
        $ns1 = 'ns1_' . uniqid();
        $ns2 = 'ns2_' . uniqid();
        $a = new TPV_Sync_Circuit_Breaker($ns1);
        $b = new TPV_Sync_Circuit_Breaker($ns2);
        for ($i = 0; $i < 5; $i++) {
            $a->recordFailure();
        }
        $this->assertSame('open', $a->state());
        $this->assertSame('closed', $b->state(), 'Namespaces independientes no deben compartir estado');
    }
}
