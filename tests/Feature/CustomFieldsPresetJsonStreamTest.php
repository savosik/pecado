<?php

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\Presets\CustomFieldsPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на потоковую запись JSON в CustomFieldsPreset.
 *
 * Раньше writeJson() копил весь массив в памяти и делал один fwrite() в конце,
 * что на каталоге ~5к товаров × 250 полей упирало пик в 260+ МБ (рядом с
 * --memory=256 у worker'а). Здесь проверяем что результат валиден,
 * содержит все товары, и память не растёт линейно с количеством строк.
 */
class CustomFieldsPresetJsonStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_streamed_json_is_valid_and_contains_all_products(): void
    {
        $user = User::factory()->create();
        Product::factory()->count(50)->create();

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Stream JSON test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'id', 'label' => 'id'],
                ['key' => 'name', 'label' => 'name'],
                ['key' => 'sku', 'label' => 'sku'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, 'Stream output is not valid JSON: '.json_last_error_msg());
        $this->assertIsArray($decoded);
        $this->assertCount(50, $decoded);

        foreach ($decoded as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('sku', $row);
        }
    }

    public function test_streamed_json_uses_custom_label_as_key(): void
    {
        $user = User::factory()->create();
        Product::factory()->create(['name' => 'Test Product', 'sku' => 'TST-1']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Label as key test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'name', 'label' => 'product_title'],
                ['key' => 'sku', 'label' => 'article'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertCount(1, $decoded);

        $row = $decoded[0];
        $this->assertArrayHasKey('product_title', $row, 'JSON-ключ должен быть label, а не технический key');
        $this->assertArrayHasKey('article', $row);
        $this->assertArrayNotHasKey('name', $row);
        $this->assertArrayNotHasKey('sku', $row);
        $this->assertSame('Test Product', $row['product_title']);
        $this->assertSame('TST-1', $row['article']);
    }

    public function test_streamed_json_handles_empty_dataset(): void
    {
        $user = User::factory()->create();

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Empty stream test',
            'format' => ExportFormat::JSON,
            'fields' => [['key' => 'id', 'label' => 'id']],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $this->assertSame([], $decoded, 'Пустой JSON должен парситься как []');
    }

    public function test_streamed_json_memory_does_not_scale_with_rows(): void
    {
        $user = User::factory()->create();
        Product::factory()->count(500)->create();

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Memory bound test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'id', 'label' => 'id'],
                ['key' => 'name', 'label' => 'name'],
                ['key' => 'description', 'label' => 'description'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        gc_collect_cycles();
        $before = memory_get_usage();

        $stream = fopen('php://memory', 'w+');
        $peak = memory_get_peak_usage();
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        $peakAfter = memory_get_peak_usage();
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $delta = $peakAfter - $peak;
        $decoded = json_decode($content, true);
        $this->assertCount(500, $decoded);

        // Sanity check: пик должен быть ниже 50 МБ для 500 товаров×3 поля.
        // Если writeJson() снова начнёт копить весь массив — пик легко
        // улетит за пределы chunk_size×per_row_bytes.
        $this->assertLessThan(50 * 1024 * 1024, $delta, "Память подскочила на ".round($delta / 1024 / 1024, 1)." МБ — JSON может снова копить весь массив");
    }
}
