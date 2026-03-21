<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\CnpsAgent;
use App\Models\Declaration;
use App\Models\User;
use App\Notifications\DeclarationStatusUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 1. L'acteur principal : L'agent CNPS
    $this->cnpsUser = User::factory()->create(['role' => 'cnps']);
    $this->cnpsAgent = CnpsAgent::factory()->create([
        'user_id' => $this->cnpsUser->id,
        'matricule' => 'CNPS-001'
    ]);

    // 2. Les acteurs secondaires pour lier les déclarations
    $this->companyUser = User::factory()->create(['role' => 'company']);
    $this->company = Company::factory()->create(['user_id' => $this->companyUser->id]);

    $this->bankUser = User::factory()->create(['role' => 'bank']);
    $this->bank = Bank::factory()->create(['user_id' => $this->bankUser->id]);
});

// ==========================================
// TESTS DE SÉCURITÉ
// ==========================================

it('interdit l\'accès aux routes CNPS pour une entreprise', function () {
    $this->actingAs($this->companyUser)
         ->getJson('/api/cnps/declarations')
         ->assertStatus(403);
});

// ==========================================
// TESTS DE LECTURE (GET)
// ==========================================

it('peut lister les déclarations avec pagination', function () {
    Declaration::factory()->count(15)->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
    ]);

    $this->actingAs($this->cnpsUser)
         ->getJson('/api/cnps/declarations')
         ->assertStatus(200)
         ->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);
});

it('peut récupérer les statistiques globales', function () {
    Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'status' => 'cnps_validated',
        'amount' => 100000
    ]);

    $this->actingAs($this->cnpsUser)
         ->getJson('/api/cnps/statistics')
         ->assertStatus(200)
         ->assertJsonStructure([
             'kpis' => ['totalCollected', 'reconciliationRate', 'rejectedCount'],
             'bankChartData',
             'paymentModeData'
         ]);
});

// ==========================================
// TESTS D'ACTIONS METIERS (PUT / RAPPROCHEMENT)
// ==========================================

it('peut rapprocher un paiement et notifier l\'entreprise', function () {
    Notification::fake(); // Empêche l'envoi de vrais emails

    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'status' => 'bank_validated'
    ]);

    $this->actingAs($this->cnpsUser)
         ->putJson("/api/cnps/declarations/{$declaration->id}/reconcile")
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'id' => $declaration->id,
        'status' => 'cnps_validated'
    ]);

    // Vérifie que la notification a bien été envoyée à l'utilisateur de l'entreprise
    Notification::assertSentTo(
        [$this->companyUser], 
        DeclarationStatusUpdated::class
    );
});

it('peut rejeter un paiement avec un motif', function () {
    Notification::fake();

    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'status' => 'bank_validated'
    ]);

    $this->actingAs($this->cnpsUser)
         ->putJson("/api/cnps/declarations/{$declaration->id}/reject", [
             'comment_reject' => 'Le montant ne correspond pas au bordereau.'
         ])
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'id' => $declaration->id,
        'status' => 'rejected',
        'comment_reject' => 'Le montant ne correspond pas au bordereau.'
    ]);
});

it('échoue si on rejette sans fournir de commentaire', function () {
    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
    ]);

    $this->actingAs($this->cnpsUser)
         ->putJson("/api/cnps/declarations/{$declaration->id}/reject", [])
         ->assertStatus(422) // Validation error
         ->assertJsonValidationErrors(['comment_reject']);
});

// ==========================================
// TESTS D'UPLOAD DE QUITTANCE (POST)
// ==========================================

it('peut uploader une quittance PDF si le statut est validé', function () {
    Storage::fake('public');
    Notification::fake();
    
    $file = UploadedFile::fake()->create('quittance.pdf', 100, 'application/pdf');

    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'status' => 'cnps_validated' // Doit être validé !
    ]);

    $this->actingAs($this->cnpsUser)
         ->postJson("/api/cnps/declarations/{$declaration->id}/receipt", [
             'receipt_pdf' => $file
         ])
         ->assertStatus(200);

    // Recharger la déclaration depuis la BD pour voir si le chemin a été ajouté
    $declaration->refresh();
    
    $this->assertNotNull($declaration->receipt_path);
    Storage::disk('public')->assertExists($declaration->receipt_path);
});

it('refuse l\'upload de la quittance si le paiement n\'est pas rapproché', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('quittance.pdf', 100, 'application/pdf');

    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'status' => 'bank_validated' // <-- Pas encore cnps_validated
    ]);

    $this->actingAs($this->cnpsUser)
         ->postJson("/api/cnps/declarations/{$declaration->id}/receipt", [
             'receipt_pdf' => $file
         ])
         ->assertStatus(403); // Bloqué par la sécurité métier
});

// ==========================================
// TESTS DE GESTION DES PROFILS (ADMIN)
// ==========================================

it('peut créer un compte banque', function () {
    $this->actingAs($this->cnpsUser)
         ->postJson('/api/cnps/banks', [
             'email' => 'contact@ubabank.cm',
             'password' => 'password123',
             'bank_code' => 'UBA',
             'bank_name' => 'United Bank for Africa'
         ])
         ->assertStatus(201);

    $this->assertDatabaseHas('users', ['email' => 'contact@ubabank.cm', 'role' => 'bank']);
    $this->assertDatabaseHas('banks', ['bank_code' => 'UBA', 'bank_name' => 'United Bank for Africa']);
});

it('peut lister les banques', function () {
    $this->actingAs($this->cnpsUser)
         ->getJson('/api/cnps/banks')
         ->assertStatus(200);
});

it('peut créer un agent CNPS', function () {
    $this->actingAs($this->cnpsUser)
         ->postJson('/api/cnps/agents', [
             'email' => 'nouveau@cnps.cm',
             'password' => 'password123',
             'matricule' => 'MAT-999',
             'full_name' => 'Agent Test'
         ])
         ->assertStatus(201);

    $this->assertDatabaseHas('cnps_agents', ['matricule' => 'MAT-999']);
});