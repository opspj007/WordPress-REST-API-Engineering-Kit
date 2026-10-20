<?php

declare(strict_types=1);

namespace RAEK\Webhooks;

/**
 * Stripe-style signatures: HMAC-SHA256 over "{timestamp}.{raw body}".
 * Binding the timestamp into the MAC (and enforcing a tolerance) defeats replay attacks.
 */
final class Signer
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $secret, int $timestamp, string $body, string $header, int $tolerance = 300, ?int $now = null): bool
    {
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }
        return hash_equals(self::sign($secret, $timestamp, $body), $header);
    }
}
