<?php

namespace Tests\Feature\Api\Client;

use App\Models\Faq;
use App\Models\News;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Region;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiContentTest extends ClientApiTestCase
{
    private Region $region;

    private Region $otherRegion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::factory()->create();
        $this->otherRegion = Region::factory()->create();
        $this->client->update(['region_id' => $this->region->id]);
    }

    #[Test]
    #[TestDox('Акции: видны действующие для клиента правила с периодом, условиями и наградой; чужой регион, чужая аудитория и закончившиеся — скрыты')]
    public function promotions_show_only_what_applies_to_the_client(): void
    {
        $gift = Product::factory()->create(['code' => 'GIFT', 'sku' => 'SKU-GIFT', 'name' => 'Подарок']);
        $landingProduct = Product::factory()->create(['code' => 'L1', 'sku' => 'SKU-L1', 'name' => 'Товар акции']);

        $live = Promotion::factory()->create(['name' => 'Подарок за 150 000', 'slug' => 'podarok', 'description' => '<p>Купите на 150 000 ₽ — подарок.</p>']);
        $live->products()->attach($landingProduct->id);
        PromotionRule::factory()->active()->create([
            'promotion_id' => $live->id,
            'name' => 'Порог 150 000',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'rewards' => [['type' => 'fixed', 'product_id' => $gift->id, 'quantity' => 1, 'price' => 0, 'multiply' => 'once', 'max_multiplier' => 1, 'optional' => false]],
        ]);

        $infoOnly = Promotion::factory()->create(['name' => 'Сезон скидок', 'slug' => 'sezon', 'description' => 'Скидки в каталоге']);

        $finished = Promotion::factory()->create(['slug' => 'proshla']);
        PromotionRule::factory()->active()->create(['promotion_id' => $finished->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);

        $foreignAudience = Promotion::factory()->create(['slug' => 'ne-vam']);
        PromotionRule::factory()->active()->create(['promotion_id' => $foreignAudience->id, 'audience' => ['user_ids' => [$this->client->id + 100]]]);

        $foreignRegion = Promotion::factory()->create(['slug' => 'drugoi-region']);
        $foreignRegion->regions()->attach($this->otherRegion->id);

        Promotion::factory()->create(['slug' => 'vyklyuchena', 'is_active' => false]);

        $list = $this->api('GET', '/promotions')->assertOk();
        $this->assertEqualsCanonicalizing(['podarok', 'sezon'], array_column($list->json('data'), 'slug'));

        $row = collect($list->json('data'))->firstWhere('slug', 'podarok');
        $this->assertSame(url('/promotions/podarok'), $row['url']);
        $this->assertSame('Порог 150 000', $row['rules'][0]['name']);
        $this->assertStringStartsWith('с ', $row['rules'][0]['period']);
        $this->assertNotEmpty($row['rules'][0]['conditions']);
        $this->assertSame('Подарок', $row['rules'][0]['rewards'][0]['products'][0]['name']);
        $this->assertSame('бесплатно', $row['rules'][0]['rewards'][0]['price']);
        $this->assertSame('manager', $row['rules'][0]['how_applied'], 'В режиме info промо-позицию добавляет менеджер');

        $this->api('GET', '/promotions/podarok')->assertOk()
            ->assertJsonPath('data.text', 'Купите на 150 000 ₽ — подарок.');

        $products = $this->api('GET', '/promotions/podarok/products')->assertOk()->json('data');
        $roles = collect($products)->mapWithKeys(fn ($p) => [$p['sku'] => $p['role']])->all();
        $this->assertSame(['landing'], $roles['SKU-L1']);
        $this->assertContains('reward', $roles['SKU-GIFT']);

        foreach (['proshla', 'ne-vam', 'drugoi-region', 'vyklyuchena'] as $hidden) {
            $this->api('GET', "/promotions/{$hidden}")->assertStatus(404);
        }
    }

    #[Test]
    #[TestDox('Новости: опубликованные, свежие первыми, фильтр по тегу; полный текст в news.get; черновик и будущая — 404')]
    public function news_list_and_item(): void
    {
        $old = News::factory()->create(['title' => 'Старая', 'slug' => 'old', 'is_published' => true, 'published_at' => now()->subWeek(), 'detailed_description' => '<p>Текст старой</p>']);
        $fresh = News::factory()->create(['title' => 'Свежая', 'slug' => 'fresh', 'is_published' => true, 'published_at' => now()->subHour(), 'short_description' => 'Анонс', 'detailed_description' => '<p>Полный <b>текст</b></p>']);
        $fresh->attachTag('график');
        News::factory()->create(['slug' => 'draft', 'is_published' => false]);
        News::factory()->create(['slug' => 'future', 'is_published' => true, 'published_at' => now()->addDay()]);

        $this->api('GET', '/news')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'fresh')
            ->assertJsonPath('data.0.summary', 'Анонс')
            ->assertJsonMissingPath('data.0.text');

        $this->api('GET', '/news?tags[]=график')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'fresh');

        $this->api('GET', '/news/fresh')->assertOk()->assertJsonPath('data.text', 'Полный текст')->assertJsonPath('data.tags.0', 'график');
        $this->api('GET', '/news/draft')->assertStatus(404);
        $this->api('GET', '/news/future')->assertStatus(404);
    }

    #[Test]
    #[TestDox('Подборки: активные региона, описание и товары с признаком featured')]
    public function collections(): void
    {
        $a = Product::factory()->create(['sku' => 'SKU-A']);
        $b = Product::factory()->create(['sku' => 'SKU-B']);

        $selection = ProductSelection::factory()->create(['name' => 'Хиты осени', 'slug' => 'hity', 'description' => 'Лучшее к сезону']);
        $selection->products()->attach([$a->id => ['featured' => true], $b->id => ['featured' => false]]);
        ProductSelection::factory()->create(['slug' => 'off', 'is_active' => false]);
        ProductSelection::factory()->create(['slug' => 'foreign'])->regions()->attach($this->otherRegion->id);

        $this->api('GET', '/collections')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'hity')
            ->assertJsonPath('data.0.products_count', 2);

        $this->api('GET', '/collections/hity')->assertOk()->assertJsonPath('data.text', 'Лучшее к сезону');

        $products = $this->api('GET', '/collections/hity/products')->assertOk()->json('data');
        $this->assertSame(['SKU-A' => true, 'SKU-B' => false], collect($products)->mapWithKeys(fn ($p) => [$p['sku'] => $p['featured']])->all());

        $this->api('GET', '/collections/foreign')->assertStatus(404);
        $this->api('GET', '/collections/off/products')->assertStatus(404);
    }

    #[Test]
    #[TestDox('Страницы и FAQ: опубликованное региона, полный текст; поиск по FAQ')]
    public function pages_and_faq(): void
    {
        Page::factory()->create(['title' => 'Доставка', 'slug' => 'dostavka', 'content' => json_encode(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Бесплатно от 30 000 ₽']],
        ]])]);
        Page::factory()->create(['slug' => 'hidden', 'is_published' => false]);
        Page::factory()->create(['slug' => 'foreign'])->regions()->attach($this->otherRegion->id);

        $this->api('GET', '/pages')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.url', url('/pages/dostavka'));
        $this->api('GET', '/pages/dostavka')->assertOk()->assertJsonPath('data.text', 'Бесплатно от 30 000 ₽');
        $this->api('GET', '/pages/hidden')->assertStatus(404);

        Faq::factory()->create(['title' => 'Как вернуть брак?', 'content' => '<p>Оформите возврат в кабинете.</p>', 'sort_order' => 1]);
        Faq::factory()->create(['title' => 'Сроки доставки', 'content' => 'До 3 дней', 'sort_order' => 2]);
        Faq::factory()->create(['title' => 'Скрытый', 'is_published' => false]);
        Faq::factory()->create(['title' => 'Для другого региона'])->regions()->attach($this->otherRegion->id);

        $this->api('GET', '/faq')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.question', 'Как вернуть брак?')
            ->assertJsonPath('data.0.answer', 'Оформите возврат в кабинете.');

        $this->api('GET', '/faq?q=доставк')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.answer', 'До 3 дней');
    }
}
