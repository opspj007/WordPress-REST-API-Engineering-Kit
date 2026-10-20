<?php

declare(strict_types=1);

namespace RAEK\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;

/** Runs requests through the real REST server, including the post-dispatch filter chain. */
abstract class ApiTestCase extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
        add_filter('raek_rate_limits', static fn() => []); // off by default; enabled per-test
    }

    /** @param array<string,mixed>|null $body */
    protected function call(string $method, string $route, ?array $body = null, array $query = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_query_params($query);
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        if ($body !== null) {
            $request->set_header('content-type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        global $wp_rest_server;
        $response = rest_ensure_response($wp_rest_server->dispatch($request));
        return apply_filters('rest_post_dispatch', $response, $wp_rest_server, $request); // normally run by serve_request()
    }

    protected function login_as_new_user(string $role = 'subscriber'): int
    {
        $id = self::factory()->user->create(['role' => $role]);
        wp_set_current_user($id);
        return $id;
    }
}
