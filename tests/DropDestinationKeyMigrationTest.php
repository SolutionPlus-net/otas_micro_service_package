<?php

namespace Otas\MicroService\Tests;

use Illuminate\Support\Facades\Schema;

class DropDestinationKeyMigrationTest extends TestCase
{
    const MIGRATION = 'drop_destination_key_from_micro_service_maps_table';

    protected function setUp(): void
    {
        parent::setUp();

        $this->migratePackageTables();
    }

    public function test_it_drops_the_destination_key_column(): void
    {
        $this->assertTrue(Schema::hasColumn('micro_service_maps', 'destination_key'));

        $this->runPackageMigration(self::MIGRATION);

        $this->assertFalse(Schema::hasColumn('micro_service_maps', 'destination_key'));
    }

    public function test_it_does_nothing_when_the_column_is_already_dropped(): void
    {
        $this->runPackageMigration(self::MIGRATION);

        $this->runPackageMigration(self::MIGRATION);

        $this->assertFalse(Schema::hasColumn('micro_service_maps', 'destination_key'));
    }
}
