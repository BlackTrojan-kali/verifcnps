<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bank>
 */
class BankFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //,
            "user_id" => User::factory()->state(['role' => 'bank']),
            "bank_code" => $this->faker->unique()->lexify('???'),
            "bank_name" => $this->faker->company() . ' Bank',
            "address" => 'Agence de ' . $this->faker->city(),
        ];
    }
}
