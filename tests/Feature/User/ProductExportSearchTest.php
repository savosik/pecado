<?php

namespace Tests\Feature\User;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductExportSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchExports(string $queryString = ''): array
    {
        $url = '/cabinet/product-exports'.($queryString !== '' ? '?'.$queryString : '');
        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['exports']['data'] ?? [];
    }

    /**
     * @param  array<mixed>  $filters
     */
    private function makeExport(string $name, array $filters = [], bool $isActive = true, ?User $user = null): ProductExport
    {
        return ProductExport::create([
            'user_id' => ($user ?? $this->user)->id,
            'client_user_id' => ($user ?? $this->user)->id,
            'name' => $name,
            'format' => 'json',
            'filters' => $filters,
            'fields' => [['key' => 'id']],
            'is_active' => $isActive,
        ]);
    }

    #[Test]
    public function search_by_name(): void
    {
        $matching = $this->makeExport('Каталог Adidas');
        $other = $this->makeExport('Каталог Nike');

        $ids = array_column($this->fetchExports('search='.urlencode('Adidas')), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_brand_in_filters_text(): void
    {
        $brand = Brand::create(['name' => 'AdidasExport', 'slug' => 'adidas-export-'.Str::random(5)]);

        $matching = $this->makeExport('Без упоминания в названии', [
            'logic' => 'and',
            'conditions' => [
                ['type' => 'condition', 'field' => 'brand_id', 'operator' => 'in', 'value' => [$brand->id]],
            ],
        ]);

        $other = $this->makeExport('Совсем другой');

        $ids = array_column($this->fetchExports('search=AdidasExport'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function search_by_category_in_filters_text(): void
    {
        $category = Category::create(['name' => 'НовинкиОбуви', 'slug' => 'novinki-obuvi-'.Str::random(5)]);

        $matching = $this->makeExport('Каталог сезона', [
            'logic' => 'and',
            'conditions' => [
                ['type' => 'condition', 'field' => 'category_id', 'operator' => 'in', 'value' => [$category->id]],
            ],
        ]);

        $other = $this->makeExport('Без категории');

        $ids = array_column($this->fetchExports('search=НовинкиОбуви'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function filters_text_supports_flat_format(): void
    {
        $brand = Brand::create(['name' => 'ReebokFlat', 'slug' => 'reebok-flat-'.Str::random(5)]);

        $matching = $this->makeExport('Плоский формат', [
            ['field' => 'brand_id', 'operator' => 'in', 'value' => [$brand->id]],
        ]);

        $ids = array_column($this->fetchExports('search=ReebokFlat'), 'id');

        $this->assertContains($matching->id, $ids);
    }

    #[Test]
    public function filter_created_from_excludes_older(): void
    {
        $old = $this->makeExport('Старая');
        DB::table('product_exports')->where('id', $old->id)->update(['created_at' => '2026-01-01 10:00:00']);

        $fresh = $this->makeExport('Свежая');
        DB::table('product_exports')->where('id', $fresh->id)->update(['created_at' => '2026-04-20 10:00:00']);

        $ids = array_column($this->fetchExports('created_from=2026-04-01'), 'id');

        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    #[Test]
    public function filter_created_to_excludes_newer(): void
    {
        $old = $this->makeExport('Старая');
        DB::table('product_exports')->where('id', $old->id)->update(['created_at' => '2026-01-01 10:00:00']);

        $fresh = $this->makeExport('Свежая');
        DB::table('product_exports')->where('id', $fresh->id)->update(['created_at' => '2026-04-20 10:00:00']);

        $ids = array_column($this->fetchExports('created_to=2026-02-01'), 'id');

        $this->assertContains($old->id, $ids);
        $this->assertNotContains($fresh->id, $ids);
    }

    #[Test]
    public function filter_last_downloaded_from(): void
    {
        $never = $this->makeExport('Не скачивалась');

        $recent = $this->makeExport('Скачана недавно');
        DB::table('product_exports')->where('id', $recent->id)
            ->update(['last_downloaded_at' => '2026-04-25 10:00:00']);

        $ids = array_column($this->fetchExports('last_downloaded_from=2026-04-01'), 'id');

        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($never->id, $ids);
    }

    #[Test]
    public function filter_is_active_only_active(): void
    {
        $active = $this->makeExport('Активная', [], isActive: true);
        $inactive = $this->makeExport('Неактивная', [], isActive: false);

        $ids = array_column($this->fetchExports('is_active=1'), 'id');

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    #[Test]
    public function filter_is_active_only_inactive(): void
    {
        $active = $this->makeExport('Активная', [], isActive: true);
        $inactive = $this->makeExport('Неактивная', [], isActive: false);

        $ids = array_column($this->fetchExports('is_active=0'), 'id');

        $this->assertContains($inactive->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    #[Test]
    public function sort_name_asc(): void
    {
        $b = $this->makeExport('Бета');
        $a = $this->makeExport('Альфа');
        $g = $this->makeExport('Гамма');

        $ids = array_column($this->fetchExports('sort=name_asc'), 'id');

        $this->assertSame([$a->id, $b->id, $g->id], $ids);
    }

    #[Test]
    public function sort_created_asc(): void
    {
        $first = $this->makeExport('Первая');
        DB::table('product_exports')->where('id', $first->id)->update(['created_at' => '2026-01-01 10:00:00']);

        $second = $this->makeExport('Вторая');
        DB::table('product_exports')->where('id', $second->id)->update(['created_at' => '2026-04-20 10:00:00']);

        $ids = array_column($this->fetchExports('sort=created_asc'), 'id');

        $this->assertSame([$first->id, $second->id], $ids);
    }

    #[Test]
    public function sort_last_downloaded_desc_puts_nulls_last(): void
    {
        $never = $this->makeExport('Не скачивалась');

        $recent = $this->makeExport('Недавно');
        DB::table('product_exports')->where('id', $recent->id)
            ->update(['last_downloaded_at' => '2026-04-25 10:00:00']);

        $older = $this->makeExport('Давно');
        DB::table('product_exports')->where('id', $older->id)
            ->update(['last_downloaded_at' => '2026-01-01 10:00:00']);

        $ids = array_column($this->fetchExports('sort=last_downloaded_desc'), 'id');

        $position = array_flip($ids);
        $this->assertLessThan($position[$older->id], $position[$recent->id]);
        $this->assertLessThan($position[$never->id], $position[$older->id]);
    }

    #[Test]
    public function invalid_sort_falls_back_to_default(): void
    {
        $first = $this->makeExport('Первая');
        DB::table('product_exports')->where('id', $first->id)->update(['created_at' => '2026-01-01 10:00:00']);

        $second = $this->makeExport('Вторая');
        DB::table('product_exports')->where('id', $second->id)->update(['created_at' => '2026-04-20 10:00:00']);

        $ids = array_column($this->fetchExports('sort=garbage'), 'id');

        $this->assertSame([$second->id, $first->id], $ids);
    }

    #[Test]
    public function scope_excludes_other_users(): void
    {
        $mine = $this->makeExport('Моя');
        $other = User::factory()->create();
        $foreign = $this->makeExport('Чужая', [], user: $other);

        $ids = array_column($this->fetchExports(), 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function presets_excluded_from_listing(): void
    {
        $custom = $this->makeExport('Кастомная');

        $preset = ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => 'Пресет YML',
            'format' => 'xml',
            'preset' => 'yml',
            'filters' => [],
            'fields' => [['key' => 'id']],
            'is_active' => true,
        ]);

        $ids = array_column($this->fetchExports(), 'id');

        $this->assertContains($custom->id, $ids);
        $this->assertNotContains($preset->id, $ids);
    }

    #[Test]
    public function filters_text_updated_on_filters_change(): void
    {
        $brandA = Brand::create(['name' => 'BrandA', 'slug' => 'brand-a-'.Str::random(5)]);
        $brandB = Brand::create(['name' => 'BrandB', 'slug' => 'brand-b-'.Str::random(5)]);

        $export = $this->makeExport('Меняющаяся', [
            ['field' => 'brand_id', 'operator' => 'in', 'value' => [$brandA->id]],
        ]);

        $this->assertStringContainsString('BrandA', (string) $export->fresh()->filters_text);

        $export->update([
            'filters' => [
                ['field' => 'brand_id', 'operator' => 'in', 'value' => [$brandB->id]],
            ],
        ]);

        $fresh = $export->fresh();
        $this->assertStringContainsString('BrandB', (string) $fresh->filters_text);
        $this->assertStringNotContainsString('BrandA', (string) $fresh->filters_text);
    }
}
