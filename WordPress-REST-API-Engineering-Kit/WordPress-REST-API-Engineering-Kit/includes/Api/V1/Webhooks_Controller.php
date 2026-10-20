<?php

declare(strict_types=1);

namespace RAEK\Api\V1;

use RAEK\Api\Base_Controller;
use RAEK\Api\Provides_Schemas;
use RAEK\Http\Error;
use RAEK\Http\Pagination;
use RAEK\Support\Sanitizer;
use RAEK\Support\Secrets;
use RAEK\Webhooks\Dispatcher;
use RAEK\Webhooks\Endpoint_Policy;
use RAEK\Webhooks\Repository;
use WP_REST_Response;
use WP_REST_Server;

final class Webhooks_Controller extends Base_Controller implements Provides_Schemas
{
    private const MAX_PER_USER = 10;

    public function __construct(private Repository $repository, private Dispatcher $dispatcher)
    {
        $this->namespace = 'raek/v1';
        $this->rest_base = 'webhooks';
    }

    public function register_routes(): void
    {
        $id_arg = ['id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Webhook identifier.']];
        $paging = [
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
        ];

        register_rest_route($this->namespace, '/webhooks', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'require_login'],
                'args' => $paging,
                'raek_docs' => self::docs('List your webhooks', 'Webhooks', 'WebhookList'),
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_item'],
                'permission_callback' => [$this, 'require_login'],
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
                'raek_docs' => self::docs('Subscribe a URL to events. The signing secret is returned once.', 'Webhooks', 'WebhookCreated', 201),
            ],
            'schema' => [$this, 'get_public_item_schema'],
        ]);

        register_rest_route($this->namespace, '/webhooks/(?P<id>[\d]+)', [
            'args' => $id_arg,
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'require_login'],
                'raek_docs' => self::docs('Get a webhook', 'Webhooks', 'Webhook'),
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'update_item'],
                'permission_callback' => [$this, 'require_login'],
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
                'raek_docs' => self::docs('Update a webhook (re-enabling resets the failure counter)', 'Webhooks', 'Webhook'),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'require_login'],
                'raek_docs' => self::docs('Delete a webhook and its delivery log', 'Webhooks', null, 204),
            ],
            'schema' => [$this, 'get_public_item_schema'],
        ]);

        register_rest_route($this->namespace, '/webhooks/(?P<id>[\d]+)/deliveries', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_deliveries'],
            'permission_callback' => [$this, 'require_login'],
            'args' => $id_arg + $paging + ['status' => ['type' => 'string', 'enum' => ['pending', 'success', 'failed']]],
            'raek_docs' => self::docs('Delivery log with response codes and timings', 'Webhooks', 'DeliveryList'),
        ]);

        register_rest_route($this->namespace, '/webhooks/(?P<id>[\d]+)/ping', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'ping'],
            'permission_callback' => [$this, 'require_login'],
            'args' => $id_arg,
            'raek_docs' => self::docs('Queue a test `ping` delivery', 'Webhooks', null, 202),
        ]);

        register_rest_route($this->namespace, '/webhooks/(?P<id>[\d]+)/rotate-secret', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rotate_secret'],
            'permission_callback' => [$this, 'require_login'],
            'args' => $id_arg,
            'raek_docs' => self::docs('Issue a new signing secret (returned once)', 'Webhooks', 'WebhookCreated'),
        ]);
    }

    /** @return true|\WP_Error */
    public function validate_url($value, $request, $param)
    {
        $valid = rest_validate_request_arg($value, $request, $param);
        return $valid === true ? Endpoint_Policy::validate((string) $value) : $valid;
    }

    public function get_items($request)
    {
        $per_page = (int) $request['per_page'];
        $page = (int) $request['page'];
        [$rows, $total] = $this->repository->list_for_user(get_current_user_id(), $page, $per_page);
        $response = rest_ensure_response(array_map([$this->repository, 'present'], $rows));
        return Pagination::apply($response, $request, $total, $per_page, $page);
    }

    public function get_item($request)
    {
        $row = $this->owned((int) $request['id']);
        return is_wp_error($row) ? $row : rest_ensure_response($this->repository->present($row));
    }

    public function create_item($request)
    {
        $user_id = get_current_user_id();
        if ($this->repository->count_for_user($user_id) >= self::MAX_PER_USER) {
            return Error::conflict('raek_webhook_limit', sprintf('You can register at most %d webhooks.', self::MAX_PER_USER));
        }

        try {
            [$id, $secret] = $this->repository->create(
                $user_id,
                (string) $request['url'],
                (array) $request['events'],
                (string) ($request['description'] ?? '')
            );
        } catch (\RuntimeException) {
            return Error::make('raek_create_failed', 'The webhook could not be created.', 500);
        }

        $response = rest_ensure_response($this->repository->present((array) $this->repository->find($id)) + ['secret' => $secret]);
        $response->set_status(201);
        $response->header('Location', rest_url("{$this->namespace}/webhooks/$id"));
        return $response;
    }

    public function update_item($request)
    {
        $row = $this->owned((int) $request['id']);
        if (is_wp_error($row)) {
            return $row;
        }
        $fields = [];
        foreach (['url', 'description'] as $key) {
            if ($request->has_param($key)) {
                $fields[$key] = (string) $request[$key];
            }
        }
        if ($request->has_param('events')) {
            $fields['events'] = (string) wp_json_encode(array_values((array) $request['events']));
        }
        if ($request->has_param('active')) {
            $fields['active'] = (int) (bool) $request['active'];
            if ($fields['active']) {
                $fields['failure_count'] = 0;
            }
        }
        $this->repository->update((int) $row['id'], $fields);

        return rest_ensure_response($this->repository->present((array) $this->repository->find((int) $row['id'])));
    }

    public function delete_item($request)
    {
        $row = $this->owned((int) $request['id']);
        if (is_wp_error($row)) {
            return $row;
        }
        $this->repository->delete((int) $row['id']);
        return new WP_REST_Response(null, 204);
    }

    public function get_deliveries($request)
    {
        $row = $this->owned((int) $request['id']);
        if (is_wp_error($row)) {
            return $row;
        }
        $per_page = (int) $request['per_page'];
        $page = (int) $request['page'];
        [$rows, $total] = $this->repository->deliveries((int) $row['id'], $request['status'] ?: null, $page, $per_page);
        return Pagination::apply(rest_ensure_response($rows), $request, $total, $per_page, $page);
    }

    public function ping($request)
    {
        $row = $this->owned((int) $request['id']);
        if (is_wp_error($row)) {
            return $row;
        }
        $delivery = $this->dispatcher->ping((int) $row['id']);
        return new WP_REST_Response(['delivery_id' => $delivery, 'status' => 'queued'], 202);
    }

    public function rotate_secret($request)
    {
        $row = $this->owned((int) $request['id']);
        if (is_wp_error($row)) {
            return $row;
        }
        $secret = $this->repository->new_secret();
        $this->repository->update((int) $row['id'], ['secret' => Secrets::encrypt($secret)]);
        return rest_ensure_response($this->repository->present((array) $this->repository->find((int) $row['id'])) + ['secret' => $secret]);
    }

    /** @return array<string,mixed>|\WP_Error */
    private function owned(int $id)
    {
        $row = $this->repository->find($id);
        return ($row && (int) $row['user_id'] === get_current_user_id()) ? $row : Error::not_found('Webhook');
    }

    public function get_item_schema(): array
    {
        if ($this->schema) {
            return $this->add_additional_fields_schema($this->schema);
        }
        $this->schema = [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'webhook',
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readonly' => true],
                'url' => [
                    'type' => 'string', 'format' => 'uri', 'maxLength' => 2000, 'required' => true,
                    'description' => 'HTTPS endpoint that receives POSTed events.',
                    'arg_options' => ['validate_callback' => [$this, 'validate_url']],
                ],
                'events' => [
                    'type' => 'array', 'items' => ['type' => 'string', 'enum' => Dispatcher::EVENTS],
                    'minItems' => 1, 'uniqueItems' => true, 'required' => true,
                ],
                'description' => [
                    'type' => 'string', 'maxLength' => 200, 'default' => '',
                    'arg_options' => ['sanitize_callback' => [Sanitizer::class, 'text']],
                ],
                'active' => ['type' => 'boolean', 'default' => true, 'description' => 'Auto-set to false after 20 consecutive failed deliveries.'],
                'failure_count' => ['type' => 'integer', 'readonly' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'readonly' => true],
            ],
        ];
        return $this->add_additional_fields_schema($this->schema);
    }

    public function schemas(): array
    {
        $webhook = $this->get_public_item_schema();
        $ref = fn(string $n) => ['$ref' => '#/components/schemas/v1.' . $n];
        return [
            'Webhook' => $webhook,
            'WebhookCreated' => ['allOf' => [$ref('Webhook'), ['type' => 'object', 'properties' => [
                'secret' => ['type' => 'string', 'description' => 'HMAC signing secret. Shown only once; store it securely.'],
            ]]]],
            'WebhookList' => ['type' => 'array', 'items' => $ref('Webhook')],
            'Delivery' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'event' => ['type' => 'string'], 'event_id' => ['type' => 'string', 'format' => 'uuid'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'success', 'failed']],
                'attempt' => ['type' => 'integer'], 'response_code' => ['type' => ['integer', 'null']],
                'response_body' => ['type' => ['string', 'null']], 'duration_ms' => ['type' => ['integer', 'null']],
                'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
            ]],
            'DeliveryList' => ['type' => 'array', 'items' => $ref('Delivery')],
        ];
    }
}
