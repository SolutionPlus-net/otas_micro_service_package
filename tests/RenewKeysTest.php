<?php

namespace Otas\MicroService\Tests;

use Exception;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Otas\MicroService\Helpers\MsHttp;
use Otas\MicroService\Helpers\RequestEncryption;
use Otas\MicroService\Jobs\RenewKeysJob;
use Otas\MicroService\Models\MicroServiceMap;
use Otas\MicroService\Models\MicroServiceToken;

class RenewKeysTest extends TestCase
{
    private MicroServiceMap $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migratePackageTables();

        $this->tenant = MicroServiceMap::factory()
            ->has(MicroServiceToken::factory(), 'token')
            ->create(['name' => 'tenant', 'origin' => 'tenant.test']);
    }

    public function test_command_queues_one_job_for_every_connected_service(): void
    {
        Queue::fake();

        MicroServiceMap::factory()->has(MicroServiceToken::factory(), 'token')->create(['name' => 'payment', 'origin' => 'payment.test']);
        MicroServiceMap::factory()->create(['name' => 'not_connected', 'origin' => 'not-connected.test']);

        $this->artisan('ms:renew-keys')->assertSuccessful();

        Queue::assertPushed(RenewKeysJob::class, 2);
    }

    public function test_job_moves_the_current_keys_to_old_and_sends_the_new_keys(): void
    {
        $tokenBefore = $this->tenant->token;

        Http::fake([
            'tenant.test/api/micro-services/renew-keys' => function (Request $request) use (&$sentKeys, $tokenBefore) {
                $sentKeys = RequestEncryption::decryptData(encryptedFields: $request->data(), key: $tokenBefore->send_secret_key);

                return Http::response(['message' => 'renewed']);
            },
        ]);

        (new RenewKeysJob($this->tenant))->handle();

        $tokenAfter = $this->tenant->token()->first();

        $this->assertSame($tokenBefore->receive_secret_current_key, $tokenAfter->receive_secret_old_key);
        $this->assertSame($tokenBefore->receive_access_current_key, $tokenAfter->receive_access_old_key);

        $this->assertSame($sentKeys['secret_key'], $tokenAfter->receive_secret_current_key);
        $this->assertSame($sentKeys['access_key'], $tokenAfter->receive_access_current_key);
    }

    public function test_keys_the_other_service_still_uses_keep_working_when_sending_fails(): void
    {
        $tokenBefore = $this->tenant->token;

        Http::fake(['tenant.test/*' => Http::response(status: 500)]);

        try {
            (new RenewKeysJob($this->tenant))->handle();
            $this->fail('the job should fail when the other service does not save the new keys');
        } catch (Exception $exception) {
            $this->assertStringContainsString('renewing keys with "tenant" failed', $exception->getMessage());
        }

        $tokenAfter = $this->tenant->token()->first();

        $this->assertSame(
            $tokenBefore->receive_secret_current_key,
            $tokenAfter->receiveSecretKeyFor(accessKey: $tokenBefore->receive_access_current_key),
        );
    }

    public function test_other_service_new_keys_are_saved_as_send_keys(): void
    {
        $token = $this->tenant->token;

        $newKeys = [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];

        $this->withHeaders(['origin' => 'tenant.test', RequestEncryption::ACCESS_KEY_HEADER => $token->receive_access_current_key])
            ->postJson('/api/micro-services/renew-keys', RequestEncryption::encrypt(data: $newKeys, key: $token->receive_secret_current_key))
            ->assertOk();

        $tokenAfter = $token->fresh();

        $this->assertSame($newKeys['secret_key'], $tokenAfter->send_secret_key);
        $this->assertSame($newKeys['access_key'], $tokenAfter->send_access_key);

        $this->assertSame($token->receive_secret_current_key, $tokenAfter->receive_secret_current_key);
    }

    public function test_renew_endpoint_rejects_a_request_without_valid_keys(): void
    {
        $this->withHeaders(['origin' => 'tenant.test', RequestEncryption::ACCESS_KEY_HEADER => generate_key()])
            ->postJson('/api/micro-services/renew-keys', RequestEncryption::encrypt(data: [], key: generate_key()))
            ->assertStatus(405);
    }
}
