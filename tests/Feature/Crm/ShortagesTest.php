<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\TaskStatus;
use App\Enums\Substitution\LinkSource;
use App\Enums\Substitution\OfferStatus;
use App\Enums\Substitution\SignalEvent;
use App\Jobs\SendCrmEmailJob;
use App\Models\CrmTask;
use App\Models\EntitySubscription;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductSubstitution;
use App\Models\SubstitutionEvent;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * CRM-раздел «Недоборы»: очередь, карточка, кандидаты, исходы (sub-03).
 */
class ShortagesTest extends TestCase
{
    use RefreshDatabase, RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        config(['substitutions.enabled' => true]);

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $managerProfile->id,
            'email' => 'client@example.com',
        ]);
    }

    /**
     * @return array{offer: SubstitutionOffer, order: Order, line: OrderItem}
     */
    private function makeOffer(?User $client = null, ?User $manager = null): array
    {
        $client ??= $this->client;
        $manager ??= $this->manager;

        $order = Order::factory()->create([
            'user_id' => $client->id,
            'erp_number' => '29УТ-011777',
        ]);

        $product = Product::factory()->create();

        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'cancelled' => true,
            'quantity' => 5,
            'final_price' => 100,
            'subtotal' => 500,
        ]);

        $offer = SubstitutionOffer::factory()->create([
            'order_id' => $order->id,
            'user_id' => $client->id,
            'manager_user_id' => $manager->id,
        ]);

        return ['offer' => $offer, 'order' => $order, 'line' => $line];
    }

    #[Test]
    public function queue_is_gated_by_permission(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get('/crm/shortages')->assertRedirect('/');
    }

    #[Test]
    public function manager_sees_only_own_offers_in_the_queue(): void
    {
        ['offer' => $mine] = $this->makeOffer();

        // Чужая подборка: другой менеджер и его клиент.
        $otherManagerAccount = User::factory()->create();
        $otherProfile = PersonalManager::factory()->create(['user_id' => $otherManagerAccount->id]);
        $otherClient = User::factory()->create(['personal_manager_id' => $otherProfile->id]);
        $this->makeOffer($otherClient, $otherManagerAccount);

        $response = $this->actingAs($this->manager)->get('/crm/shortages');

        $response->assertOk();
        $offers = $response->viewData('page')['props']['offers']['data'];
        $this->assertCount(1, $offers);
        $this->assertSame($mine->id, $offers[0]['id']);
    }

    #[Test]
    public function card_shows_cancelled_lines_and_draft(): void
    {
        ['offer' => $offer] = $this->makeOffer();

        $response = $this->actingAs($this->manager)->get("/crm/shortages/{$offer->id}");

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['offer']['lines']);
        $this->assertSame(5, $props['offer']['lines'][0]['quantity']);
        $this->assertStringContainsString('29УТ-011777', $props['draft']['subject']);
    }

    #[Test]
    public function manual_candidate_creates_manual_link_in_the_reference(): void
    {
        ['offer' => $offer, 'line' => $line] = $this->makeOffer();
        $candidateProduct = Product::factory()->create();

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/candidates", [
                'source_order_item_id' => $line->id,
                'product_id' => $candidateProduct->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('substitution_offer_items', [
            'offer_id' => $offer->id,
            'source_order_item_id' => $line->id,
            'product_id' => $candidateProduct->id,
            'kind' => 'manual',
        ]);

        $link = ProductSubstitution::sole();
        $this->assertSame($line->product_id, $link->from_product_id);
        $this->assertSame($candidateProduct->id, $link->to_product_id);
        $this->assertSame(LinkSource::MANUAL, $link->source);
        $this->assertNotNull($link->confirmed_at);
    }

    #[Test]
    public function removing_a_candidate_records_the_negative_signal(): void
    {
        ['offer' => $offer, 'line' => $line] = $this->makeOffer();

        $item = SubstitutionOfferItem::factory()->create([
            'offer_id' => $offer->id,
            'source_order_item_id' => $line->id,
        ]);

        $this->actingAs($this->manager)
            ->deleteJson("/crm/shortages/{$offer->id}/candidates/{$item->id}")
            ->assertOk();

        $this->assertNotNull($item->fresh()->removed_by_manager_at);
        $this->assertSame(SignalEvent::MANAGER_REMOVED, SubstitutionEvent::sole()->event);
    }

    #[Test]
    public function dismiss_outcome_requires_reason_and_closes_the_task(): void
    {
        ['offer' => $offer, 'order' => $order] = $this->makeOffer();

        $task = CrmTask::factory()->create([
            'title' => 'Недобор по заказу 29УТ-011777 — тест',
            'assignee_id' => $this->manager->id,
            'author_id' => $this->manager->id,
            'status' => TaskStatus::OPEN,
        ]);
        $task->related()->associate($order)->save();

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/outcome", ['type' => 'dismiss'])
            ->assertStatus(422);

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/outcome", [
                'type' => 'dismiss',
                'reason' => 'Клиент отказался, вернём деньги',
            ])
            ->assertOk();

        $this->assertSame(OfferStatus::DISMISSED, $offer->fresh()->status);
        $this->assertSame(TaskStatus::DONE, $task->fresh()->status);
    }

    #[Test]
    public function send_outcome_fails_politely_when_outbound_is_disabled(): void
    {
        config(['notifications.mail.features.crm_outbound' => false]);

        ['offer' => $offer] = $this->makeOffer();

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/outcome", [
                'type' => 'send',
                'subject' => 'Тест',
                'body_html' => '<p>Тест</p>',
            ])
            ->assertStatus(422);

        $this->assertNull($offer->fresh()->sent_at);
    }

    #[Test]
    public function send_outcome_sends_the_letter_and_stamps_sent_at(): void
    {
        Bus::fake([SendCrmEmailJob::class]);
        config(['notifications.mail.features.crm_outbound' => true]);

        ['offer' => $offer] = $this->makeOffer();

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/outcome", [
                'type' => 'send',
                'subject' => 'Заказ 29УТ-011777: подборка замен',
                'body_html' => '<p>Здравствуйте!</p>',
            ])
            ->assertOk();

        Bus::assertDispatched(SendCrmEmailJob::class);

        $offer->refresh();
        $this->assertNotNull($offer->sent_at);
        $this->assertNotNull($offer->crm_email_id);
        $this->assertSame([$this->client->email], $offer->draftEmail->to);
        $this->assertSame($this->manager->email, $offer->draftEmail->reply_to);
    }

    /**
     * ProductSelector рендерит ответ напрямую через suggestions.map() и шлёт
     * параметр `query` — обёртка {data: []} роняла карточку на проде.
     */
    #[Test]
    public function product_search_returns_flat_array_for_the_selector(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson('/crm/shortages/products/search?query=вибро');

        $response->assertOk();
        $this->assertIsList($response->json());

        // Короткий запрос — тоже плоский массив, а не {data: []}.
        $this->actingAs($this->manager)
            ->getJson('/crm/shortages/products/search?query=а')
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function draft_prefills_client_email_and_order_subscription_addresses(): void
    {
        ['offer' => $offer] = $this->makeOffer();

        EntitySubscription::create([
            'user_id' => $this->client->id,
            'section' => 'orders',
            'channel' => 'email',
            'destination' => 'buyer@example.com',
            'is_active' => true,
        ]);

        // Неактивная подписка и чужой раздел в поле «Кому» не попадают.
        EntitySubscription::create([
            'user_id' => $this->client->id,
            'section' => 'orders',
            'channel' => 'email',
            'destination' => 'inactive@example.com',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->manager)->get("/crm/shortages/{$offer->id}");

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(['client@example.com', 'buyer@example.com'], $props['draft']['to']);

        $optionEmails = array_column($props['recipientOptions'], 'email');
        $this->assertContains('buyer@example.com', $optionEmails);
        $this->assertContains('manager@pecado.ru', $optionEmails);
        $this->assertNotContains('inactive@example.com', $optionEmails);
    }

    #[Test]
    public function refreshing_the_draft_puts_the_chosen_candidate_into_the_letter(): void
    {
        ['offer' => $offer, 'line' => $line] = $this->makeOffer();
        $candidateProduct = Product::factory()->create(['name' => 'Замена-Кандидат-123']);

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/candidates", [
                'source_order_item_id' => $line->id,
                'product_id' => $candidateProduct->id,
            ])
            ->assertOk();

        $response = $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/draft/refresh");

        $response->assertOk();
        $this->assertStringContainsString('Замена-Кандидат-123', $response->json('body_html'));
        $this->assertStringContainsString('Предлагаем на замену', $response->json('body_html'));
    }

    #[Test]
    public function send_outcome_delivers_to_the_addresses_picked_by_the_manager(): void
    {
        Bus::fake([SendCrmEmailJob::class]);
        config(['notifications.mail.features.crm_outbound' => true]);

        ['offer' => $offer] = $this->makeOffer();

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/outcome", [
                'type' => 'send',
                'subject' => 'Заказ 29УТ-011777: подборка замен',
                'body_html' => '<p>Здравствуйте!</p>',
                'to' => ['client@example.com', 'buyer@example.com', 'client@example.com'],
            ])
            ->assertOk();

        Bus::assertDispatched(SendCrmEmailJob::class);

        // Дубликат схлопнут, порядок сохранён.
        $this->assertSame(
            ['client@example.com', 'buyer@example.com'],
            $offer->refresh()->draftEmail->to,
        );
    }

    #[Test]
    public function send_outcome_rejects_a_malformed_address(): void
    {
        config(['notifications.mail.features.crm_outbound' => true]);

        ['offer' => $offer] = $this->makeOffer();

        $this->actingAs($this->manager)
            ->postJson("/crm/shortages/{$offer->id}/outcome", [
                'type' => 'send',
                'subject' => 'Тест',
                'body_html' => '<p>Тест</p>',
                'to' => ['не-адрес'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to.0']);

        $this->assertNull($offer->fresh()->sent_at);
    }
}
