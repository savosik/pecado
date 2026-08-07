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
}
