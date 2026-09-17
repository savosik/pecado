<?php

namespace Tests\Feature\Pickup;

use App\Enums\DeliveryMethod;
use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\GoodsIssue;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Pickup\HandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\EnablesClientNotifications;
use Tests\TestCase;

/** pick-12: письма клиенту «собран, ждёт выдачи» и «выдан курьеру». */
class PickupNotificationsTest extends TestCase
{
    use EnablesClientNotifications, PickupTestHelpers, RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.handover_since' => null, 'mail_stream.enabled' => true]);

        // У письма потока обязателен автор — персональный менеджер клиента.
        $manager = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $manager->id]);
        Company::factory()->create(['user_id' => $this->client->id]);
    }

    private function emails(string $event): int
    {
        return CrmEmail::query()->where('origin_event', $event)->count();
    }

    #[Test]
    public function ready_letter_goes_by_default_when_whole_order_is_assembled(): void
    {
        $order = $this->pickupOrder($this->client);
        $first = $this->goodsIssueFor($order, GoodsIssue::STATUS_TO_SHIP);
        $second = $this->goodsIssueFor($order, GoodsIssue::STATUS_TO_PICK);

        $this->moveIssue($first, GoodsIssue::STATUS_SHIPPED);
        $this->assertSame(0, $this->emails('orders.ready_for_pickup'), 'собрана только часть заказа — курьера слать рано');

        $this->moveIssue($second, GoodsIssue::STATUS_SHIPPED);
        $this->assertSame(1, $this->emails('orders.ready_for_pickup'));
    }

    #[Test]
    public function delivery_orders_and_switched_off_feature_stay_silent(): void
    {
        $delivery = $this->goodsIssueFor($this->pickupOrder($this->client, ['delivery_method' => DeliveryMethod::DELIVERY]), GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($delivery, GoodsIssue::STATUS_SHIPPED);

        config(['pickup.enabled' => false]);
        $pickup = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($pickup, GoodsIssue::STATUS_SHIPPED);

        $this->assertSame(0, $this->emails('orders.ready_for_pickup'));
    }

    #[Test]
    public function rollback_and_reassembly_is_a_new_occasion_not_a_duplicate(): void
    {
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_SHIP);

        $this->moveIssue($issue, GoodsIssue::STATUS_SHIPPED);
        $this->moveIssue($issue, GoodsIssue::STATUS_TO_PICK);
        $this->travel(30)->minutes();
        $this->moveIssue($issue->fresh(), GoodsIssue::STATUS_SHIPPED);

        $this->assertSame(2, $this->emails('orders.ready_for_pickup'));
    }

    #[Test]
    public function several_orders_in_one_set_give_one_letter_not_one_per_order(): void
    {
        // Совместимость с будущим объединением отгрузок: один ордер на несколько заказов — одно письмо.
        $a = $this->pickupOrder($this->client, ['erp_number' => '29УТ-030001']);
        $b = $this->pickupOrder($this->client, ['erp_number' => '29УТ-030002']);
        $issue = $this->goodsIssueFor([$a, $b], GoodsIssue::STATUS_TO_SHIP);

        $this->moveIssue($issue, GoodsIssue::STATUS_SHIPPED);

        $this->assertSame(1, $this->emails('orders.ready_for_pickup'));
        $this->assertStringContainsString('29УТ-030001, 29УТ-030002', (string) CrmEmail::query()->where('origin_event', 'orders.ready_for_pickup')->value('subject'));
    }

    #[Test]
    public function shortfall_during_picking_tells_client_to_order_something_else(): void
    {
        $order = $this->pickupOrder($this->client);
        event(new \App\Events\Order\OrderItemsCancelled($order, [['name' => 'Массажное масло', 'quantity' => 2.0]]));

        $email = CrmEmail::query()->where('origin_event', 'orders.items_unavailable')->first();
        $this->assertNotNull($email);
        $this->assertStringContainsString('Массажное масло — 2 шт.', (string) $email->body_html.$email->body_text);

        // В окне резерва строки уменьшает сам клиент — это не недобор склада.
        $reserved = $this->pickupOrder($this->client, ['reserve' => true, 'reserved_until' => now()->addDay()]);
        event(new \App\Events\Order\OrderItemsCancelled($reserved, [['name' => 'Свеча', 'quantity' => 1.0]]));
        $this->assertSame(1, $this->emails('orders.items_unavailable'));
    }

    #[Test]
    public function long_waiting_set_is_reminded_once_per_step(): void
    {
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_SHIPPED, ['status_changed_at' => now()->subDays(4)]);
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_SHIPPED, ['status_changed_at' => now()->subDay()]);

        $this->artisan('pickup:remind-waiting')->assertSuccessful();
        $this->artisan('pickup:remind-waiting')->assertSuccessful();
        $this->assertSame(1, $this->emails('orders.pickup_waiting'), 'ступень 3 дня — одно письмо, повторный запуск дубля не даёт');

        $this->travel(4)->days();
        $this->artisan('pickup:remind-waiting')->assertSuccessful();
        // Первый заказ дошёл до ступени 7, второй — до ступени 3. Поток писем склеивает поводы одного клиента
        // в окне склейки, поэтому писем не три, а два: клиент получает одно напоминание про оба заказа.
        $this->assertSame(2, $this->emails('orders.pickup_waiting'));
    }

    #[Test]
    public function handed_over_letter_is_opt_in(): void
    {
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client));
        $keeper = User::factory()->create();

        app(HandoverService::class)->issue($issue, $keeper, 'manual');
        $this->assertSame(0, $this->emails('orders.handed_over'), 'выключено умолчанием');

        $second = $this->goodsIssueFor($this->pickupOrder($this->client));
        $this->enableNotificationsFor($this->client, ['orders.handed_over']);
        app(HandoverService::class)->issue($second, $keeper, 'manual', null, ['recipient_name' => 'Олег']);

        $this->assertSame(1, $this->emails('orders.handed_over'));
    }
}
