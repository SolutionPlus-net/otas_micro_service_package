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
            ->has(MicroServiceToken::factory()->state(['receive_secret_old_key' => generate_key()]), 'token')
            ->create(['name' => 'tenant', 'origin' => 'tenant.test'])
            ->token;
    }

    public function test_sending_encrypts_with_the_send_key(): void
    {
        Http::fake([
            'tenant.test/api/test-send' => function (Request $request) use (&$receivedData) {
                $receivedData = RequestEncryption::decryptData(encryptedFields: $request->data(), key: $this->token->send_secret_key);

                return Http::response();
            },
        ]);

        MsHttp::post(microserviceName: 'tenant', uri: 'test-send', data: ['amount' => 100]);

        $this->assertSame(['amount' => 100], $receivedData);
    }

    public function test_request_encrypted_with_the_current_receive_key_is_accepted(): void
    {
        $this->postEncrypted(key: $this->token->receive_secret_current_key)
            ->assertOk()
            ->assertExactJson(['amount' => 100]);
    }

    public function test_request_encrypted_with_the_old_receive_key_is_accepted(): void
    {
        $this->postEncrypted(key: $this->token->receive_secret_old_key)
            ->assertOk()
            ->assertExactJson(['amount' => 100]);
    }

    public function test_request_encrypted_with_an_unknown_key_is_rejected(): void
    {
        $this->postEncrypted(key: generate_key())->assertStatus(405);
    }

    public function test_request_from_an_unknown_origin_is_rejected(): void
    {
        $this->postEncrypted(key: $this->token->receive_secret_current_key, origin: 'unknown.test')->assertStatus(405);
    }

    private function postEncrypted(string $key, string $origin = 'tenant.test')
    {
        $fields = RequestEncryption::encrypt(data: ['amount' => 100], key: $key);

        return $this->withHeader('origin', $origin)->postJson('/api/test-receive', $fields);
    }
}
