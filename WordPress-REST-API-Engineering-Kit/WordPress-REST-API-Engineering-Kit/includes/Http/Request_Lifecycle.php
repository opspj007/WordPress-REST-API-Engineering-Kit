<?php

declare(strict_types=1);

namespace RAEK\Http;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Cross-cutting concerns for every `/raek/*` route, implemented as REST server filters
 * so individual controllers stay free of plumbing:
 *
 *   rest_pre_dispatch   -> request ID + rate limiting (short-circuits with 429)
 *   rest_post_dispatch  -> X-Request-Id, X-RateLimit-*, deprecation headers,
 *                          and conversion of every error into RFC 9457 problem+json
 */
final class Request_Lifecycle
{
    private string $request_id = '';
    /** @var array<string,int|bool>|null */
    private ?array $rate = null;

    public function __construct(private Rate_Limiter $limiter)
    {
    }

    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'before'], 10, 3);
        add_filter('rest_post_dispatch', [$this, 'after'], 10, 3);
    }

    public function before(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if (!$this->is_ours($request)) {
            return $result;
        }

        $this->rate = null;
        $inbound = (string) $request->get_header('x_request_id');
        $this->request_id = preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $inbound) ? $inbound : wp_generate_uuid4();

        $bucket = $this->bucket($request);
        $limits = (array) apply_filters('raek_rate_limits', [
            'auth' => [5, 60],    // credential endpoints: brute-force protection
            'write' => [30, 60],
            'read' => [120, 60],
        ]);
        if (!isset($limits[$bucket])) {
            return $result;
        }

        [$limit, $window] = $limits[$bucket];
        $user_id = get_current_user_id();
        $identity = ($bucket !== 'auth' && $user_id) ? 'user:' . $user_id : 'ip:' . $this->client_ip();
        $this->rate = $this->limiter->hit($bucket, $identity, (int) $limit, (int) $window);

        return $this->rate['allowed'] ? $result : Error::rate_limited((int) $this->rate['retry_after']);
    }

    public function after(mixed $response, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if (!$this->is_ours($request) || !$response instanceof WP_HTTP_Response) {
            return $response;
        }

        $response->header('X-Request-Id', $this->request_id ?: wp_generate_uuid4());
        if ($this->rate) {
            $response->header('X-RateLimit-Limit', (string) $this->rate['limit']);
            $response->header('X-RateLimit-Remaining', (string) $this->rate['remaining']);
            $response->header('X-RateLimit-Reset', (string) $this->rate['reset']);
        }
        $this->add_deprecation_headers($response, $request);

        if ($response->get_status() >= 400) {
            $this->to_problem($response, $request);
        }
        return $response;
    }

    private function is_ours(WP_REST_Request $request): bool
    {
        return str_starts_with($request->get_route(), '/raek/');
    }

    private function bucket(WP_REST_Request $request): string
    {
        if (preg_match('#^/raek/v\d+/auth/(token|refresh)$#', $request->get_route())) {
            return 'auth';
        }
        return in_array($request->get_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'write' : 'read';
    }

    /**
     * Only REMOTE_ADDR is trusted by default. Behind a proxy, return the real client IP
     * from the `raek_client_ip` filter (never read X-Forwarded-For blindly: it is spoofable).
     */
    private function client_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
        return (string) apply_filters('raek_client_ip', $ip);
    }

    /** Opt-in v1 retirement: add_filter('raek_v1_sunset', fn() => '2027-06-30'); */
    private function add_deprecation_headers(WP_HTTP_Response $response, WP_REST_Request $request): void
    {
        $route = $request->get_route();
        $sunset = apply_filters('raek_v1_sunset', null);
        if (!is_string($sunset) || !str_starts_with($route, '/raek/v1/')) {
            return;
        }
        $response->header('Deprecation', 'true');
        $timestamp = strtotime($sunset);
        if ($timestamp) {
            $response->header('Sunset', gmdate('D, d M Y H:i:s', $timestamp) . ' GMT');
        }
        if (str_starts_with($route, '/raek/v1/items') && $response instanceof \WP_REST_Response) {
            $response->link_header('successor-version', rest_url('raek/v2/' . substr($route, strlen('/raek/v1/'))));
        }
    }

    private function to_problem(WP_HTTP_Response $response, WP_REST_Request $request): void
    {
        $data = $response->get_data();
        if (!is_array($data) || !isset($data['code'], $data['message'])) {
            return;
        }

        $status = $response->get_status();
        $extra = is_array($data['data'] ?? null) ? $data['data'] : [];
        foreach ((array) ($extra['raek_headers'] ?? []) as $name => $value) {
            $response->header((string) $name, (string) $value);
        }

        $detail = (string) $data['message'];
        if ($status >= 500) {
            // Never leak internals: log the real message, return a generic one.
            error_log(sprintf('[raek] %s %s -> %d %s (request %s)', $request->get_method(), $request->get_route(), $status, $detail, $this->request_id)); // phpcs:ignore
            $detail = 'An unexpected error occurred. Quote the request ID when contacting support.';
        }

        $problem = [
            'type' => 'urn:raek:problem:' . $data['code'],
            'title' => get_status_header_desc($status) ?: 'Error',
            'status' => $status,
            'detail' => $detail,
            'code' => (string) $data['code'],
            'request_id' => $this->request_id,
        ];
        $errors = $extra['raek_errors'] ?? $this->core_param_errors($extra);
        if ($errors) {
            $problem['errors'] = $errors;
        }

        $response->set_data($problem);
        $response->header('Content-Type', 'application/problem+json; charset=' . get_option('blog_charset', 'UTF-8'));
    }

    /**
     * Normalises core's `rest_invalid_param` payload into the same shape as our own errors.
     *
     * @param array<string,mixed> $extra
     * @return list<array{field:string,message:string,code:string}>
     */
    private function core_param_errors(array $extra): array
    {
        $out = [];
        foreach ((array) ($extra['params'] ?? []) as $field => $message) {
            $out[] = [
                'field' => (string) $field,
                'message' => (string) $message,
                'code' => (string) ($extra['details'][$field]['code'] ?? 'invalid'),
            ];
        }
        return $out;
    }
}
