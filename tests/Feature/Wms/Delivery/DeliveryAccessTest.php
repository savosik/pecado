<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Delivery\DeliveryShipment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Права на раздел отправок.
 *
 * Отдельно проверяем, что новые права не открыли складским ролям /admin: префикс
 * `wms-` — единственное, что их туда не пускает (см. User::hasAdminAccess).
 */
class DeliveryAccessTest extends DeliveryTestCase
{
    #[Test]
    #[TestDox('Гость на журнал отправок не попадает')]
    public function guest_is_redirected(): void
    {
        $this->get('/wms/deliveries')->assertRedirect('/login');
    }

    #[Test]
    #[TestDox('Клиент без складских ролей в раздел не заходит')]
    public function client_cannot_open_deliveries(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())
            ->get('/wms/deliveries')
            ->assertRedirect('/');
    }

    #[Test]
    #[TestDox('Кладовщик видит журнал и мастер создания')]
    public function storekeeper_can_open_index_and_create(): void
    {
        $storekeeper = $this->userWithRole('storekeeper');

        $this->actingAs($storekeeper)->get('/wms/deliveries')->assertOk();
        $this->actingAs($storekeeper)->get('/wms/deliveries/create')->assertOk();
    }

    #[Test]
    #[TestDox('Кладовщику отмена заявки в ТК недоступна')]
    public function storekeeper_cannot_cancel(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->post("/wms/deliveries/{$delivery->id}/cancel")
            ->assertForbidden();
    }

    #[Test]
    #[TestDox('Начальник склада отменяет заявку')]
    public function warehouse_head_can_cancel(): void
    {
        $this->fakeApiShip(['*/cancel' => \Illuminate\Support\Facades\Http::response(['orderId' => 1], 200)]);

        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('warehouse-head'))
            ->post("/wms/deliveries/{$delivery->id}/cancel")
            ->assertRedirect();

        $this->assertSame('cancelled', $delivery->fresh()->status->value);
    }

    #[Test]
    #[TestDox('Складские роли по-прежнему не попадают в /admin')]
    public function warehouse_roles_stay_out_of_admin(): void
    {
        foreach (['warehouse-head', 'storekeeper'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/admin')
                ->assertRedirect('/');
        }
    }
}
