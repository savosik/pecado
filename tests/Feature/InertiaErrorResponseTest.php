<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InertiaErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_inertia_request_on_missing_route_renders_error_page(): void
    {
        $slug = 'definitely-not-an-existing-product-'.uniqid();

        $response = $this
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => '1',
                'Accept' => 'text/html, application/xhtml+xml',
            ])
            ->get('/products/'.$slug);

        $response->assertStatus(404);
        $response->assertHeader('X-Inertia', 'true');

        $payload = $response->json();
        $this->assertSame('ErrorPage', $payload['component']);
        $this->assertSame(404, $payload['props']['status']);
    }

    public function test_api_request_on_missing_route_returns_json(): void
    {
        $response = $this
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/api/non-existent-endpoint-'.uniqid());

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Не найдено']);
    }

    public function test_ajax_request_outside_inertia_returns_json(): void
    {
        $slug = 'still-not-existing-'.uniqid();

        $response = $this
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/products/'.$slug);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Не найдено']);
    }

    public function test_legacy_catalog_url_redirects_to_products(): void
    {
        $this->get('/catalog')->assertRedirect('/products')->assertStatus(301);
        $this->get('/catalog/some-slug')->assertRedirect('/products')->assertStatus(301);
        $this->get('/catalog/category/foo/bar')->assertRedirect('/products')->assertStatus(301);
    }
}
