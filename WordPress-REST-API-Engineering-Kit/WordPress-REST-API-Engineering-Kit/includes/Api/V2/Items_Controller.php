<?php

declare(strict_types=1);

namespace RAEK\Api\V2;

use RAEK\Api\V1;
use RAEK\Http\Pagination;
use WP_Post;
use WP_Query;

/**
 * v2 shows how a version evolves without forking the codebase:
 *   - breaking field rename:  due_date -> due_at, plus a `url` field
 *   - breaking list contract: keyset (cursor) pagination in a {data, meta} envelope
 * Everything else (rules, ownership, events, concurrency) is inherited, so v1 and v2 can never
 * disagree about business logic. Only the contract differs.
 */
final class Items_Controller extends V1\Items_Controller
{
    protected string $due_field = 'due_at';

    public function __construct()
    {
        parent::__construct();
        $this->namespace = 'raek/v2';
    }

    public function get_collection_params(): array
    {
        $params = parent::get_collection_params();
        unset($params['page'], $params['per_page'], $params['orderby'], $params['order']);

        return $params + [
            'limit' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'description' => 'Page size (max 100).'],
            'cursor' => ['type' => 'string', 'maxLength' => 200, 'validate_callback' => [$this, 'validate_cursor'], 'description' => 'Opaque cursor from `meta.next_cursor`. Newest first.'],
        ];
    }

    /** @return true|\WP_Error */
    public function validate_cursor($value)
    {
        return $this->decode_cursor((string) $value) === null
            ? new \WP_Error('rest_invalid_param', 'The cursor is invalid.', ['status' => 400])
            : true;
    }

    public function get_items($request)
    {
        $limit = (int) $request['limit'];
        $before = $request->get_param('cursor') ? $this->decode_cursor((string) $request['cursor']) : null;

        $args = $this->build_query_args($request);
        unset($args['meta_key']); // v2 has a single, stable sort: newest first
        $args = ['orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true, 'posts_per_page' => $limit + 1] + $args;

        // Keyset pagination: stable under inserts/deletes and O(1) deep pages, unlike OFFSET.
        $filter = static function (string $where) use ($before): string {
            global $wpdb;
            return $before ? $where . $wpdb->prepare(" AND {$wpdb->posts}.ID < %d", $before) : $where;
        };
        add_filter('posts_where', $filter);
        $posts = (new WP_Query($args))->posts;
        remove_filter('posts_where', $filter);

        $has_more = count($posts) > $limit;
        $posts = array_slice($posts, 0, $limit);
        $next = $has_more ? $this->encode_cursor((int) end($posts)->ID) : null;

        $response = rest_ensure_response([
            'data' => array_map([$this, 'item_to_array'], $posts),
            'meta' => ['limit' => $limit, 'next_cursor' => $next],
        ]);
        if ($next !== null) {
            $response->link_header('next', add_query_arg(Pagination::safe_params($request) + ['cursor' => $next], rest_url('raek/v2/items')));
        }
        return $response;
    }

    public function item_to_array(WP_Post $post): array
    {
        return parent::item_to_array($post) + ['url' => rest_url('raek/v2/items/' . $post->ID)];
    }

    public function schemas(): array
    {
        $schemas = parent::schemas();
        $schemas['ItemList'] = [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/v2.Item']],
                'meta' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer'],
                    'next_cursor' => ['type' => ['string', 'null']],
                ]],
            ],
        ];
        return $schemas;
    }

    private function encode_cursor(int $id): string
    {
        return \RAEK\Auth\Jwt::b64('id:' . $id);
    }

    private function decode_cursor(string $cursor): ?int
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        return ($raw !== false && preg_match('/^id:(\d{1,18})$/', $raw, $m)) ? (int) $m[1] : null;
    }
}
