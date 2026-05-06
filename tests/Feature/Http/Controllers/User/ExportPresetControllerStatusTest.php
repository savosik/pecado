<?php

namespace Tests\Feature\Http\Controllers\User;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportPresetControllerStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Product::factory()->count(2)->create();
    }

    public function test_status_returns_idle_when_no_export_exists(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/export-presets/yml/status');

        $response->assertOk();
        $response->assertJson([
            'status' => 'idle',
            'cached_at' => null,
            'download_url' => null,
            'last_run' => null,
        ]);
    }

    public function test_status_returns_404_for_unknown_preset(): void
    {
        $this->actingAs($this->user)
            ->getJson('/cabinet/export-presets/no-such-preset/status')
            ->assertNotFound();
    }

    public function test_status_returns_last_run_payload(): void
    {
        $export = ProductExport::create([
            'user_id' => $this->user->id,
            'client_user_id' => $this->user->id,
            'name' => 'YML',
            'format' => 'xml',
            'preset' => 'yml',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $run = ProductExportRun::create([
            'product_export_id' => $export->id,
            'status' => ProductExportRun::STATUS_READY,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'duration_ms' => 1234,
            'rows_count' => 12,
            'bytes' => 5000,
        ]);

        $export->update([
            'last_run_id' => $run->id,
            'status' => ProductExport::STATUS_READY,
            'cached_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/export-presets/yml/status');

        $response->assertOk();
        $response->assertJsonPath('status', 'ready');
        $response->assertJsonPath('last_run.duration_ms', 1234);
        $response->assertJsonPath('last_run.rows_count', 12);
        $response->assertJsonPath('last_run.bytes', 5000);
        $this->assertNotNull($response->json('cached_at'));
        $this->assertNotNull($response->json('download_url'));
    }

    public function test_status_does_not_leak_other_users_exports(): void
    {
        $other = User::factory()->create();
        ProductExport::create([
            'user_id' => $other->id,
            'client_user_id' => $other->id,
            'name' => 'Other YML',
            'format' => 'xml',
            'preset' => 'yml',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'status' => ProductExport::STATUS_READY,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/export-presets/yml/status');

        $response->assertOk();
        $response->assertJsonPath('status', 'idle');
    }
}
