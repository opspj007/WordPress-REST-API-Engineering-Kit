<?php

declare(strict_types=1);

namespace RAEK\Tests\Integration;

final class ItemsTest extends ApiTestCase
{
    public function test_anonymous_requests_are_rejected_with_problem_json(): void
    {
        $res = $this->call('GET', '/raek/v1/items');
        $this->assertSame(401, $res->get_status());
        $this->assertSame('raek_unauthorized', $res->get_data()['code']);
        $this->assertStringContainsString('application/problem+json', $res->get_headers()['Content-Type']);
        $this->assertNotEmpty($res->get_headers()['X-Request-Id']);
    }

    public function test_create_get_and_etag(): void
    {
        $this->login_as_new_user();
        $created = $this->call('POST', '/raek/v1/items', ['title' => '  <b>Ship it</b> ', 'priority' => 4, 'tags' => ['a', 'a ', 'b']]);

        $this->assertSame(201, $created->get_status());
        $this->assertSame('Ship it', $created->get_data()['title'], 'title is sanitized');
        $this->assertSame(['a', 'b'], $created->get_data()['tags'], 'tags are de-duplicated');
        $this->assertNotEmpty($created->get_headers()['ETag']);

        $fetched = $this->call('GET', '/raek/v1/items/' . $created->get_data()['id']);
        $this->assertSame(200, $fetched->get_status());
    }

    public function test_validation_errors_use_one_shape(): void
    {
        $this->login_as_new_user();

        $syntax = $this->call('POST', '/raek/v1/items', ['title' => 'x', 'priority' => 99]);
        $this->assertSame(400, $syntax->get_status());
        $this->assertSame('priority', $syntax->get_data()['errors'][0]['field']);

        $semantic = $this->call('POST', '/raek/v1/items', ['title' => 'x', 'due_date' => '2001-01-01T00:00:00Z']);
        $this->assertSame(422, $semantic->get_status());
        $this->assertSame('due_date', $semantic->get_data()['errors'][0]['field']);
    }

    public function test_other_users_items_are_404_not_403(): void
    {
        $this->login_as_new_user();
        $id = $this->call('POST', '/raek/v1/items', ['title' => 'private'])->get_data()['id'];

        $this->login_as_new_user();
        $this->assertSame(404, $this->call('GET', '/raek/v1/items/' . $id)->get_status());
        $this->assertSame(404, $this->call('DELETE', '/raek/v1/items/' . $id)->get_status());
    }

    public function test_if_match_prevents_lost_updates(): void
    {
        $this->login_as_new_user();
        $created = $this->call('POST', '/raek/v1/items', ['title' => 'v1']);
        $id = $created->get_data()['id'];

        $stale = $this->call('PATCH', "/raek/v1/items/$id", ['title' => 'v2'], [], ['if_match' => '"stale"']);
        $this->assertSame(412, $stale->get_status());

        $ok = $this->call('PATCH', "/raek/v1/items/$id", ['title' => 'v2'], [], ['if_match' => $created->get_headers()['ETag']]);
        $this->assertSame('v2', $ok->get_data()['title']);
    }

    public function test_pagination_headers_and_links(): void
    {
        $this->login_as_new_user();
        foreach (range(1, 5) as $n) {
            $this->call('POST', '/raek/v1/items', ['title' => "item $n"]);
        }
        $res = $this->call('GET', '/raek/v1/items', null, ['per_page' => 2, 'page' => 2]);

        $this->assertSame('5', $res->get_headers()['X-WP-Total']);
        $this->assertSame('3', $res->get_headers()['X-WP-TotalPages']);
        $this->assertStringContainsString('rel="next"', $res->get_headers()['Link']);
        $this->assertStringContainsString('rel="prev"', $res->get_headers()['Link']);
    }

    public function test_v2_uses_cursor_envelope_and_renamed_field(): void
    {
        $this->login_as_new_user();
        foreach (range(1, 3) as $n) {
            $this->call('POST', '/raek/v2/items', ['title' => "item $n"]);
        }
        $first = $this->call('GET', '/raek/v2/items', null, ['limit' => 2]);
        $this->assertCount(2, $first->get_data()['data']);
        $this->assertArrayHasKey('due_at', $first->get_data()['data'][0]);

        $cursor = $first->get_data()['meta']['next_cursor'];
        $second = $this->call('GET', '/raek/v2/items', null, ['limit' => 2, 'cursor' => $cursor]);
        $this->assertCount(1, $second->get_data()['data']);
        $this->assertNull($second->get_data()['meta']['next_cursor']);
    }
}
