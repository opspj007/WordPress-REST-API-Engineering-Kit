<?php

declare(strict_types=1);

namespace RAEK\Auth;

/**
 * Minimal, dependency-free HS256 JWT codec (RFC 7519).
 *
 * Deliberately pure PHP (no WordPress calls) so it is unit-testable in isolation.
 * Security properties:
 *  - the algorithm is pinned to HS256: `alg: none` and RS/HS confusion are rejected;
 *  - signatures are compared in constant time;
 *  - issuer, audience, exp and nbf are always enforced (with bounded clock leeway).
 */
final class Jwt
{
    private const ALG = 'HS256';
    private ?\Closure $clock;

    public function __construct(
        private string $secret,
        private string $issuer,
        private string $audience,
        private int $leeway = 30,
        ?\Closure $clock = null
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('The JWT secret must be at least 32 bytes.');
        }
        $this->clock = $clock;
    }

    /** @param array<string,mixed> $claims */
    public function encode(array $claims): string
    {
        // Left operand wins on key collisions: callers cannot override iss/aud.
        $claims = ['iss' => $this->issuer, 'aud' => $this->audience] + $claims;
        $signing = self::b64(json_encode(['typ' => 'JWT', 'alg' => self::ALG], JSON_THROW_ON_ERROR))
            . '.' . self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        return $signing . '.' . self::b64(hash_hmac('sha256', $signing, $this->secret, true));
    }

    /**
     * @return array<string,mixed>
     * @throws Jwt_Exception
     */
    public function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new Jwt_Exception('malformed');
        }
        [$h, $p, $s] = $parts;

        $header = self::json($h);
        if (($header['alg'] ?? null) !== self::ALG || ($header['typ'] ?? null) !== 'JWT') {
            throw new Jwt_Exception('unsupported_algorithm');
        }

        $given = self::unb64($s);
        $expected = hash_hmac('sha256', $h . '.' . $p, $this->secret, true);
        if ($given === null || !hash_equals($expected, $given)) {
            throw new Jwt_Exception('bad_signature');
        }

        $claims = self::json($p);
        if (($claims['iss'] ?? null) !== $this->issuer || ($claims['aud'] ?? null) !== $this->audience) {
            throw new Jwt_Exception('bad_audience');
        }
        if (!isset($claims['exp']) || !is_int($claims['exp'])) {
            throw new Jwt_Exception('malformed');
        }

        $now = $this->clock ? (int) ($this->clock)() : time();
        if ($claims['exp'] + $this->leeway < $now) {
            throw new Jwt_Exception('expired');
        }
        if (isset($claims['nbf']) && is_int($claims['nbf']) && $claims['nbf'] - $this->leeway > $now) {
            throw new Jwt_Exception('not_yet_valid');
        }

        return $claims;
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): ?string
    {
        $raw = base64_decode(strtr($data, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }

    /** @return array<string,mixed> */
    private static function json(string $segment): array
    {
        $raw = self::unb64($segment);
        if ($raw === null) {
            throw new Jwt_Exception('malformed');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Jwt_Exception('malformed');
        }
        if (!is_array($data)) {
            throw new Jwt_Exception('malformed');
        }
        return $data;
    }
}
