<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ссылка на карточку по артикулу вместо slug ведёт на карточку, а не в 404:
 * так ссылки из чата-помощника и агентов открываются даже без slug.
 */
class ProductLinkBySkuTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function карточка_по_артикулу_редиректит_на_slug_и_страница_и_быстрый_просмотр(): void
    {
        $product = Product::factory()->create(['sku' => 'WY0639', 'slug' => 'winyi-amelia-wy0639']);

        $this->get('/products/WY0639')->assertRedirect('/products/winyi-amelia-wy0639')->assertStatus(301);
        $this->get('/api/products/WY0639')->assertRedirect('/api/products/winyi-amelia-wy0639');
        $this->get('/products/vibrator-krolik-winyi-basia')->assertNotFound();
    }
}
