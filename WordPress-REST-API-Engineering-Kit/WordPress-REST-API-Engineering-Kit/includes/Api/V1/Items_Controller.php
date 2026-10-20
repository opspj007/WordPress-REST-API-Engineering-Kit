<?php

declare(strict_types=1);

namespace RAEK\Api\V1;

use DateTimeImmutable;
use DateTimeZone;
use RAEK\Api\Base_Controller;
use RAEK\Api\Provides_Schemas;
use RAEK\Content\Item_Post_Type;
use RAEK\Http\Error;
use RAEK\Http\Pagination;
use RAEK\Support\Sanitizer;
use WP_Post;
use WP_Query;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Items: the demo resource. Demonstrates the full contract
 * (schema-driven validation, sanitization, ownership, ETag concurrency, pagination, events).
 *
 * Authorization: users only ever see their own items. Another user's item is a 404, not a
 * 403, so IDs cannot be probed. Administrators (manage_options) can see everything.
 */
class Items_Controller extends Base_Controller implements Provides_Schemas
{
    public const STATUSES = ['todo', 'in_progress', 'done'];

    /** Name of the due-date field; v2 renames it, which is the classic "breaking change". */
    protected string $due_field = 'due_date';

    public function __construct()
    {
        $this->namespace = 'raek/v1';
        $this->rest_base = 'items';
    }

