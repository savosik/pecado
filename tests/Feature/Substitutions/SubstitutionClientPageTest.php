<?php

namespace Tests\Feature\Substitutions;

use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderType;
use App\Enums\Substitution\OfferStatus;
use App\Enums\Substitution\SignalEvent;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\SubstitutionEvent;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;
use App\Models\User;
use App\Services\Erp\ErpMessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Клиентская страница подборки и постинг заказа-замены (sub-04).
 *
 * Просмотр — по подписанной ссылке без входа, согласование — только владельцем;
 * заказ-замена уходит в 1С обычным order.created, валидным по JSON Schema.
 */
class SubstitutionClientPageTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config(['substitutions.enabled' => true]);

        $priceServiceMock = $this->createMock(PriceServiceInterface::class);
        $priceServiceMock->method('getPriceResult')
            ->willReturn(new PriceResult(90.0, 100.0, 10.0, true));
        $priceServiceMock->method('getUserPrice')->willReturn(90.0);
        $priceServiceMock->method('convertPrice')->willReturnArgument(0);
        $this->app->instance(PriceServiceInterface::class, $priceServiceMock);

        $stockServiceMock = $this->createMock(StockServiceInterface::class);
        $stockServiceMock->method('getStock')->willReturn(['available' => 50, 'preorder' => 50]);
        $stockServiceMock->method('getAvailableStock')->willReturn(50);
        $stockServiceMock->method('getPreorderStock')->willReturn(50);
        $this->app->instance(StockServiceInterface::class, $stockServiceMock);

        $this->client = User::factory()->create(['erp_id' => 'client-erp-uuid']);
        $this->company = Company::factory()->create(['user_id' => $this->client->id, 'is_default' => true]);
    }

    /**
     * @return array{offer: SubstitutionOffer, order: Order, line: OrderItem, candidate: SubstitutionOfferItem}
     */
    private function makeOfferWithCandidate(): array
    {
        $order = Order::factory()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'erp_number' => '29УТ-011777',
            'delivery_address' => 'г. Тюмень, ул. Республики, 1',
        ]);

        $cancelledProduct = Product::factory()->create(['external_id' => 'p-cancelled']);
        $candidateProduct = Product::factory()->create(['external_id' => 'p-candidate']);

        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $cancelledProduct->id,
            'cancelled' => true,
            'quantity' => 5,
            'final_price' => 100,
            'subtotal' => 500,
        ]);

        $offer = SubstitutionOffer::factory()->create([
            'order_id' => $order->id,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
        ]);

        $candidate = SubstitutionOfferItem::factory()->create([
            'offer_id' => $offer->id,
            'source_order_item_id' => $line->id,
            'product_id' => $candidateProduct->id,
            'suggested_quantity' => 5,
            'price_snapshot' => 95,
        ]);

        return ['offer' => $offer, 'order' => $order, 'line' => $line, 'candidate' => $candidate];
    }

    private function signedUrl(SubstitutionOffer $offer): string
    {
        return URL::temporarySignedRoute('substitutions.show', $offer->expires_at, ['offer' => $offer->uuid]);
    }

    #[Test]
    public function signed_link_opens_the_page_without_login_and_marks_it_viewed(): void
    {
        ['offer' => $offer] = $this->makeOfferWithCandidate();

        $response = $this->get($this->signedUrl($offer));

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame('open', $props['state']);
        $this->assertCount(1, $props['offer']['lines']);

        $offer->refresh();
        $this->assertSame(OfferStatus::VIEWED, $offer->status);
        $this->assertNotNull($offer->viewed_at);
    }

    #[Test]
    public function link_without_signature_is_rejected(): void
    {
        ['offer' => $offer] = $this->makeOfferWithCandidate();

        $this->get("/substitutions/{$offer->uuid}")->assertForbidden();
    }

    #[Test]
    public function expired_offer_shows_an_honest_stub_with_manager_contacts(): void
    {
        ['offer' => $offer] = $this->makeOfferWithCandidate();

        // Ссылка ещё валидна по подписи, но подборка уже закрыта менеджером.
        $url = $this->signedUrl($offer);
        $offer->update(['status' => OfferStatus::DISMISSED, 'dismiss_reason' => 'тест']);

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('inactive', $response->viewData('page')['props']['state']);
    }

    #[Test]
    public function confirmation_requires_an_authenticated_owner(): void
    {
        ['offer' => $offer, 'line' => $line, 'candidate' => $candidate] = $this->makeOfferWithCandidate();

        $payload = [
            'choices' => [
                ['source_order_item_id' => $line->id, 'type' => 'candidate', 'candidate_id' => $candidate->id, 'quantity' => 5],
            ],
        ];

        // Без входа — 401: пересланное письмо не даёт право постить заказы.
        $this->postJson("/substitutions/{$offer->uuid}/confirm", $payload)->assertUnauthorized();

        // Чужой аккаунт — 403.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->postJson("/substitutions/{$offer->uuid}/confirm", $payload)
            ->assertForbidden();
    }

    #[Test]
    public function confirmation_creates_a_linked_replacement_order_with_a_valid_erp_payload(): void
    {
        Queue::fake();

        ['offer' => $offer, 'order' => $order, 'line' => $line, 'candidate' => $candidate] = $this->makeOfferWithCandidate();

        $response = $this->actingAs($this->client)
            ->postJson("/substitutions/{$offer->uuid}/confirm", [
                'choices' => [
                    // Кап: клиент просит 9 при отменённых 5 — получит 5.
                    ['source_order_item_id' => $line->id, 'type' => 'candidate', 'candidate_id' => $candidate->id, 'quantity' => 9],
                ],
            ]);

        $response->assertOk()->assertJsonPath('confirmed', true);

        $offer->refresh();
        $this->assertSame(OfferStatus::CONFIRMED, $offer->status);
        $this->assertNotEmpty($offer->result_order_ids);

        $replacement = Order::find($offer->result_order_ids[0]);
        $this->assertSame($order->id, $replacement->replacement_for_order_id);
        $this->assertSame('Замена недоборов по заказу 29УТ-011777', $replacement->manager_comment);
        $this->assertSame($order->delivery_address, $replacement->delivery_address);

        $replacementItem = $replacement->items->first();
        $this->assertSame($line->id, $replacementItem->replaces_order_item_id);
        $this->assertSame(5, $replacementItem->quantity);

        $candidate->refresh();
        $this->assertTrue($candidate->chosen);
        $this->assertSame(5, $candidate->chosen_quantity);
        $this->assertSame(SignalEvent::CLIENT_CHOSEN, SubstitutionEvent::sole()->event);

        // Протокол не меняется: в 1С уходит обычный order.created, валидный по схеме.
        $validator = app(ErpMessageValidator::class);
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($validator) {
            return ($job->payload['event'] ?? null) === 'order.created'
                && $validator->validateOutbound('order.created', $job->payload)['valid'] === true;
        });
    }

    #[Test]
    public function mixed_choice_with_a_defect_batch_creates_two_linked_orders(): void
    {
        Queue::fake();

        ['offer' => $offer, 'order' => $order, 'line' => $line, 'candidate' => $candidate] = $this->makeOfferWithCandidate();

        $defectProduct = Product::factory()->create(['external_id' => 'p-defect']);
        $defect = ProductDefect::factory()->sellable(70.0)->create([
            'product_id' => $defectProduct->id,
            'quantity' => 10,
        ]);

        $secondLineProduct = Product::factory()->create(['external_id' => 'p-cancelled-2']);
        $secondLine = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $secondLineProduct->id,
            'cancelled' => true,
            'quantity' => 3,
            'final_price' => 80,
            'subtotal' => 240,
        ]);

        $defectCandidate = SubstitutionOfferItem::factory()->defect($defect)->create([
            'offer_id' => $offer->id,
            'source_order_item_id' => $secondLine->id,
            'suggested_quantity' => 3,
        ]);

        $response = $this->actingAs($this->client)
            ->postJson("/substitutions/{$offer->uuid}/confirm", [
                'choices' => [
                    ['source_order_item_id' => $line->id, 'type' => 'candidate', 'candidate_id' => $candidate->id, 'quantity' => 5],
                    ['source_order_item_id' => $secondLine->id, 'type' => 'candidate', 'candidate_id' => $defectCandidate->id, 'quantity' => 3],
                ],
            ]);

        $response->assertOk();

        $offer->refresh();
        $this->assertCount(2, $offer->result_order_ids);

        $orders = Order::whereIn('id', $offer->result_order_ids)->get();
        $this->assertEqualsCanonicalizing(
            [OrderType::ORDER, OrderType::DEFECT],
            $orders->pluck('type')->all(),
        );

        foreach ($orders as $replacement) {
            $this->assertSame($order->id, $replacement->replacement_for_order_id);
        }

        $defectOrder = $orders->firstWhere('type', OrderType::DEFECT);
        $this->assertSame($defect->id, $defectOrder->items->first()->product_defect_id);
        $this->assertSame($secondLine->id, $defectOrder->items->first()->replaces_order_item_id);

        Queue::assertPushed(PublishOrderToErpJob::class, 2);
    }

    #[Test]
    public function double_submit_returns_already_confirmed_instead_of_a_second_order(): void
    {
        Queue::fake();

        ['offer' => $offer, 'line' => $line, 'candidate' => $candidate] = $this->makeOfferWithCandidate();

        $payload = [
            'choices' => [
                ['source_order_item_id' => $line->id, 'type' => 'candidate', 'candidate_id' => $candidate->id, 'quantity' => 5],
            ],
        ];

        $this->actingAs($this->client)->postJson("/substitutions/{$offer->uuid}/confirm", $payload)->assertOk();

        $second = $this->actingAs($this->client)->postJson("/substitutions/{$offer->uuid}/confirm", $payload);

        $second->assertOk()->assertJsonPath('already_confirmed', true);
        $this->assertSame(1, Order::whereNotNull('replacement_for_order_id')->count());
    }

    #[Test]
    public function declining_all_lines_confirms_the_offer_without_orders_and_records_skips(): void
    {
        ['offer' => $offer, 'line' => $line] = $this->makeOfferWithCandidate();

        $response = $this->actingAs($this->client)
            ->postJson("/substitutions/{$offer->uuid}/confirm", [
                'choices' => [
                    ['source_order_item_id' => $line->id, 'type' => 'none'],
                ],
            ]);

        $response->assertOk()->assertJsonPath('confirmed', true);

        $offer->refresh();
        $this->assertSame(OfferStatus::CONFIRMED, $offer->status);
        $this->assertNull($offer->result_order_ids);
        $this->assertSame(SignalEvent::CLIENT_SKIPPED, SubstitutionEvent::sole()->event);
    }
}
