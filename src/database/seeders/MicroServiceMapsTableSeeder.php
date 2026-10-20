<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Otas\MicroService\Models\MicroServiceMap;
use Otas\MicroService\Models\MicroServiceToken;

class MicroServiceMapsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        MicroServiceMap::factory()->has(MicroServiceToken::factory(), 'token')->create();
    }
}
