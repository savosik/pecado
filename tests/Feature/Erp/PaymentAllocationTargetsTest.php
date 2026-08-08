<?php

namespace Tests\Feature\Erp;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Shipment;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Расшифровка платежа за пределами реализаций (протокол v15.16.0).
 *
 * До этой версии строка разнесения умела указывать только на реализацию.
 * Замер 1С на боевой базе за 2026 год: из 156 754 061,98 ₽ разнесения
 * на реализации ложится 44 881 268,77 ₽, а 111 872 793,21 ₽ (71,4%)
 * не помещалось — предоплаты по заказам, первичные документы, отчёты
 * комиссионера. Такие строки сайт пропускал с записью в лог: деньги
 * исчезали молча, без ошибки у 1С и без видимого сбоя на сайте.
 */
class PaymentAllocationTargetsTest extends TestCase
{
    use RefreshDatabase;

    private PaymentAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PaymentAllocationService::class);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
    }

    #[Test]
    public function prepayment_for_an_order_is_stored_and_shown_on_the_order(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'order-uuid-prepay',
            'total_amount' => 100000,
        ]);
        $payment = Payment::factory()->create(['amount' => 60000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            [
                'line_number' => 1,
                'target_type' => 'order',
                'order_uuid' => 'order-uuid-prepay',
                'amount' => 60000,
            ],
        ]);

        $allocation = $payment->allocations()->first();

        $this->assertNotNull($allocation, 'Строка по заказу обязана сохраниться, а не быть пропущенной');
        $this->assertSame(PaymentAllocation::TARGET_ORDER, $allocation->target_type);
        $this->assertNull($allocation->shipment_uuid);
        $this->assertSame('order-uuid-prepay', $allocation->order_uuid);

        $this->assertSame('60000.00', (string) $order->fresh()->prepaid_amount);
        $this->assertSame('0.00', (string) $payment->fresh()->unallocated_amount);
    }

    /**
     * Главное ограничение: предоплата по заказу накладную не гасит. Пока
     * реализации нет — гасить нечего; когда она появится, 1С переразнесёт
     * платёж и пришлёт его целиком заново.
     */
    #[Test]
    public function prepayment_does_not_close_shipment_of_the_same_order(): void
    {
        $order = Order::factory()->create(['uuid' => 'order-uuid-both', 'total_amount' => 50000]);
        $shipment = Shipment::factory()->create([
            'uuid' => 'shipment-uuid-both',
            'total_amount' => 50000,
            'currency_code' => 'RUB',
        ]);

        $payment = Payment::factory()->create(['amount' => 50000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            [
                'line_number' => 1,
                'target_type' => 'order',
                'order_uuid' => 'order-uuid-both',
                // 1С вправе указать реализацию справочно и в строке по заказу
                'shipment_uuid' => 'shipment-uuid-both',
                'amount' => 50000,
            ],
        ]);

        $this->assertSame('50000.00', (string) $order->fresh()->prepaid_amount);

        $shipment->refresh();
        $this->assertSame('0.00', (string) $shipment->paid_amount, 'Предоплата по заказу не закрывает накладную');
        $this->assertSame(Shipment::PAYMENT_UNPAID, $shipment->payment_status);
    }

    #[Test]
    public function other_document_line_is_stored_with_its_name(): void
    {
        $payment = Payment::factory()->create(['amount' => 12000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            [
                'line_number' => 1,
                'target_type' => 'other',
                'target_uuid' => 'a1b2c3d4-0000-4000-a000-000000000001',
                'target_name' => 'Отчёт комиссионера 29УТ-000112 от 14.03.2026',
                'amount' => 12000,
            ],
        ]);

        $allocation = $payment->allocations()->first();

        $this->assertNotNull($allocation);
        $this->assertSame(PaymentAllocation::TARGET_OTHER, $allocation->target_type);
        $this->assertSame('Отчёт комиссионера 29УТ-000112 от 14.03.2026', $allocation->documentLabel());
        $this->assertSame('0.00', (string) $payment->fresh()->unallocated_amount);
    }

    /**
     * Смешанный платёж — как раз тот случай, ради которого расширяли контракт:
     * часть суммы закрывает накладную, часть лежит предоплатой по заказу,
     * часть относится к документу, которого на сайте нет вовсе.
     */
    #[Test]
    public function mixed_payment_splits_between_shipment_order_and_other(): void
    {
        $order = Order::factory()->create(['uuid' => 'order-uuid-mixed', 'total_amount' => 30000]);
        $shipment = Shipment::factory()->create([
            'uuid' => 'shipment-uuid-mixed',
            'total_amount' => 20000,
            'currency_code' => 'RUB',
        ]);

        $payment = Payment::factory()->create(['amount' => 60000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['line_number' => 1, 'target_type' => 'shipment', 'shipment_uuid' => 'shipment-uuid-mixed', 'amount' => 20000],
            ['line_number' => 2, 'target_type' => 'order', 'order_uuid' => 'order-uuid-mixed', 'amount' => 30000],
            ['line_number' => 3, 'target_type' => 'other', 'target_name' => 'Первичный документ', 'amount' => 5000],
        ]);

        $this->assertCount(3, $payment->allocations()->get());

        $this->assertSame('20000.00', (string) $shipment->fresh()->paid_amount);
        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->fresh()->payment_status);
        $this->assertSame('30000.00', (string) $order->fresh()->prepaid_amount);

        $payment->refresh();
        $this->assertSame('55000.00', (string) $payment->allocated_amount);
        // Сумма строк не обязана сходиться с суммой платежа — разница остаётся авансом
        $this->assertSame('5000.00', (string) $payment->unallocated_amount);
    }

    /**
     * Обратная совместимость с v15.11.0: 1С включает target_type не одномоментно,
     * и строки без него обязаны по-прежнему считаться разнесением на реализацию.
     */
    #[Test]
    public function line_without_target_type_is_still_a_shipment(): void
    {
        $shipment = Shipment::factory()->create([
            'uuid' => 'shipment-uuid-legacy',
            'total_amount' => 1000,
            'currency_code' => 'RUB',
        ]);
        $payment = Payment::factory()->create(['amount' => 1000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['shipment_uuid' => 'shipment-uuid-legacy', 'amount' => 1000],
        ]);

        $allocation = $payment->allocations()->first();

        $this->assertSame(PaymentAllocation::TARGET_SHIPMENT, $allocation->target_type);
        $this->assertSame('1000.00', (string) $shipment->fresh()->paid_amount);
    }

    #[Test]
    public function order_line_without_order_uuid_is_skipped(): void
    {
        $payment = Payment::factory()->create(['amount' => 5000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['line_number' => 1, 'target_type' => 'order', 'amount' => 5000],
        ]);

        $this->assertCount(0, $payment->allocations()->get(), 'Строка по заказу без order_uuid бессмысленна');
        $this->assertSame('5000.00', (string) $payment->fresh()->unallocated_amount);
    }

    #[Test]
    public function shipment_line_without_shipment_uuid_is_skipped(): void
    {
        $payment = Payment::factory()->create(['amount' => 5000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['line_number' => 1, 'target_type' => 'shipment', 'amount' => 5000],
        ]);

        $this->assertCount(0, $payment->allocations()->get());
    }

    #[Test]
    public function unknown_target_type_is_skipped(): void
    {
        $payment = Payment::factory()->create(['amount' => 5000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['line_number' => 1, 'target_type' => 'contract', 'amount' => 5000],
        ]);

        $this->assertCount(0, $payment->allocations()->get());
    }

    /**
     * Платежи и заказы идут разными очередями. Для предоплат порядок «платёж
     * раньше заказа» — норма, а не редкий случай: предоплата обычно и возникает
     * до того, как документ доедет до сайта.
     */
    #[Test]
    public function prepayment_arriving_before_the_order_is_picked_up_when_it_comes(): void
    {
        $payment = Payment::factory()->create(['amount' => 40000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['line_number' => 1, 'target_type' => 'order', 'order_uuid' => 'order-uuid-late', 'amount' => 40000],
        ]);

        // Заказа ещё нет — строка сохранена, пересчитывать нечего
        $this->assertCount(1, $payment->allocations()->get());

        $order = Order::factory()->create(['uuid' => 'order-uuid-late', 'total_amount' => 40000]);

        $this->service->recalculateOrder($order);

        $this->assertSame('40000.00', (string) $order->fresh()->prepaid_amount);
    }

    #[Test]
    public function refund_reduces_the_prepayment(): void
    {
        $order = Order::factory()->create(['uuid' => 'order-uuid-refund', 'total_amount' => 30000]);

        $incoming = Payment::factory()->create(['amount' => 30000, 'currency_code' => 'RUB', 'direction' => 'in']);
        $this->service->sync($incoming, [
            ['target_type' => 'order', 'order_uuid' => 'order-uuid-refund', 'amount' => 30000],
        ]);

        $refund = Payment::factory()->create(['amount' => 10000, 'currency_code' => 'RUB', 'direction' => 'out']);
        $this->service->sync($refund, [
            ['target_type' => 'order', 'order_uuid' => 'order-uuid-refund', 'amount' => 10000],
        ]);

        $this->assertSame('20000.00', (string) $order->fresh()->prepaid_amount);
    }

    #[Test]
    public function removing_the_line_clears_the_prepayment(): void
    {
        $order = Order::factory()->create(['uuid' => 'order-uuid-cleared', 'total_amount' => 15000]);
        $payment = Payment::factory()->create(['amount' => 15000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['target_type' => 'order', 'order_uuid' => 'order-uuid-cleared', 'amount' => 15000],
        ]);
        $this->assertSame('15000.00', (string) $order->fresh()->prepaid_amount);

        // 1С переразнесла платёж: пустой массив очищает расшифровку целиком
        $this->service->sync($payment, []);

        $this->assertSame('0.00', (string) $order->fresh()->prepaid_amount);
        $this->assertSame('15000.00', (string) $payment->fresh()->unallocated_amount);
    }

    #[Test]
    public function prepayment_recalculation_is_idempotent(): void
    {
        $order = Order::factory()->create(['uuid' => 'order-uuid-idempotent', 'total_amount' => 25000]);
        $payment = Payment::factory()->create(['amount' => 25000, 'currency_code' => 'RUB']);

        $rows = [['target_type' => 'order', 'order_uuid' => 'order-uuid-idempotent', 'amount' => 25000]];

        $this->service->sync($payment, $rows);
        $this->service->sync($payment, $rows);
        $this->service->sync($payment, $rows);

        $this->assertCount(1, $payment->allocations()->get(), 'Повторная доставка не должна множить строки');
        $this->assertSame('25000.00', (string) $order->fresh()->prepaid_amount, 'И не должна задваивать суммы');
    }

    /**
     * Сквозной путь: raw JSON → JSON Schema → HandlePaymentCreated.
     *
     * Проверяется именно он, а не только сервис: строка без `shipment_uuid`
     * до v15.16.0 не проходила валидацию схемы, где поле было обязательным, —
     * и смешанное сообщение целиком ушло бы в DLQ.
     */
    #[Test]
    public function mixed_allocations_survive_the_full_pipeline(): void
    {
        $company = \App\Models\Company::factory()->create([
            'erp_id' => '00000000-0000-4000-a000-0000000040c1',
            'tax_id' => '7744000001',
        ]);

        Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-0000000040e1',
            'total_amount' => 30000,
        ]);
        Shipment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-0000000040d1',
            'total_amount' => 20000,
            'currency_code' => 'RUB',
        ]);

        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode([
            'event' => 'payment.created',
            'uuid' => '00000000-0000-4000-a000-0000000040f1',
            'message_id' => 'msg-payment-mixed-targets',
            'number' => '29УТ-002488',
            'date' => '2026-08-08T10:15:00+03:00',
            'amount' => 55000,
            'currency_code' => 'RUB',
            'contractor_uuid' => $company->erp_id,
            'allocations' => [
                [
                    'line_number' => 1,
                    'target_type' => 'shipment',
                    'shipment_uuid' => '00000000-0000-4000-a000-0000000040d1',
                    'amount' => 20000,
                ],
                [
                    'line_number' => 2,
                    'target_type' => 'order',
                    'order_uuid' => '00000000-0000-4000-a000-0000000040e1',
                    'amount' => 30000,
                ],
                [
                    'line_number' => 3,
                    'target_type' => 'other',
                    'target_name' => 'Отчёт комиссионера 29УТ-000112 от 14.03.2026',
                    'amount' => 5000,
                ],
            ],
        ]));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new \App\Queue\Jobs\ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.payments',
        ))->fire();

        $payment = Payment::where('uuid', '00000000-0000-4000-a000-0000000040f1')->first();

        $this->assertNotNull($payment, 'Смешанное сообщение обязано пройти валидацию схемы');
        $this->assertCount(3, $payment->allocations, 'Все три строки должны сохраниться');

        $this->assertSame(
            '20000.00',
            (string) Shipment::where('uuid', '00000000-0000-4000-a000-0000000040d1')->value('paid_amount'),
        );
        $this->assertSame(
            '30000.00',
            (string) Order::where('uuid', '00000000-0000-4000-a000-0000000040e1')->value('prepaid_amount'),
        );
    }

    #[Test]
    public function deleted_payment_does_not_count_as_prepayment(): void
    {
        $order = Order::factory()->create(['uuid' => 'order-uuid-deleted', 'total_amount' => 18000]);
        $payment = Payment::factory()->create(['amount' => 18000, 'currency_code' => 'RUB']);

        $this->service->sync($payment, [
            ['target_type' => 'order', 'order_uuid' => 'order-uuid-deleted', 'amount' => 18000],
        ]);

        $payment->delete();
        $this->service->recalculateOrder($order);

        $this->assertSame('0.00', (string) $order->fresh()->prepaid_amount);

        // Восстановление платежа возвращает предоплату: строки расшифровки
        // сохраняются, повторной доставки сообщения не требуется
        $payment->restore();
        $this->service->recalculateOrder($order);

        $this->assertSame('18000.00', (string) $order->fresh()->prepaid_amount);
    }
}
