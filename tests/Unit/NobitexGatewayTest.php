<?php

namespace Tests\Unit;

use App\Models\PaymentGateway;
use App\Services\Gateways\NobitexGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NobitexGatewayTest extends TestCase
{
    protected string $testPublicKey = 'NXH77kqb4VLVY0DxK1jkkQviPGD6lm6XzZDvuJC0DhA=';
    protected string $testSecretKey = 'HW53S1BSTCf3E6rhXhNzXTrZWUdrs9BQhBbFXYkI9g4=';

    public function test_gateway_identifies_ed25519_mode_when_secret_key_present(): void
    {
        $gatewayModel = new PaymentGateway([
            'driver' => 'nobitex',
            'config' => [
                'api_key' => $this->testPublicKey,
                'secret_key' => $this->testSecretKey,
            ],
        ]);

        $gateway = new NobitexGateway($gatewayModel);
        $this->assertTrue($gateway->isEd25519());

        $tokenGateway = new PaymentGateway([
            'driver' => 'nobitex',
            'config' => [
                'api_key' => 'legacy_40_char_token',
                'secret_key' => '',
            ],
        ]);
        $legacy = new NobitexGateway($tokenGateway);
        $this->assertFalse($legacy->isEd25519());
    }

    public function test_auth_headers_contain_ed25519_signature_and_timestamp(): void
    {
        $gatewayModel = new PaymentGateway([
            'driver' => 'nobitex',
            'config' => [
                'api_key' => $this->testPublicKey,
                'secret_key' => $this->testSecretKey,
            ],
        ]);

        $gateway = new NobitexGateway($gatewayModel);
        $headers = $gateway->getAuthHeaders(method: 'GET', path: '/users/wallets/list');

        $this->assertArrayHasKey('Nobitex-Key', $headers);
        $this->assertArrayHasKey('Nobitex-Timestamp', $headers);
        $this->assertArrayHasKey('Nobitex-Signature', $headers);
        $this->assertEquals($this->testPublicKey, $headers['Nobitex-Key']);
        $this->assertNotEmpty($headers['Nobitex-Signature']);
    }

    public function test_validate_credentials_detects_ip_whitelist_restriction(): void
    {
        Http::fake([
            'https://apiv2.nobitex.ir/users/wallets/list' => Http::response([
                'detail' => 'IP 81.27.107.42 is not whitelisted.',
            ], 401),
        ]);

        $gatewayModel = new PaymentGateway([
            'driver' => 'nobitex',
            'config' => [
                'api_key' => $this->testPublicKey,
                'secret_key' => $this->testSecretKey,
            ],
        ]);

        $gateway = new NobitexGateway($gatewayModel);
        $result = $gateway->validateCredentials($gatewayModel->config);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['is_ip_restricted'] ?? false);
        $this->assertStringContainsString('لیست مجاز', $result['message']);
        $this->assertStringContainsString('81.27.107.42', $result['message']);
    }

    public function test_validate_credentials_and_sync_wallets_successful_with_ed25519(): void
    {
        Http::fake([
            'https://apiv2.nobitex.ir/users/wallets/list' => Http::response([
                'status' => 'ok',
                'wallets' => [
                    [
                        'id' => 12345,
                        'currency' => 'trx',
                        'depositAddress' => 'TTestEd25519TrxAddressValid99',
                    ],
                ],
            ], 200),
        ]);

        $config = [
            'api_key' => $this->testPublicKey,
            'secret_key' => $this->testSecretKey,
            'base_url' => 'https://apiv2.nobitex.ir',
        ];

        $gateway = new NobitexGateway();
        $validation = $gateway->validateCredentials($config);
        $this->assertTrue($validation['valid']);

        $sync = $gateway->syncWallets($config);
        $this->assertTrue($sync['success']);
        $this->assertEquals(12345, $config['wallet_id']);
        $this->assertEquals('TTestEd25519TrxAddressValid99', $config['deposit_address']);
    }
}
