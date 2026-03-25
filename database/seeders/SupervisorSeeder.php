<?php

namespace Database\Seeders;

use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Database\Seeder;

class SupervisorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Création d'un superviseur Administrateur spécifique pour vos tests
        $adminUser = User::factory()->create([
            'name' => 'Admin System',
            'email' => 'admin@superviseur.com',
            'role' => 'supervisor', // Si vous utilisez le champ role du User
        ]);

        Supervisor::factory()->create([
            'user_id' => $adminUser->id,
            'supervisor_name' => 'Superviseur Principal',
            'is_admin' => true,
        ]);

        // 2. Création de 5 superviseurs aléatoires avec leurs propres utilisateurs
        Supervisor::factory(5)->create();
    }
}