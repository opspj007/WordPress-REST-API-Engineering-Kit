<?php

declare(strict_types=1);

namespace RAEK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RAEK\Webhooks\Signer;

final class SignerTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const BODY = '{"id":"evt_1","type":"item.created"}';

    public function test_signature_is_deterministic_and_prefixed(): void
    {
        $sig = Signer::sign(self::SECRET, 1_700_000_000, self::BODY);
        $this->assertStringStartsWith('sha256=', $sig);
        $this->assertSame($sig, Signer::sign(self::SECRET, 1_700_000_000, self::BODY));
    }

    public function test_verify_accepts_valid_signature(): void
    {
        $sig = Signer::sign(self::SECRET, 1_700_000_000, self::BODY);
        $this->assertTrue(Signer::verify(self::SECRET, 1_700_000_000, self::BODY, $sig, 300, 1_700_000_100));
    }

    public function test_verify_rejects_tampered_body_secret_or_timestamp(): void
    {
        $sig = Signer::sign(self::SECRET, 1_700_000_000, self::BODY);
        $this->assertFalse(Signer::verify(self::SECRET, 1_700_000_000, self::BODY . ' ', $sig, 300, 1_700_000_001));
        $this->assertFalse(Signer::verify('other', 1_700_000_000, self::BODY, $sig, 300, 1_700_000_001));
        $this->assertFalse(Signer::verify(self::SECRET, 1_700_000_001, self::BODY, $sig, 300, 1_700_000_001));
    }

    public function test_verify_rejects_replay_outside_tolerance(): void
    {
        $sig = Signer::sign(self::SECRET, 1_700_000_000, self::BODY);
        $this->assertFalse(Signer::verify(self::SECRET, 1_700_000_000, self::BODY, $sig, 300, 1_700_000_301));
    }
}
