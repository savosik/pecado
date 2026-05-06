<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateProductExportJob;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Models\User;
use App\Services\ProductExport\ProductExportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GenerateProductExportJobTest extends TestCase
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
            'name' => 'Job test',
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

    public function test_job_generates_file_and_marks_run_ready(): void
    {
        (new GenerateProductExportJob($this->export->id))
            ->handle(app(ProductExportGenerator::class));

        $this->export->refresh();

        $this->assertSame(ProductExport::STATUS_READY, $this->export->status);
        $this->assertNotNull($this->export->cached_at);
        $this->assertNotNull($this->export->last_run_id);

        $run = ProductExportRun::find($this->export->last_run_id);
        $this->assertSame(ProductExportRun::STATUS_READY, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertGreaterThan(0, $run->bytes);
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);

        $this->assertFileExists($this->export->getCacheFilePath());
    }

    public function test_failed_job_marks_run_and_export_failed(): void
    {
        $generator = Mockery::mock(ProductExportGenerator::class);
        $generator->shouldReceive('generate')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(ProductExportGenerator::class, $generator);

        $job = new GenerateProductExportJob($this->export->id);

        try {
            $job->handle($generator);
            $this->fail('Ожидалось исключение');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $job->failed(new \RuntimeException('boom'));

        $this->export->refresh();
        $this->assertSame(ProductExport::STATUS_FAILED, $this->export->status);
    }

    public function test_job_with_missing_export_does_not_throw(): void
    {
        $job = new GenerateProductExportJob(99999999);

        // Не должно бросить
        $job->handle(app(ProductExportGenerator::class));

        $this->expectNotToPerformAssertions();
    }

    public function test_job_unique_id_is_per_export(): void
    {
        $a = new GenerateProductExportJob(101);
        $b = new GenerateProductExportJob(101);
        $c = new GenerateProductExportJob(202);

        $this->assertSame($a->uniqueId(), $b->uniqueId());
        $this->assertNotSame($a->uniqueId(), $c->uniqueId());
        $this->assertSame('product-export:101', $a->uniqueId());
    }

    public function test_job_uses_exports_queue(): void
    {
        Queue::fake();

        GenerateProductExportJob::dispatch($this->export->id);

        Queue::assertPushedOn('exports', GenerateProductExportJob::class);
    }
}
