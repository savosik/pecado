<?php

namespace Tests\Feature\Console;

use App\Enums\BrandCategory;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportSeoMetaTest extends TestCase
{
    use RefreshDatabase;

    private function makeCsv(array $rows): string
    {
        $header = 'url;статус;волна;целевой_ключ;частота_фраз;Title;Description;Keywords;H1';
        $lines = [$header];
        foreach ($rows as $r) {
            $lines[] = implode(';', $r);
        }

        $path = tempnam(sys_get_temp_dir(), 'seo_meta_').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    #[Test]
    public function imports_meta_for_categories_and_brands_by_slug(): void
    {
        $cat = Category::create(['name' => 'Вибраторы', 'slug' => 'vibratory']);
        $brand = Brand::create(['name' => 'Lelo', 'slug' => 'lelo', 'category' => BrandCategory::Other->value]);

        $csv = $this->makeCsv([
            ['/categories/vibratory', 'существует', '1', 'купить вибратор', '1809', 'Вибраторы | Pecado', 'Описание вибраторов', 'вибратор, купить вибратор', 'Вибраторы'],
            ['/brands/lelo', 'существует', '1', 'lelo', '500', 'Lelo | Pecado', 'Описание Lelo', 'lelo, лело', 'Lelo'],
        ]);

        $this->artisan('seo:import-meta', ['--file' => $csv])->assertSuccessful();

        $cat->refresh();
        $brand->refresh();

        $this->assertSame('Вибраторы | Pecado', $cat->meta_title);
        $this->assertSame('Описание вибраторов', $cat->meta_description);
        $this->assertSame('вибратор, купить вибратор', $cat->meta_keywords);

        $this->assertSame('Lelo | Pecado', $brand->meta_title);
        $this->assertSame('lelo, лело', $brand->meta_keywords);

        @unlink($csv);
    }

    #[Test]
    public function dry_run_does_not_write_to_database(): void
    {
        $cat = Category::create(['name' => 'Вибраторы', 'slug' => 'vibratory']);

        $csv = $this->makeCsv([
            ['/categories/vibratory', 'существует', '1', 'ключ', '1', 'Новый title', 'Новое описание', 'ключи', 'Вибраторы'],
        ]);

        $this->artisan('seo:import-meta', ['--file' => $csv, '--dry-run' => true])->assertSuccessful();

        $cat->refresh();
        $this->assertNull($cat->meta_title);
        $this->assertNull($cat->meta_keywords);

        @unlink($csv);
    }

    #[Test]
    public function wave_filter_only_touches_matching_rows(): void
    {
        $w1 = Category::create(['name' => 'Волна 1', 'slug' => 'wave-one']);
        $w2 = Category::create(['name' => 'Волна 2', 'slug' => 'wave-two']);

        $csv = $this->makeCsv([
            ['/categories/wave-one', 'существует', '1', 'k', '1', 'Title W1', 'Desc', 'kw1', 'Волна 1'],
            ['/categories/wave-two', 'существует', '2', 'k', '1', 'Title W2', 'Desc', 'kw2', 'Волна 2'],
        ]);

        $this->artisan('seo:import-meta', ['--file' => $csv, '--wave' => '1'])->assertSuccessful();

        $this->assertSame('Title W1', $w1->refresh()->meta_title);
        $this->assertNull($w2->refresh()->meta_title);

        @unlink($csv);
    }

    #[Test]
    public function second_run_is_idempotent(): void
    {
        $cat = Category::create(['name' => 'Вибраторы', 'slug' => 'vibratory']);

        $csv = $this->makeCsv([
            ['/categories/vibratory', 'существует', '1', 'k', '1', 'Title', 'Desc', 'kw', 'Вибраторы'],
        ]);

        $this->artisan('seo:import-meta', ['--file' => $csv])->assertSuccessful();
        $firstUpdatedAt = $cat->refresh()->updated_at;

        $this->artisan('seo:import-meta', ['--file' => $csv])
            ->expectsOutputToContain('Обновлено: 0')
            ->assertSuccessful();

        $this->assertEquals($firstUpdatedAt, $cat->refresh()->updated_at);

        @unlink($csv);
    }

    #[Test]
    public function missing_slug_is_reported_and_main_page_skipped(): void
    {
        $csv = $this->makeCsv([
            ['/', 'существует', '3', 'секс шоп', '25592', 'Главная', 'Описание', 'kw', 'Pecado'],
            ['/categories/nesushchestvuet', 'существует', '1', 'k', '1', 'Title', 'Desc', 'kw', 'Нет такой'],
        ]);

        $this->artisan('seo:import-meta', ['--file' => $csv])
            ->expectsOutputToContain('Не найдено по slug: 1')
            ->assertSuccessful();

        @unlink($csv);
    }
}
