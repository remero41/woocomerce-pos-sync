<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios de TPV_Sync_Secrets: encrypt/decrypt round-trip + edge cases.
 *
 * Requiere libsodium (nativo PHP 7.2+). Si el entorno no lo tiene, marca los
 * tests como skipped.
 */
class SecretsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // AUTH_KEYs mínimas para derivar clave estable.
        if (!defined('AUTH_KEY'))        define('AUTH_KEY',        'test-auth-key-for-unit-tests-only');
        if (!defined('SECURE_AUTH_KEY')) define('SECURE_AUTH_KEY', 'test-secure-auth-key-for-unit-tests');
        if (!defined('LOGGED_IN_KEY'))   define('LOGGED_IN_KEY',   'test-logged-in-key-for-unit-tests');
        if (!defined('NONCE_KEY'))       define('NONCE_KEY',       'test-nonce-key-for-unit-tests');
        if (!defined('ABSPATH'))         define('ABSPATH', '/tmp/');

        require_once __DIR__ . '/../../includes/class-secrets.php';
    }

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium no disponible en este entorno.');
        }
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plain = 'super-secret-value-' . bin2hex(random_bytes(8));
        $enc   = TPV_Sync_Secrets::encrypt($plain);
        $this->assertNotSame($plain, $enc, 'Valor encriptado debe diferir del plano.');
        $this->assertStringStartsWith('enc:v1:', $enc, 'Prefijo de formato debe estar presente.');
        $this->assertSame($plain, TPV_Sync_Secrets::decrypt($enc));
    }

    public function testEncryptEmptyStringIsNoOp(): void
    {
        $this->assertSame('', TPV_Sync_Secrets::encrypt(''));
    }

    public function testEncryptingAlreadyEncryptedValueIsNoOp(): void
    {
        $plain = 'foo';
        $enc1  = TPV_Sync_Secrets::encrypt($plain);
        $enc2  = TPV_Sync_Secrets::encrypt($enc1);
        $this->assertSame($enc1, $enc2,
            'Un valor ya encriptado no debe volver a cifrarse (evita doble-enc en filters de WP).');
    }

    public function testDecryptPlaintextReturnsPlaintext(): void
    {
        $this->assertSame('not-encrypted', TPV_Sync_Secrets::decrypt('not-encrypted'),
            'Decrypt de valor plano (legacy) lo devuelve tal cual.');
    }

    public function testDecryptEmptyReturnsEmpty(): void
    {
        $this->assertSame('', TPV_Sync_Secrets::decrypt(''));
    }

    public function testIsEncryptedDetectsPrefix(): void
    {
        $this->assertFalse(TPV_Sync_Secrets::isEncrypted(''));
        $this->assertFalse(TPV_Sync_Secrets::isEncrypted('plain'));
        $this->assertTrue(TPV_Sync_Secrets::isEncrypted('enc:v1:something'));
    }

    public function testDecryptCorruptedCiphertextReturnsEmpty(): void
    {
        $corrupted = 'enc:v1:' . base64_encode('garbage-data-not-a-real-ciphertext');
        $this->assertSame('', TPV_Sync_Secrets::decrypt($corrupted),
            'Ciphertext corrupto → "" (no exception).');
    }

    public function testTwoEncryptionsOfSameValueProduceDifferentCiphertexts(): void
    {
        $plain = 'same-value';
        $enc1  = TPV_Sync_Secrets::encrypt($plain);
        $enc2  = TPV_Sync_Secrets::encrypt($plain);
        $this->assertNotSame($enc1, $enc2,
            'Nonce aleatorio: dos cifrados del mismo plaintext deben dar ciphertexts distintos.');
        // Pero ambos deben desencriptar al mismo plaintext
        $this->assertSame($plain, TPV_Sync_Secrets::decrypt($enc1));
        $this->assertSame($plain, TPV_Sync_Secrets::decrypt($enc2));
    }

    public function testSecretOptionsListHasExpectedKeys(): void
    {
        $this->assertContains('tpv_sync_client_secret',  TPV_Sync_Secrets::SECRET_OPTIONS);
        $this->assertContains('tpv_sync_webhook_secret', TPV_Sync_Secrets::SECRET_OPTIONS);
    }

    public function testEncryptHandlesLargeValues(): void
    {
        $plain = str_repeat('A', 4096);
        $enc   = TPV_Sync_Secrets::encrypt($plain);
        $this->assertSame($plain, TPV_Sync_Secrets::decrypt($enc));
    }

    public function testEncryptHandlesUnicodeAndEmoji(): void
    {
        $plain = 'Café ☕ emoji 🔐 中文';
        $enc   = TPV_Sync_Secrets::encrypt($plain);
        $this->assertSame($plain, TPV_Sync_Secrets::decrypt($enc));
    }
}
