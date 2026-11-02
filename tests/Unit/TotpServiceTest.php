<?php

namespace Tests\Unit;

use App\Services\Security\TotpService;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    protected TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new TotpService();
    }

    public function test_secret_generation_and_base32(): void
    {
        $secret = $this->totp->generateSecret(20);
        $this->assertNotEmpty($secret);
        $this->assertEquals(32, strlen($secret));

        // Roundtrip encode/decode
        $original = "Hello World 12345";
        $encoded = $this->totp->base32Encode($original);
        $decoded = $this->totp->base32Decode($encoded);
        $this->assertEquals($original, $decoded);
    }

    public function test_totp_code_calculation_and_verification(): void
    {
        $secret = $this->totp->generateSecret();
        $currentTime = time();

        $code = $this->totp->calculateCode($secret, $currentTime);
        $this->assertEquals(6, strlen($code));
        $this->assertTrue(ctype_digit($code));

        // Exact time should verify
        $this->assertTrue($this->totp->verifyCode($secret, $code, 1, $currentTime));

        // Within 30 seconds drift should verify
        $this->assertTrue($this->totp->verifyCode($secret, $code, 1, $currentTime + 25));
        $this->assertTrue($this->totp->verifyCode($secret, $code, 1, $currentTime - 25));

        // 90 seconds in the future should fail (outside window)
        $this->assertFalse($this->totp->verifyCode($secret, $code, 1, $currentTime + 90));

        // Wrong code should fail
        $this->assertFalse($this->totp->verifyCode($secret, '999999', 1, $currentTime));
    }

    public function test_recovery_codes_generation(): void
    {
        $codes = $this->totp->generateRecoveryCodes(8);
        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-F0-9]{6}-[A-F0-9]{6}$/', $code);
        }
    }

    public function test_qr_code_url_generation(): void
    {
        $secret = "JBSWY3DPEHPK3PXP";
        $url = $this->totp->generateQrCodeUrl("GetNumber", "alice", $secret);

        $this->assertStringStartsWith("otpauth://totp/GetNumber:alice?secret=JBSWY3DPEHPK3PXP", $url);
        $this->assertStringContainsString("issuer=GetNumber", $url);
    }
}

