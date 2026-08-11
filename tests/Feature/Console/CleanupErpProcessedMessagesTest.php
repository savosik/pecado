<?php

namespace Tests\Feature\Console;

use App\Models\ErpProcessedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ретенция журнала дедупликации входящих ERP-сообщений.
 *
 * Журнал нужен ровно на горизонт повторной доставки RabbitMQ, но до августа
 * 2026 не чистился вообще и рос вечно.
 */
class CleanupErpProcessedMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['erp.processed_retention_days' => 14]);
    }

    private function processed(string $processedAt): ErpProcessedMessage
    {
        return ErpProcessedMessage::create([
            'message_id' => 'msg-'.uniqid(),
            'event' => 'order.updated',
            'processed_at' => $processedAt,
        ]);
    }

    #[Test]
    public function удаляет_только_записи_старше_срока(): void
    {
        $old = $this->processed(now()->subDays(20)->toDateTimeString());
        $fresh = $this->processed(now()->subDays(3)->toDateTimeString());

        $this->artisan('erp:cleanup-processed')->assertSuccessful();

        $this->assertNull(ErpProcessedMessage::find($old->message_id));
        $this->assertNotNull(ErpProcessedMessage::find($fresh->message_id));
    }

    #[Test]
    public function dry_run_ничего_не_удаляет(): void
    {
        $old = $this->processed(now()->subDays(30)->toDateTimeString());

        $this->artisan('erp:cleanup-processed', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(ErpProcessedMessage::find($old->message_id));
    }

    #[Test]
    public function нулевая_ретенция_выключает_чистку(): void
    {
        config(['erp.processed_retention_days' => 0]);
        $old = $this->processed(now()->subYear()->toDateTimeString());

        $this->artisan('erp:cleanup-processed')->assertSuccessful();

        $this->assertNotNull(ErpProcessedMessage::find($old->message_id));
    }
}
