<?php

use App\Models\User;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Auth Webhook Configuration', function () {

    test('authenticated user can update webhook url', function () {
        $user = User::factory()->create(['webhook_url' => null]);
        $token = auth('api')->login($user);
        $url = 'https://meu-site.com/callback';

        postJson('/api/auth/webhook', ['url' => $url], ['Authorization' => "Bearer $token"])
            ->assertStatus(200)
            ->assertJsonPath('data.webhook_url', $url);

        // Verifica no banco se salvou
        expect($user->fresh()->webhook_url)->toBe($url);
    });

    test('it validates webhook url format', function () {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        // URL inválida
        postJson('/api/auth/webhook', ['url' => 'not-a-url'], ['Authorization' => "Bearer $token"])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
            
        // URL vazia
        postJson('/api/auth/webhook', ['url' => ''], ['Authorization' => "Bearer $token"])
            ->assertStatus(422);
    });

    test('unauthenticated user cannot update webhook', function () {
        postJson('/api/auth/webhook', ['url' => 'https://google.com'])
            ->assertStatus(401);
    });

});
