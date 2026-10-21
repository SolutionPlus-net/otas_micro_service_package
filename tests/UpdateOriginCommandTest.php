<?php

namespace Otas\MicroService\Tests;

use Illuminate\Support\Facades\Cache;
use Otas\MicroService\Helpers\MsHttp;
use Otas\MicroService\Models\MicroServiceMap;
use Otas\MicroService\Models\MicroServiceToken;

class UpdateOriginCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->migratePackageTables();
    }

    public function test_it_updates_the_origin_and_keeps_the_keys(): void
    {
        $service = MicroServiceMap::factory()
            ->has(MicroServiceToken::factory(), 'token')
            ->create(['name' => 'landlord', 'origin' => 'old-landlord.test']);

        MsHttp::refreshCache();

        $this->artisan('ms:update-origin', ['name' => 'landlord', 'origin' => 'https://new-landlord.test'])
            ->assertSuccessful();

        $this->assertSame('new-landlord.test', $service->fresh()->origin);

        $this->assertSame('new-landlord.test', Cache::get('micro-services')->first()->origin);

        $this->assertSame($service->token->send_secret_key, $service->fresh()->token->send_secret_key);
    }

    public function test_it_fails_for_a_service_that_is_not_connected(): void
    {
        $this->artisan('ms:update-origin', ['name' => 'landlord', 'origin' => 'new-landlord.test'])
            ->assertFailed();
    }
}
