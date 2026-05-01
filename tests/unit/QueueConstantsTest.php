<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests regresivos sobre las constantes de la cola.
 *
 * Si alguien cambia MAX_ATTEMPTS o BACKOFF_MINUTES sin pensar en el impacto
 * (horas esperando un reintento en un banco de jobs), estos tests fallan.
 *
 * No necesitamos mockear $wpdb aquí — probamos los invariantes de diseño.
 */
class QueueConstantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
        if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
        if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
        require_once __DIR__ . '/../../includes/class-queue.php';
    }

    public function testMaxAttemptsIs6(): void
    {
        $this->assertSame(6, TPV_Sync_Queue::MAX_ATTEMPTS,
            'Cambiar MAX_ATTEMPTS rompe el contrato: jobs se abandonan tras 6 intentos.');
    }

    public function testBackoffHasExactlyMaxAttemptsEntries(): void
    {
        $this->assertCount(
            TPV_Sync_Queue::MAX_ATTEMPTS,
            TPV_Sync_Queue::BACKOFF_MINUTES,
            'BACKOFF_MINUTES debe tener un valor por cada intento.'
        );
    }

    public function testBackoffIsMonotonicallyIncreasing(): void
    {
        $prev = 0;
        foreach (TPV_Sync_Queue::BACKOFF_MINUTES as $idx => $minutes) {
            $this->assertGreaterThan($prev, $minutes,
                "BACKOFF_MINUTES[$idx]=$minutes debe ser mayor que el anterior ($prev)");
            $prev = $minutes;
        }
    }

    public function testBackoffMaxIsAtLeast24h(): void
    {
        $arr = TPV_Sync_Queue::BACKOFF_MINUTES;
        $last = end($arr);
        $this->assertGreaterThanOrEqual(
            24 * 60,
            $last,
            'El último backoff debe ser al menos 24h (cubre el backoff máximo del dispatcher TPV).'
        );
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('pending',   TPV_Sync_Queue::STATUS_PENDING);
        $this->assertSame('done',      TPV_Sync_Queue::STATUS_DONE);
        $this->assertSame('abandoned', TPV_Sync_Queue::STATUS_ABANDONED);
    }

    public function testBackoffFirstAttemptIsShort(): void
    {
        $first = TPV_Sync_Queue::BACKOFF_MINUTES[0];
        $this->assertLessThanOrEqual(5, $first,
            'El primer retry debe ser rápido (<=5min) para fallos transitorios.');
    }
}
