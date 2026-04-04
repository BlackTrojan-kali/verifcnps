<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
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
            // Crée automatiquement un User lié avec le rôle 'entreprise'
            'user_id' => User::factory()->state(['role' => 'company']),
            'numero_employeur' => 'M' . $this->faker->unique()->randomNumber(9, true),
            'raison_sociale' => $this->faker->company(),
            'telephone' => '+237 6' . $this->faker->randomNumber(8, true),
            'address' => $this->faker->city() . ', Cameroun',
        ];
    }
}
