<?php

declare(strict_types=1);

namespace RAEK\Api\V1;

use RAEK\Api\Base_Controller;
use RAEK\Api\Provides_Schemas;
use RAEK\Auth\Authenticator;
use RAEK\Auth\Token_Service;
use RAEK\Http\Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Two ways in:
 *  1. JWT   - POST /auth/token, then `Authorization: Bearer <access_token>` (interactive clients).
 *  2. Application Passwords - core feature, `Authorization: Basic base64(user:app-password)`
 *     (server-to-server). Create them under Users > Profile. Use HTTPS only.
 */
final class Auth_Controller extends Base_Controller implements Provides_Schemas
{
    public function __construct(private Token_Service $tokens)
    {
        $this->namespace = 'raek/v1';
        $this->rest_base = 'auth';
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/auth/token', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_token'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 100, 'description' => 'Username or email address.'],
                'password' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 256, 'description' => 'Account password (never sanitized or logged).'],
            ],
            'raek_docs' => self::docs('Exchange credentials for a token pair', 'Authentication', '~TokenPair', 200, false),
        ]);

        register_rest_route($this->namespace, '/auth/refresh', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'refresh'],
            'permission_callback' => '__return_true',
            'args' => [
                'refresh_token' => ['type' => 'string', 'required' => true, 'minLength' => 20, 'maxLength' => 2000],
            ],
            'raek_docs' => self::docs('Rotate a refresh token for a new token pair', 'Authentication', '~TokenPair', 200, false),
        ]);

        register_rest_route($this->namespace, '/auth/revoke', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'revoke'],
            'permission_callback' => [$this, 'require_login'],
            'args' => [
                'refresh_token' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Revoke this single session.'],
                'all' => ['type' => 'boolean', 'default' => false, 'description' => 'Revoke every session for the current user.'],
            ],
            'raek_docs' => self::docs('Log out (one session or all)', 'Authentication', null, 204),
        ]);

        register_rest_route($this->namespace, '/auth/me', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'me'],
            'permission_callback' => [$this, 'require_login'],
            'raek_docs' => self::docs('Current user and how they authenticated', 'Authentication', '~User'),
        ]);
    }

    public function create_token($request)
    {
        // wp_authenticate() runs the standard `authenticate` filters, so lockout/2FA plugins keep working.
        $user = wp_authenticate((string) $request['username'], (string) $request['password']);
        if (is_wp_error($user) || !user_can($user, 'read')) {
            // One generic error: never reveal whether the account exists.
            return Error::unauthorized('raek_invalid_credentials', 'Incorrect username or password.');
        }
        return rest_ensure_response($this->tokens->issue_pair((int) $user->ID));
    }

    public function refresh($request)
    {
        $result = $this->tokens->refresh((string) $request['refresh_token']);
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function revoke($request)
    {
        if ($request['all']) {
            $this->tokens->revoke_all(get_current_user_id());
        } elseif (!empty($request['refresh_token'])) {
            $this->tokens->revoke((string) $request['refresh_token']);
        }
        return new WP_REST_Response(null, 204);
    }

    public function me($request)
    {
        $user = wp_get_current_user();
        $method = rest_get_authenticated_app_password() !== null
            ? 'application_password'
            : (Authenticator::authenticated_via_jwt() ? 'jwt' : 'cookie');

        return rest_ensure_response([
            'id' => (int) $user->ID,
            'username' => $user->user_login,
            'display_name' => $user->display_name,
            'roles' => array_values($user->roles),
            'auth_method' => $method,
        ]);
    }

    public function schemas(): array
    {
        return [
            '~TokenPair' => [
                'type' => 'object',
                'properties' => [
                    'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
                    'access_token' => ['type' => 'string'],
                    'expires_in' => ['type' => 'integer', 'description' => 'Access token lifetime in seconds.'],
                    'refresh_token' => ['type' => 'string', 'description' => 'Single use. Rotate via /auth/refresh.'],
                    'refresh_expires_in' => ['type' => 'integer'],
                ],
            ],
            '~User' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'username' => ['type' => 'string'],
                    'display_name' => ['type' => 'string'],
                    'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'auth_method' => ['type' => 'string', 'enum' => ['jwt', 'application_password', 'cookie']],
                ],
            ],
        ];
    }
}