    public function register_routes(): void
    {
        $base = '/' . $this->rest_base;

        register_rest_route($this->namespace, $base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'require_login'],
                'args' => $this->get_collection_params(),
                'raek_docs' => self::docs('List items', 'Items', 'ItemList'),
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_item'],
                'permission_callback' => [$this, 'require_login'],
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
                'raek_docs' => self::docs('Create an item', 'Items', 'Item', 201),
            ],
            'schema' => [$this, 'get_public_item_schema'],
        ]);

        register_rest_route($this->namespace, $base . '/(?P<id>[\d]+)', [
            'args' => ['id' => ['description' => 'Unique item identifier.', 'type' => 'integer', 'minimum' => 1]],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'require_login'],
                'raek_docs' => self::docs('Get an item', 'Items', 'Item'),
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_item'],
                'permission_callback' => [$this, 'require_login'],
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
                'raek_docs' => self::docs('Update an item (partial; send If-Match for optimistic locking)', 'Items', 'Item'),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'require_login'],
                'raek_docs' => self::docs('Delete an item', 'Items', null, 204),
            ],
            'schema' => [$this, 'get_public_item_schema'],
        ]);
    }

    /* ------------------------------------------------------------------ queries */

    public function get_collection_params(): array
    {
        return [
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'description' => 'Page number.'],
            'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (max 100).'],
            'search' => ['type' => 'string', 'maxLength' => 100, 'sanitize_callback' => [Sanitizer::class, 'text'], 'description' => 'Full-text search in title and description.'],
            'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'Filter by status.'],
            'tag' => ['type' => 'string', 'maxLength' => 30, 'sanitize_callback' => [Sanitizer::class, 'slug'], 'description' => 'Filter by tag slug.'],
            'due_before' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Only items due at or before this ISO 8601 instant.'],
            'orderby' => ['type' => 'string', 'enum' => ['created', 'title', 'priority', 'due'], 'default' => 'created', 'description' => 'Sorting by `due` only returns items that have a due date.'],
            'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
        ];
    }

    /** @return array<string,mixed> */
    protected function build_query_args($request): array
    {
        $args = [
            'post_type' => Item_Post_Type::POST_TYPE,
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
        ];
        if (!current_user_can('manage_options')) {
            $args['author'] = get_current_user_id();
        }
        if ($request->get_param('search')) {
            $args['s'] = (string) $request['search'];
        }
        if ($request->get_param('tag')) {
            $args['tax_query'] = [['taxonomy' => Item_Post_Type::TAXONOMY, 'field' => 'slug', 'terms' => (string) $request['tag']]];
        }

        $meta = [];
        if ($request->get_param('status')) {
            $meta[] = ['key' => '_raek_status', 'value' => (string) $request['status']];
        }
        if ($request->get_param('due_before') && ($utc = $this->to_utc((string) $request['due_before']))) {
            $meta[] = ['key' => '_raek_due_at', 'value' => $utc, 'compare' => '<=', 'type' => 'DATETIME'];
        }
        if ($meta) {
            $args['meta_query'] = ['relation' => 'AND'] + $meta;
        }

        $order = strtoupper((string) ($request->get_param('order') ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';
        switch ($request->get_param('orderby') ?? 'created') {
            case 'title':
                $args += ['orderby' => 'title', 'order' => $order];
                break;
            case 'priority':
                $args += ['meta_key' => '_raek_priority', 'orderby' => 'meta_value_num', 'order' => $order];
                break;
            case 'due':
                $args += ['meta_key' => '_raek_due_at', 'orderby' => 'meta_value', 'order' => $order];
                break;
            default:
                $args += ['orderby' => 'date', 'order' => $order];
        }
        return $args;
    }

    public function get_items($request)
    {
        $per_page = (int) $request['per_page'];
        $page = (int) $request['page'];
        $query = new WP_Query($this->build_query_args($request) + ['posts_per_page' => $per_page, 'paged' => $page]);

        $response = rest_ensure_response(array_map([$this, 'item_to_array'], $query->posts));
        return Pagination::apply($response, $request, (int) $query->found_posts, $per_page, $page);
    }

    public function get_item($request)
    {
        $post = $this->find_accessible((int) $request['id']);
        return $post instanceof WP_Post ? $this->respond($post) : $post;
    }

    /* ------------------------------------------------------------------ writes */

    public function create_item($request)
    {
        $title = (string) $request['title'];
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'The title must contain visible characters.';
        }
        $due = $request->get_param($this->due_field);
        if (is_string($due) && ($utc = $this->to_utc($due)) && strtotime($utc . ' UTC') < time()) {
            $errors[$this->due_field] = 'The due date must be in the future.';
        }
        if ($errors) {
            return Error::validation($errors); // semantic rule => 422 (syntax errors are 400 from core)
        }

        $id = wp_insert_post(wp_slash([
            'post_type' => Item_Post_Type::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => (string) ($request['description'] ?? ''),
            'post_author' => get_current_user_id(),
        ]), true);
        if (is_wp_error($id)) {
            return Error::make('raek_create_failed', 'The item could not be created.', 500);
        }

        $this->persist((int) $id, $request, true);
        $post = get_post((int) $id);
        do_action('raek_item_created', (int) $id, $this->item_to_array($post), (int) $post->post_author);

        return $this->respond($post, 201);
    }

    public function update_item($request)
    {
        $post = $this->find_accessible((int) $request['id']);
        if (!$post instanceof WP_Post) {
            return $post;
        }

        // Optimistic concurrency: refuse to overwrite changes the client has not seen.
        $if_match = trim((string) $request->get_header('if_match'));
        if ($if_match !== '' && $if_match !== '*' && !hash_equals($this->etag($post), $if_match)) {
            return Error::precondition_failed();
        }

        $update = ['ID' => $post->ID];
        if ($request->has_param('title')) {
            if ((string) $request['title'] === '') {
                return Error::validation(['title' => 'The title must contain visible characters.']);
            }
            $update['post_title'] = (string) $request['title'];
        }
        if ($request->has_param('description')) {
            $update['post_content'] = (string) $request['description'];
        }
        $result = wp_update_post(wp_slash($update), true); // always bumps post_modified => new ETag
        if (is_wp_error($result)) {
            return Error::make('raek_update_failed', 'The item could not be updated.', 500);
        }

        $this->persist($post->ID, $request, false);
        $fresh = get_post($post->ID);
        do_action('raek_item_updated', $post->ID, $this->item_to_array($fresh), (int) $fresh->post_author);

        return $this->respond($fresh);
    }

    public function delete_item($request)
    {
        $post = $this->find_accessible((int) $request['id']);
        if (!$post instanceof WP_Post) {
            return $post;
        }
        $snapshot = $this->item_to_array($post);
        wp_delete_post($post->ID, true);
        do_action('raek_item_deleted', $post->ID, $snapshot, (int) $post->post_author);

        return new WP_REST_Response(null, 204);
    }

    /* ------------------------------------------------------------------ schema */

    public function get_item_schema(): array
    {
        if ($this->schema) {
            return $this->add_additional_fields_schema($this->schema);
        }
        $this->schema = [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'item',
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readonly' => true, 'description' => 'Unique identifier.'],
                'title' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'required' => true,
                    'description' => 'Short summary.',
                    'arg_options' => ['sanitize_callback' => [Sanitizer::class, 'text']],
                ],
                'description' => [
                    'type' => 'string', 'maxLength' => 5000, 'default' => '',
                    'description' => 'Plain-text details (HTML is stripped).',
                    'arg_options' => ['sanitize_callback' => [Sanitizer::class, 'textarea']],
                ],
                'status' => ['type' => 'string', 'enum' => self::STATUSES, 'default' => 'todo'],
                'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 3, 'description' => '1 (lowest) to 5 (highest).'],
                $this->due_field => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'ISO 8601 date-time. Send null to clear.'],
                'tags' => [
                    'type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
                    'maxItems' => 10, 'uniqueItems' => true, 'default' => [],
                    'arg_options' => ['sanitize_callback' => [Sanitizer::class, 'tags']],
                ],
                'author' => ['type' => 'integer', 'readonly' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'readonly' => true],
                'updated_at' => ['type' => 'string', 'format' => 'date-time', 'readonly' => true],
            ],
        ];
        return $this->add_additional_fields_schema($this->schema);
    }

    public function schemas(): array
    {
        $item = $this->get_public_item_schema();
        return ['Item' => $item, 'ItemList' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $this->version() . '.Item']]];
    }

    /* ------------------------------------------------------------------ helpers */

    /** @return array<string,mixed> */
    public function item_to_array(WP_Post $post): array
    {
        $due = (string) get_post_meta($post->ID, '_raek_due_at', true);
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'status' => (string) (get_post_meta($post->ID, '_raek_status', true) ?: 'todo'),
            'priority' => (int) (get_post_meta($post->ID, '_raek_priority', true) ?: 3),
            $this->due_field => $due !== '' ? $this->iso($due) : null,
            'tags' => array_values((array) wp_get_object_terms($post->ID, Item_Post_Type::TAXONOMY, ['fields' => 'names'])),
            'author' => (int) $post->post_author,
            'created_at' => $this->iso($post->post_date_gmt),
            'updated_at' => $this->iso($post->post_modified_gmt),
        ];
    }

    private function persist(int $id, $request, bool $creating): void
    {
        foreach (['status' => '_raek_status', 'priority' => '_raek_priority'] as $param => $meta) {
            if ($creating || $request->has_param($param)) {
                update_post_meta($id, $meta, $request->get_param($param));
            }
        }
        if ($request->has_param($this->due_field)) {
            $utc = is_string($request[$this->due_field]) ? $this->to_utc($request[$this->due_field]) : null;
            $utc ? update_post_meta($id, '_raek_due_at', $utc) : delete_post_meta($id, '_raek_due_at');
        }
        if ($creating || $request->has_param('tags')) {
            wp_set_object_terms($id, (array) ($request->get_param('tags') ?? []), Item_Post_Type::TAXONOMY);
        }
    }

    /** @return WP_Post|\WP_Error */
    private function find_accessible(int $id)
    {
        $post = get_post($id);
        $owns = $post && ((int) $post->post_author === get_current_user_id() || current_user_can('manage_options'));
        if (!$post || $post->post_type !== Item_Post_Type::POST_TYPE || $post->post_status !== 'publish' || !$owns) {
            return Error::not_found('Item');
        }
        return $post;
    }

    private function respond(WP_Post $post, int $status = 200): WP_REST_Response
    {
        $url = rest_url(sprintf('%s/%s/%d', $this->namespace, $this->rest_base, $post->ID));
        $response = new WP_REST_Response($this->item_to_array($post), $status);
        $response->header('ETag', $this->etag($post));
        $response->add_link('self', $url);
        $response->add_link('collection', rest_url($this->namespace . '/' . $this->rest_base));
        if ($status === 201) {
            $response->header('Location', $url);
        }
        return $response;
    }

    private function etag(WP_Post $post): string
    {
        return '"' . md5($post->ID . '|' . $post->post_modified_gmt) . '"';
    }

    private function to_utc(string $iso): ?string
    {
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function iso(string $mysql_gmt): string
    {
        return (new DateTimeImmutable($mysql_gmt, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
