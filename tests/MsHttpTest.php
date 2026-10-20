<?php

namespace Otas\MicroService\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Otas\MicroService\Helpers\MsHttp;
use Otas\MicroService\Models\MicroServiceMap;

class MsHttpTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('database.connections.microservice', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app['config']->set('microservice.db_connection_name', 'microservice');
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('microservice');
        $this->migratePackageTables();
        DB::setDefaultConnection('testing');
    }

    public function test_cache_reads_services_from_the_package_connection_without_changing_the_default_connection(): void
    {
        MicroServiceMap::on('microservice')->create([
            'name' => 'tenant',
            'origin' => 'tenant.test',
        ]);

        MsHttp::refreshCache();

        $this->assertSame('tenant', Cache::get('micro-services')->first()->name);

        $this->assertSame('testing', DB::getDefaultConnection());
    }

    public function test_current_service_is_not_faked_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';

        $this->assertNull(MsHttp::current());

        $this->assertSame(0, MicroServiceMap::on('microservice')->count());
    }
}
