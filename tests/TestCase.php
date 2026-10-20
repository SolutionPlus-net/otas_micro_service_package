<?php

namespace Otas\MicroService\Tests;

use Otas\MicroService\MicroServiceServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            MicroServiceServiceProvider::class,
        ];
    }
}
