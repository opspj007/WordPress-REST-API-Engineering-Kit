<?php

declare(strict_types=1);

namespace RAEK\Auth;

use WP_Error;

/**
 * Teaches WordPress to authenticate `Authorization: Bearer <jwt>`.
 *
 * Application Passwords (HTTP Basic) are handled by WordPress core and run first; this class
 * only acts when no user has been determined yet. Invalid tokens surface as 401 problems
 * through `rest_authentication_errors` instead of silently degrading to "anonymous".
 */
final class Authenticator
{
    private static ?WP_Error $error = null;
    private static bool $used_jwt = false;

    public function __construct(private Token_Service $tokens)
    {
    }

    public function register(): void
    {
        add_filter('determine_current_user', [$this, 'determine_user'], 20);
        add_filter('rest_authentication_errors', [$this, 'report_error'], 20);
    }

    public static function authenticated_via_jwt(): bool
    {
        return self::$used_jwt;
    }

    public function determine_user(mixed $user_id): mixed
    {
        if ($user_id || !$this->is_rest_request()) {
            return $user_id;
        }
        $token = $this->bearer_token();
        if ($token === null) {
            return $user_id;
        }

        $result = $this->tokens->authenticate_access($token);
        if ($result instanceof WP_Error) {
            self::$error = $result;
            return $user_id;
        }
        self::$used_jwt = true;
        return $result;
    }

    public function report_error(mixed $result): mixed
    {
        if (!empty($result)) {
            return $result;
        }
        return self::$error ?? $result;
    }

    private function bearer_token(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($header) && preg_match('/^Bearer\s+([A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]*)$/', trim($header), $m)) {
            return $m[1];
        }
        return null;
    }

    private function is_rest_request(): bool
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        return str_contains($uri, '/' . rest_get_url_prefix() . '/') || isset($_GET['rest_route']); // phpcs:ignore WordPress.Security.NonceVerification
    }
}
