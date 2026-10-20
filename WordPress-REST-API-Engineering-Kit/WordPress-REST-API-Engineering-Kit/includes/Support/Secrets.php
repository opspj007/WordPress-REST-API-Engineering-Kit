<?php

declare(strict_types=1);

namespace RAEK\Support;

/**
 * Key material and at-rest encryption for stored webhook secrets.
 */
final class Secrets
{
    /** Prefer a constant in wp-config.php; fall back to a generated, non-autoloaded option. */
    public static function jwt_key(): string
    {
        if (defined('RAEK_JWT_SECRET') && is_string(RAEK_JWT_SECRET) && strlen(RAEK_JWT_SECRET) >= 32) {
            return RAEK_JWT_SECRET;
        }
        $key = get_option('raek_jwt_secret');
        if (!is_string($key) || strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            update_option('raek_jwt_secret', $key, false);
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::box_key()));
    }

    public static function decrypt(string $blob): ?string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::box_key());
        return $plain === false ? null : $plain;
    }

    private static function box_key(): string
    {
        return hash('sha256', wp_salt('auth') . '|raek-webhook-secrets', true);
    }
}
