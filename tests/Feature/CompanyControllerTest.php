<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\Declaration;
use App\Models\User;
use App\Notifications\DeclarationStatusUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 1. L'acteur principal : L'entreprise
    $this->companyUser = User::factory()->create(['role' => 'company']);
    $this->company = Company::factory()->create(['user_id' => $this->companyUser->id]);

    // 2. L'acteur secondaire : Une autre entreprise (pour tester l'isolement des données)
    $this->otherCompanyUser = User::factory()->create(['role' => 'company']);
    $this->otherCompany = Company::factory()->create(['user_id' => $this->otherCompanyUser->id]);

    // 3. Les destinataires des notifications
    $this->bankUser = User::factory()->create(['role' => 'bank']);
    $this->bank = Bank::factory()->create(['user_id' => $this->bankUser->id]);

    $this->cnpsUser = User::factory()->create(['role' => 'cnps']);
});

// ==========================================
// TESTS DE SÉCURITÉ
// ==========================================

it('interdit l\'accès à l\'espace entreprise pour un compte banque', function () {
    $this->actingAs($this->bankUser)
         ->getJson('/api/company/declarations')
         ->assertStatus(403);
});

// ==========================================
// TESTS DE LECTURE (GET)
// ==========================================

it('liste uniquement les déclarations de l\'entreprise connectée', function () {
    // 3 déclarations pour NOTRE entreprise
    Declaration::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id
    ]);

    // 2 déclarations pour une AUTRE entreprise
    Declaration::factory()->count(2)->create([
        'company_id' => $this->otherCompany->id,
        'bank_id' => $this->bank->id
    ]);

    $response = $this->actingAs($this->companyUser)
         ->getJson('/api/company/declarations')
         ->assertStatus(200);
         
    // On vérifie qu'on ne récupère que les 3 nôtres
    expect(count($response->json('declarations.data')))->toBe(3);
});

// ==========================================
// TESTS D'INITIATION (POST)
// ==========================================

it('peut initier une déclaration classique (Virement) avec un PDF', function () {
    Storage::fake('public');
    Notification::fake();
    
    $file = UploadedFile::fake()->create('preuve.pdf', 100, 'application/pdf');

    $this->actingAs($this->companyUser)
         ->postJson('/api/company/declarations', [
             'bank_id' => $this->bank->id,
             'reference' => 'VIR-001',
             'period' => '2026-03-01',
             'amount' => 500000,
             'payment_mode' => 'virement',
             'proof_pdf' => $file
         ])
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'reference' => 'VIR-001',
        'status' => 'submited' // Le statut classique en attente de la banque
    ]);

    // Vérifie que la banque a bien reçu un email
    Notification::assertSentTo([$this->bankUser], DeclarationStatusUpdated::class);
});

it('valide automatiquement la déclaration et notifie la CNPS si c\'est du Mobile Money', function () {
    Notification::fake();

    $this->actingAs($this->companyUser)
         ->postJson('/api/company/declarations', [
             'reference' => 'MOMO-001',
             'mobile_reference' => 'TXN-MOMO-999',
             'period' => '2026-03-01',
             'amount' => 15000,
             'payment_mode' => 'mobile_money'
         ])
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'reference' => 'MOMO-001',
        'status' => 'bank_validated' // Magie du MoMo : ça saute l'étape banque !
    ]);

    // Vérifie que la CNPS (et pas la banque) a été alertée
    Notification::assertSentTo([$this->cnpsUser], DeclarationStatusUpdated::class);
});

// ==========================================
// TESTS DE MODIFICATION (PUT)
// ==========================================

it('peut modifier une déclaration rejetée', function () {
    Storage::fake('public');
    Notification::fake();

    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'status' => 'rejected'
    ]);

    $file = UploadedFile::fake()->create('nouvelle-preuve.pdf', 100, 'application/pdf');

    $this->actingAs($this->companyUser)
         ->postJson("/api/company/declarations/{$declaration->id}", [
             '_method' => 'PUT', // Toujours nécessaire pour uploader un fichier sur une route PUT
             'bank_id' => $this->bank->id,
             'reference' => 'REF-CORRIGEE',
             'period' => '2026-03-01',
             'amount' => 600000,
             'payment_mode' => 'virement',
             'proof_pdf' => $file
         ])
         ->assertStatus(200);

    $this->assertDatabaseHas('declarations', [
        'id' => $declaration->id,
        'reference' => 'REF-CORRIGEE',
        'amount' => 600000,
        'status' => 'submited' // Le statut repasse en attente !
    ]);
});

it('empêche de modifier une déclaration déjà validée', function () {
    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'status' => 'cnps_validated' // Déjà validé
    ]);

    $this->actingAs($this->companyUser)
         ->postJson("/api/company/declarations/{$declaration->id}", [
             '_method' => 'PUT',
             'reference' => 'HACK-REF',
             'amount' => 10,
             'payment_mode' => 'virement'
         ])
         ->assertStatus(403); // Interdit !
});

// ==========================================
// TESTS DE TÉLÉCHARGEMENT DE QUITTANCE (GET)
// ==========================================

it('peut télécharger sa quittance officielle', function () {
    Storage::fake('public');
    
    // On crée un faux fichier physique
    $file = UploadedFile::fake()->create('quittance.pdf', 100, 'application/pdf');
    $path = $file->store('receipts', 'public');

    $declaration = Declaration::factory()->create([
        'company_id' => $this->company->id,
        'bank_id' => $this->bank->id,
        'reference' => 'TEST-QUITTANCE',
        'status' => 'cnps_validated',
        'receipt_path' => $path
    ]);

    $this->actingAs($this->companyUser)
         ->get("/api/company/declarations/{$declaration->id}/download-receipt")
         ->assertStatus(200); // Le fichier se télécharge bien
});

it('empêche de télécharger la quittance d\'une autre entreprise', function () {
    $declaration = Declaration::factory()->create([
        'company_id' => $this->otherCompany->id, // Appartient à l'autre entreprise
        'bank_id' => $this->bank->id,
        'receipt_path' => 'receipts/fake.pdf'
    ]);

    $this->actingAs($this->companyUser)
         ->get("/api/company/declarations/{$declaration->id}/download-receipt")
         ->assertStatus(403);
});