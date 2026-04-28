<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateRichContentJob;
use App\Models\Product;
use App\Services\Product\RichContent\RichContentGenerationException;
use App\Services\Product\RichContent\RichContentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class GenerateRichContentJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_calls_generator_when_product_has_no_blocks(): void
    {
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара для генерации. ', 10),
            'rich_content' => null,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn ($p) => $p instanceof Product && $p->id === $product->id))
            ->andReturn(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'ok']]]]);
        $generator->shouldNotReceive('recordFailure');

        (new GenerateRichContentJob($product->id))->handle($generator);
    }

    public function test_skips_when_blocks_already_present(): void
    {
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'cached']]]],
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');

        (new GenerateRichContentJob($product->id))->handle($generator);
    }

    public function test_skips_when_disabled(): void
    {
        config(['rich_content.enabled' => false]);

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');

        (new GenerateRichContentJob($product->id))->handle($generator);
    }

    public function test_skips_during_failure_cooldown(): void
    {
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
            'rich_content_generation_failed_at' => now()->subHour(),
            'rich_content_generation_attempts' => 1,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');

        (new GenerateRichContentJob($product->id))->handle($generator);
    }

    public function test_skips_after_max_attempts(): void
    {
        config(['rich_content.max_attempts' => 3]);

        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
            'rich_content_generation_attempts' => 3,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldNotReceive('generate');

        (new GenerateRichContentJob($product->id))->handle($generator);
    }

    public function test_records_failure_when_generator_throws(): void
    {
        $product = Product::factory()->create([
            'description' => str_repeat('Описание товара. ', 10),
            'rich_content' => null,
        ]);

        $generator = Mockery::mock(RichContentGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new RichContentGenerationException('LLM не отвечает'));
        $generator->shouldReceive('recordFailure')
            ->once()
            ->with(Mockery::on(fn ($p) => $p instanceof Product && $p->id === $product->id));

        (new GenerateRichContentJob($product->id))->handle($generator);
    }

    public function test_dispatches_to_rich_content_queue(): void
    {
        $job = new GenerateRichContentJob(1);

        $this->assertSame('rich-content', $job->queue);
    }
}
