<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве» (res-12): менеджер должен видеть, что заказ
 * в окне резерва клиента, и не должен задевать его массовыми операциями.
 *
 * Резервный заказ приезжает из 1С со статусом «Готов к отгрузке» — без пометки
 * он неотличим от обычного.
 */
class AdminReserveVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    private function reserveOrder(): Order
    {
        return Order::factory()->create([
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
        ]);
    }

    #[Test]
    public function order_list_marks_reserved_orders(): void
    {
        $reserved = $this->reserveOrder();
        $plain = Order::factory()->create([
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => false,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/orders')
            ->assertOk()
            ->assertInertia(function ($page) use ($reserved, $plain) {
                $rows = collect($page->toArray()['props']['orders']['data'] ?? []);

                $reservedRow = $rows->firstWhere('id', $reserved->id);
                $plainRow = $rows->firstWhere('id', $plain->id);

                $this->assertNotNull($reservedRow);
                $this->assertTrue($reservedRow['reserve']);
                $this->assertNotNull($reservedRow['reserved_until'], 'срок показывается менеджеру');

                $this->assertNotNull($plainRow);
                $this->assertFalse($plainRow['reserve']);
                $this->assertNull($plainRow['reserved_until']);
            });
    }

    #[Test]
    public function order_card_and_edit_form_expose_reserve(): void
    {
        $order = $this->reserveOrder();

        $this->actingAs($this->admin)
            ->get("/admin/orders/{$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.reserve', true)
                ->whereNot('order.reserved_until', null));

        $this->actingAs($this->admin)
            ->get("/admin/orders/{$order->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.reserve', true)
                ->whereNot('order.reserved_until', null));
    }

    #[Test]
    public function bulk_status_skips_reserved_orders(): void
    {
        $reserved = $this->reserveOrder();
        $plain = Order::factory()->create([
            'status' => OrderStatus::READY_FOR_PROVISION,
            'reserve' => false,
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/orders/bulk-status', [
                'order_ids' => [$reserved->id, $plain->id],
                'status' => OrderStatus::SHIPPING->value,
            ])
            ->assertRedirect();

        $this->assertSame(
            OrderStatus::READY_FOR_SHIPMENT,
            $reserved->refresh()->status,
            'резерв клиента массовой операцией не тронут',
        );
        $this->assertSame(OrderStatus::SHIPPING, $plain->refresh()->status);
        $this->assertTrue($reserved->reserve, 'признак резерва сохранён');
    }

    #[Test]
    public function bulk_status_reports_skipped_reserves(): void
    {
        $reserved = $this->reserveOrder();

        $this->actingAs($this->admin)
            ->post('/admin/orders/bulk-status', [
                'order_ids' => [$reserved->id],
                'status' => OrderStatus::SHIPPING->value,
            ])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'в резерве клиента'));
    }
}
