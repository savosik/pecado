<?php

namespace Tests\Feature\Erp;

use App\Enums\Substitution\OfferStatus;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\SubstitutionOffer;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Интеграционные тесты детекта недобора (sub-02): путь начинается с сообщения шины.
 *
 * `order.updated` с отменой строк → подборка замен + задача менеджеру;
 * повторные волны дополняют, повторная доставка не плодит дублей,
 * выключенный флаг не меняет поведение системы вовсе.
 */
class ShortageOfferDetectionTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_UUID = '00000000-0000-4000-a000-0000000030a1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('substitutions.enabled', true);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function orderUpdatedMessage(string $uuid, array $items, string $messageId, ?int $revision = null): array
    {
        return [
            'event' => 'order.updated',
            'uuid' => $uuid,
            'message_id' => $messageId,
            'revision' => $revision,
            'number' => '29УТ-011777',
            'erp_updated_at' => '2026-08-14T12:00:00+03:00',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $lineNumber, bool $cancelled, float $finalPrice = 100.0, int $quantity = 5): array
    {
        return [
            'line_number' => $lineNumber,
            'product_uuid' => self::PRODUCT_UUID,
            'quantity' => $quantity,
            'base_price' => $finalPrice,
            'discount_percent' => 0,
            'final_price' => $finalPrice,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Клиент с персональным менеджером и заказ, известный сайту.
     *
     * @return array{order: Order, client: User, managerAccount: User}
     */
    private function makeOrderWithManager(string $uuid): array
    {
        Product::factory()->create(['external_id' => self::PRODUCT_UUID]);

        $managerAccount = User::factory()->create();
        $manager = PersonalManager::factory()->create([
            'user_id' => $managerAccount->id,
            'is_active' => true,
        ]);
        $client = User::factory()->create(['personal_manager_id' => $manager->id]);

        $order = Order::factory()->create([
            'uuid' => $uuid,
            'user_id' => $client->id,
            'erp_number' => '29УТ-011777',
            'total_amount' => 0,
        ]);

        return ['order' => $order, 'client' => $client, 'managerAccount' => $managerAccount];
    }

    #[Test]
    public function cancellation_in_order_updated_creates_offer_and_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        ['order' => $order, 'client' => $client, 'managerAccount' => $managerAccount] =
            $this->makeOrderWithManager('11111111-0000-4000-a000-000000000001');

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: false),
            $this->line(2, cancelled: true),
        ], 'msg-shortage-1'))->fire();

        $offer = SubstitutionOffer::sole();
        $this->assertSame($order->id, $offer->order_id);
        $this->assertSame($client->id, $offer->user_id);
        $this->assertSame($managerAccount->id, $offer->manager_user_id);
        $this->assertSame(OfferStatus::PENDING, $offer->status);
        $this->assertTrue($offer->expires_at->is('2026-08-21 10:00:00'));

        $task = CrmTask::sole();
        $this->assertSame($managerAccount->id, $task->assignee_id);
        $this->assertStringContainsString('Недобор по заказу 29УТ-011777', $task->title);
        $this->assertTrue($task->due_at->is('2026-08-14 18:00:00'));
        $this->assertSame($order->id, (int) $task->related_id);
    }

    #[Test]
    public function urgent_amount_shortens_the_deadline_to_two_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        ['order' => $order] = $this->makeOrderWithManager('11111111-0000-4000-a000-000000000002');

        // 5 шт. по 3000 ₽ = 15 000 ₽ > порога срочности 10 000 ₽
        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true, finalPrice: 3000.0),
        ], 'msg-shortage-urgent'))->fire();

        $task = CrmTask::sole();
        $this->assertTrue($task->due_at->is('2026-08-14 12:00:00'));
    }

    #[Test]
    public function second_wave_of_cancellations_extends_the_open_offer_without_duplicates(): void
    {
        ['order' => $order] = $this->makeOrderWithManager('11111111-0000-4000-a000-000000000003');

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: false),
            $this->line(2, cancelled: true),
        ], 'msg-wave-1', revision: 1))->fire();

        // Вторая волна: 1С проверила сборку ещё раз и отменила ещё одну строку.
        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
            $this->line(2, cancelled: true),
        ], 'msg-wave-2', revision: 2))->fire();

        $this->assertSame(1, SubstitutionOffer::count());
        $this->assertSame(1, CrmTask::count());
    }

    #[Test]
    public function redelivery_of_the_same_cancellation_creates_nothing_new(): void
    {
        ['order' => $order] = $this->makeOrderWithManager('11111111-0000-4000-a000-000000000004');

        $payload = $this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
        ], 'msg-redelivery-1');

        $this->makeJob($payload)->fire();

        // Повторная доставка с другим message_id: строка уже отменена,
        // нового события нет, дубли не создаются.
        $payload['message_id'] = 'msg-redelivery-2';
        $this->makeJob($payload)->fire();

        $this->assertSame(1, SubstitutionOffer::count());
        $this->assertSame(1, CrmTask::count());
    }

    #[Test]
    public function confirmed_offer_is_closed_so_a_new_wave_starts_a_new_offer(): void
    {
        ['order' => $order] = $this->makeOrderWithManager('11111111-0000-4000-a000-000000000005');

        SubstitutionOffer::factory()->confirmed()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
        ]);

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
        ], 'msg-after-confirmed'))->fire();

        $this->assertSame(2, SubstitutionOffer::count());
        $this->assertSame(1, SubstitutionOffer::open()->count());
    }

    #[Test]
    public function client_without_manager_falls_back_to_sales_head(): void
    {
        Product::factory()->create(['external_id' => self::PRODUCT_UUID]);

        Role::create(['name' => 'sales-head']);
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $client = User::factory()->create(['personal_manager_id' => null]);
        $order = Order::factory()->create([
            'uuid' => '11111111-0000-4000-a000-000000000006',
            'user_id' => $client->id,
            'total_amount' => 0,
        ]);

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
        ], 'msg-orphan'))->fire();

        $offer = SubstitutionOffer::sole();
        $this->assertNull($offer->manager_user_id);

        $task = CrmTask::sole();
        $this->assertSame($head->id, $task->assignee_id);
    }

    #[Test]
    public function site_order_roundtrip_without_cancellations_creates_no_offers(): void
    {
        Product::factory()->create(['external_id' => self::PRODUCT_UUID]);
        $client = User::factory()->create(['erp_id' => '22222222-0000-4000-a000-000000000001']);

        // Roundtrip: сайтовый заказ вернулся из 1С обычным order.created без отмен.
        $order = Order::factory()->create([
            'uuid' => '11111111-0000-4000-a000-000000000007',
            'user_id' => $client->id,
            'total_amount' => 0,
        ]);

        $this->makeJob([
            'event' => 'order.created',
            'uuid' => $order->uuid,
            'message_id' => 'msg-roundtrip',
            'partner_uuid' => '22222222-0000-4000-a000-000000000001',
            'contractor' => [
                'uuid' => '33333333-0000-4000-a000-000000000001',
                'tax_id' => '7701234567',
                'name' => 'ООО Тест',
            ],
            'items' => [
                $this->line(1, cancelled: false),
            ],
        ])->fire();

        $this->assertSame(0, SubstitutionOffer::count());
        $this->assertSame(0, CrmTask::count());
    }

    #[Test]
    public function disabled_flag_changes_nothing_at_all(): void
    {
        config()->set('substitutions.enabled', false);

        ['order' => $order] = $this->makeOrderWithManager('11111111-0000-4000-a000-000000000008');

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
        ], 'msg-disabled'))->fire();

        $this->assertSame(0, SubstitutionOffer::count());
        $this->assertSame(0, CrmTask::count());

        // Сама отмена строки при этом применилась — флаг гасит только контур замен.
        $this->assertSame(1, $order->items()->where('cancelled', true)->count());
    }
}
