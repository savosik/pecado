<?php

namespace Tests\Feature\Crm;

use App\Models\Category;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Закупки партнёра в его карточке: средний чек, бренды, категории.
 *
 * Раздел ничего не считает сам — цифра здесь обязана совпадать с провалом
 * в партнёра на «грядках», поэтому оба берут её из ClientInsightService.
 * Скоуп тот же, что и у карточки: чужой партнёр — 404, а не 403.
 */
class ClientPurchasesTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->travelTo('2026-08-12 12:00:00');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    #[Test]
    #[TestDox('Средний чек и разрезы считаются по отгрузкам за 12 месяцев')]
    public function it_returns_metrics_and_breakdowns(): void
    {
        $category = Category::create(['name' => 'Вибраторы', 'slug' => 'vibrators']);
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->shipment('2026-07-10', 'Satisfyer', 30000, $product);
        $this->shipment('2026-07-20', 'Satisfyer', 10000, $product);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.insights', $this->client->id))
            ->assertOk();

        $response->assertJsonPath('metrics.shipments_count', 2);
        $response->assertJsonPath('metrics.avg_check', 20000);
        $response->assertJsonPath('brands.0.label', 'Satisfyer');
        $response->assertJsonPath('categories.0.label', 'Вибраторы');
    }

    #[Test]
    #[TestDox('Чужой партнёр — 404: его существование не подтверждаем')]
    public function it_hides_foreign_partner(): void
    {
        $other = User::factory()->create();
        $other->assignRole('sales-manager');
        $foreignProfile = PersonalManager::factory()->create(['user_id' => $other->id]);
        $foreign = User::factory()->create(['personal_manager_id' => $foreignProfile->id]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.insights', $foreign->id))
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Без права на базу партнёров раздел закрыт')]
    public function it_is_gated(): void
    {
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-comments.view');

        $this->actingAs($outsider)
            ->getJson(route('crm.clients.insights', $this->client->id))
            ->assertForbidden();
    }

    private function shipment(string $date, string $brand, float $amount, Product $product): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'date' => $date,
            'erp_created_at' => $date.' 10:00:00',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
        ]);

        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'brand_name_snapshot' => $brand,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'subtotal' => $amount,
        ]);
    }
}
