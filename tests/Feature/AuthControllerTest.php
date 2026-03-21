<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\CnpsAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 1. Création d'une Banque
    $this->bankUser = User::factory()->create([
        'email' => 'bank@test.com',
        'password' => bcrypt('password123'),
        'role' => 'bank'
    ]);
    $this->bank = Bank::factory()->create(['user_id' => $this->bankUser->id]);

    // 2. Création d'un Agent CNPS
    $this->cnpsUser = User::factory()->create([
        'email' => 'cnps@test.com',
        'password' => bcrypt('password123'),
        'role' => 'cnps'
    ]);
    $this->cnpsAgent = CnpsAgent::factory()->create(['user_id' => $this->cnpsUser->id]);

    // 3. Création d'une Entreprise
    $this->companyUser = User::factory()->create([
        'email' => 'company@test.com',
        'password' => bcrypt('password123'),
        'role' => 'company'
    ]);
    $this->company = Company::factory()->create([
        'user_id' => $this->companyUser->id,
        'niu' => 'M0123456789'
    ]);
});

// ==========================================
// TESTS DE CONNEXION CLASSIQUE (Banque & CNPS)
// ==========================================

it('permet à une banque de se connecter avec les bons identifiants', function () {
    $this->postJson('/api/login', [
        'email' => 'bank@test.com',
        'password' => 'password123'
    ])
    ->assertStatus(200)
    ->assertJsonStructure([
        'user' => ['id', 'email', 'role', 'bank'], // On vérifie que la relation 'bank' est bien chargée
        'access_token',
        'token_type'
    ]);
});

it('permet à un agent CNPS de se connecter avec les bons identifiants', function () {
    $this->postJson('/api/login', [
        'email' => 'cnps@test.com',
        'password' => 'password123'
    ])
    ->assertStatus(200)
    ->assertJsonStructure([
        'user' => ['id', 'email', 'role', 'cnps'], // On vérifie la relation 'cnps'
        'access_token'
    ]);
});

it('rejette la connexion avec un mauvais mot de passe', function () {
    $this->postJson('/api/login', [
        'email' => 'bank@test.com',
        'password' => 'mauvais_mot_de_passe'
    ])
    ->assertStatus(401);
});

it('empêche une entreprise d\'utiliser la route de login classique', function () {
    $this->postJson('/api/login', [
        'email' => 'company@test.com',
        'password' => 'password123'
    ])
    ->assertStatus(200) // Note: Un statut 403 (Forbidden) serait encore mieux ici !
    ->assertSee("les entreprises doivent se connecter via l'api", false); // <-- AJOUTEZ ', false' ICI
});
// ==========================================
// TESTS DE CONNEXION ENTREPRISE (Via NIU)
// ==========================================

it('permet à une entreprise existante de se connecter avec son NIU', function () {
    $this->postJson('/api/login-company', [
        'niu' => 'M0123456789'
    ])
    ->assertStatus(200)
    ->assertJsonStructure([
        'user',
        'access_token'
    ]);
});

it('crée un nouveau compte si le NIU est inconnu lors de la connexion', function () {
    // Un NIU qui n'existe pas en base
    $nouveauNiu = 'M999888777666';

    $this->postJson('/api/login-company', [
        'niu' => $nouveauNiu,
        'name' => 'Nouvelle Entreprise SARL'
    ])
    ->assertStatus(200)
    ->assertJsonStructure(['access_token']);

    // On vérifie que l'entreprise a bien été insérée dans la base de données
    $this->assertDatabaseHas('companies', [
        'niu' => $nouveauNiu,
        'raison_sociale' => 'Nouvelle Entreprise SARL'
    ]);
});

// ==========================================
// TESTS DU PROFIL (/me) ET DECONNEXION (/logout)
// ==========================================

it('retourne les informations du profil pour un utilisateur authentifié', function () {
    // Sanctum::actingAs permet de simuler une requête avec un token valide
    Sanctum::actingAs($this->bankUser, ['*']);

    $this->getJson('/api/me')
         ->assertStatus(200)
         ->assertJsonPath('email', $this->bankUser->email)
         ->assertJsonPath('role', 'bank');
});

it('bloque l\'accès au profil si l\'utilisateur n\'est pas authentifié', function () {
    $this->getJson('/api/me')
         ->assertStatus(401); // Unauthorized
});

it('permet à l\'utilisateur de se déconnecter', function () {
    // On simule un utilisateur connecté avec Sanctum
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/logout')
         ->assertStatus(200)
         ->assertJson(['message' => 'vous êtes déconnecté']);
});