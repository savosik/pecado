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
            ->where('seo.title', 'Pecado — товары для взрослых: секс-игрушки, бренды, доставка')
            ->where('seo.h1', 'Товары для взрослых')
            ->where('seo.canonical', url('/'))
            ->where('seo.image', url('/images/logo.png'))
            // SEO-текст главной (из seo/texts/home.html) для нижнего блока.
            ->where('seoText', fn ($t) => is_string($t) && str_contains($t, 'товаров для взрослых'))
        );
    }

    #[Test]
    public function home_renders_neutral_title_without_wholesale(): void
    {
        $html = $this->withoutVite()->get('/')->getContent();

        // Ровно один <title> в серверном HTML.
        $this->assertSame(1, substr_count($html, '<title'));
        // Нейтральный розничный заголовок (без «секс-шоп» и без «опт»).
        $this->assertStringContainsString('<title inertia>Pecado — товары для взрослых: секс-игрушки, бренды, доставка</title>', $html);
        $this->assertStringNotContainsString('Секс-шоп', $html);
        $this->assertStringNotContainsString('оптов', $html);
        $this->assertStringNotContainsString('оптом', $html);
        // canonical и og:image на главной (из аудита).
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringContainsString('<meta property="og:image"', $html);
        // Бренд не задвоен.
        $this->assertStringNotContainsString('Pecado - Pecado', $html);
        $this->assertStringNotContainsString('Pecado | Pecado', $html);
    }
}
