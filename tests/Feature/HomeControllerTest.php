<?php

namespace Tests\Feature;

use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Region::factory()->create();
    }

    #[Test]
    public function home_exposes_seo_title_and_h1(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('User/Home')
            ->where('seo.title', 'Pecado — оптовый поставщик товаров для взрослых')
            ->where('seo.h1', 'Pecado — товары для взрослых оптом')
            // SEO-текст главной (из seo/texts/home.html) для нижнего блока.
            ->where('seoText', fn ($t) => is_string($t) && str_contains($t, 'оптов'))
        );
    }

    #[Test]
    public function home_renders_single_title_without_duplicated_brand(): void
    {
        $html = $this->withoutVite()->get('/')->getContent();

        // Ровно один <title> в серверном HTML.
        $this->assertSame(1, substr_count($html, '<title'));
        // B2B-заголовок главной (без позиционирования как «секс-шоп»).
        $this->assertStringContainsString('<title inertia>Pecado — оптовый поставщик товаров для взрослых</title>', $html);
        $this->assertStringNotContainsString('Секс-шоп', $html);
        // Бренд не задвоен.
        $this->assertStringNotContainsString('Pecado - Pecado', $html);
        $this->assertStringNotContainsString('Pecado | Pecado', $html);
    }
}
