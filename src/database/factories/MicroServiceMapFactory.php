<?php

namespace Database\Factories;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;
use Otas\MicroService\Models\MicroServiceMap;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Otas\MicroService\Models\MicroServiceMap>
 */
class MicroServiceMapFactory extends Factory
{
    protected $model = MicroServiceMap::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $name = $this->faker->words(2, true);

        return [
            'name' => Str::slug($name, '_'),
            'display_name' => \ucfirst($name),
            'origin' => 'localhost:8001',
        ];
    }
}
