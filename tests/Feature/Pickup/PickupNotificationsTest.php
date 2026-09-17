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
