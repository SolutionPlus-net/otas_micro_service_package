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

    protected function migratePackageTables(): void
    {
        foreach (['create_micro_service_maps_table', 'create_microservice_tokens_table'] as $migrationName) {
            $this->runPackageMigration($migrationName);
        }
    }

    protected function runPackageMigration(string $migrationName, string $direction = 'up'): void
    {
        (include __DIR__ . "/../src/database/migrations/{$migrationName}.php.stub")->{$direction}();
    }
}
