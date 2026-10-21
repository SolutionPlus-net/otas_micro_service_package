<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Otas\MicroService\Models\MicroServiceToken;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Otas\MicroService\Models\MicroServiceToken>
 */
class MicroServiceTokenFactory extends Factory
{
    protected $model = MicroServiceToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $receiveSecretKey = generate_key();
        $receiveAccessKey = generate_key();

        return [
            'receive_secret_old_key' => $receiveSecretKey,
            'receive_secret_current_key' => $receiveSecretKey,
            'send_secret_key' => generate_key(),

            'receive_access_old_key' => $receiveAccessKey,
            'receive_access_current_key' => $receiveAccessKey,
            'send_access_key' => generate_key(),
        ];
    }
}
