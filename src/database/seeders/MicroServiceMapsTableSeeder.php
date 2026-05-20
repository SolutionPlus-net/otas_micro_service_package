<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Otas\MicroService\Models\MicroServiceMap;

class MicroServiceMapsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        MicroServiceMap::factory()->create();
    }
}
