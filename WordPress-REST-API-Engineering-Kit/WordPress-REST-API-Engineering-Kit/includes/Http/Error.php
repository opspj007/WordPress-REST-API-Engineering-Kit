<?php

declare(strict_types=1);

namespace RAEK\Http;

use WP_Error;

/**
 * Error factory. Every API failure goes through here so status codes, error codes and
 * response headers stay consistent. Request_Lifecycle renders these as RFC 9457 problems.
 */
final class Error
{
    /** @param array<string,mixed> $extra */
    public static function make(string $code, string $message, int $status, array $extra = []): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status] + $extra);
    }

    public static function unauthorized(string $code = 'raek_unauthorized', string $message = 'Authentication is required to access this resource.', bool $invalid_token = false): WP_Error
    {
        $challenge = 'Bearer realm="raek"' . ($invalid_token ? ', error="invalid_token"' : '');
        return self::make($code, $message, 401, ['raek_headers' => ['WWW-Authenticate' => $challenge]]);
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): WP_Error
    {
        return self::make('raek_forbidden', $message, 403);
    }

    public static function not_found(string $what = 'Resource'): WP_Error
    {
        return self::make('raek_not_found', sprintf('%s not found.', $what), 404);
    }

    public static function conflict(string $code, string $message): WP_Error
    {
        return self::make($code, $message, 409);
    }

    public static function precondition_failed(string $message = 'The resource was modified since you last fetched it.'): WP_Error
    {
        return self::make('raek_precondition_failed', $message, 412);
    }

    /** @param array<string,string> $fields field => message */
    public static function validation(array $fields, string $message = 'The request body failed validation.'): WP_Error
    {
        $errors = [];
        foreach ($fields as $field => $text) {
            $errors[] = ['field' => (string) $field, 'message' => $text, 'code' => 'invalid'];
        }
        return self::make('raek_validation_failed', $message, 422, ['raek_errors' => $errors]);
    }

    public static function rate_limited(int $retry_after): WP_Error
    {
        return self::make('raek_rate_limited', 'Too many requests. Slow down and retry later.', 429, [
            'raek_headers' => ['Retry-After' => (string) $retry_after],
        ]);
    }
}
