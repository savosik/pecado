<?php

namespace Tests\Feature\Crm;

use App\Models\CrmComment;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        // Фильтруем по комментариям: сами заказ и реализация тоже попадают
        // в ленту как события, а этот тест про сбор комментариев отовсюду.
        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['comment']]))
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
    public function timeline_shows_how_many_files_are_attached_to_a_comment(): void
    {
        Storage::fake(config('media-library.disk_name'));

        $withFiles = CrmComment::factory()->on($this->client)->by($this->manager)->create();
        CrmComment::factory()->on($this->client)->by($this->manager)->create();

        $withFiles->addMedia(UploadedFile::fake()->image('акт.jpg'))
            ->toMediaCollection(CrmAttachments::COLLECTION);
        $withFiles->addMedia(UploadedFile::fake()->image('накладная.jpg'))
            ->toMediaCollection(CrmAttachments::COLLECTION);

        $entries = collect(
            $this->actingAs($this->manager)
                ->getJson(route('crm.clients.timeline', $this->client))
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        // Без счётчика в ленте не видно, что к записи приложены файлы.
        $this->assertSame(2, $entries[$withFiles->id]['attachments_count']);
        $this->assertSame(0, $entries->except($withFiles->id)->first()['attachments_count']);
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

    #[Test]
    public function timeline_includes_orders_and_shipments_in_chronology(): void
    {
        // Хронология строится по бизнес-дате 1С: по created_at вся импортированная
        // история встала бы одним днём поверх свежих записей.
        Order::factory()->create([
            'user_id' => $this->client->id,
            'erp_created_at' => now()->subDays(10),
        ]);
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_created_at' => now()->subDays(5),
        ]);
        CrmComment::factory()->on($this->client)->by($this->manager)->create(['body' => 'Свежая заметка']);

        $data = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data');

        $this->assertSame(['comment', 'shipment', 'order'], array_column($data, 'type'));
    }

    #[Test]
    public function document_entries_are_system_and_not_editable(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->client->id,
            'erp_number' => 'ЗК-77',
            'total_amount' => 12345.60,
            'currency_code' => 'RUB',
        ]);

        $entry = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['order']]))
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($entry['system']);
        $this->assertNull($entry['author']);
        $this->assertFalse($entry['can']['update']);
        $this->assertFalse($entry['can']['delete']);
        $this->assertSame('Заказ №ЗК-77', $entry['title']);
        $this->assertSame('12 345,60 ₽', $entry['amount_label']);
        $this->assertSame($order->status->label(), $entry['status_label']);
    }

    #[Test]
    public function types_filter_narrows_sources(): void
    {
        Order::factory()->create(['user_id' => $this->client->id]);
        Shipment::factory()->create(['user_id' => $this->client->id]);
        CrmComment::factory()->on($this->client)->by($this->manager)->create();

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['shipment']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'shipment');

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['order', 'shipment']]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function unknown_type_in_filter_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['passwords']]))
            ->assertUnprocessable();
    }

    #[Test]
    public function timeline_does_not_expose_foreign_client_documents(): void
    {
        $otherProfile = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $otherProfile->id]);
        Order::factory()->create(['user_id' => $foreign->id]);

        Order::factory()->create(['user_id' => $this->client->id]);

        $data = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', $this->client))
            ->assertOk()
            ->json('data');

        // В своей ленте только свой документ — чужой не приезжает даже соседней записью.
        $this->assertCount(1, $data);
        $this->assertSame($this->client->id, Order::find($data[0]['id'])->user_id);
    }

    #[Test]
    public function soft_deleted_order_disappears_from_timeline(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['order']]))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $order->delete();

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['order']]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function pagination_is_stable_across_pages(): void
    {
        // Десять документов одной датой: если порядок недетерминирован, вторая
        // страница вернёт записи, уже показанные на первой.
        $sameMoment = now()->subDay();

        foreach (range(1, 5) as $i) {
            Order::factory()->create(['user_id' => $this->client->id, 'erp_created_at' => $sameMoment]);
            Shipment::factory()->create(['user_id' => $this->client->id, 'erp_created_at' => $sameMoment]);
        }

        $keys = function (int $page): array {
            $data = $this->actingAs($this->manager)
                ->getJson(route('crm.clients.timeline', [$this->client, 'per_page' => 4, 'page' => $page]))
                ->assertOk()
                ->json('data');

            return array_map(static fn (array $row): string => $row['type'].'-'.$row['id'], $data);
        };

        $all = array_merge($keys(1), $keys(2), $keys(3));

        $this->assertCount(10, $all);
        $this->assertSame($all, array_unique($all), 'Записи повторяются между страницами');
    }
}
