<?php

namespace Otas\MicroService\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Otas\MicroService\Helpers\MsHttp;
use Otas\MicroService\Helpers\RequestEncryption;
use Otas\MicroService\Models\MicroServiceMap;
use Otas\MicroService\Models\MicroServiceToken;

class SendingAndReceivingTest extends TestCase
{
    private MicroServiceToken $token;

    protected function defineRoutes($router)
    {
        $router->post('/api/test-receive', fn () => request()->all())->middleware('micro-service');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migratePackageTables();

        $this->token = MicroServiceMap::factory()
            ->has(MicroServiceToken::factory()->state([
                'receive_secret_old_key' => generate_key(),
                'receive_access_old_key' => generate_key(),
            ]), 'token')
            ->create(['name' => 'tenant', 'origin' => 'tenant.test'])
            ->token;
    }

    public function test_sending_encrypts_with_the_send_key_and_adds_the_send_access_key(): void
    {
        Http::fake([
            'tenant.test/api/test-send' => function (Request $request) use (&$sentRequest) {
                $sentRequest = $request;

                return Http::response();
            },
        ]);

        MsHttp::post(microserviceName: 'tenant', uri: 'test-send', data: ['amount' => 100]);

        $this->assertSame($this->token->send_access_key, $sentRequest->header(RequestEncryption::ACCESS_KEY_HEADER)[0]);

        $receivedData = RequestEncryption::decryptData(encryptedFields: $sentRequest->data(), key: $this->token->send_secret_key);

        $this->assertSame(['amount' => 100], $receivedData);
    }

    public function test_request_with_the_current_access_key_and_current_secret_key_is_accepted(): void
    {
        $this->postEncrypted(accessKey: $this->token->receive_access_current_key, secretKey: $this->token->receive_secret_current_key)
            ->assertOk()
            ->assertExactJson(['amount' => 100]);
    }

    public function test_request_with_the_old_access_key_and_old_secret_key_is_accepted(): void
    {
        $this->postEncrypted(accessKey: $this->token->receive_access_old_key, secretKey: $this->token->receive_secret_old_key)
            ->assertOk()
            ->assertExactJson(['amount' => 100]);
    }

    public function test_request_with_keys_from_different_generations_is_rejected(): void
    {
        $this->postEncrypted(accessKey: $this->token->receive_access_current_key, secretKey: $this->token->receive_secret_old_key)
            ->assertStatus(405);
    }

    public function test_request_with_a_wrong_or_missing_access_key_is_rejected_without_decrypting(): void
    {
        $fields = RequestEncryption::encrypt(data: ['amount' => 100], key: $this->token->receive_secret_current_key);

        foreach ([generate_key(), ''] as $wrongAccessKey) {
            $this->withHeaders(['origin' => 'tenant.test', RequestEncryption::ACCESS_KEY_HEADER => $wrongAccessKey])
                ->postJson('/api/test-receive', $fields)
                ->assertStatus(405);
        }

        // Decrypting would have used up the nonce, so the same request is still accepted with the right access key.
        $this->withHeaders(['origin' => 'tenant.test', RequestEncryption::ACCESS_KEY_HEADER => $this->token->receive_access_current_key])
            ->postJson('/api/test-receive', $fields)
            ->assertOk();
    }

    public function test_request_from_an_unknown_origin_is_rejected(): void
    {
        $this->postEncrypted(
            accessKey: $this->token->receive_access_current_key,
            secretKey: $this->token->receive_secret_current_key,
            origin: 'unknown.test',
        )->assertStatus(405);
    }

    private function postEncrypted(string $accessKey, string $secretKey, string $origin = 'tenant.test')
    {
        $fields = RequestEncryption::encrypt(data: ['amount' => 100], key: $secretKey);

        return $this->withHeaders(['origin' => $origin, RequestEncryption::ACCESS_KEY_HEADER => $accessKey])
            ->postJson('/api/test-receive', $fields);
    }
}
