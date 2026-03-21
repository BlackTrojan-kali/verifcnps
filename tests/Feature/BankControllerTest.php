<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\Declaration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // 1. Création d'un utilisateur avec le rôle "bank" et sa banque
    $this->bankUser = User::factory()->create(['role' => 'bank']);
    $this->bank = Bank::factory()->create([
        'user_id' => $this->bankUser->id,
        'bank_name' => 'Ecobank Test'
    ]);

    // 2. Création d'une entreprise pour les tests de guichet
    $this->companyUser = User::factory()->create(['role' => 'company']);
    $this->company = Company::factory()->create([
        'user_id' => $this->companyUser->id,
        'niu' => 'M0123456789'
    ]);
});

// ==========================================
// TESTS DE SÉCURITÉ (MIDDLEWARE)
// ==========================================

it('interdit l\'accès aux utilisateurs qui ne sont pas des banques', function () {
    $this->actingAs($this->companyUser)
         ->getJson('/api/bank/dashboard-stats')
         ->assertStatus(403); // Forbidden
});

it('bloque l\'accès aux utilisateurs non authentifiés', function () {
    $this->getJson('/api/bank/dashboard-stats')
         ->assertStatus(401); // Unauthorized
});

// ==========================================
// TESTS DES ROUTES (GET)
// ==========================================

it('peut récupérer les statistiques du dashboard', function () {
    Declaration::factory()->count(3)->create([
        'company_id' => $this->company->id, // <-- L'AJOUT EST ICI
        'bank_id' => $this->bank->id,
        'status' => 'submited',
        'amount' => 50000
    ]);
    // ... suite du test


    $this->actingAs($this->bankUser)
         ->getJson('/api/bank/dashboard-stats')
         ->assertStatus(200)
         ->assertJsonStructure([
             'kpis' => ['pendingCount', 'validatedCount', 'rejectedCount', 'totalCollected'],
             'paymentModeData',
             'trendData'
         ]);
});

it('peut lister les déclarations de la banque', function () {
    Declaration::factory()->count(5)->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id
    ]);

    $this->actingAs($this->bankUser)
         ->getJson('/api/bank/declarations')
         ->assertStatus(200)
         ->assertJsonStructure([
             'data' => [
                 '*' => ['id', 'reference', 'amount', 'status'] // Optionnel : vérifie le contenu des objets
             ],           
             'current_page',   // Infos de pagination directement à la racine
             'last_page',
             'total',
             'per_page'
         ]); 
});
it('peut afficher les détails d\'une déclaration spécifique', function () {
   $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id, // <-- L'AJOUT EST ICI
        'bank_id' => $this->bank->id
    ]);
    $this->actingAs($this->bankUser)
         ->getJson("/api/bank/declarations/{$declaration->id}")
         ->assertStatus(200)
         ->assertJsonPath('declaration.id', $declaration->id);
});

it('empêche une banque de voir la déclaration d\'une autre banque', function () {
    $otherBank = Bank::factory()->create();
    
    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id, // <--- LA CORRECTION EST ICI
        'bank_id' => $otherBank->id
    ]);

    $this->actingAs($this->bankUser)
         ->getJson("/api/bank/declarations/{$declaration->id}")
         ->assertStatus(403); // ou 404 selon votre gestion d'erreur
});
// ==========================================
// TESTS DES ACTIONS SUR LES DÉPÔTS EN LIGNE
// ==========================================

it('peut valider un paiement en ligne', function () {
   $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id, // <-- L'AJOUT EST ICI
        'bank_id' => $this->bank->id,
        'status' => 'submited'
    ]);

    $this->actingAs($this->bankUser)
         ->putJson("/api/bank/declarations/{$declaration->id}/validate", [
             'reference' => 'REF-BANK-999'
         ])
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'id' => $declaration->id,
        'status' => 'bank_validated',
        'reference' => 'REF-BANK-999'
    ]);
});

it('peut rejeter un paiement avec un commentaire', function () {
    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id, // <--- AJOUTEZ CETTE LIGNE
        'bank_id' => $this->bank->id,
        'status' => 'submited'
    ]);

    $this->actingAs($this->bankUser)
         ->putJson("/api/bank/declarations/{$declaration->id}/reject", [
             'comment_reject' => 'Signature non conforme'
         ])
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'id' => $declaration->id,
        'status' => 'rejected',
        'comment_reject' => 'Signature non conforme'
    ]);
});

// ==========================================
// TESTS DE SAISIE AU GUICHET
// ==========================================

it('peut rechercher une entreprise par son NIU', function () {
    $this->actingAs($this->bankUser)
         ->getJson("/api/bank/companies/search?niu={$this->company->niu}")
         ->assertStatus(200)
         ->assertJsonPath('company.niu', $this->company->niu)
         ->assertJsonPath('company.id', $this->company->id);
});

it('peut enregistrer un nouveau dépôt au guichet avec un fichier PDF', function () {
    Storage::fake('public'); // Simule le système de fichiers
    $file = UploadedFile::fake()->create('bordereau.pdf', 100, 'application/pdf');

    $this->actingAs($this->bankUser)
         ->postJson('/api/bank/counter-deposits', [
             'company_id' => $this->company->id,
             'reference' => 'GUICHET-001',
             'payment_mode' => 'especes',
             'amount' => 150000,
             'period' => '2026-03-01',
             'proof_pdf' => $file
         ])
         ->assertStatus(201); // <--- ON CHANGE 200 EN 201 ICI

    $this->assertDatabaseHas('declarations', [
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'reference' => 'GUICHET-001',
        'amount' => 150000,
        'status' => 'bank_validated' // Ou submited selon la logique métier de votre guichet
    ]);
    
    // Vérifier qu'un fichier a bien été uploadé
    $declaration = Declaration::where('reference', 'GUICHET-001')->first();
    Storage::disk('public')->assertExists($declaration->proof_path);
});

it('peut modifier un dépôt au guichet existant', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('nouveau-bordereau.pdf', 100, 'application/pdf');
    
    $declaration = Declaration::factory()->create([
        'bank_id' => $this->bank->id,
        'company_id' => $this->company->id,
        'reference' => 'OLD-REF',
        'payment_mode' => 'especes',
        'status' => 'submited'
    ]);

    $this->actingAs($this->bankUser)
         ->postJson("/api/bank/counter-deposits/{$declaration->id}", [
             '_method' => 'PUT', // Astuce Laravel pour les uploads en PUT
             'company_id' => $this->company->id,
             'reference' => 'NEW-REF-UPDATED',
             'payment_mode' => 'especes',
             'amount' => 200000,
             'period' => '2026-03-01',
             'proof_pdf' => $file
         ])
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'id' => $declaration->id,
        'reference' => 'NEW-REF-UPDATED',
        'amount' => 200000
    ]);
});