<?php

namespace Tests\Feature\Api\Content;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тесты авторизации Content API.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/content/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_request_returns_200(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['*']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->getJson('/api/content/me');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token' => ['name', 'abilities', 'last_used_at', 'created_at'],
                    'endpoints',
                ],
            ]);
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token-12345',
        ])->getJson('/api/content/me');

        $response->assertStatus(401);
    }

    public function test_all_content_endpoints_require_auth(): void
    {
        $endpoints = [
            ['GET', '/api/content/news'],
            ['GET', '/api/content/articles'],
            ['GET', '/api/content/faqs'],
            ['GET', '/api/content/brand-stories'],
            ['GET', '/api/content/banners'],
            ['GET', '/api/content/pages'],
            ['GET', '/api/content/promotions'],
            ['GET', '/api/content/product-selections'],
            ['GET', '/api/content/stories'],
            ['GET', '/api/content/tags'],
            ['GET', '/api/content/products'],
            ['GET', '/api/content/brands'],
            ['GET', '/api/content/categories'],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->json($method, $url);
            $this->assertEquals(401, $response->status(), "Эндпоинт {$method} {$url} должен требовать авторизацию");
        }
    }
}
