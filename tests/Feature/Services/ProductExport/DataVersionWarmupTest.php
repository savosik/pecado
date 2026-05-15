<?php

namespace Tests\Feature\Services\ProductExport;

use App\Jobs\GenerateProductExportJob;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\ProductExportDataVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Покрывает event-driven инвалидацию из PR 4:
 *   - ProductExportDataVersion::bump меняет current
 *   - hasFreshCache учитывает data_version_at
 *   - exports:warm пропускает выгрузки, уже отражающие текущую версию данных
 */
class DataVersionWarmupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Свежий cache для каждого теста — иначе Product::saved bump из
        // setUp подгрязнит ожидания. cache:clear фасад вместо ::forget,
        // чтобы не зависеть от конкретного ключа.
        Cache::flush();
    }

    public function test_bump_advances_current_version(): void
    {
        $svc = app(ProductExportDataVersion::class);

        $before = $svc->current();
        $this->assertSame(0, $before->getTimestamp(), 'Без bump current = epoch 0');

        $svc->bump();
        $after = $svc->current();
        $this->assertGreaterThan(0, $after->getTimestamp(), 'После bump current — валидный timestamp');
    }

    public function test_has_fresh_cache_invalidates_when_data_version_advances(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'fresh-check',
            'format' => 'json',
            'preset' => null,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        // Имитируем успешную генерацию: файл, cached_at, data_version_at = now.
        $path = $export->getCacheFilePath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, '[]');
        $export->update([
            'cached_at' => now(),
            'data_version_at' => now(),
        ]);

        // Кеш свежий до bump'а.
        $this->assertTrue($export->fresh()->hasFreshCache());

        // bump через секунду — data_version станет позже data_version_at.
        sleep(1);
        app(ProductExportDataVersion::class)->bump();

        $this->assertFalse(
            $export->fresh()->hasFreshCache(),
            'После bump кеш должен считаться устаревшим',
        );

        @unlink($path);
    }

    public function test_pre_pr4_caches_with_null_data_version_remain_valid(): void
    {
        $user = User::factory()->create();
        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'legacy',
            'format' => 'json',
            'preset' => null,
            'filters' => [],
            'fields' => [],
            'is_active' => true,
        ]);

        $path = $export->getCacheFilePath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, '[]');
        $export->update(['cached_at' => now(), 'data_version_at' => null]);

        // bump существует — но pre-PR4 кеш не должен сбрасываться, иначе при
        // прод-деплое все существующие кеши разом улетят на регенерацию.
        app(ProductExportDataVersion::class)->bump();

        $this->assertTrue(
            $export->fresh()->hasFreshCache(),
            'Кеш с data_version_at = null должен оставаться валидным до естественной перегенерации',
        );

        @unlink($path);
    }

    public function test_warmup_skips_exports_already_at_current_version(): void
    {
        $user = User::factory()->create();

        // Свежая по версии — не должна попасть в warmup.
        $upToDate = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'up-to-date',
            'format' => 'json',
            'preset' => 'json_catalog',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'last_downloaded_at' => now()->subHour(),
            'data_version_at' => now()->addSecond(),
        ]);

        // Устаревшая — нужна перегенерация.
        $stale = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'stale',
            'format' => 'json',
            'preset' => 'json_catalog',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'last_downloaded_at' => now()->subHour(),
            'data_version_at' => now()->subDay(),
        ]);

        // Bump после создания up-to-date, чтобы её data_version_at был ≥ current.
        app(ProductExportDataVersion::class)->bump();
        // up-to-date уже имеет data_version_at = now+1сек > current → останется свежей.

        Queue::fake();
        $this->artisan('exports:warm --days=30')->assertSuccessful();

        Queue::assertPushed(GenerateProductExportJob::class, function ($job) use ($stale) {
            return $job->productExportId === $stale->id;
        });
        Queue::assertNotPushed(GenerateProductExportJob::class, function ($job) use ($upToDate) {
            return $job->productExportId === $upToDate->id;
        });
    }
}
