<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Импорт физических габаритов товара из партнёрского CSV в products.
 * Sex-opt-формат: width_packed/height_packed/length_packed в см,
 * weight_packed в кг. Размеры конвертируются в метры (наш формат хранения).
 */
class EnrichProductDimsFromPartnerCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_dims_for_empty_products(): void
    {
        $p = Product::factory()->create([
            'code' => 'DIM-001',
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'weight_gross' => 0,
        ]);

        $csv = tempnam(sys_get_temp_dir(), 'dims_').'.csv';
        file_put_contents(
            $csv,
            "code;width_packed;height_packed;length_packed;weight_packed\n".
            "DIM-001;10;20;30;1.5\n"
        );

        Artisan::call('partner-export:enrich-product-dims', ['path' => $csv]);
        unlink($csv);

        $p->refresh();
        // 10 см → 0.1 м, 20 см → 0.2 м, 30 см → 0.3 м, вес 1.5 кг как есть
        $this->assertSame(0.1, (float) $p->width);
        $this->assertSame(0.2, (float) $p->height);
        $this->assertSame(0.3, (float) $p->depth);
        $this->assertSame(1.5, (float) $p->weight_gross);
    }

    public function test_does_not_overwrite_non_empty_without_force(): void
    {
        $p = Product::factory()->create([
            'code' => 'DIM-002',
            'width' => 0.5,   // уже заполнено
            'height' => 0,
            'depth' => 0,
            'weight_gross' => 0,
        ]);

        $csv = tempnam(sys_get_temp_dir(), 'dims_').'.csv';
        file_put_contents(
            $csv,
            "code;width_packed;height_packed;length_packed;weight_packed\n".
            "DIM-002;10;20;30;1.5\n"
        );

        Artisan::call('partner-export:enrich-product-dims', ['path' => $csv]);
        unlink($csv);

        $p->refresh();
        // width НЕ изменился (был 0.5), остальное обновилось
        $this->assertSame(0.5, (float) $p->width);
        $this->assertSame(0.2, (float) $p->height);
        $this->assertSame(0.3, (float) $p->depth);
        $this->assertSame(1.5, (float) $p->weight_gross);
    }

    public function test_force_overwrites_non_empty(): void
    {
        $p = Product::factory()->create([
            'code' => 'DIM-003',
            'width' => 0.5,
            'height' => 0.5,
            'depth' => 0.5,
            'weight_gross' => 5.0,
        ]);

        $csv = tempnam(sys_get_temp_dir(), 'dims_').'.csv';
        file_put_contents(
            $csv,
            "code;width_packed;height_packed;length_packed;weight_packed\n".
            "DIM-003;10;20;30;1.5\n"
        );

        Artisan::call('partner-export:enrich-product-dims', ['path' => $csv, '--force' => true]);
        unlink($csv);

        $p->refresh();
        $this->assertSame(0.1, (float) $p->width);
        $this->assertSame(0.2, (float) $p->height);
        $this->assertSame(0.3, (float) $p->depth);
        $this->assertSame(1.5, (float) $p->weight_gross);
    }

    public function test_skips_zero_or_empty_values_in_csv(): void
    {
        $p = Product::factory()->create([
            'code' => 'DIM-004',
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'weight_gross' => 0,
        ]);

        $csv = tempnam(sys_get_temp_dir(), 'dims_').'.csv';
        file_put_contents(
            $csv,
            "code;width_packed;height_packed;length_packed;weight_packed\n".
            "DIM-004;0;;30;\n"
        );

        Artisan::call('partner-export:enrich-product-dims', ['path' => $csv]);
        unlink($csv);

        $p->refresh();
        // 0 и пустые не пишутся, только length_packed=30 → depth=0.3
        $this->assertSame(0.0, (float) $p->width);
        $this->assertSame(0.0, (float) $p->height);
        $this->assertSame(0.3, (float) $p->depth);
        $this->assertSame(0.0, (float) $p->weight_gross);
    }

    public function test_dry_run_does_not_modify_db(): void
    {
        $p = Product::factory()->create(['code' => 'DIM-005', 'width' => 0]);

        $csv = tempnam(sys_get_temp_dir(), 'dims_').'.csv';
        file_put_contents(
            $csv,
            "code;width_packed;height_packed;length_packed;weight_packed\n".
            "DIM-005;10;20;30;1.5\n"
        );

        Artisan::call('partner-export:enrich-product-dims', ['path' => $csv, '--dry-run' => true]);
        unlink($csv);

        $p->refresh();
        $this->assertSame(0.0, (float) $p->width);
    }
}
