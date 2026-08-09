<?php

namespace Tests\Feature\Erp;

use App\Models\Shipment;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сквозной путь графика оплаты: сообщение из RabbitMQ → валидация схемой →
 * обработчик → строки графика, погашенные фактическими платежами.
 *
 * Через ErpIncomingJob, а не напрямую через обработчик: только так проверяется,
 * что новое поле `payment_schedule` проходит runtime-валидацию `shipment.*.json`
 * и не отправляет сообщение в DLQ.
 *
 * Порядок сообщений проверяется в обе стороны: платежи и реализации идут разными
 * очередями (`erp_in.payments` и `erp_in.documents`) без гарантии порядка.
 */
class PaymentScheduleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const SHIPMENT_UUID = '550e8400-e29b-41d4-a716-4466554400a1';

    private const PAYMENT_UUID = '550e8400-e29b-41d4-a716-4466554400a2';

    private const CONTRACTOR_UUID = '550e8400-e29b-41d4-a716-4466554400a3';

    private const ORDER_UUID = '550e8400-e29b-41d4-a716-4466554400a5';

    private const PREPAYMENT_UUID = '550e8400-e29b-41d4-a716-4466554400a6';

    /**
     * Прогон сообщения тем же путём, каким его получает воркер очереди:
     * через AMQP-конверт и `fire()`, а не вызовом обработчика напрямую.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $payload, string $queue = 'erp_in.documents'): void
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            $queue,
        ))->fire();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $schedule
     * @return array<string, mixed>
     */
    private function shipmentMessage(?array $schedule, string $event = 'shipment.created'): array
    {
        $payload = [
            'event' => $event,
            'message_id' => 'msg-'.$event.'-'.uniqid(),
            'uuid' => self::SHIPMENT_UUID,
            'number' => '29УТ-006915',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'tax_id' => '7710140679',
            'date' => '2026-07-28',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [[
                // Товара с таким uuid на сайте нет — позиция сохранится без
                // product_id, для графика оплаты это несущественно.
                'product_uuid' => '550e8400-e29b-41d4-a716-4466554400a4',
                'quantity' => 1,
                'price' => 10000.00,
                'total' => 10000.00,
            ]],
        ];

        if ($schedule !== null) {
            $payload['payment_schedule'] = $schedule;
        }

        return $payload;
    }

    /**
     * Платёж, разнесённый 1С на ЗАКАЗ, а не на реализацию.
     *
     * На проде так пришла почти половина денег (39,7 из 82,8 млн ₽ на 2026-08-09),
     * и до июня это была основная практика.
     *
     * @return array<string, mixed>
     */
    private function prepaymentMessage(float $amount, string $orderUuid = self::ORDER_UUID): array
    {
        return [
            'event' => 'payment.created',
            'message_id' => 'msg-prepay-'.uniqid(),
            'uuid' => self::PREPAYMENT_UUID,
            'number' => '29УТ-002489',
            'date' => '2026-08-20T10:00:00+03:00',
            'direction' => 'in',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'amount' => $amount,
            'currency_code' => 'RUB',
            'allocations' => [[
                'target_type' => 'order',
                'order_uuid' => $orderUuid,
                'amount' => $amount,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentMessage(float $amount): array
    {
        return [
            'event' => 'payment.created',
            'message_id' => 'msg-payment-'.uniqid(),
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'date' => '2026-08-27T10:00:00+03:00',
            'direction' => 'in',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'amount' => $amount,
            'currency_code' => 'RUB',
            'allocations' => [['shipment_uuid' => self::SHIPMENT_UUID, 'amount' => $amount]],
        ];
    }

    /**
     * Строки графика из скриншота 1С: рассрочка двумя платежами.
     *
     * @return array<int, array<string, mixed>>
     */
    private function schedule(): array
    {
        return [
            [
                'line_number' => 1,
                'due_date' => '2026-08-27',
                'amount' => 3000.00,
                'percent' => 30,
                'term_days' => 30,
                'basis' => 'shipment_date',
                'basis_name' => 'от даты отгрузки',
                'stage' => 'credit',
                'stage_name' => 'Оплата после отгрузки',
            ],
            [
                'line_number' => 2,
                'due_date' => '2026-09-27',
                'amount' => 7000.00,
                'percent' => 70,
                'term_days' => 60,
                'basis' => 'shipment_date',
                'basis_name' => 'от даты отгрузки',
                'stage' => 'credit',
                'stage_name' => 'Оплата после отгрузки',
            ],
        ];
    }

    /**
     * Тот же график, но строки знают, из какого заказа выросли, — как присылает 1С.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scheduleWithOrder(): array
    {
        return array_map(
            static fn (array $line): array => $line + ['order_uuid' => self::ORDER_UUID],
            $this->schedule(),
        );
    }

    private function shipment(): Shipment
    {
        return Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();
    }

    #[Test]
    public function schedule_passes_runtime_validation_and_is_stored(): void
    {
        $this->dispatch($this->shipmentMessage($this->schedule()));

        $shipment = $this->shipment();

        // Сообщение не ушло в DLQ — новое поле прошло схему shipment.created.json.
        $this->assertSame(2, $shipment->paymentSchedules()->count());
        $this->assertSame('2026-08-27', $shipment->payment_due_date->toDateString());
        $this->assertSame('Оплата после отгрузки', $shipment->paymentSchedules()->first()->stage_name);
    }

    #[Test]
    public function payment_after_shipment_closes_first_schedule_line(): void
    {
        $this->dispatch($this->shipmentMessage($this->schedule()));
        $this->dispatch($this->paymentMessage(3000.00), 'erp_in.payments');

        $lines = $this->shipment()->paymentSchedules()->get();

        $this->assertEquals(3000.00, (float) $lines[0]->paid_amount);
        $this->assertEquals(0.00, (float) $lines[1]->paid_amount);
        // План переехал на вторую строку — первая закрыта.
        $this->assertSame('2026-09-27', $this->shipment()->payment_due_date->toDateString());
    }

    #[Test]
    public function payment_arriving_before_shipment_still_closes_the_schedule(): void
    {
        // Обратный порядок — штатная ситуация первичной выгрузки: платёж приезжает
        // раньше своей реализации и ждёт её с shipment_id = null.
        $this->dispatch($this->paymentMessage(3000.00), 'erp_in.payments');
        $this->dispatch($this->shipmentMessage($this->schedule()));

        $lines = $this->shipment()->paymentSchedules()->get();

        $this->assertEquals(3000.00, (float) $lines[0]->paid_amount);
        $this->assertSame('2026-09-27', $this->shipment()->payment_due_date->toDateString());
    }

    #[Test]
    public function shipment_updated_backfills_schedule_for_documents_from_older_protocol(): void
    {
        // Документ проведён до 15.12.0 — графика в сообщении нет.
        $this->dispatch($this->shipmentMessage(null));
        $this->assertSame(0, $this->shipment()->paymentSchedules()->count());
        $this->assertNull($this->shipment()->payment_due_date);

        // Повторная выгрузка довозит график.
        $this->dispatch($this->shipmentMessage($this->schedule(), 'shipment.updated'));

        $this->assertSame(2, $this->shipment()->paymentSchedules()->count());
        $this->assertSame('2026-08-27', $this->shipment()->payment_due_date->toDateString());
    }

    #[Test]
    public function repeated_delivery_of_the_same_message_does_not_double_the_schedule(): void
    {
        $message = $this->shipmentMessage($this->schedule());

        $this->dispatch($message);
        // Тот же message_id: повторную доставку отсекает erp_processed_messages,
        // но даже пройди она — delete-and-recreate даёт тот же результат.
        $this->dispatch($message);

        $this->assertSame(2, $this->shipment()->paymentSchedules()->count());
    }

    /**
     * Предоплата по заказу закрывает график, хотя `shipments.paid_amount` не растёт.
     *
     * Без зачёта такая строка висела бы просроченной вечно: 1С разносит поступление
     * на заказ, а не на реализацию, и до июня 2026 так шла почти половина денег.
     */
    #[Test]
    public function order_prepayment_closes_schedule_without_touching_shipment_paid_amount(): void
    {
        $this->dispatch($this->shipmentMessage($this->scheduleWithOrder()));
        $this->dispatch($this->prepaymentMessage(3000.00), 'erp_in.payments');

        $lines = $this->shipment()->paymentSchedules()->inFifoOrder()->get();

        $this->assertEquals(3000.00, (float) $lines[0]->prepaid_amount);
        $this->assertEquals(0.00, (float) $lines[0]->paid_amount, 'Разнесения на реализацию не было.');
        $this->assertEquals(0.00, (float) $lines[0]->unpaid_amount);
        $this->assertEquals(0.00, (float) $lines[1]->prepaid_amount);

        // Проекция расшифровки платежа по реализациям остаётся нетронутой.
        $this->assertEquals(0.00, (float) $this->shipment()->paid_amount);
        // А план переехал на вторую строку: деньги по первой в учёте есть.
        $this->assertSame('2026-09-27', $this->shipment()->payment_due_date->toDateString());
    }

    /**
     * Прямое разнесение имеет приоритет: аванс закрывает только то, что осталось,
     * иначе один и тот же долг был бы погашен дважды.
     */
    #[Test]
    public function direct_allocation_wins_over_prepayment(): void
    {
        $this->dispatch($this->shipmentMessage($this->scheduleWithOrder()));
        $this->dispatch($this->paymentMessage(3000.00), 'erp_in.payments');
        $this->dispatch($this->prepaymentMessage(10000.00), 'erp_in.payments');

        $lines = $this->shipment()->paymentSchedules()->inFifoOrder()->get();

        // Первая строка закрыта деньгами, аванс на неё не тратится.
        $this->assertEquals(3000.00, (float) $lines[0]->paid_amount);
        $this->assertEquals(0.00, (float) $lines[0]->prepaid_amount);
        // Вторая закрыта авансом целиком — но не больше своей суммы.
        $this->assertEquals(7000.00, (float) $lines[1]->prepaid_amount);
        $this->assertEquals(0.00, (float) $lines[1]->unpaid_amount);

        $this->assertNull($this->shipment()->payment_due_date, 'Долга по графику не осталось.');
    }

    /**
     * Аванс заказа делится между его реализациями, а не зачитывается каждой целиком.
     */
    #[Test]
    public function order_prepayment_is_shared_between_shipments_of_the_same_order(): void
    {
        $second = '550e8400-e29b-41d4-a716-4466554400b1';

        $this->dispatch($this->shipmentMessage($this->scheduleWithOrder()));

        $secondMessage = $this->shipmentMessage($this->scheduleWithOrder());
        $secondMessage['uuid'] = $second;
        $secondMessage['number'] = '29УТ-006916';
        $this->dispatch($secondMessage);

        // Аванса хватает на первую реализацию целиком и на кусок второй.
        $this->dispatch($this->prepaymentMessage(12000.00), 'erp_in.payments');

        $first = $this->shipment()->paymentSchedules()->inFifoOrder()->get();
        $other = Shipment::where('uuid', $second)->firstOrFail()
            ->paymentSchedules()->inFifoOrder()->get();

        $covered = $first->sum(fn ($line) => (float) $line->prepaid_amount)
            + $other->sum(fn ($line) => (float) $line->prepaid_amount);

        // Ровно сумма аванса: ни рубля сверх того, что заплатил клиент.
        $this->assertEquals(12000.00, $covered);
    }

    /**
     * Повторный пересчёт не меняет результат — тот же контракт, что у остальных
     * денежных агрегатов: полная функция от состояния БД, а не инкремент.
     */
    #[Test]
    public function prepayment_offset_is_idempotent(): void
    {
        $this->dispatch($this->shipmentMessage($this->scheduleWithOrder()));
        $this->dispatch($this->prepaymentMessage(5000.00), 'erp_in.payments');

        $service = app(\App\Services\Payments\PaymentScheduleService::class);
        $service->applyOrderPrepayments([self::ORDER_UUID]);
        $service->applyOrderPrepayments([self::ORDER_UUID]);

        $lines = $this->shipment()->paymentSchedules()->inFifoOrder()->get();

        $this->assertEquals(3000.00, (float) $lines[0]->prepaid_amount);
        $this->assertEquals(2000.00, (float) $lines[1]->prepaid_amount);
    }
}
