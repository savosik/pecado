<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\GenerateProductExportJob;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductExportDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected ProductExport $export;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Product::factory()->count(2)->create();

        $this->export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'download test',
            'format' => 'json',
            'preset' => 'json_catalog',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $path = $this->export->getCacheFilePath();
        if (file_exists($path)) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_fresh_cache_is_served_without_dispatching_job(): void
    {
        // Имитируем уже сгенерированный свежий кэш.
        $cacheDir = dirname($this->export->getCacheFilePath());
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($this->export->getCacheFilePath(), '{"products":[]}');
        $this->export->update([
            'cached_at' => now(),
            'status' => ProductExport::STATUS_READY,
        ]);

        Queue::fake();

        $response = $this->get("/export/{$this->export->hash}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json; charset=utf-8');
        $response->assertHeaderMissing('X-Export-Stale');

        Queue::assertNothingPushed();
    }

    public function test_missing_cache_with_async_queue_dispatches_job_and_returns_202(): void
    {
        Queue::fake();

        $response = $this->get("/export/{$this->export->hash}");

        $response->assertStatus(202);
        $response->assertJsonStructure(['status', 'message', 'run']);

        Queue::assertPushed(GenerateProductExportJob::class, function ($job) {
            return $job->productExportId === $this->export->id;
        });
    }

    public function test_stale_cache_is_served_with_header_when_async_regeneration_pending(): void
    {
        // Кэш существует, но устарел (cached_at старше 10 мин).
        $cacheDir = dirname($this->export->getCacheFilePath());
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($this->export->getCacheFilePath(), '{"products":[],"stale":true}');
        $this->export->update([
            'cached_at' => now()->subHour(),
            'status' => ProductExport::STATUS_READY,
        ]);

        // Queue::fake() не даёт job-у выполниться → файл останется stale,
        // и контроллер должен отдать его с заголовком X-Export-Stale.
        Queue::fake();

        $response = $this->get("/export/{$this->export->hash}");

        $response->assertOk();
        $response->assertHeader('X-Export-Stale', '1');

        Queue::assertPushed(GenerateProductExportJob::class);
    }

    public function test_inactive_export_returns_404(): void
    {
        $this->export->update(['is_active' => false]);

        $this->get("/export/{$this->export->hash}")->assertNotFound();
    }

    public function test_pending_response_includes_last_run_info(): void
    {
        // Был один прошлый запуск со статусом failed.
        $run = ProductExportRun::create([
            'product_export_id' => $this->export->id,
            'status' => ProductExportRun::STATUS_FAILED,
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'error_message' => 'предыдущая попытка упала',
        ]);
        $this->export->update([
            'last_run_id' => $run->id,
            'status' => ProductExport::STATUS_FAILED,
        ]);

        Queue::fake();

        $response = $this->get("/export/{$this->export->hash}");

        $response->assertStatus(202);
        $response->assertJsonPath('run.id', $run->id);
        $response->assertJsonPath('run.status', ProductExportRun::STATUS_FAILED);
    }
}
