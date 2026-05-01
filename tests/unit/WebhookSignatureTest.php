<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests de la verificación HMAC de webhooks entrantes.
 *
 * verify_signature es `public static` (refactor 2.0.1 para testabilidad).
 * No requiere instanciar la clase, WC ni WP.
 */
class WebhookSignatureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/stubs.php';
        require_once __DIR__ . '/../../includes/class-webhook-handler.php';
    }

    private const SECRET = '0123456789abcdef0123456789abcdef';
    private const BODY   = '{"event_type":"stock.adjusted","resource_id":1}';

    public function testValidSignaturePasses(): void
    {
        $sig = 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertTrue(
            TPV_Sync_Webhook::verify_signature(self::BODY, $sig, self::SECRET)
        );
    }

    public function testInvalidSignatureRejected(): void
    {
        $this->assertFalse(
            TPV_Sync_Webhook::verify_signature(self::BODY, 'sha256=FAKE', self::SECRET)
        );
    }

    public function testEmptySecretRejected(): void
    {
        $sig = 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertFalse(
            TPV_Sync_Webhook::verify_signature(self::BODY, $sig, ''),
            'secret vacío → rechazado (401 en handle)'
        );
    }

    public function testEmptySignatureRejected(): void
    {
        $this->assertFalse(
            TPV_Sync_Webhook::verify_signature(self::BODY, '', self::SECRET)
        );
    }

    public function testSignatureWithoutPrefixRejected(): void
    {
        $hmacOnly = hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertFalse(
            TPV_Sync_Webhook::verify_signature(self::BODY, $hmacOnly, self::SECRET),
            'firma sin "sha256=" prefix → rechazada'
        );
    }

    public function testSignatureWithDifferentBodyRejected(): void
    {
        $sig = 'sha256=' . hash_hmac('sha256', 'OTRO BODY', self::SECRET);
        $this->assertFalse(
            TPV_Sync_Webhook::verify_signature(self::BODY, $sig, self::SECRET)
        );
    }

    public function testSignatureWithDifferentSecretRejected(): void
    {
        $sig = 'sha256=' . hash_hmac('sha256', self::BODY, 'otro-secret');
        $this->assertFalse(
            TPV_Sync_Webhook::verify_signature(self::BODY, $sig, self::SECRET)
        );
    }

    public function testTimingSafeComparison(): void
    {
        // Este test sólo verifica que verify_signature NO usa === sino hash_equals.
        // No podemos medir timing aquí, pero sí chequear el código fuente.
        $src = file_get_contents(__DIR__ . '/../../includes/class-webhook-handler.php');
        $this->assertStringContainsString('hash_equals', $src,
            'verify_signature debe usar hash_equals (timing-safe), no === (vulnerable a timing attacks).');
    }

    public function testUnicodeBodyWorks(): void
    {
        $body = '{"name":"Café ☕","qty":5}';
        $sig  = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        $this->assertTrue(
            TPV_Sync_Webhook::verify_signature($body, $sig, self::SECRET)
        );
    }

    public function testNewlineInBodyHandled(): void
    {
        $body = "line1\nline2\n";
        $sig  = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        $this->assertTrue(
            TPV_Sync_Webhook::verify_signature($body, $sig, self::SECRET)
        );
    }
}
