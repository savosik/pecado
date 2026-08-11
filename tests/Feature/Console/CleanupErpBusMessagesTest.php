<?php

namespace Tests\Feature\Console;

use App\Models\ErpBusMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ретенция лога шины ERP с выгрузкой в холодное хранилище.
 *
 * Главное, что здесь проверяется, — порядок операций: запись удаляется из БД
 * только после того, как её архив реально лёг в хранилище. Обратный порядок
 * означал бы тихую потерю лога при любом сбое S3.
 */
class CleanupErpBusMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('erp-archive');

        config([
            'erp.bus_retention_days' => 14,
            'erp.bus_archive.enabled' => true,
            'erp.bus_archive.disk' => 'erp-archive',
            'erp.bus_archive.prefix' => 'erp-bus',
        ]);
    }

    private function message(string $createdAt, array $attrs = []): ErpBusMessage
    {
        return ErpBusMessage::create(array_merge([
            'direction' => 'incoming',
            'routing_key' => 'erp_in.orders',
            'event' => 'order.updated',
            'message_id' => 'msg-'.uniqid(),
            'payload' => ['uuid' => 'test', 'number' => 'ЗК-1'],
            'status' => 'success',
            'created_at' => $createdAt,
        ], $attrs));
    }

    #[Test]
    public function старые_сообщения_уезжают_в_архив_и_удаляются(): void
    {
        $old = $this->message(now()->subDays(20)->setTime(10, 0)->toDateTimeString());
        $fresh = $this->message(now()->subDay()->toDateTimeString());

        $this->artisan('erp:cleanup-messages')->assertSuccessful();

        $this->assertNull(ErpBusMessage::find($old->id), 'Старое сообщение должно быть удалено');
        $this->assertNotNull(ErpBusMessage::find($fresh->id), 'Свежее сообщение трогать нельзя');

        $day = now()->subDays(20);
        $key = sprintf('erp-bus/%s/%s/erp_bus_messages-%s.jsonl.gz', $day->format('Y'), $day->format('m'), $day->toDateString());
        Storage::disk('erp-archive')->assertExists($key);

        $lines = array_filter(explode("\n", gzdecode(Storage::disk('erp-archive')->get($key))));
        $this->assertCount(1, $lines);

        $row = json_decode($lines[0], true);
        $this->assertSame($old->id, $row['id']);
        $this->assertSame('ЗК-1', $row['payload']['number'], 'payload должен лежать объектом, а не строкой');
    }

    #[Test]
    public function dry_run_ничего_не_удаляет_и_не_пишет_в_хранилище(): void
    {
        $old = $this->message(now()->subDays(30)->toDateTimeString());

        $this->artisan('erp:cleanup-messages', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(ErpBusMessage::find($old->id));
        $this->assertEmpty(Storage::disk('erp-archive')->allFiles());
    }

    #[Test]
    public function при_недоступном_хранилище_записи_остаются_в_бд(): void
    {
        $old = $this->message(now()->subDays(20)->toDateTimeString());

        // Диск без сконфигурированного драйвера = хранилище недоступно.
        config(['erp.bus_archive.disk' => 'disk-which-does-not-exist']);

        $this->artisan('erp:cleanup-messages')->assertFailed();

        $this->assertNotNull(
            ErpBusMessage::find($old->id),
            'Без успешного архива удалять нельзя — иначе лог потеряется безвозвратно',
        );
    }

    #[Test]
    public function существующий_объект_не_перезаписывается_а_дополняется_частью(): void
    {
        $day = now()->subDays(20);
        $key = sprintf('erp-bus/%s/%s/erp_bus_messages-%s.jsonl.gz', $day->format('Y'), $day->format('m'), $day->toDateString());
        Storage::disk('erp-archive')->put($key, 'архив прошлого прогона');

        $this->message($day->copy()->setTime(9, 0)->toDateTimeString());

        $this->artisan('erp:cleanup-messages')->assertSuccessful();

        $this->assertSame(
            'архив прошлого прогона',
            Storage::disk('erp-archive')->get($key),
            'Существующий архив мог остаться от прогона, упавшего после заливки — перезапись потеряла бы данные',
        );
        Storage::disk('erp-archive')->assertExists(str_replace('.jsonl.gz', '.part-02.jsonl.gz', $key));
    }

    #[Test]
    public function без_архивации_команда_просто_удаляет(): void
    {
        config(['erp.bus_archive.enabled' => false]);
        $old = $this->message(now()->subDays(20)->toDateTimeString());

        $this->artisan('erp:cleanup-messages')->assertSuccessful();

        $this->assertNull(ErpBusMessage::find($old->id));
        $this->assertEmpty(Storage::disk('erp-archive')->allFiles());
    }

    #[Test]
    public function нулевая_ретенция_выключает_чистку(): void
    {
        config(['erp.bus_retention_days' => 0]);
        $old = $this->message(now()->subYear()->toDateTimeString());

        $this->artisan('erp:cleanup-messages')->assertSuccessful();

        $this->assertNotNull(ErpBusMessage::find($old->id));
    }
}
