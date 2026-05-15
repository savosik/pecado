<?php

namespace Tests\Feature\Services\ProductExport;

use App\Jobs\GenerateProductExportJob;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\ProductExportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Покрывает инструментацию PR 1: в product_export_runs.steps_json должна
 * лежать разбивка длительности по этапам, а queued_for_ms — заполняться
 * из Job. Если тест отвалится — значит замеры в Generator/AbstractPreset/
 * CustomFieldsPreset порвали, и UI перестанет показывать диагностику.
 */
class RunStepTimingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeExport(?string $preset = 'json_catalog'): ProductExport
    {
        $user = User::factory()->create();
        Product::factory()->count(3)->create();

        return ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Timing test',
            'format' => $preset === null ? 'csv' : 'json',
            'preset' => $preset,
            'filters' => [],
            'fields' => $preset === null ? [['key' => 'id', 'label' => 'ID'], ['key' => 'name', 'label' => 'Имя']] : [],
            'is_active' => true,
        ]);
    }

    public function test_preset_run_writes_step_timings(): void
    {
        $export = $this->makeExport('json_catalog');

        app(ProductExportGenerator::class)->generate($export);

        $run = $export->fresh()->lastRun;

        $this->assertNotNull($run, 'last_run должен ссылаться на свежесозданный run');
        $this->assertIsArray($run->steps_json, 'steps_json должен сохраниться массивом');
        $this->assertNotEmpty($run->steps_json, 'steps_json не должен быть пустым');

        // Минимальный контракт: AbstractPreset мерит chunks_total + хотя бы один
        // компонент. Это значит, что инструментация дошла до пресета,
        // setStepTimer был вызван, и StepTimer не подменился.
        $this->assertArrayHasKey('chunks_total', $run->steps_json);
        $this->assertGreaterThan(0, $run->steps_json['chunks_total']);

        // Все ключи — int миллисекунды, не строки/null.
        foreach ($run->steps_json as $key => $value) {
            $this->assertIsInt($value, "Значение шага {$key} должно быть int");
            $this->assertGreaterThanOrEqual(0, $value, "Значение шага {$key} не может быть отрицательным");
        }

        @unlink($export->getCacheFilePath());
    }

    public function test_custom_fields_run_writes_step_timings(): void
    {
        $export = $this->makeExport(null);

        app(ProductExportGenerator::class)->generate($export);

        $run = $export->fresh()->lastRun;

        $this->assertIsArray($run->steps_json);
        $this->assertArrayHasKey('chunks_total', $run->steps_json);
        // CustomFieldsPreset мерит map_rows и write_format внутри callback'а chunk'а.
        // Хотя бы один из них должен быть зафиксирован при 3 товарах.
        $this->assertTrue(
            isset($run->steps_json['map_rows']) || isset($run->steps_json['write_format']),
            'CustomFieldsPreset должен фиксировать map_rows или write_format'
        );

        @unlink($export->getCacheFilePath());
    }

    public function test_queued_for_ms_is_persisted_from_job(): void
    {
        $export = $this->makeExport('json_catalog');

        $job = new GenerateProductExportJob($export->id);
        // Имитируем небольшую задержку в очереди — выставляем dispatchedAtMs в прошлое.
        $job->dispatchedAtMs -= 250;
        $job->handle(app(ProductExportGenerator::class));

        $run = $export->fresh()->lastRun;

        $this->assertNotNull($run->queued_for_ms);
        $this->assertGreaterThanOrEqual(250, $run->queued_for_ms, 'queued_for_ms должен учесть смоделированную задержку');

        @unlink($export->getCacheFilePath());
    }
}
