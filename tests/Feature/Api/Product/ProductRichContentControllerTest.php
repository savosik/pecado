<?php

namespace Tests\Feature\Api\Product;

use App\Models\Product;
use App\Services\Product\RichContent\RichContentGenerationException;
use App\Services\Product\RichContent\RichContentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductRichContentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_existing_rich_content_without_calling_generator(): void
    {
        $blocks = [['type' => 'paragraph', 'data' => ['text' => 'Готовое описание.']]];
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 20),
            'rich_content' => ['blocks' => $blocks],
            'rich_content_generated_at' => now(),
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');
        $generator->shouldNotReceive('recordFailure');
        $this->app->instance(RichContentGenerator::class, $generator);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertOk()
            ->assertJson([
                'cached' => true,
                'blocks' => $blocks,
            ]);
    }

    public function test_generates_and_returns_rich_content_when_missing(): void
    {
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара для генерации. ', 10),
            'rich_content' => null,
        ]);

        $generated = ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Сгенерированный текст.']],
        ]];

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn ($p) => $p instanceof Product && $p->id === $product->id))
            ->andReturn($generated);
        $this->app->instance(RichContentGenerator::class, $generator);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertOk()
            ->assertJson([
                'cached' => false,
                'blocks' => $generated['blocks'],
            ]);
    }

    public function test_returns_204_when_disabled(): void
    {
        config(['rich_content.enabled' => false]);

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(RichContentGenerator::class, $generator);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertNoContent();
    }

    public function test_returns_204_during_failure_cooldown(): void
    {
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
            'rich_content_generation_failed_at' => now()->subHour(),
            'rich_content_generation_attempts' => 1,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(RichContentGenerator::class, $generator);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertNoContent();
    }

    public function test_returns_204_after_max_attempts(): void
    {
        config(['rich_content.max_attempts' => 3]);

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
            'rich_content_generation_attempts' => 3,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(RichContentGenerator::class, $generator);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertNoContent();
    }

    public function test_returns_500_and_records_failure_when_generator_throws(): void
    {
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new RichContentGenerationException('LLM не отвечает'));
        $generator->shouldReceive('recordFailure')->once();
        $this->app->instance(RichContentGenerator::class, $generator);

        $response = $this->getJson("/api/products/{$product->slug}/rich-content");

        $response->assertStatus(500);
    }

    public function test_returns_404_for_unknown_slug(): void
    {
        $response = $this->getJson('/api/products/no-such-slug/rich-content');

        $response->assertNotFound();
    }
}
