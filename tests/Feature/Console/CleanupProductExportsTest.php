<?php

namespace Tests\Feature\Console;

use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Покрывает exports:cleanup — критично, потому что команда удаляет файлы;
 * регрессия в селекторе orphan/stale могла бы снести валидный кеш.
 */
class CleanupProductExportsTest extends TestCase
{
    use RefreshDatabase;

    protected string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = storage_path('app/exports');
        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Чистим только тестовые файлы — каталог общий с дев-окружением.
        foreach (glob($this->cacheDir.'/__test_*') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/__test_*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_removes_orphaned_files_but_keeps_known_ones(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'keep',
            'format' => 'json',
            'preset' => null,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'last_downloaded_at' => now(),
        ]);

        $validFile = "{$this->cacheDir}/__test_{$export->hash}";
        $orphanFile = "{$this->cacheDir}/__test_orphan_hash_12345";
        $orphanGz = "{$this->cacheDir}/__test_orphan_hash_67890.gz";

        file_put_contents($validFile, 'valid content');
        file_put_contents($orphanFile, 'orphan content');
        file_put_contents($orphanGz, gzencode('orphan gzipped'));

        // Подменяем валидный hash на наш __test_ префикс не получится — поэтому
        // делаем по-другому: создаём файл с реальным hash, и используем его.
        @unlink($validFile);
        $validFile = "{$this->cacheDir}/{$export->hash}";
        file_put_contents($validFile, 'valid content');

        $this->artisan('exports:cleanup')->assertSuccessful();

        $this->assertFileExists($validFile, 'Валидный файл должен остаться');
        $this->assertFileDoesNotExist($orphanFile, 'Orphan должен быть удалён');
        $this->assertFileDoesNotExist($orphanGz, 'Orphan .gz должен быть удалён');

        @unlink($validFile);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $orphanFile = "{$this->cacheDir}/__test_dryrun_orphan_99999";
        file_put_contents($orphanFile, 'should survive dry run');

        $this->artisan('exports:cleanup --dry-run')->assertSuccessful();

        $this->assertFileExists($orphanFile, 'Dry-run не должен ничего удалять');
    }

    public function test_removes_stale_files_by_last_downloaded_threshold(): void
    {
        $user = User::factory()->create();
        $staleExport = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'stale',
            'format' => 'csv',
            'preset' => null,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'last_downloaded_at' => now()->subDays(120), // старше дефолтного порога 90
        ]);

        $staleFile = "{$this->cacheDir}/{$staleExport->hash}";
        file_put_contents($staleFile, 'stale');

        $this->artisan('exports:cleanup')->assertSuccessful();

        $this->assertFileDoesNotExist($staleFile, 'Файл выгрузки, не скачивавшейся 120 дней, должен быть удалён');
    }

    public function test_recent_tmp_files_are_kept(): void
    {
        // Свежий tmp — другой воркер прямо сейчас в середине генерации.
        // Cleanup не должен мешать.
        $tmpFile = "{$this->cacheDir}/__test_recent_hash.tmp.12345";
        file_put_contents($tmpFile, 'tmp from running worker');

        $this->artisan('exports:cleanup')->assertSuccessful();

        $this->assertFileExists($tmpFile, 'Свежий tmp-файл не должен удаляться');

        @unlink($tmpFile);
    }

    public function test_old_tmp_files_are_removed(): void
    {
        // Tmp-файл от убитого воркера (SIGKILL, OOM, supervisor stop) —
        // Generator не успел его удалить через finally. Без явной чистки
        // такой файл копится навсегда.
        $tmpFile = "{$this->cacheDir}/__test_old_hash.tmp.99999";
        file_put_contents($tmpFile, 'tmp from killed worker');
        // Сдвигаем mtime на 5 часов назад — больше дефолтного --tmp-hours=2.
        touch($tmpFile, time() - 5 * 3600);

        $this->artisan('exports:cleanup')->assertSuccessful();

        $this->assertFileDoesNotExist($tmpFile, 'tmp старше порога должен удаляться');
    }

    public function test_trims_run_history_keeping_latest_n(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'runs-retention',
            'format' => 'csv',
            'preset' => null,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'last_downloaded_at' => now(),
        ]);

        // Создаём 10 run-ов, ID нарастают.
        for ($i = 0; $i < 10; $i++) {
            ProductExportRun::create([
                'product_export_id' => $export->id,
                'status' => 'ready',
                'started_at' => now()->subMinutes(10 - $i),
                'finished_at' => now()->subMinutes(10 - $i)->addSecond(),
                'duration_ms' => 100 + $i,
            ]);
        }

        $this->assertSame(10, ProductExportRun::where('product_export_id', $export->id)->count());

        // Оставляем последние 3 — должны удалиться первые 7.
        $this->artisan('exports:cleanup --keep-runs-per-export=3')->assertSuccessful();

        $left = ProductExportRun::where('product_export_id', $export->id)
            ->orderBy('id')
            ->pluck('duration_ms')
            ->all();
        $this->assertCount(3, $left, 'Должны остаться ровно 3 последних run-а');
        // Удаляются самые старые (меньший id) — у новых duration_ms = 107, 108, 109.
        $this->assertSame([107, 108, 109], $left);
    }

    public function test_keep_runs_zero_disables_retention(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'no-retention',
            'format' => 'csv',
            'preset' => null,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'last_downloaded_at' => now(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            ProductExportRun::create([
                'product_export_id' => $export->id,
                'status' => 'ready',
            ]);
        }

        $this->artisan('exports:cleanup --keep-runs-per-export=0')->assertSuccessful();

        $this->assertSame(5, ProductExportRun::where('product_export_id', $export->id)->count());
    }
}
