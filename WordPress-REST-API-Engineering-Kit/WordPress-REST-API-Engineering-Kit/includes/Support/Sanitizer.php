<?php

declare(strict_types=1);

namespace RAEK\Support;

use WP_REST_Request;

/**
 * Sanitize callbacks for REST args. Each first runs core's schema-driven sanitizer,
 * then applies field-specific cleaning (core sanitizers must not receive extra args).
 */
final class Sanitizer
{
    public static function text(mixed $value, WP_REST_Request $request, string $param): string
    {
        return sanitize_text_field((string) rest_sanitize_request_arg($value, $request, $param));
    }

    public static function textarea(mixed $value, WP_REST_Request $request, string $param): string
    {
        return sanitize_textarea_field((string) rest_sanitize_request_arg($value, $request, $param));
    }

    public static function slug(mixed $value, WP_REST_Request $request, string $param): string
    {
        return sanitize_title((string) rest_sanitize_request_arg($value, $request, $param));
    }

    /** @return string[] */
    public static function tags(mixed $value, WP_REST_Request $request, string $param): array
    {
        $tags = rest_sanitize_request_arg($value, $request, $param);
        $tags = is_array($tags) ? array_map(static fn($t) => sanitize_text_field((string) $t), $tags) : [];
        return array_values(array_unique(array_filter($tags, static fn($t) => $t !== '')));
    }
}
