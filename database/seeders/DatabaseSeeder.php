<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\CnpsAgent;
use App\Models\Company;
use App\Models\Declaration;
use App\Models\Document;
use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ==========================================
        // 1. CRÉATION DES COMPTES DE TEST (Pour vos logins)
        // Tous les mots de passe sont : 'password'
        // ==========================================

        // Compte Entreprise de test
        $userEntreprise = User::factory()->create([
            'email' => 'contact@entreprise.cm',
            'role' => 'company',
            'password' => Hash::make('password')
        ]); 
        
        $entrepriseTest = Company::factory()->create([
            'user_id' => $userEntreprise->id,
            'numero_employeur' => 'M123456789', // Anciennement 'niu'
            'raison_sociale' => 'Entreprise Test SARL',
            'is_verified' => true // Pratique pour contourner les blocages de test
        ]);

        // Compte Banque de test
        $userBanque = User::factory()->create([
            'email' => 'guichet@banque.cm',
            'role' => 'bank',
            'password' => Hash::make('password')
        ]);
        
        $banqueTest = Bank::factory()->create([
            'user_id' => $userBanque->id,
            "bank_code" => 'BNK-001',
            "is_admin"=> true,
            "bank_name" => 'Banque Test Cameroun'
        ]);

        // Compte CNPS de test
        $userCnps = User::factory()->create([
            'email' => 'admin@cnps.cm',
            'role' => 'cnps',
            'password' => Hash::make('password')
        ]);
        
        CnpsAgent::factory()->create([
            'user_id' => $userCnps->id,
            "is_admin" => true,
            'matricule' => 'ADMIN-001',
            "full_name" => 'Agent Supervision'
        ]);

        // Compte Superviseur de test (Nouveau)
        $userSuperviseur = User::factory()->create([
            'email' => 'admin@superviseur.cm',
            'role' => 'supervisor', // Assurez-vous que ce rôle correspond à votre logique métier
            'password' => Hash::make('password')
        ]);
        
        Supervisor::factory()->create([
            'user_id' => $userSuperviseur->id,
            'supervisor_name' => 'Superviseur Principal',
            'is_admin' => true,
        ]);

        // ==========================================
        // 2. GÉNÉRATION DE DONNÉES ALÉATOIRES
        // ==========================================
        
        // Créer d'autres entités aléatoires
        Company::factory(10)->create();
        Bank::factory(3)->create();
        Supervisor::factory(5)->create();

        // Récupérer toutes les entreprises et banques pour les lier
        $toutesLesEntreprises = Company::all();
        $toutesLesBanques = Bank::all();

        // Créer 50 déclarations aléatoires
        for ($i = 0; $i < 50; $i++) {
            
            // On tire une entreprise au hasard
            $entrepriseAleatoire = $toutesLesEntreprises->random();
            
            // 80% de chance qu'une banque soit déjà assignée (si le paiement n'est pas juste Mobile Money)
            $banqueAleatoire = rand(1, 100) <= 80 ? $toutesLesBanques->random()->id : null;

            $declaration = Declaration::factory()->create([
                'company_id' => $entrepriseAleatoire->id,
                'bank_id' => $banqueAleatoire,
            ]);

            // Pour chaque déclaration, on génère son document PDF (Avis)
            Document::factory()->create([
                'declaration_id' => $declaration->id,
            ]);
        }
        
        // Créer spécifiquement 5 déclarations pour notre "Entreprise Test" pour qu'elle ait un historique visible
        for ($i = 0; $i < 5; $i++) {
            $declarationTest = Declaration::factory()->create([
                'company_id' => $entrepriseTest->id,
                'bank_id' => $banqueTest->id,
            ]);
            
            Document::factory()->create(['declaration_id' => $declarationTest->id]);
        }
    }
}