<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderType;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Типы заказов в админке.
 *
 * Уценка (`defect`) существует с 2026-06, но список типов в контроллере
 * перечислял только `order` и `preorder`: заказы уценки нельзя было
 * отфильтровать, а в таблице они подписывались как «Со склада».
 */
class AdminOrderTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');

        $this->company = Company::factory()->create(['user_id' => $this->admin->id]);
    }

    #[Test]
    public function список_типов_содержит_все_типы_заказов(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $types = collect($page->toArray()['props']['types']);

                $this->assertSame(
                    ['order', 'preorder', 'defect'],
                    $types->pluck('value')->all(),
                );
                $this->assertSame('Уценка', $types->firstWhere('value', 'defect')['label']);
            });
    }

    #[Test]
    public function фильтр_по_уценке_отбирает_только_заказы_уценки(): void
    {
        $defect = $this->createOrder(OrderType::DEFECT);
        $this->createOrder(OrderType::ORDER);
        $this->createOrder(OrderType::PREORDER);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['type' => 'defect']))
            ->assertInertia(function (AssertableInertia $page) use ($defect) {
                $orders = $page->toArray()['props']['orders']['data'];

                $this->assertCount(1, $orders);
                $this->assertSame($defect->id, $orders[0]['id']);
                $this->assertSame('defect', $orders[0]['type']);
            });
    }

    private function createOrder(OrderType $type): Order
    {
        return Order::factory()->create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'type' => $type,
        ]);
    }
}
