<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // On crée un utilisateur de test
    $this->user = User::factory()->create();

    // On lui ajoute 3 notifications NON LUES
    for ($i = 0; $i < 3; $i++) {
        $this->user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\TestNotification',
            'data' => ['message' => 'Ceci est une notification non lue ' . $i],
            'read_at' => null, // null = non lue
        ]);
    }

    // On lui ajoute 2 notifications DÉJÀ LUES
    for ($i = 0; $i < 2; $i++) {
        $this->user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\TestNotification',
            'data' => ['message' => 'Ceci est une ancienne notification ' . $i],
            'read_at' => now(), // date = déjà lue
        ]);
    }
});

// ==========================================
// TESTS DE SÉCURITÉ
// ==========================================

it('bloque l\'accès aux utilisateurs non connectés', function () {
    $this->getJson('/api/notifications/unread')
         ->assertStatus(401); // 401 Unauthorized
});

// ==========================================
// TESTS DE LECTURE (GET)
// ==========================================

it('peut récupérer uniquement les notifications non lues', function () {
    $response = $this->actingAs($this->user)
         ->getJson('/api/notifications/unread')
         ->assertStatus(200);

    // L'utilisateur doit avoir exactement 3 notifications non lues
    expect(count($response->json()))->toBe(3);
});

it('peut récupérer toutes les notifications avec pagination', function () {
    $response = $this->actingAs($this->user)
         ->getJson('/api/notifications/all')
         ->assertStatus(200)
         ->assertJsonStructure(['data', 'current_page', 'total']); // Vérifie le format paginate()

    // L'utilisateur doit avoir 5 notifications au total (3 non lues + 2 lues)
    expect($response->json('total'))->toBe(5);
    expect(count($response->json('data')))->toBe(5);
});

// ==========================================
// TESTS DE MISE À JOUR (PUT / POST)
// ==========================================

it('peut marquer une notification spécifique comme lue', function () {
    // On récupère la première notification non lue
    $notification = $this->user->unreadNotifications->first();

    $this->actingAs($this->user)
         ->putJson("/api/notifications/mark-as-read/{$notification->id}")
         ->assertStatus(201); // Votre code renvoie 201

    // On vérifie en base de données que le champ "read_at" n'est plus nul
    $this->assertDatabaseMissing('notifications', [
        'id' => $notification->id,
        'read_at' => null
    ]);
});

it('peut marquer toutes les notifications comme lues en une seule fois', function () {
    // Avant l'action, on a 3 notifications non lues
    expect($this->user->unreadNotifications->count())->toBe(3);

    $this->actingAs($this->user)
         ->postJson('/api/notifications/mark-all-as-read')
         ->assertStatus(200);

    // Après l'action, l'utilisateur ne doit plus avoir aucune notification non lue
    $this->user->refresh(); // On rafraîchit le modèle depuis la BD
    expect($this->user->unreadNotifications->count())->toBe(0);
});