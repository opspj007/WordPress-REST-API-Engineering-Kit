<?php

declare(strict_types=1);

namespace RAEK\Api;

use RAEK\Http\Error;
use WP_REST_Controller;

abstract class Base_Controller extends WP_REST_Controller
{
    /** "v1" / "v2", derived from the namespace. */
    public function version(): string
    {
        return substr((string) strrchr($this->namespace, '/'), 1);
    }

    /** @return true|\WP_Error */
    public function require_login()
    {
        return is_user_logged_in() ? true : Error::unauthorized();
    }

    /**
     * Metadata consumed by the OpenAPI generator. Stored on the route handler so the
     * documentation can never drift from what is actually registered.
     *
     * @return array<string,mixed>
     */
    protected static function docs(string $summary, string $tag, ?string $response = null, int $status = 200, bool $auth = true): array
    {
        return compact('summary', 'tag', 'response', 'status', 'auth');
    }
}
