<?php

namespace Tests\Feature\Defect;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\ProductDefect;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Удаление партии уценки закупщиком в /admin/defects.
 */
class AdminDefectDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  string[]  $permissions
     */
    private function buyer(array $permissions = ['defects.view', 'defects.delete']): User
    {
        $role = Role::firstOrCreate(['name' => 'buyer-manager', 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Резервирует партию заказом уценки — такую удалять нельзя. */
    private function reserve(ProductDefect $defect, int $quantity): void
    {
        $order = Order::factory()->create(['type' => OrderType::DEFECT]);
        $order->items()->create([
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
            'name' => 'Резерв',
            'price' => 300,
            'base_price' => 300,
            'discount_percent' => 0,
            'final_price' => 300,
            'quantity' => $quantity,
            'subtotal' => 300 * $quantity,
        ]);
    }

    #[Test]
    public function buyer_deletes_defect_batch(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 3]);

        $this->actingAs($this->buyer())
            ->delete("/admin/defects/{$defect->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('product_defects', ['id' => $defect->id]);
    }

    #[Test]
    public function reserved_defect_batch_is_not_deleted(): void
    {
        $defect = ProductDefect::factory()->sellable(300)->create(['quantity' => 3]);
        $this->reserve($defect, 1);

        $this->actingAs($this->buyer())
            ->delete("/admin/defects/{$defect->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('product_defects', ['id' => $defect->id, 'deleted_at' => null]);
    }

    #[Test]
    public function buyer_without_permission_cannot_delete(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 3]);

        $this->actingAs($this->buyer(['defects.view', 'defects.price']))
            ->delete("/admin/defects/{$defect->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('product_defects', ['id' => $defect->id, 'deleted_at' => null]);
    }
}
