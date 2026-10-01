<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\ErpValidationError;
use App\Models\GoodsIssue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Pickup\PickupHandover;
use App\Models\Product;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Pickup\HandoverException;
use App\Services\Pickup\HandoverService;
use App\Services\Pickup\OrderFulfilmentResolver;
use App\Services\Pickup\PickupQueue;
use App\Services\Shortage\CancellationHintResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\EnablesClientNotifications;
use Tests\Feature\Pickup\PickupTestHelpers;
use Tests\TestCase;

/**
 * Полный недобор: расходный ордер отгружен без товара (v16.15.0, топик Agent Hub №19).
 *
 * Сценарий из постановки 1С: ордер на 5 шт дошёл до `to_check`, упаковщик оформил недобор
 * (2 некондиция, 1 брак, 2 недостача) — собрано ничего. 1С проводит ордер без строк и шлёт
 * `goods_issue.updated` со `status: shipped`, `items: []`, `packages: []`.
 *
 * Сообщения идут через {@see ErpIncomingJob} целиком: валидация схемы → handler → маппер →
 * журнал статусов → наблюдатель самовывоза → письма.
 */
class GoodsIssueEmptyShippedIntegrationTest extends TestCase
{
    use EnablesClientNotifications, PickupTestHelpers, RefreshDatabase;

    private const GI_UUID = '00000000-0000-4000-a000-0000000019a1';

    private const PRODUCT_UUID = '00000000-0000-4000-a000-0000000019b1';

    private User $client;

    private Product $product;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.handover_since' => null, 'mail_stream.enabled' => true]);

