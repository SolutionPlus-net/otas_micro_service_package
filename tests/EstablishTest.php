<?php

namespace Otas\MicroService\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Otas\MicroService\Helpers\MsHttp;
use Otas\MicroService\Helpers\RequestEncryption;
use Otas\MicroService\Models\MicroServiceMap;
use Otas\MicroService\Models\MicroServiceToken;

class EstablishTest extends TestCase
{
    const PROJECT_SECRET = 'project-secret';

    protected function defineEnvironment($app)
    {
        $app['config']->set('microservice.micro_service_name', 'landlord');
        $app['config']->set('microservice.project_secret', self::PROJECT_SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migratePackageTables();
    }

    public function test_establish_saves_the_keys_of_both_services(): void
    {
        $tenantReceiveKeys = $this->newKeys();

        Http::fake([
            'tenant.test/api/micro-services' => function (Request $request) use (&$sentData, $tenantReceiveKeys) {
                $sentData = RequestEncryption::decryptData(encryptedFields: $request->data(), key: self::PROJECT_SECRET);
                $sentData['has_access_key_header'] = $request->hasHeader(RequestEncryption::ACCESS_KEY_HEADER);

                return Http::response([
                    'message' => 'established',
                    ...RequestEncryption::encrypt(data: $tenantReceiveKeys, key: self::PROJECT_SECRET),
                ]);
            },
        ]);

        $this->assertTrue(MsHttp::establish(microserviceName: 'tenant', origin: 'tenant.test')->ok());

        $this->assertFalse($sentData['has_access_key_header']);

        $token = MicroServiceMap::where('name', 'tenant')->first()->token;

        $this->assertSame($sentData['secret_key'], $token->receive_secret_current_key);
        $this->assertSame($sentData['access_key'], $token->receive_access_current_key);

        $this->assertSame($tenantReceiveKeys['secret_key'], $token->send_secret_key);
        $this->assertSame($tenantReceiveKeys['access_key'], $token->send_access_key);
    }

    public function test_establish_with_a_protocol_in_the_origin_saves_the_host_only(): void
    {
        Http::fake([
            'tenant.test/api/micro-services' => Http::response([
                'message' => 'established',
                ...RequestEncryption::encrypt(data: $this->newKeys(), key: self::PROJECT_SECRET),
            ]),
        ]);

        $this->assertTrue(MsHttp::establish(microserviceName: 'tenant', origin: 'https://tenant.test')->ok());

        Http::assertSent(fn (Request $request) => $request->url() === 'http://tenant.test/api/micro-services');

        $this->assertSame('tenant.test', MicroServiceMap::where('name', 'tenant')->first()->origin);
    }

    public function test_other_service_can_establish_a_connection(): void
    {
        $tenantReceiveKeys = $this->newKeys();

        $response = $this->postEstablishRequest(name: 'tenant', origin: 'tenant.test', keys: $tenantReceiveKeys);

        $response->assertOk();

        $landlordReceiveKeys = RequestEncryption::decryptData(encryptedFields: $response->json(), key: self::PROJECT_SECRET);

        $token = MicroServiceMap::where('name', 'tenant')->first()->token;

        $this->assertSame($landlordReceiveKeys['secret_key'], $token->receive_secret_current_key);
        $this->assertSame($landlordReceiveKeys['access_key'], $token->receive_access_current_key);

        $this->assertSame($token->receive_secret_current_key, $token->receive_secret_old_key);
        $this->assertSame($token->receive_access_current_key, $token->receive_access_old_key);

        $this->assertSame($tenantReceiveKeys['secret_key'], $token->send_secret_key);
        $this->assertSame($tenantReceiveKeys['access_key'], $token->send_access_key);
    }

    public function test_establish_again_from_the_same_origin_replaces_all_the_keys(): void
    {
        $this->createService(name: 'tenant', origin: 'tenant.test');

        $tenantReceiveKeys = $this->newKeys();

        $this->postEstablishRequest(name: 'tenant', origin: 'tenant.test', keys: $tenantReceiveKeys)->assertOk();

        $token = MicroServiceMap::where('name', 'tenant')->first()->token;

        $this->assertSame($tenantReceiveKeys['secret_key'], $token->send_secret_key);

        $this->assertSame($token->receive_secret_current_key, $token->receive_secret_old_key);
        $this->assertSame($token->receive_access_current_key, $token->receive_access_old_key);
    }

    public function test_establish_with_a_taken_name_from_another_origin_is_rejected(): void
    {
        $this->createService(name: 'tenant', origin: 'tenant.test');

        $this->postEstablishRequest(name: 'tenant', origin: 'another.test', keys: $this->newKeys())->assertUnprocessable();

        $this->assertSame('tenant.test', MicroServiceMap::where('name', 'tenant')->first()->origin);
    }

    public function test_establish_request_encrypted_with_a_wrong_project_secret_is_rejected(): void
    {
        $fields = RequestEncryption::encrypt(
            data: ['name' => 'tenant', 'origin' => 'tenant.test', ...$this->newKeys()],
            key: 'wrong-project-secret',
        );

        $this->postJson('/api/micro-services', $fields)->assertStatus(405);

        $this->assertSame(0, MicroServiceMap::count());
    }

    public function test_keys_are_stored_encrypted_in_the_database(): void
    {
        $tenantReceiveKeys = $this->newKeys();

        $this->postEstablishRequest(name: 'tenant', origin: 'tenant.test', keys: $tenantReceiveKeys)->assertOk();

        $storedSendSecretKey = DB::table('microservice_tokens')->value('send_secret_key');

        $this->assertNotSame($tenantReceiveKeys['secret_key'], $storedSendSecretKey);

        $this->assertSame($tenantReceiveKeys['secret_key'], decrypt($storedSendSecretKey, false));
    }

    private function postEstablishRequest(string $name, string $origin, array $keys)
    {
        $fields = RequestEncryption::encrypt(
            data: ['name' => $name, 'origin' => $origin, ...$keys],
            key: self::PROJECT_SECRET,
        );

        return $this->withHeader('origin', $origin)->postJson('/api/micro-services', $fields);
    }

    private function createService(string $name, string $origin): MicroServiceMap
    {
        return MicroServiceMap::factory()
            ->has(MicroServiceToken::factory()->state(['receive_secret_old_key' => generate_key()]), 'token')
            ->create(['name' => $name, 'origin' => $origin]);
    }

    private function newKeys(): array
    {
        return [
            'secret_key' => generate_key(),
            'access_key' => generate_key(),
        ];
    }
}
