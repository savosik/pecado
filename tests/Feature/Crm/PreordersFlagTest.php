<?php

namespace Tests\Feature\Crm;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Галочка «предлагать предзаказ» в карточке партнёра (CRM).
 *
 * Инварианты те же, что у страхового запаса: меняет менеджер с crm-profile.edit,
 * смена журналируется, выключение чистит предзаказные строки из корзин партнёра.
 */
class PreordersFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    private function toggle(bool $enabled, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->manager)->put(
            route('crm.clients.preorders.update', $this->client),
            ['enabled' => $enabled],
        );
    }

    #[Test]
    public function preorders_are_enabled_by_default(): void
    {
        $this->assertTrue($this->client->fresh()->preordersEnabled());
    }

    #[Test]
    public function disabling_writes_flag_journal_and_clears_preorder_cart_rows(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->client->id, 'is_active' => true]);
        $product = Product::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2, 'item_type' => 'instock']);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 3, 'item_type' => 'preorder']);

        $this->toggle(false)->assertRedirect();

        $this->assertFalse($this->client->refresh()->preorders_enabled);
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->client->id,
            'field' => 'preorders',
            'from_value' => '1',
            'to_value' => '0',
            'user_id' => $this->manager->id,
        ]);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id, 'item_type' => 'preorder']);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'item_type' => 'instock', 'quantity' => 2]);
    }

    #[Test]
    public function enabling_back_writes_journal(): void
    {
        $this->client->forceFill(['preorders_enabled' => false])->save();

        $this->toggle(true)->assertRedirect();

        $this->assertTrue($this->client->refresh()->preorders_enabled);
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->client->id,
            'field' => 'preorders',
            'from_value' => '0',
            'to_value' => '1',
        ]);
    }

    #[Test]
    public function same_value_does_not_pollute_the_journal(): void
    {
        $this->toggle(true)->assertRedirect();

        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    public function employee_without_profile_edit_gets_403(): void
    {
        $role = Role::create(['name' => 'crm-viewer']);
        $role->givePermissionTo(Permission::findByName('crm-dashboard.view'));

        $viewer = User::factory()->create();
        $viewer->assignRole($role);

        $this->toggle(false, $viewer)->assertForbidden();
        $this->assertTrue($this->client->refresh()->preordersEnabled());
    }

    #[Test]
    public function partner_card_exposes_flag_and_lead_time(): void
    {
        config(['preorder.lead_days' => ['min' => 7, 'max' => 9]]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('client.preorders.enabled', true)
                ->where('client.preorders.lead_label', '7–9 дней'));
    }
}
