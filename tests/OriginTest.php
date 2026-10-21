<?php

namespace Otas\MicroService\Tests;

use Otas\MicroService\Helpers\Origin;

class OriginTest extends TestCase
{
    public function test_clean_removes_the_protocol(): void
    {
        $this->assertSame('tenant.test', Origin::clean('https://tenant.test'));
        $this->assertSame('localhost:8001', Origin::clean('http://localhost:8001'));
        $this->assertSame('tenant.test', Origin::clean('tenant.test'));
    }

    public function test_current_adds_the_local_port_only_for_localhost(): void
    {
        $this->app['request']->headers->set('host', 'localhost');

        $this->assertSame('localhost:8001', Origin::current(localPort: 8001));

        $this->app['request']->headers->set('host', 'landlord.test');

        $this->assertSame('landlord.test', Origin::current(localPort: 8001));
    }

    public function test_incoming_reads_the_origin_header_without_the_protocol(): void
    {
        $this->app['request']->headers->set('origin', 'https://tenant.test');

        $this->assertSame('tenant.test', Origin::incoming());
    }
}
