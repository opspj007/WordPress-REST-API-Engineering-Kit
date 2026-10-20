<?php

declare(strict_types=1);

namespace RAEK\Http;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Offset pagination metadata: X-WP-Total / X-WP-TotalPages (WordPress convention)
 * plus RFC 8288 `Link` headers so clients never build URLs by hand.
 */
final class Pagination
{
    public static function apply(WP_REST_Response $response, WP_REST_Request $request, int $total, int $per_page, int $page): WP_REST_Response
    {
        $pages = max(1, (int) ceil($total / max(1, $per_page)));
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $pages);

        $base = rest_url(ltrim($request->get_route(), '/'));
        $params = self::safe_params($request);
        $url = static fn(int $p): string => add_query_arg($params + ['page' => $p], $base);

        $response->link_header('first', $url(1));
        if ($page > 1) {
            $response->link_header('prev', $url(min($page - 1, $pages)));
        }
        if ($page < $pages) {
            $response->link_header('next', $url($page + 1));
        }
        $response->link_header('last', $url($pages));

        return $response;
    }

    /**
     * Scalar query params, URL-encoded (add_query_arg does not encode values), minus paging keys.
     *
     * @return array<string,string>
     */
    public static function safe_params(WP_REST_Request $request): array
    {
        $out = [];
        foreach ($request->get_query_params() as $key => $value) {
            if (is_scalar($value) && !in_array($key, ['page', 'cursor'], true)) {
                $out[rawurlencode((string) $key)] = rawurlencode((string) $value);
            }
        }
        return $out;
    }
}
