<?php

namespace Tests\Feature\Models;

use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductExportRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_runs_table_and_extends_exports(): void
    {
        $this->assertTrue(Schema::hasTable('product_export_runs'));

        foreach (['product_export_id', 'status', 'started_at', 'finished_at', 'duration_ms', 'rows_count', 'bytes', 'error_message', 'error_count'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_export_runs', $column),
                "product_export_runs должна содержать колонку {$column}",
            );
        }

        $this->assertTrue(Schema::hasColumn('product_exports', 'status'));
        $this->assertTrue(Schema::hasColumn('product_exports', 'last_run_id'));
    }

    public function test_new_export_has_idle_status_by_default(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'idle',
            'format' => 'json',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $this->assertSame(ProductExport::STATUS_IDLE, $export->status);
        $this->assertNull($export->last_run_id);
    }

    public function test_runs_relation_returns_all_runs_in_creation_order(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'with-runs',
            'format' => 'json',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $first = ProductExportRun::create([
            'product_export_id' => $export->id,
            'status' => ProductExportRun::STATUS_READY,
            'duration_ms' => 1234,
            'rows_count' => 50,
        ]);
        $second = ProductExportRun::create([
            'product_export_id' => $export->id,
            'status' => ProductExportRun::STATUS_FAILED,
            'error_message' => 'boom',
        ]);

        $this->assertCount(2, $export->runs);
        $this->assertSame($first->id, $export->runs->first()->id);
        $this->assertSame(1234, $export->runs->first()->duration_ms);
        $this->assertSame('boom', $second->error_message);
    }

    public function test_last_run_relation_resolves_via_last_run_id(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'last-run',
            'format' => 'json',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $run = ProductExportRun::create([
            'product_export_id' => $export->id,
            'status' => ProductExportRun::STATUS_READY,
            'rows_count' => 10,
        ]);

        $export->update([
            'last_run_id' => $run->id,
            'status' => ProductExport::STATUS_READY,
        ]);

        $export->refresh()->load('lastRun');
        $this->assertNotNull($export->lastRun);
        $this->assertSame($run->id, $export->lastRun->id);
        $this->assertSame(10, $export->lastRun->rows_count);
    }

    public function test_runs_are_deleted_with_export(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'cascade',
            'format' => 'json',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        ProductExportRun::create([
            'product_export_id' => $export->id,
            'status' => ProductExportRun::STATUS_READY,
        ]);

        $exportId = $export->id;
        $export->delete();

        $this->assertDatabaseMissing('product_export_runs', ['product_export_id' => $exportId]);
    }
}
