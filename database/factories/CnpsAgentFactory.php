<?php

namespace Database\Factories;

use App\Models\CnpsAgent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CnpsAgent>
 */
class CnpsAgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
            "user_id" => User::factory()->state(['role' => 'cnps']),
            "matricule" => 'CNPS-' . $this->faker->unique()->randomNumber(5, true),
            "full_name" => $this->faker->name(),
            "department" => $this->faker->randomElement(['Recouvrement', 'Audit', 'Supervision']),
        ];
    }
}
