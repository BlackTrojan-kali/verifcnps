<?php

namespace Database\Factories;

use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supervisor>
 */
class SupervisorFactory extends Factory
{
    /**
     * Le nom du modèle correspondant au factory.
     *
     * @var string
     */
    protected $model = Supervisor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Crée un nouvel utilisateur à la volée pour chaque superviseur
            'user_id' => User::factory(), 
            'supervisor_name' => fake()->name(),
            // 20% de chances d'être administrateur, 80% d'être un superviseur standard
            'is_admin' => fake()->boolean(20), 
        ];
    }
}