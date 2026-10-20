<?php

declare(strict_types=1);

namespace RAEK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RAEK\Auth\Jwt;
use RAEK\Auth\Jwt_Exception;

final class JwtTest extends TestCase
{
    private const SECRET = 'unit-test-secret-unit-test-secret-1234';
    private int $now = 1_800_000_000;

    private function jwt(string $secret = self::SECRET, string $aud = 'raek-api'): Jwt
    {
        return new Jwt($secret, 'https://example.test', $aud, 30, fn() => $this->now);
    }

    /** @return array<string,mixed> */
    private function claims(array $override = []): array
    {
        return $override + ['sub' => '7', 'iat' => $this->now, 'nbf' => $this->now, 'exp' => $this->now + 900];
    }

    private function assertRejected(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected Jwt_Exception(' . $reason . ')');
        } catch (Jwt_Exception $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function test_round_trip(): void
    {
        $claims = $this->jwt()->decode($this->jwt()->encode($this->claims()));
        $this->assertSame('7', $claims['sub']);
        $this->assertSame('raek-api', $claims['aud']);
    }

    public function test_caller_cannot_override_issuer_or_audience(): void
    {
        $token = $this->jwt()->encode($this->claims(['aud' => 'attacker', 'iss' => 'evil']));
        $this->assertSame('raek-api', $this->jwt()->decode($token)['aud']);
    }

    public function test_rejects_tampered_payload(): void
    {
        [$h, , $s] = explode('.', $this->jwt()->encode($this->claims()));
        $forged = Jwt::b64((string) json_encode(['sub' => '1', 'iss' => 'https://example.test', 'aud' => 'raek-api', 'exp' => $this->now + 900]));
        $this->assertRejected('bad_signature', fn() => $this->jwt()->decode("$h.$forged.$s"));
    }

    public function test_rejects_alg_none(): void
    {
        $h = Jwt::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $p = Jwt::b64((string) json_encode($this->claims(['iss' => 'https://example.test', 'aud' => 'raek-api'])));
        $this->assertRejected('unsupported_algorithm', fn() => $this->jwt()->decode("$h.$p."));
    }

    public function test_rejects_other_secret(): void
    {
        $token = $this->jwt()->encode($this->claims());
        $this->assertRejected('bad_signature', fn() => $this->jwt('another-secret-another-secret-123456')->decode($token));
    }

    public function test_rejects_wrong_audience(): void
    {
        $token = $this->jwt(self::SECRET, 'other-service')->encode($this->claims());
        $this->assertRejected('bad_audience', fn() => $this->jwt()->decode($token));
    }

    public function test_expiry_respects_leeway(): void
    {
        $token = $this->jwt()->encode($this->claims(['exp' => $this->now - 10])); // inside 30s leeway
        $this->assertSame('7', $this->jwt()->decode($token)['sub']);

        $expired = $this->jwt()->encode($this->claims(['exp' => $this->now - 31]));
        $this->assertRejected('expired', fn() => $this->jwt()->decode($expired));
    }

    public function test_rejects_token_before_nbf(): void
    {
        $token = $this->jwt()->encode($this->claims(['nbf' => $this->now + 120]));
        $this->assertRejected('not_yet_valid', fn() => $this->jwt()->decode($token));
    }

    /** @dataProvider malformed */
    public function test_rejects_malformed(string $input): void
    {
        $this->assertRejected('malformed', fn() => $this->jwt()->decode($input));
    }

    /** @return array<string,array{string}> */
    public function malformed(): array
    {
        return ['empty' => [''], 'two parts' => ['a.b'], 'four parts' => ['a.b.c.d'], 'not base64 json' => ['!!!.@@@.###']];
    }

    public function test_short_secret_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Jwt('short', 'iss', 'aud');
    }
}