        // У письма потока обязателен автор — персональный менеджер клиента.
        $manager = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $manager->id]);
        Company::factory()->create(['user_id' => $this->client->id]);

        $this->product = Product::factory()->create(['external_id' => self::PRODUCT_UUID]);
        $this->order = $this->pickupOrder($this->client, ['erp_number' => '29УТ-019001']);
    }

    private function fire(array $payload): void
    {
        $amqp = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqp->method('getBody')->willReturn(json_encode($payload));
        $amqp->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqp,
            'rabbitmq-erp-incoming',
            'erp_in.warehouse',
        ))->fire();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items  null — строка на 5 шт по заказу
     * @return array<string, mixed>
     */
    private function message(string $event, string $status, ?array $items = null, array $extra = []): array
    {
        return array_merge([
            'event' => $event,
            'message_id' => 'msg-gi-19-'.uniqid(),
            'uuid' => self::GI_UUID,
            'number' => 'УТ-00019001',
            'date' => '2026-09-29T10:00:00+03:00',
            'status' => $status,
            'recipient_name' => 'ООО «Тест»',
            'items' => $items ?? [[
                'line_number' => 1,
                'product_uuid' => self::PRODUCT_UUID,
                'product_name' => 'Товар на 5 шт',
                'order_uuid' => $this->order->uuid,
                'order_number' => '29УТ-019001',
                'quantity' => 5,
            ]],
        ], $extra);
    }

    /** Ордер дошёл до «К проверке», затем полный недобор. */
    private function assembleThenShortfall(array $emptyExtra = ['packages' => []]): GoodsIssue
    {
        $this->fire($this->message('goods_issue.created', GoodsIssue::STATUS_TO_PICK));
        $this->fire($this->message('goods_issue.updated', GoodsIssue::STATUS_TO_CHECK));
        $this->fire($this->message('goods_issue.updated', GoodsIssue::STATUS_SHIPPED, [], $emptyExtra));

        return GoodsIssue::query()->where('uuid', self::GI_UUID)->firstOrFail();
    }

    #[Test]
    public function full_shortfall_after_to_check_is_accepted_and_keeps_order_link(): void
    {
        $issue = $this->assembleThenShortfall();

        $this->assertSame(0, ErpValidationError::query()->count(), 'пустой shipped должен пройти схему');
        $this->assertSame(GoodsIssue::STATUS_SHIPPED, $issue->status);
        $this->assertTrue($issue->shipped_empty);
        $this->assertTrue($issue->isShippedEmpty());
        $this->assertSame('Отгружен без товара', $issue->status_label);
        $this->assertSame(0, (int) $issue->packages_count);

        // Строки последней сборки остались: связь с заказом живёт только в них.
        $this->assertSame(1, $issue->items()->count());
        $this->assertSame($this->order->uuid, $issue->items()->value('order_uuid'));

        $this->assertDatabaseHas('goods_issue_status_histories', [
            'goods_issue_id' => $issue->id,
            'from_status' => GoodsIssue::STATUS_TO_CHECK,
            'to_status' => GoodsIssue::STATUS_SHIPPED,
        ]);
    }

    #[Test]
    public function full_shortfall_does_not_send_ready_letter_and_stays_out_of_queues(): void
    {
        $issue = $this->assembleThenShortfall();

        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'orders.ready_for_pickup')->count(), 'клиенту нельзя писать «собран, ждёт выдачи» о пустом ордере');

        $queue = app(PickupQueue::class);
        $this->assertFalse($queue->awaiting()->pluck('id')->contains($issue->id), 'ордер не ждёт выдачи');
        $this->assertFalse($queue->stale()->pluck('id')->contains($issue->id));
        $this->assertFalse($queue->picking()->pluck('id')->contains($issue->id), 'ордер не «в сборке»');
        $this->assertTrue($queue->search('УТ-00019001')->isEmpty(), 'ручной поиск выдачи его не находит');

        // Напоминание «не забрали» по пустому ордеру не уходит и через неделю.
        config(['pickup.handover_since' => '2020-01-01 00:00:00']);
        $this->travel(8)->days();
        $this->artisan('pickup:remind-waiting', ['--dry-run' => true])
            ->doesntExpectOutputToContain('УТ-00019001')
            ->assertSuccessful();

        $this->assertFalse($issue->fresh()->is_stale, 'в «залежавшихся» не висит');
    }

    #[Test]
    public function order_stage_is_not_collected_and_timeline_explains_it(): void
    {
        $issue = $this->assembleThenShortfall();
        $resolver = app(OrderFulfilmentResolver::class);

        $view = $resolver->forOrder($this->order);
        $this->assertSame('not_collected', $view['stage']);
        $this->assertSame('Сборка не состоялась', $view['label']);
        $this->assertNull($view['promise'], 'обещать время сборки нечего');
        $this->assertSame([$issue->id], array_column($view['goods_issues'], 'id'), 'ордер остался в карточке заказа');

        $this->assertTrue($resolver->readyForUser($this->client)->isEmpty(), 'в кабинете «готово к выдаче» пусто');

        $labels = array_column($resolver->timeline($this->order), 'label');
        $this->assertContains('Сборка не состоялась: товара нет', $labels);
        $this->assertNotContains('Собран', $labels);
    }

    #[Test]
    public function cancelled_order_lines_keep_warehouse_hint(): void
    {
        $this->assembleThenShortfall();

        $line = OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'cancelled' => true,
            'cancelled_at' => now(),
            'quantity' => 5,
        ]);

        $hint = app(CancellationHintResolver::class)->forItems(collect([$line]))[$line->id];

        $this->assertSame(CancellationHintResolver::HINT_WAREHOUSE_STRONG, $hint['kind']);
        $this->assertSame('Похоже на склад', $hint['label']);
    }

    #[Test]
    public function empty_shipped_with_measurement_not_required_and_revision_is_accepted(): void
    {
        $issue = $this->assembleThenShortfall([
            'revision' => 3,
            'shipping_mode' => 'pickup',
            'packages' => [],
            'measurement' => ['required' => false, 'state' => 'not_required', 'measured_at' => null, 'measured_by' => null],
        ]);

        $this->assertSame(0, ErpValidationError::query()->count());
        $this->assertTrue($issue->isShippedEmpty());
    }

    #[Test]
    public function empty_items_are_rejected_in_created_and_outside_shipped(): void
    {
        // created с пустым items — ордер не появляется.
        $this->fire($this->message('goods_issue.created', GoodsIssue::STATUS_SHIPPED, [], ['message_id' => 'msg-gi-19-created-empty']));
        $this->assertDatabaseHas('erp_validation_errors', ['event' => 'goods_issue.created', 'message_id' => 'msg-gi-19-created-empty']);
        $this->assertDatabaseMissing('goods_issues', ['uuid' => self::GI_UUID]);

        // Ордер в сборке; пустой items в to_check отклоняется и ордер не трогает.
        $this->fire($this->message('goods_issue.created', GoodsIssue::STATUS_TO_PICK));
        $this->fire($this->message('goods_issue.updated', GoodsIssue::STATUS_TO_CHECK, [], ['message_id' => 'msg-gi-19-check-empty']));
        $this->assertDatabaseHas('erp_validation_errors', ['event' => 'goods_issue.updated', 'message_id' => 'msg-gi-19-check-empty']);

        $issue = GoodsIssue::query()->where('uuid', self::GI_UUID)->firstOrFail();
        $this->assertSame(GoodsIssue::STATUS_TO_PICK, $issue->status);
        $this->assertFalse($issue->shipped_empty);
        $this->assertSame(1, $issue->items()->count());
    }

    #[Test]
    public function reposting_with_goods_clears_the_mark_and_replaces_lines(): void
    {
        $this->assembleThenShortfall();

        $this->fire($this->message('goods_issue.updated', GoodsIssue::STATUS_TO_CHECK, [[
            'line_number' => 1,
            'product_uuid' => self::PRODUCT_UUID,
            'order_uuid' => $this->order->uuid,
            'quantity' => 2,
        ]]));

        $issue = GoodsIssue::query()->where('uuid', self::GI_UUID)->firstOrFail();
        $this->assertFalse($issue->shipped_empty);
        $this->assertSame(1, $issue->items()->count());
        $this->assertSame(2.0, (float) $issue->items()->value('quantity'));
        $this->assertSame('picking', app(OrderFulfilmentResolver::class)->forOrder($this->order)['stage']);
    }

    #[Test]
    public function empty_issue_does_not_spoil_order_with_another_collected_issue(): void
    {
        $collected = $this->goodsIssueFor($this->order, GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($collected, GoodsIssue::STATUS_SHIPPED);
        $this->assertSame(1, CrmEmail::query()->where('origin_event', 'orders.ready_for_pickup')->count());

        $this->travel(5)->minutes();
        $this->assembleThenShortfall();

        $this->assertSame(1, CrmEmail::query()->where('origin_event', 'orders.ready_for_pickup')->count(), 'пустой ордер не повторяет письмо');

        $view = app(OrderFulfilmentResolver::class)->forOrder($this->order);
        $this->assertSame('ready', $view['stage']);
        $this->assertSame(1, $view['issues_total']);
        $this->assertFalse($view['is_partial']);
    }

    #[Test]
    public function empty_shipped_issue_cannot_be_handed_over(): void
    {
        $issue = $this->assembleThenShortfall();

        $this->expectException(HandoverException::class);
        app(HandoverService::class)->issue($issue, User::factory()->create(), PickupHandover::METHOD_MANUAL);
    }
}
