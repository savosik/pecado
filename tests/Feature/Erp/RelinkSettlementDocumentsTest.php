<?php

namespace Tests\Feature\Erp;

use App\Models\SettlementEntry;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Доливка связи «строка регистра → документ сайта» (`settlements:relink-documents`).
 *
 * Линковка внутри проекции появилась 25.08.2026; всё, что легло в регистр раньше,
 * осталось без document_id — и без ссылки «Открыть» в календаре оплат.
 */
class RelinkSettlementDocumentsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function backlog_rows_get_linked_by_document_uuid(): void
    {
        // Реализация создаётся ДО строки: наблюдатель доклейки срабатывает на
        // создание документа и строки ещё не видит — ровно как в истории.
        $shipment = Shipment::factory()->create();

        $stale = SettlementEntry::factory()->create([
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'document_type' => null,
            'document_id' => null,
        ]);
        $orphan = SettlementEntry::factory()->create([
            'document_uuid' => '00000000-0000-4000-a000-000000009999',
            'document_kind' => 'shipment',
            'document_type' => null,
            'document_id' => null,
        ]);

        $this->artisan('settlements:relink-documents', ['--dry-run' => true])
            ->expectsOutputToContain('документ найден на сайте у 1, не найден у 1')
            ->assertSuccessful();

        $this->assertNull($stale->fresh()->document_id, 'Прогон без записи не должен ничего менять');

        $this->artisan('settlements:relink-documents')
            ->expectsOutputToContain('привязано 1 строк по 1 документам; документа на сайте нет у 1')
            ->assertSuccessful();

        $stale->refresh();
        $this->assertSame($shipment->getKey(), $stale->document_id);
        $this->assertSame($shipment->getMorphClass(), $stale->document_type);

        // Документа на сайте нет — строка остаётся без связи, а не падает.
        $this->assertNull($orphan->fresh()->document_id);
    }
}
