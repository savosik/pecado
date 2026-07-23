<?php

namespace Tests\Feature\Crm;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Gap-анализ CRM: партнёры/контрагенты без покупок бренда/категории/товара.
 */
class GapAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-dashboard.view', 'crm-analytics.view', 'crm-clients-all.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeHead(): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('crm-analytics.view');
        $actor->givePermissionTo('crm-clients-all.view');
        PersonalManager::create(['name' => 'РОП', 'user_id' => $actor->id]);

        return $actor->fresh();
    }

    private function makeClient(): User
    {
        $card = PersonalManager::create(['name' => 'Менеджер '.Str::random(4), 'user_id' => User::factory()->create()->id]);

        return User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function ship(User $client, Product $product, array $attrs = [], int $qty = 1, float $total = 1000): Shipment
    {
        $shipment = Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today(),
            'erp_created_at' => Carbon::today(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ], $attrs));

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price' => $total / max(1, $qty),
            'total' => $total,
            'subtotal' => $total,
            'product_name_snapshot' => $product->name,
            'brand_name_snapshot' => optional($product->brand)->name,
        ]);

        return $shipment->fresh();
    }

    private function gap(User $actor, array $query): array
    {
        $url = '/crm/analytics/gap?'.http_build_query($query);
        $response = $this->actingAs($actor)->getJson($url);
        $response->assertOk();

        return $response->json();
    }

    #[Test]
    public function lists_partners_without_the_brand(): void
    {
        $head = $this->makeHead();

        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $pa = Product::factory()->create(['brand_id' => $brandA->id]);
        $pb = Product::factory()->create(['brand_id' => $brandB->id]);

        $buyerOfA = $this->makeClient();
        $buyerOfB = $this->makeClient();
        $this->ship($buyerOfA, $pa);
        $this->ship($buyerOfB, $pb);

        $result = $this->gap($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'subject' => 'partner',
            'exclude_dimension' => 'brand',
            'exclude_value' => $brandA->id,
            'exclude_window' => 'all',
        ]);

        $ids = collect($result['rows'])->pluck('id')->all();
        $this->assertContains($buyerOfB->id, $ids);
        $this->assertNotContains($buyerOfA->id, $ids);
        $this->assertSame(2, $result['summary']['base']);
        $this->assertSame(1, $result['summary']['bought']);
        $this->assertSame(1, $result['summary']['gap']);
    }

    #[Test]
    public function category_exclusion_covers_subtree(): void
    {
        $head = $this->makeHead();

        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $productInChild = Product::factory()->create(['category_id' => $child->id]);
        $otherProduct = Product::factory()->create(['category_id' => Category::factory()->create()->id]);

        $buyerInChild = $this->makeClient();
        $buyerOther = $this->makeClient();
        $this->ship($buyerInChild, $productInChild);
        $this->ship($buyerOther, $otherProduct);

        // Исключаем родительскую категорию — покупка подкатегории должна засчитаться.
        $result = $this->gap($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'subject' => 'partner',
            'exclude_dimension' => 'category',
            'exclude_value' => $parent->id,
            'exclude_window' => 'all',
        ]);

        $ids = collect($result['rows'])->pluck('id')->all();
        $this->assertContains($buyerOther->id, $ids);
        $this->assertNotContains($buyerInChild->id, $ids);
    }

    #[Test]
    public function include_condition_narrows_to_relevant_buyers(): void
    {
        $head = $this->makeHead();

        $catTarget = Category::factory()->create();
        $brandX = Brand::factory()->create();

        // Товар нужной категории, но НЕ бренда X.
        $catProductNoBrand = Product::factory()->create(['category_id' => $catTarget->id, 'brand_id' => Brand::factory()->create()->id]);
        // Товар бренда X (и той же категории).
        $brandXProduct = Product::factory()->create(['category_id' => $catTarget->id, 'brand_id' => $brandX->id]);
        // Товар вне целевой категории.
        $unrelated = Product::factory()->create(['category_id' => Category::factory()->create()->id]);

        $relevant = $this->makeClient();    // берёт категорию, не берёт бренд X → цель
        $alreadyHasX = $this->makeClient(); // берёт категорию и бренд X → не цель
        $irrelevant = $this->makeClient();  // не берёт категорию вовсе → не цель

        $this->ship($relevant, $catProductNoBrand);
        $this->ship($alreadyHasX, $brandXProduct);
        $this->ship($irrelevant, $unrelated);

        $result = $this->gap($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'subject' => 'partner',
            'exclude_dimension' => 'brand',
            'exclude_value' => $brandX->id,
            'exclude_window' => 'all',
            'include_dimension' => 'category',
            'include_value' => $catTarget->id,
        ]);

        $ids = collect($result['rows'])->pluck('id')->all();
        $this->assertContains($relevant->id, $ids);
        $this->assertNotContains($alreadyHasX->id, $ids);
        $this->assertNotContains($irrelevant->id, $ids);
    }

    #[Test]
    public function dormant_toggle_includes_clients_without_shipments(): void
    {
        $head = $this->makeHead();

        $brandA = Brand::factory()->create();
        $pa = Product::factory()->create(['brand_id' => $brandA->id]);

        $active = $this->makeClient();
        $this->ship($active, $pa); // купил бренд A
        $dormant = $this->makeClient(); // вообще без отгрузок

        // Без спящих: активная база = 1 (active), он купил A → gap пуст.
        $withoutDormant = $this->gap($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'subject' => 'partner',
            'exclude_dimension' => 'brand',
            'exclude_value' => $brandA->id,
            'exclude_window' => 'all',
        ]);
        $this->assertSame(0, $withoutDormant['summary']['gap']);

        // Со спящими: dormant без покупок A попадает в цель.
        $withDormant = $this->gap($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'subject' => 'partner',
            'exclude_dimension' => 'brand',
            'exclude_value' => $brandA->id,
            'exclude_window' => 'all',
            'include_dormant' => '1',
        ]);
        $ids = collect($withDormant['rows'])->pluck('id')->all();
        $this->assertContains($dormant->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    #[Test]
    public function foreign_clients_never_leak(): void
    {
        // Менеджер видит только своих клиентов; чужой партнёр не появляется даже
        // как «без покупок».
        $manager = User::factory()->create();
        $manager->givePermissionTo('crm-analytics.view');
        $card = PersonalManager::create(['name' => 'Мой', 'user_id' => $manager->id]);
        $myClient = User::factory()->create(['personal_manager_id' => $card->id]);

        $foreign = $this->makeClient();

        $brandA = Brand::factory()->create();
        $pa = Product::factory()->create(['brand_id' => $brandA->id]);
        $pOther = Product::factory()->create(['brand_id' => Brand::factory()->create()->id]);
        $this->ship($myClient, $pOther);   // мой клиент, без бренда A
        $this->ship($foreign, $pOther);    // чужой клиент, тоже без бренда A

        $result = $this->gap($manager->fresh(), [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'subject' => 'partner',
            'exclude_dimension' => 'brand',
            'exclude_value' => $brandA->id,
            'exclude_window' => 'all',
            'include_dormant' => '1',
        ]);

        $ids = collect($result['rows'])->pluck('id')->all();
        $this->assertContains($myClient->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function empty_when_no_exclude_value(): void
    {
        $head = $this->makeHead();

        $result = $this->gap($head, [
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->toDateString(),
            'subject' => 'partner',
            'exclude_dimension' => 'brand',
            'exclude_value' => 0,
        ]);

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['summary']['gap']);
    }
}
