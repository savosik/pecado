<?php

namespace Tests\Feature\Console;

use App\Enums\BrandCategory;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportSeoTextsTest extends TestCase
{
    use RefreshDatabase;

    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir().'/seo_texts_'.uniqid();
        mkdir($dir);
        foreach ($files as $name => $content) {
            file_put_contents("{$dir}/{$name}", $content);
        }

        return $dir;
    }

    #[Test]
    public function imports_html_into_category_description_and_brand_short_description(): void
    {
        $cat = Category::create(['name' => 'Вибраторы', 'slug' => 'vibratory-t', 'is_active' => true]);
        $brand = Brand::create(['name' => 'Tenga', 'slug' => 'tenga-t', 'category' => BrandCategory::Other->value]);

        $dir = $this->makeDir([
            'vibratory-t.html' => '<p>Текст про вибраторы.</p>',
            'tenga-t.html' => '<p>Про бренд Tenga.</p>',
        ]);

        $this->artisan('seo:import-texts', ['--dir' => $dir])->assertSuccessful();

        $this->assertSame('<p>Текст про вибраторы.</p>', $cat->refresh()->description);
        $this->assertSame('<p>Про бренд Tenga.</p>', $brand->refresh()->short_description);
    }

    #[Test]
    public function dry_run_does_not_write(): void
    {
        $cat = Category::create(['name' => 'Кат', 'slug' => 'cat-dry', 'is_active' => true]);

        $dir = $this->makeDir(['cat-dry.html' => '<p>Новый текст.</p>']);

        $this->artisan('seo:import-texts', ['--dir' => $dir, '--dry-run' => true])->assertSuccessful();

        $this->assertNull($cat->refresh()->description);
    }

    #[Test]
    public function second_run_is_idempotent(): void
    {
        Category::create(['name' => 'Кат', 'slug' => 'cat-idem', 'is_active' => true]);
        $dir = $this->makeDir(['cat-idem.html' => '<p>Текст.</p>']);

        $this->artisan('seo:import-texts', ['--dir' => $dir])->assertSuccessful();
        $this->artisan('seo:import-texts', ['--dir' => $dir])
            ->expectsOutputToContain('Обновлено: 0')
            ->assertSuccessful();
    }

    #[Test]
    public function reports_unknown_slug(): void
    {
        $dir = $this->makeDir(['nesushchestvuet.html' => '<p>Текст.</p>']);

        $this->artisan('seo:import-texts', ['--dir' => $dir])
            ->expectsOutputToContain('Не найдено по slug: 1')
            ->assertSuccessful();
    }
}
