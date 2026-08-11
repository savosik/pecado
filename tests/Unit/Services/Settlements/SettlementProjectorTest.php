<?php

namespace Tests\Unit\Services\Settlements;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Settlements\SettlementProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Доклейка движений к документам сайта (v16.0.0, карточка fin-04).
 *
 * Проектор — единственный писатель производных данных регистра, и главное его
 * свойство — идемпотентность: повторный прогон обязан давать тот же результат,
 * иначе пересчёт после сбоя стал бы опасной операцией.
 */
class SettlementProjectorTest extends TestCase
{
    use RefreshDatabase;

    private const DOCUMENT_UUID = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

    private SettlementProjector $projector;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projector = app(SettlementProjector::class);
        $this->user = User::factory()->create();
    }

    private function entry(string $documentUuid = self::DOCUMENT_UUID): SettlementEntry
    {
        return SettlementEntry::factory()->create([
            'document_uuid' => $documentUuid,
            'document_type' => null,
            'document_id' => null,
        ]);
    }

    #[Test]
    public function движение_сшивается_с_реализацией(): void
    {
        $entry = $this->entry();
        $shipment = Shipment::factory()->create(['uuid' => self::DOCUMENT_UUID, 'user_id' => $this->user->id]);

        // Наблюдатель уже сшил при создании — проектор обязан согласиться, а не
        // переписать связь на другую.
        $this->assertSame($shipment->id, $entry->fresh()->document_id);

        $this->projector->projectDocument(self::DOCUMENT_UUID);

        $entry = $entry->fresh();
        $this->assertSame($shipment->id, $entry->document_id);
        $this->assertSame($shipment->getMorphClass(), $entry->document_type);
    }

    /**
     * Повторный прогон не меняет данные и не переписывает уже сшитые строки.
     *
     * Проверяем по числу затронутых строк, а не по числу запросов: сам UPDATE
     * выполняется всегда, но условие отсекает строки с уже верной связью —
     * на первичной заливке это разница между «тронули сотни тысяч строк» и «ноль».
     */
    #[Test]
    public function повторный_прогон_идемпотентен(): void
    {
        $this->entry();
        Shipment::factory()->create(['uuid' => self::DOCUMENT_UUID, 'user_id' => $this->user->id]);

        $before = SettlementEntry::query()->sole()->only(['document_type', 'document_id']);

        $touched = $this->projector->linkDocument(self::DOCUMENT_UUID);

        $after = SettlementEntry::query()->sole()->only(['document_type', 'document_id']);

        $this->assertSame($before, $after);
        $this->assertSame(0, $touched, 'Уже сшитая строка переписываться не должна.');
    }

    /**
     * Документа на сайте может не быть вовсе — отчёт комиссионера сюда
     * не приезжает. Это не ошибка, движение остаётся читаемым по своим
     * продублированным реквизитам.
     */
    #[Test]
    public function отсутствие_документа_не_ошибка(): void
    {
        $entry = $this->entry('11111111-2222-3333-4444-555555555555');

        $this->assertSame(0, $this->projector->linkDocument('11111111-2222-3333-4444-555555555555'));
        $this->assertNull($entry->fresh()->document_id);
    }

    #[Test]
    public function заказ_и_платёж_сшиваются_так_же_как_реализация(): void
    {
        $orderUuid = 'a2c4e6f8-1b3d-4507-9e2a-6c8f4d1b7e35';
        $paymentUuid = 'c3d4e5f6-7081-492a-b3c4-4e5f60718293';

        $this->entry($orderUuid);
        $this->entry($paymentUuid);

        $order = Order::factory()->create(['uuid' => $orderUuid, 'user_id' => $this->user->id]);
        $payment = Payment::factory()->create(['uuid' => $paymentUuid]);

        $this->assertSame(
            $order->getMorphClass(),
            SettlementEntry::query()->where('document_uuid', $orderUuid)->value('document_type'),
        );
        $this->assertSame(
            $payment->getMorphClass(),
            SettlementEntry::query()->where('document_uuid', $paymentUuid)->value('document_type'),
        );
    }
}
