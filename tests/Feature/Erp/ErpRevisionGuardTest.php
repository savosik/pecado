<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\ErpBusMessage;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Erp\ErpRevisionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Защита от применения устаревших данных по номеру ревизии (протокол v15.16.0).
 *
 * Сценарий взят из журнала исходящих 1С: при оформлении недобора по заказу
 * 29УТ-012045 ушли два сообщения с одинаковым erp_updated_at (15:41:28),
 * содержательно разные:
 *
 *   [4609584] 1 строка: 5 шт, не отменено       — УСТАРЕВШИЕ данные
 *   [4609585] 2 строки: 2 активно + 3 отменено  — актуальные
 *
 * Порядок доставки не гарантирован. Если бы они применились в обратном порядке,
 * сайт записал бы устаревшее состояние и никак этого не заметил.
 */
class ErpRevisionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Журнал шины пишется только при включённом логировании — а именно в нём
        // обязано быть видно, что сообщение отброшено
        config()->set('erp.bus_logging_enabled', true);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
    }

    private function makeJob(array $payload): ErpIncomingJob
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        return new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.orders',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderMessage(string $uuid, int $revision, int $quantity, string $messageId): array
    {
        return [
            'event' => 'order.updated',
            'uuid' => $uuid,
            'message_id' => $messageId,
            'revision' => $revision,
            'erp_updated_at' => '2026-08-08T15:41:28+03:00',
            'items' => [
                [
                    'line_number' => 1,
                    'product_uuid' => '00000000-0000-4000-a000-0000000030a1',
                    'quantity' => $quantity,
                    'base_price' => 5090,
                    'discount_percent' => 25,
                    'final_price' => 3817.50,
                ],
            ],
        ];
    }

    private function makeOrder(string $uuid): Order
    {
        Product::factory()->create(['external_id' => '00000000-0000-4000-a000-0000000030a1']);

        return Order::factory()->create(['uuid' => $uuid, 'total_amount' => 0]);
    }

    #[Test]
    public function out_of_order_delivery_keeps_the_newer_state(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f1';
        $order = $this->makeOrder($uuid);

        // Сначала доехало свежее сообщение
        $this->makeJob($this->orderMessage($uuid, 8, 2, 'msg-4609585'))->fire();

        $this->assertSame(2, $order->fresh()->items->first()->quantity);

        // Следом — устаревшее, с той же меткой времени, но меньшей ревизией
        $this->makeJob($this->orderMessage($uuid, 7, 5, 'msg-4609584'))->fire();

        $order->refresh();

        $this->assertSame(
            2,
            $order->items->first()->quantity,
            'Устаревшее сообщение не должно откатывать состояние заказа',
        );
        $this->assertSame(8, (int) $order->applied_revision);
    }

    #[Test]
    public function stale_message_is_visible_in_the_bus_log(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f2';
        $this->makeOrder($uuid);

        $this->makeJob($this->orderMessage($uuid, 8, 2, 'msg-fresh'))->fire();
        $this->makeJob($this->orderMessage($uuid, 7, 5, 'msg-stale'))->fire();

        $logged = ErpBusMessage::where('message_id', 'msg-stale')->first();

        $this->assertNotNull($logged, 'Отброшенное сообщение обязано попасть в журнал шины');
        $this->assertSame('stale', $logged->status);
        $this->assertStringContainsString('пришла ревизия 7', $logged->error_message);
        $this->assertStringContainsString('уже ревизия 8', $logged->error_message);
    }

    #[Test]
    public function equal_revision_is_rejected(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f3';
        $order = $this->makeOrder($uuid);

        $this->makeJob($this->orderMessage($uuid, 5, 2, 'msg-first'))->fire();
        $this->makeJob($this->orderMessage($uuid, 5, 9, 'msg-same-revision'))->fire();

        $this->assertSame(
            2,
            $order->fresh()->items->first()->quantity,
            'Ревизия обязана расти: сообщение с той же ревизией — повтор',
        );
    }

    #[Test]
    public function newer_revision_is_applied(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f4';
        $order = $this->makeOrder($uuid);

        $this->makeJob($this->orderMessage($uuid, 5, 2, 'msg-rev-5'))->fire();
        $this->makeJob($this->orderMessage($uuid, 6, 9, 'msg-rev-6'))->fire();

        $order->refresh();

        $this->assertSame(9, $order->items->first()->quantity);
        $this->assertSame(6, (int) $order->applied_revision);
    }

    /**
     * 1С включает поле по одному каналу за раз — сообщения без revision обязаны
     * обрабатываться как раньше, иначе выкатка сломала бы весь обмен разом.
     */
    #[Test]
    public function message_without_revision_is_always_applied(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f5';
        $order = $this->makeOrder($uuid);

        $this->makeJob($this->orderMessage($uuid, 9, 2, 'msg-with-revision'))->fire();

        $withoutRevision = $this->orderMessage($uuid, 0, 7, 'msg-no-revision');
        unset($withoutRevision['revision']);

        $this->makeJob($withoutRevision)->fire();

        $order->refresh();

        $this->assertSame(7, $order->items->first()->quantity);
        $this->assertSame(9, (int) $order->applied_revision, 'Отметка не должна сбрасываться');
    }

    #[Test]
    public function first_message_for_new_document_passes(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f6';
        $order = $this->makeOrder($uuid);

        $this->makeJob($this->orderMessage($uuid, 42, 3, 'msg-first-ever'))->fire();

        $order->refresh();

        $this->assertSame(3, $order->items->first()->quantity);
        $this->assertSame(42, (int) $order->applied_revision);
    }

    /**
     * Ревизия сдвигается только после успешной обработки. Иначе упавшее сообщение
     * заблокировало бы само себя: повтор из очереди пришёл бы с той же ревизией
     * и был бы отброшен как устаревший.
     */
    #[Test]
    public function failed_handling_does_not_move_the_revision(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f7';

        // Заказа нет, partner_uuid и items отсутствуют — восстановить нечем,
        // обработчик бросает ErpUnprocessableMessageException
        $this->makeJob([
            'event' => 'order.updated',
            'uuid' => $uuid,
            'message_id' => 'msg-unprocessable',
            'revision' => 4,
            'status' => 'closed',
        ])->fire();

        $this->assertDatabaseMissing('orders', ['uuid' => $uuid]);

        // Тот же документ приезжает заново с той же ревизией — и обязан примениться
        $order = $this->makeOrder($uuid);
        $this->makeJob($this->orderMessage($uuid, 4, 3, 'msg-retry'))->fire();

        $order->refresh();

        $this->assertSame(3, $order->items->first()->quantity);
        $this->assertSame(4, (int) $order->applied_revision);
    }

    /**
     * Мягко удалённый документ сохраняет отметку: иначе после `*.deleted`
     * отсчёт начался бы заново и устаревшее сообщение прошло бы проверку.
     */
    #[Test]
    public function soft_deleted_document_keeps_its_revision(): void
    {
        $uuid = '00000000-0000-4000-a000-0000000030f8';
        $order = $this->makeOrder($uuid);

        $this->makeJob($this->orderMessage($uuid, 11, 2, 'msg-before-delete'))->fire();

        $this->makeJob([
            'event' => 'order.deleted',
            'uuid' => $uuid,
            'message_id' => 'msg-delete',
            'revision' => 12,
        ])->fire();

        $this->assertSoftDeleted('orders', ['uuid' => $uuid]);

        $guard = app(ErpRevisionGuard::class);

        $this->assertNotNull(
            $guard->staleReason('order.updated', ['uuid' => $uuid, 'revision' => 11]),
            'Сообщение старше удаления обязано отбрасываться и после soft-delete',
        );
        $this->assertNull(
            $guard->staleReason('order.updated', ['uuid' => $uuid, 'revision' => 13]),
            'Более свежее сообщение возвращает документ к жизни',
        );

        $this->assertSame(12, (int) Order::withTrashed()->where('uuid', $uuid)->value('applied_revision'));
    }

    /**
     * Проверка живёт не только в заказах: платежи и реализации идут своими
     * очередями и рвут порядок ровно так же.
     */
    #[Test]
    public function guard_covers_shipments_and_payments(): void
    {
        $user = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-0000000031b1']);
        Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => '00000000-0000-4000-a000-0000000031c1',
            'tax_id' => '7733000001',
        ]);

        Shipment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-0000000031f1',
            'applied_revision' => 5,
        ]);

        Payment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-0000000031f2',
            'applied_revision' => 5,
        ]);

        $guard = app(ErpRevisionGuard::class);

        $this->assertNotNull($guard->staleReason('shipment.updated', [
            'uuid' => '00000000-0000-4000-a000-0000000031f1',
            'revision' => 4,
        ]));
        $this->assertNull($guard->staleReason('shipment.updated', [
            'uuid' => '00000000-0000-4000-a000-0000000031f1',
            'revision' => 6,
        ]));

        $this->assertNotNull($guard->staleReason('payment.updated', [
            'uuid' => '00000000-0000-4000-a000-0000000031f2',
            'revision' => 5,
        ]));
        $this->assertNull($guard->staleReason('payment.updated', [
            'uuid' => '00000000-0000-4000-a000-0000000031f2',
            'revision' => 6,
        ]));
    }

    /**
     * Справочники ревизий не имеют: там нет документа с жизненным циклом,
     * и последнее пришедшее значение и есть правильное.
     */
    #[Test]
    public function reference_events_are_not_guarded(): void
    {
        $guard = app(ErpRevisionGuard::class);

        foreach (['product.updated', 'price.updated', 'stock.updated', 'goods_issue.updated'] as $event) {
            $this->assertNull(
                $guard->staleReason($event, ['uuid' => 'любой', 'revision' => 1]),
                "Событие {$event} не должно проверяться по ревизии",
            );
        }
    }
}
