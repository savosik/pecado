<?php

namespace Tests\Feature\Crm;

use App\Models\CrmComment;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Главное требование карточки: в ленте клиента видны все комментарии,
 * где бы они ни были оставлены.
 */
class ClientTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $profile;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->profile->id]);
    }

    #[Test]
    public function timeline_collects_comments_from_client_order_and_shipment(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);
        $shipment = Shipment::factory()->create(['user_id' => $this->client->id]);

        CrmComment::factory()->on($this->client)->by($this->manager)->create(['body' => 'По клиенту']);
        CrmComment::factory()->on($order)->by($this->manager)->create(['body' => 'По заказу']);
        CrmComment::factory()->on($shipment)->by($this->manager)->create(['body' => 'По реализации']);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $types = collect($response->json('data'))->pluck('entity.type')->sort()->values()->all();
        $this->assertSame(['client', 'order', 'shipment'], $types);
    }

    #[Test]
    public function pinned_comment_goes_first(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        CrmComment::factory()->on($this->client)->by($this->manager)->create(['body' => 'Обычный']);
        CrmComment::factory()->on($order)->by($this->manager)->pinned()->create(['body' => 'Важное']);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Важное')
            ->assertJsonPath('data.0.is_pinned', true);
    }

    #[Test]
    public function comment_on_order_without_client_stays_out_of_any_timeline(): void
    {
        $partnerOrder = Order::factory()->create(['user_id' => null]);
        CrmComment::factory()->on($partnerOrder)->by($this->manager)->create();
        CrmComment::factory()->on($this->client)->by($this->manager)->create();

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function foreign_client_timeline_returns_404(): void
    {
        $otherProfile = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $otherProfile->id]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function entity_link_is_hidden_from_crm_only_roles(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);
        CrmComment::factory()->on($order)->by($this->manager)->create();

        // sales-manager ходит в админку — ссылка на заказ ему полезна.
        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->assertJsonPath('data.0.entity.url', route('admin.orders.show', $order->id));

        // sales-head в /admin намеренно не пускают: вместо ссылки в 403
        // показываем подпись без URL.
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->assertJsonPath('data.0.entity.url', null)
            ->assertJsonPath('data.0.entity.title', 'Заказ №'.$order->number);
    }

    #[Test]
    public function timeline_survives_soft_deleted_entity(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);
        CrmComment::factory()->on($order)->by($this->manager)->create(['body' => 'До удаления заказа']);

        $order->delete();

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity', null)
            ->assertJsonPath('data.0.body', 'До удаления заказа');
    }
}
