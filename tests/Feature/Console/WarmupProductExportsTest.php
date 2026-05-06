<?php

namespace Tests\Feature\Console;

use App\Jobs\GenerateProductExportJob;
use App\Models\ProductExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmupProductExportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makeExport(array $overrides = []): ProductExport
    {
        return ProductExport::create(array_merge([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => 'warm test',
            'format' => 'json',
            'preset' => 'json_catalog',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'last_downloaded_at' => now()->subDay(),
            'status' => ProductExport::STATUS_READY,
        ], $overrides));
    }

    public function test_dispatches_job_for_recent_active_preset_export(): void
    {
        $export = $this->makeExport([
            'last_downloaded_at' => now()->subDay(),
        ]);

        Queue::fake();

        $this->artisan('exports:warm')->assertExitCode(0);

        Queue::assertPushed(GenerateProductExportJob::class, function ($job) use ($export) {
            return $job->productExportId === $export->id;
        });
    }

    public function test_skips_inactive_exports(): void
    {
        $this->makeExport(['is_active' => false]);

        Queue::fake();

        $this->artisan('exports:warm')->assertExitCode(0);

        Queue::assertNotPushed(GenerateProductExportJob::class);
    }

    public function test_skips_custom_exports_without_preset(): void
    {
        $this->makeExport(['preset' => null]);

        Queue::fake();

        $this->artisan('exports:warm')->assertExitCode(0);

        Queue::assertNotPushed(GenerateProductExportJob::class);
    }

    public function test_skips_exports_not_downloaded_within_threshold(): void
    {
        $this->makeExport(['last_downloaded_at' => now()->subDays(30)]);

        Queue::fake();

        $this->artisan('exports:warm', ['--days' => 7])->assertExitCode(0);

        Queue::assertNotPushed(GenerateProductExportJob::class);
    }

    public function test_skips_already_generating_or_queued_exports(): void
    {
        $this->makeExport(['status' => ProductExport::STATUS_QUEUED]);
        $this->makeExport(['status' => ProductExport::STATUS_GENERATING]);

        Queue::fake();

        $this->artisan('exports:warm')->assertExitCode(0);

        Queue::assertNotPushed(GenerateProductExportJob::class);
    }

    public function test_respects_limit_option(): void
    {
        $this->makeExport(['name' => 'a', 'last_downloaded_at' => now()->subHour(), 'preset' => 'yml']);
        $this->makeExport(['name' => 'b', 'last_downloaded_at' => now()->subDay(), 'preset' => 'shopify']);
        $this->makeExport(['name' => 'c', 'last_downloaded_at' => now()->subDays(2), 'preset' => 'tilda']);

        Queue::fake();

        $this->artisan('exports:warm', ['--limit' => 2])->assertExitCode(0);

        Queue::assertPushed(GenerateProductExportJob::class, 2);
    }

    public function test_processes_failed_exports_too(): void
    {
        $this->makeExport(['status' => ProductExport::STATUS_FAILED]);

        Queue::fake();

        $this->artisan('exports:warm')->assertExitCode(0);

        Queue::assertPushed(GenerateProductExportJob::class);
    }
}
