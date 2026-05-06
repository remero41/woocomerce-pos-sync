<?php
declare(strict_types=1);
/**
 * Encriptación simétrica de secrets en wp_options.
 *
 * Usa libsodium (sodium_crypto_secretbox) que está disponible desde PHP 7.2+
 * y desde WP 5.2 como wp_generate_uuid4() / wp_hash() etc.
 *
 * Key derivation: SHA256(AUTH_KEY || SECURE_AUTH_KEY || LOGGED_IN_KEY) → 32 bytes.
 * Si alguna no está definida, se usa un fallback persistido en wp_options.
 *
 * Formato almacenado:
 *   "enc:v1:<base64(nonce||ciphertext)>"
 *
 * API:
 *   TPV_Sync_Secrets::encrypt($plain)  → string almacenable
 *   TPV_Sync_Secrets::decrypt($stored) → plaintext (o '' si falla)
 *
 * Integrado con las opciones del plugin: al guardar se encripta, al leer se
 * desencripta automáticamente vía filter 'option_<name>' de WP.
 */
defined('ABSPATH') || exit;

class TPV_Sync_Secrets
{
    public const PREFIX = 'enc:v1:';

    /** Opciones que contienen secrets — se encriptan/desencriptan automáticamente. */
    public const SECRET_OPTIONS = [
        'tpv_sync_client_secret',
        'tpv_sync_webhook_secret',
    ];

    /**
     * Deriva una clave de 32 bytes a partir de las AUTH_KEYs de wp-config.
     * Si wp-config tiene las 3 claves (es lo habitual) son 192 bytes de
     * entropía → más que suficiente.
     */
    private static function deriveKey(): string
    {
        $material = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $c) {
            if (defined($c)) $material .= constant($c);
        }
        if ($material === '') {
            // Fallback: clave persistida en opciones (menos seguro, pero algo es algo)
            $key = get_option('tpv_sync_secrets_fallback_key');
            if (!$key) {
                $key = base64_encode(random_bytes(32));
                update_option('tpv_sync_secrets_fallback_key', $key, false);
            }
            $material = base64_decode($key);
        }
        return substr(hash('sha256', $material, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Encripta un string. Devuelve "enc:v1:base64(nonce||ciphertext)".
     * Si ya está encriptado (tiene el prefijo), lo devuelve tal cual.
     * Si $plain es vacío, devuelve vacío.
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '' || self::isEncrypted($plain)) return $plain;
        if (!function_exists('sodium_crypto_secretbox')) return $plain; // sin libsodium: plain
        $key    = self::deriveKey();
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Desencripta un string con el prefijo enc:v1:. Si no está encriptado,
     * lo devuelve tal cual (retrocompatibilidad con valores legacy).
     *
     * Si el descifrado falla (ciphertext corrupto o cifrado con clave
     * distinta — caso típico: AUTH_KEY rotada, restauración de backup
     * desde otro entorno, migración), devolvemos '' y dejamos un flag
     * en wp_options para que el admin muestre banner accionable. Sin
     * esto, el cliente ve "invalid_client" en /auth/token sin pista
     * de la causa.
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || !self::isEncrypted($stored)) return $stored;
        if (!function_exists('sodium_crypto_secretbox_open')) return '';
        $b64   = substr($stored, strlen(self::PREFIX));
        $raw   = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            self::flagDecryptFailure('base64_or_too_short');
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key   = self::deriveKey();
        $plain = sodium_crypto_secretbox_open($ct, $nonce, $key);
        if ($plain === false) {
            self::flagDecryptFailure('mac_or_key_mismatch');
            return '';
        }
        return $plain;
    }

    /**
     * Marca en wp_options que un descifrado ha fallado, para que el admin
     * muestre el banner. Se limpia en cuanto un guardado nuevo sustituye
     * el ciphertext con uno cifrado por la AUTH_KEY actual.
     */
    private static function flagDecryptFailure(string $reason): void
    {
        update_option('tpv_sync_secret_decrypt_failed', [
            'reason' => $reason,
            'at'     => time(),
        ], false);
    }

    /**
     * Marca que la auth contra el TPV ha devuelto invalid_client (el
     * conector no existe / secret rotado / cid pegado a otra tienda).
     * Distinto de decrypt_failed: aquí el descifrado funcionó pero las
     * credenciales NO coinciden con ningún conector del TPV.
     *
     * Se llama desde parse() en class-api-client.php cuando recibe 401
     * con error invalid_client. Se limpia al guardar un secret nuevo
     * (filter_pre_update).
     */
    public static function flagInvalidCredentials(string $reason): void
    {
        update_option('tpv_sync_invalid_credentials', [
            'reason' => $reason,
            'at'     => time(),
        ], false);
    }

    public static function isEncrypted(string $s): bool
    {
        return str_starts_with($s, self::PREFIX);
    }

    /**
     * Registra los filtros/actions de WP para encriptar/desencriptar
     * automáticamente las opciones listadas en SECRET_OPTIONS.
     */
    public static function register_filters(): void
    {
        foreach (self::SECRET_OPTIONS as $opt) {
            add_filter("pre_update_option_$opt", [self::class, 'filter_pre_update'], 10, 1);
            add_filter("option_$opt",            [self::class, 'filter_option'],      10, 1);
        }
    }

    /** Filter: encripta valor antes de guardar en wp_options. */
    public static function filter_pre_update($value): string
    {
        if (!is_string($value) || $value === '') return (string)$value;
        // Si ya viene con prefijo enc:v1:, asumimos que es ciphertext
        // válido y NO re-ciframos (eso produciría enc:v1:enc:v1:... y al
        // descifrar saldría basura). Esto evita el bug de "secret cifrado
        // dos veces" que rompía la auth tras editar el secret.
        if (self::isEncrypted($value)) return $value;

        // Al guardar un secret nuevo, limpiamos los flags de fallo previo.
        // El cliente acaba de pegar credenciales nuevas — démosle la
        // oportunidad de funcionar antes de molestar con los banners.
        delete_option('tpv_sync_secret_decrypt_failed');
        delete_option('tpv_sync_invalid_credentials');

        return self::encrypt($value);
    }

    /** Filter: desencripta valor al leer de wp_options. */
    public static function filter_option($value): string
    {
        if (!is_string($value) || $value === '') return (string)$value;
        return self::decrypt($value);
    }

    /**
     * Migración: encripta secrets que estén en texto plano en BD.
     * Se ejecuta una sola vez al activar el plugin o al cargar si detecta
     * valores legacy.
     */
    public static function migrate_plaintext(): int
    {
        $migrated = 0;
        global $wpdb;
        foreach (self::SECRET_OPTIONS as $opt) {
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $opt
            ));
            if ($raw && !self::isEncrypted($raw)) {
                $wpdb->update($wpdb->options, ['option_value' => self::encrypt($raw)], ['option_name' => $opt]);
                $migrated++;
            }
        }
        return $migrated;
    }
}
