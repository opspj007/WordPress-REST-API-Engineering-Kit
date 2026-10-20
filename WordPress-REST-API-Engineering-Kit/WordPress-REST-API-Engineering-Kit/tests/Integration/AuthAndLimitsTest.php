<?php

declare(strict_types=1);

namespace RAEK\Tests\Integration;

final class AuthAndLimitsTest extends ApiTestCase
{
    public function test_token_refresh_rotation_and_reuse_detection(): void
    {
        $id = self::factory()->user->create(['user_login' => 'ada', 'user_pass' => 'correct horse battery']);

        $pair = $this->call('POST', '/raek/v1/auth/token', ['username' => 'ada', 'password' => 'correct horse battery'])->get_data();
        $this->assertNotEmpty($pair['access_token']);

        $rotated = $this->call('POST', '/raek/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]);
        $this->assertSame(200, $rotated->get_status());

        // Replaying the already-used refresh token is treated as theft: everything is revoked.
        $replay = $this->call('POST', '/raek/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]);
        $this->assertSame(401, $replay->get_status());
        $this->assertSame('raek_refresh_reuse', $replay->get_data()['code']);

        $after = $this->call('POST', '/raek/v1/auth/refresh', ['refresh_token' => $rotated->get_data()['refresh_token']]);
        $this->assertSame(401, $after->get_status(), 'sibling session was revoked too');
        $this->assertSame($id, get_user_by('login', 'ada')->ID);
    }

    public function test_bad_credentials_do_not_reveal_account_existence(): void
    {
        self::factory()->user->create(['user_login' => 'real', 'user_pass' => 'secret-pass-123']);
        $wrong_pass = $this->call('POST', '/raek/v1/auth/token', ['username' => 'real', 'password' => 'nope']);
        $no_user = $this->call('POST', '/raek/v1/auth/token', ['username' => 'ghost', 'password' => 'nope']);

        $this->assertSame(401, $wrong_pass->get_status());
        $this->assertSame($wrong_pass->get_data()['detail'], $no_user->get_data()['detail']);
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        add_filter('raek_rate_limits', static fn() => ['read' => [2, 60]], 20);
        $this->login_as_new_user();

        $this->call('GET', '/raek/v1/items');
        $this->call('GET', '/raek/v1/items');
        $limited = $this->call('GET', '/raek/v1/items');

        $this->assertSame(429, $limited->get_status());
        $this->assertArrayHasKey('Retry-After', $limited->get_headers());
        $this->assertSame('0', $limited->get_headers()['X-RateLimit-Remaining']);
    }

    public function test_openapi_document_describes_both_versions(): void
    {
        $spec = $this->call('GET', '/raek/v1/openapi')->get_data();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/raek/v1/items', $spec['paths']);
        $this->assertArrayHasKey('/raek/v2/items', $spec['paths']);
        $this->assertArrayHasKey('Problem', $spec['components']['schemas']);
    }
}
