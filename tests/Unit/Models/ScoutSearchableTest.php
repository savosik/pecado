<?php

namespace Tests\Unit\Models;

use App\Helpers\SearchHelper;
use App\Models\Article;
use App\Models\Brand;
use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoutSearchableTest extends TestCase
{
    use RefreshDatabase;

    // ─── Brand ─────────────────────────────────────────────────

    public function test_brand_to_searchable_array_contains_required_fields(): void
    {
        $brand = Brand::factory()->create(['name' => 'Lola Dream']);

        $array = $brand->toSearchableArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('name_translit', $array);
        $this->assertArrayHasKey('name_cyrillic', $array);
        $this->assertArrayHasKey('name_layout', $array);

        $this->assertEquals('Lola Dream', $array['name']);
        $this->assertEquals((int) $brand->id, $array['id']);
    }

    public function test_brand_translit_fields_generated_correctly(): void
    {
        $brand = Brand::factory()->create(['name' => 'Вибратор']);

        $array = $brand->toSearchableArray();

        $this->assertEquals('vibrator', $array['name_translit']); // RU→EN translit
        $this->assertEquals('вибратор', $array['name_cyrillic']); // already cyrillic
        $this->assertEquals(SearchHelper::convertLayout('Вибратор'), $array['name_layout']);
    }

    // ─── Article ───────────────────────────────────────────────

    public function test_article_to_searchable_array_contains_required_fields(): void
    {
        $article = Article::factory()->create([
            'title' => 'Как выбрать подарок',
            'short_description' => 'Советы по выбору',
        ]);

        $array = $article->toSearchableArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('title_translit', $array);
        $this->assertArrayHasKey('title_cyrillic', $array);
        $this->assertArrayHasKey('title_layout', $array);
        $this->assertArrayHasKey('short_description', $array);

        $this->assertEquals('Как выбрать подарок', $array['title']);
        $this->assertEquals('Советы по выбору', $array['short_description']);
    }

    public function test_article_should_be_searchable_when_published(): void
    {
        $article = Article::factory()->create([
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);

        $this->assertTrue($article->shouldBeSearchable());
    }

    public function test_article_should_not_be_searchable_when_unpublished(): void
    {
        $article = Article::factory()->create([
            'is_published' => false,
            'published_at' => now()->subDay(),
        ]);

        $this->assertFalse($article->shouldBeSearchable());
    }

    public function test_article_should_not_be_searchable_when_future_date(): void
    {
        $article = Article::factory()->create([
            'is_published' => true,
            'published_at' => now()->addDay(),
        ]);

        $this->assertFalse($article->shouldBeSearchable());
    }

    // ─── News ──────────────────────────────────────────────────

    public function test_news_to_searchable_array_contains_required_fields(): void
    {
        $news = News::factory()->create(['title' => 'Новинки сезона']);

        $array = $news->toSearchableArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('title_translit', $array);
        $this->assertArrayHasKey('title_cyrillic', $array);
        $this->assertArrayHasKey('title_layout', $array);

        $this->assertEquals('Новинки сезона', $array['title']);
    }

    public function test_news_should_be_searchable_when_published(): void
    {
        $news = News::factory()->create([
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);

        $this->assertTrue($news->shouldBeSearchable());
    }

    public function test_news_should_not_be_searchable_when_unpublished(): void
    {
        $news = News::factory()->create([
            'is_published' => false,
            'published_at' => now()->subDay(),
        ]);

        $this->assertFalse($news->shouldBeSearchable());
    }
}
