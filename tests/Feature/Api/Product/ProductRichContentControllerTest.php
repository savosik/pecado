<?php

namespace Tests\Feature\Api\Product;

use App\Jobs\GenerateRichContentJob;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProductRichContentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_existing_rich_content_without_dispatching_job(): void
    {
        Bus::fake();

        $blocks = [['type' => 'paragraph', 'data' => ['text' => 'Готовое описание.']]];
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 20),
            'rich_content' => ['blocks' => $blocks],
            'rich_content_generated_at' => now(),
        ]);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertOk()
            ->assertJson([
                'cached' => true,
                'blocks' => $blocks,
            ]);

        Bus::assertNotDispatched(GenerateRichContentJob::class);
    }

    public function test_dispatches_job_and_returns_202_when_missing(): void
    {
        Bus::fake();

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара для генерации. ', 10),
            'rich_content' => null,
        ]);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertStatus(202);

        Bus::assertDispatched(
            GenerateRichContentJob::class,
            fn (GenerateRichContentJob $job) => $job->productId === $product->id
        );
    }

    public function test_returns_204_when_disabled(): void
    {
        Bus::fake();
        config(['rich_content.enabled' => false]);

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
        ]);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertNoContent();
        Bus::assertNotDispatched(GenerateRichContentJob::class);
    }

    public function test_returns_204_during_failure_cooldown(): void
    {
        Bus::fake();

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
            'rich_content_generation_failed_at' => now()->subHour(),
            'rich_content_generation_attempts' => 1,
        ]);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertNoContent();
        Bus::assertNotDispatched(GenerateRichContentJob::class);
    }

    public function test_returns_204_after_max_attempts(): void
    {
        Bus::fake();
        config(['rich_content.max_attempts' => 3]);

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
            'rich_content_generation_attempts' => 3,
        ]);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertNoContent();
        Bus::assertNotDispatched(GenerateRichContentJob::class);
    }

    public function test_returns_404_for_unknown_slug(): void
    {
        $response = $this->getJson('/api/products/no-such-slug/rich-content');

        $response->assertNotFound();
    }
}
