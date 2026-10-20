<?php

declare(strict_types=1);

namespace RAEK\Api\V1;

use RAEK\Api\Base_Controller;
use RAEK\Api\Provides_Schemas;
use RAEK\Docs\OpenApi;
use WP_REST_Server;

final class Meta_Controller extends Base_Controller implements Provides_Schemas
{
    public function __construct(private OpenApi $openapi)
    {
        $this->namespace = 'raek/v1';
        $this->rest_base = 'meta';
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => '__return_true',
            'raek_docs' => self::docs('Liveness/readiness probe', 'Meta', '~Health', 200, false),
        ]);

        register_rest_route($this->namespace, '/openapi', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn($request) => rest_ensure_response($this->openapi->build($request['version'] ?: null)),
            'permission_callback' => '__return_true',
            'args' => ['version' => ['type' => 'string', 'enum' => ['v1', 'v2'], 'description' => 'Limit the document to one API version.']],
            'raek_docs' => self::docs('OpenAPI 3.1 document, generated from the registered routes', 'Meta', null, 200, false),
        ]);
    }

    public function health($request)
    {
        global $wpdb;
        $db_ok = (string) $wpdb->get_var('SELECT 1') === '1';
        $response = rest_ensure_response([
            'status' => $db_ok ? 'ok' : 'degraded',
            'version' => \RAEK\VERSION,
            'database' => $db_ok,
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $response->set_status($db_ok ? 200 : 503);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function schemas(): array
    {
        return [
            '~Health' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok', 'degraded']],
                'version' => ['type' => 'string'],
                'database' => ['type' => 'boolean'],
                'time' => ['type' => 'string', 'format' => 'date-time'],
            ]],
            '~Problem' => ['type' => 'object', 'description' => 'RFC 9457 problem details.', 'properties' => [
                'type' => ['type' => 'string', 'example' => 'urn:raek:problem:raek_not_found'],
                'title' => ['type' => 'string', 'example' => 'Not Found'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'code' => ['type' => 'string', 'description' => 'Stable machine-readable error code.'],
                'request_id' => ['type' => 'string', 'description' => 'Matches the X-Request-Id header; quote it in bug reports.'],
                'errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string'], 'message' => ['type' => 'string'], 'code' => ['type' => 'string'],
                ]]],
            ]],
        ];
    }
}
