<?php

namespace Tests\Feature\Substitutions;

use App\Enums\Substitution\CandidateKind;
use App\Enums\Substitution\LinkSource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductSubstitution;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;
use App\Models\User;
use App\Services\Substitution\SubstitutionLearningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Справочник замен: самообучение и очередь подтверждений (sub-07).
 */
class SubstitutionLearningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'substitutions.enabled' => true,
            // Страховка: предразметка в тестах не должна ходить в OpenRouter.
            'search.embedder.api_key' => null,
        ]);
        Http::fake();
    }

    /**
     * @return array{offer: SubstitutionOffer, from: Product, to: Product}
     */
    private function makeConfirmedChoice(): array
    {
        $from = Product::factory()->create();
        $to = Product::factory()->create();

        $order = Order::factory()->create();
        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $from->id,
            'cancelled' => true,
        ]);

        $offer = SubstitutionOffer::factory()->confirmed()->create(['order_id' => $order->id]);

        SubstitutionOfferItem::factory()->chosen(2)->create([
            'offer_id' => $offer->id,
            'source_order_item_id' => $line->id,
            'product_id' => $to->id,
            'kind' => CandidateKind::FUNCTIONAL,
            'reason' => 'Стропа другого бренда, то же назначение',
        ]);

        return ['offer' => $offer, 'from' => $from, 'to' => $to];
    }

    #[Test]
    public function a_confirmed_choice_creates_a_learned_link_awaiting_review(): void
    {
        ['offer' => $offer, 'from' => $from, 'to' => $to] = $this->makeConfirmedChoice();

        app(SubstitutionLearningService::class)->recordClientChoice($offer);

        $link = ProductSubstitution::sole();
        $this->assertSame($from->id, $link->from_product_id);
        $this->assertSame($to->id, $link->to_product_id);
        $this->assertSame(LinkSource::LEARNED, $link->source);
        $this->assertNull($link->confirmed_at);
        $this->assertSame('Стропа другого бренда, то же назначение', $link->note);

        // Идемпотентно: повторное согласование лишь усиливает уверенность.
        app(SubstitutionLearningService::class)->recordClientChoice($offer);

        $this->assertSame(1, ProductSubstitution::count());
        $this->assertSame(70, $link->fresh()->score);
    }

    #[Test]
    public function a_rejected_pair_does_not_resurrect(): void
    {
        ['offer' => $offer, 'from' => $from, 'to' => $to] = $this->makeConfirmedChoice();

        ProductSubstitution::factory()->rejected()->create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
        ]);

        app(SubstitutionLearningService::class)->recordClientChoice($offer);

        $link = ProductSubstitution::sole();
        $this->assertNotNull($link->rejected_at);
        $this->assertNull($link->confirmed_at);
    }

    #[Test]
    public function the_review_queue_approves_and_rejects_links(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $manager->id]);

        $awaiting = ProductSubstitution::factory()->awaitingReview()->create();
        $second = ProductSubstitution::factory()->awaitingReview()->create();

        $response = $this->actingAs($manager)->get('/crm/shortages/links');
        $response->assertOk();
        $this->assertCount(2, $response->viewData('page')['props']['links']['data']);

        $this->actingAs($manager)
            ->postJson("/crm/shortages/links/{$awaiting->id}/approve")
            ->assertOk();
        $this->assertNotNull($awaiting->fresh()->confirmed_at);

        $this->actingAs($manager)
            ->postJson("/crm/shortages/links/{$second->id}/reject")
            ->assertOk();
        $this->assertNotNull($second->fresh()->rejected_at);

        // Отклонённая связь исчезла из очереди и не обрабатывается повторно.
        $this->actingAs($manager)
            ->postJson("/crm/shortages/links/{$second->id}/approve")
            ->assertNotFound();
    }

    #[Test]
    public function premark_creates_ai_links_for_the_deficit_core(): void
    {
        $category = \App\Models\Category::factory()->create();

        $core = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 1000,
            'name' => 'Лубрикант дефицитный',
        ]);
        $analog = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 950,
            'name' => 'Лубрикант аналог',
        ]);

        // Два недобора за окно — товар попадает в ядро.
        foreach (range(1, 2) as $i) {
            $order = Order::factory()->create();
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $core->id,
                'cancelled' => true,
            ]);
        }

        $this->artisan('substitutions:premark')->assertSuccessful();

        $link = ProductSubstitution::sole();
        $this->assertSame($core->id, $link->from_product_id);
        $this->assertSame($analog->id, $link->to_product_id);
        $this->assertSame(LinkSource::AI, $link->source);
        $this->assertNull($link->confirmed_at);
    }
}
