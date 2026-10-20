<?php

declare(strict_types=1);

namespace RAEK\Auth;

use RAEK\Http\Error;
use RAEK\Support\Secrets;
use WP_Error;

/**
 * Issues, rotates and revokes tokens.
 *
 * Model: short-lived access JWT (15 min) + rotating refresh JWT (14 days).
 *  - Refresh tokens are single use. Their `jti` is recorded per user; presenting one that is
 *    no longer recorded is treated as theft (reuse detection) and revokes every session.
 *  - Every token carries a per-user `ver` claim. Bumping it revokes all outstanding tokens
 *    (password change, "log out everywhere") without keeping a blocklist of access tokens.
 */
final class Token_Service
{
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 1209600;
    private const META_JTIS = 'raek_refresh_jtis';
    private const META_VER = 'raek_token_ver';
    private const MAX_SESSIONS = 10;

    private ?Jwt $jwt = null;

    private function jwt(): Jwt
    {
        return $this->jwt ??= new Jwt(Secrets::jwt_key(), home_url(), 'raek-api');
    }

    /** @return array<string,mixed> */
    public function issue_pair(int $user_id): array
    {
        $ver = $this->version($user_id);
        $access_ttl = (int) apply_filters('raek_access_token_ttl', self::ACCESS_TTL, $user_id);
        $access = $this->mint($user_id, 'access', $access_ttl, $ver);
        $refresh = $this->mint($user_id, 'refresh', self::REFRESH_TTL, $ver);
        $this->remember($user_id, $refresh['jti'], $refresh['exp']);

        return [
            'token_type' => 'Bearer',
            'access_token' => $access['token'],
            'expires_in' => $access_ttl,
            'refresh_token' => $refresh['token'],
            'refresh_expires_in' => self::REFRESH_TTL,
        ];
    }

    /** @return int|WP_Error User ID for a valid access token. */
    public function authenticate_access(string $token): int|WP_Error
    {
        try {
            $claims = $this->jwt()->decode($token);
        } catch (Jwt_Exception $e) {
            $message = $e->reason() === 'expired' ? 'The access token has expired.' : 'The access token is invalid.';
            return Error::unauthorized('raek_invalid_token', $message, true);
        }

        $user_id = (int) ($claims['sub'] ?? 0);
        if (($claims['typ'] ?? '') !== 'access' || $user_id < 1 || !get_userdata($user_id)) {
            return Error::unauthorized('raek_invalid_token', 'The access token is invalid.', true);
        }
        if ((int) ($claims['ver'] ?? -1) !== $this->version($user_id)) {
            return Error::unauthorized('raek_token_revoked', 'The access token has been revoked.', true);
        }
        return $user_id;
    }

    /** @return array<string,mixed>|WP_Error */
    public function refresh(string $token): array|WP_Error
    {
        try {
            $claims = $this->jwt()->decode($token);
        } catch (Jwt_Exception) {
            return Error::unauthorized('raek_invalid_refresh_token', 'The refresh token is invalid or expired.');
        }

        $user_id = (int) ($claims['sub'] ?? 0);
        $jti = (string) ($claims['jti'] ?? '');
        if (($claims['typ'] ?? '') !== 'refresh' || $user_id < 1 || !get_userdata($user_id)
            || (int) ($claims['ver'] ?? -1) !== $this->version($user_id)) {
            return Error::unauthorized('raek_invalid_refresh_token', 'The refresh token is invalid or expired.');
        }

        $jtis = $this->store($user_id);
        if (!isset($jtis[$jti])) {
            // A valid signature for an unknown jti means it was already rotated: assume theft.
            $this->revoke_all($user_id);
            return Error::unauthorized('raek_refresh_reuse', 'This refresh token was already used. All sessions have been revoked; sign in again.');
        }

        unset($jtis[$jti]);
        update_user_meta($user_id, self::META_JTIS, $jtis);

        return $this->issue_pair($user_id) + ['user_id' => $user_id];
    }

    public function revoke(string $token): bool
    {
        try {
            $claims = $this->jwt()->decode($token);
        } catch (Jwt_Exception) {
            return false;
        }
        $user_id = (int) ($claims['sub'] ?? 0);
        $jtis = $this->store($user_id);
        unset($jtis[(string) ($claims['jti'] ?? '')]);
        update_user_meta($user_id, self::META_JTIS, $jtis);
        return true;
    }

    public function revoke_all(int $user_id): void
    {
        update_user_meta($user_id, self::META_VER, $this->version($user_id) + 1);
        delete_user_meta($user_id, self::META_JTIS);
    }

    private function version(int $user_id): int
    {
        return (int) get_user_meta($user_id, self::META_VER, true);
    }

    /** @return array{token:string,jti:string,exp:int} */
    private function mint(int $user_id, string $type, int $ttl, int $ver): array
    {
        $now = time();
        $jti = wp_generate_uuid4();
        $exp = $now + $ttl;
        $token = $this->jwt()->encode([
            'sub' => (string) $user_id,
            'typ' => $type,
            'jti' => $jti,
            'ver' => $ver,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $exp,
        ]);
        return ['token' => $token, 'jti' => $jti, 'exp' => $exp];
    }

    /** @return array<string,int> jti => expiry */
    private function store(int $user_id): array
    {
        $jtis = get_user_meta($user_id, self::META_JTIS, true);
        return is_array($jtis) ? $jtis : [];
    }

    private function remember(int $user_id, string $jti, int $exp): void
    {
        $now = time();
        $jtis = array_filter($this->store($user_id), static fn($e) => (int) $e > $now);
        $jtis[$jti] = $exp;
        asort($jtis);
        update_user_meta($user_id, self::META_JTIS, array_slice($jtis, -self::MAX_SESSIONS, null, true));
    }
}
