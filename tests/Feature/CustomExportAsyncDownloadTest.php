<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\ProductExportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Кастомные выгрузки (preset = null) теперь проходят через тот же
 * GenerateProductExportJob + storage/app/exports/{hash} что и пресетные.
 * Проверяет, что:
 *  - download без кэша диспатчит job и отдаёт 202,
 *  - сгенерированный файл попадает в кэш и отдаётся при следующем запросе,
 *  - кастомные поля + лейблы из fields[] корректно пишутся в CSV.
 */
class CustomExportAsyncDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->client = User::factory()->create();
    }

    public function test_download_dispatches_job_when_no_cache(): void
    {
        Queue::fake();

        $product = Product::factory()->create(['name' => 'Тест-товар', 'sku' => 'SKU-1']);

        $export = ProductExport::create([
            'user_id' => $this->admin->id,
            'client_user_id' => $this->client->id,
            'name' => 'Test export',
            'format' => 'csv',
            'preset' => null,
            'is_active' => true,
            'filters' => [],
            'fields' => [
                ['key' => 'name', 'label' => 'title'],
                ['key' => 'sku', 'label' => 'article'],
            ],
        ]);

        $response = $this->get("/export/{$export->hash}");

        // Без кэша и без отработанного job — 202 + ставится в очередь.
        $response->assertStatus(202);
        Queue::assertPushed(\App\Jobs\GenerateProductExportJob::class);
    }

    public function test_generator_writes_csv_with_custom_labels_to_cache(): void
    {
        $product1 = Product::factory()->create(['name' => 'Первый', 'sku' => 'A-1']);
        $product2 = Product::factory()->create(['name' => 'Второй', 'sku' => 'B-2']);

        $export = ProductExport::create([
            'user_id' => $this->admin->id,
            'client_user_id' => $this->client->id,
            'name' => 'Test custom',
            'format' => 'csv',
            'preset' => null,
            'is_active' => true,
            'filters' => [],
            'fields' => [
                ['key' => 'name', 'label' => 'title'],
                ['key' => 'sku', 'label' => 'article'],
                ['key' => 'placeholder.brand_code', 'label' => 'brand_code'],
                ['key' => 'is_new', 'label' => 'new', 'modifiers' => ['true_value' => '1', 'false_value' => '']],
            ],
        ]);

        $generator = app(ProductExportGenerator::class);
        $run = $generator->generate($export);

        $this->assertSame('ready', $run->status);
        $this->assertSame(2, $run->rows_count);

        $filePath = $export->getCacheFilePath();
        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        // BOM есть
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        // Заголовок с кастомными лейблами
        $this->assertStringContainsString('title;article;brand_code;new', $content);
        // Данные
        $this->assertStringContainsString('Первый;A-1;;', $content);
        $this->assertStringContainsString('Второй;B-2;;', $content);
    }

    public function test_download_serves_cached_file_when_fresh(): void
    {
        $product = Product::factory()->create(['name' => 'Кэш-тест', 'sku' => 'C-1']);

        $export = ProductExport::create([
            'user_id' => $this->admin->id,
            'client_user_id' => $this->client->id,
            'name' => 'Cached',
            'format' => 'csv',
            'preset' => null,
            'is_active' => true,
            'filters' => [],
            'fields' => [['key' => 'name', 'label' => 'name']],
        ]);

        // Прогреваем кэш
        app(ProductExportGenerator::class)->generate($export);

        $response = $this->get("/export/{$export->hash}");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $expectedFilename = 'export_cached_'.now()->format('Y-m-d').'.csv';
        $this->assertStringContainsString($expectedFilename, (string) $response->headers->get('Content-Disposition'));

        // download() возвращает BinaryFileResponse — читаем файл напрямую.
        $content = file_get_contents($export->getCacheFilePath());
        $this->assertStringContainsString('Кэш-тест', $content);
    }
}
