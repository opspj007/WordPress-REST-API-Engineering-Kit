<?php

declare(strict_types=1);

namespace RAEK\Webhooks;

use WP_Error;

/**
 * SSRF guard for user-supplied callback URLs. Checked at registration and again at delivery
 * (delivery uses wp_safe_remote_post, which re-validates after DNS resolution).
 */
final class Endpoint_Policy
{
    /** @return true|WP_Error */
    public static function validate(string $url)
    {
        if (strlen($url) > 2000) {
            return self::fail('The URL must be at most 2000 characters.');
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return self::fail('The URL is not valid.');
        }
        $allow_http = (bool) apply_filters('raek_allow_insecure_webhooks', in_array(wp_get_environment_type(), ['local', 'development'], true));
        if ($parts['scheme'] !== 'https' && !($allow_http && $parts['scheme'] === 'http')) {
            return self::fail('Webhook URLs must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::fail('Credentials in the URL are not allowed.');
        }
        if (!wp_http_validate_url($url)) {
            return self::fail('The host is not allowed (private, loopback or unresolvable addresses are blocked).');
        }
        return true;
    }

    private static function fail(string $message): WP_Error
    {
        return new WP_Error('rest_invalid_param', $message, ['status' => 400]);
    }
}
