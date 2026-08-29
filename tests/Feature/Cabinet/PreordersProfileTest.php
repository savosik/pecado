<?php

namespace Tests\Feature\Cabinet;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Preorder\PreorderTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Переключатель «предлагать предзаказ» в «Моих данных» кабинета.
 *
 * Клиент решает сам; запись в журнал идёт с автором-клиентом, чтобы менеджер
 * видел, что предзаказ пропал не по его вине.
 */
class PreordersProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function client_can_switch_preorders_off_and_on(): void
    {
        $this->actingAs($this->user)
            ->put('/cabinet/profile/preorders', ['enabled' => false])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($this->user->refresh()->preorders_enabled);
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->user->id,
            'field' => 'preorders',
            'to_value' => '0',
            'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->put('/cabinet/profile/preorders', ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue($this->user->refresh()->preorders_enabled);
    }

    #[Test]
    public function switching_off_removes_preorder_rows_from_every_cart(): void
    {
        $product = Product::factory()->create();
        $active = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $saved = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => false]);
        $stranger = Cart::factory()->create(['user_id' => User::factory()->create()->id, 'is_active' => true]);

        foreach ([$active, $saved, $stranger] as $cart) {
            CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'item_type' => 'instock']);
            CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2, 'item_type' => 'preorder']);
        }

        $this->actingAs($this->user)->put('/cabinet/profile/preorders', ['enabled' => false]);

        $this->assertDatabaseMissing('cart_items', ['cart_id' => $active->id, 'item_type' => 'preorder']);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $saved->id, 'item_type' => 'preorder']);
        // Чужая корзина нетронута.
        $this->assertDatabaseHas('cart_items', ['cart_id' => $stranger->id, 'item_type' => 'preorder', 'quantity' => 2]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $active->id, 'item_type' => 'instock', 'quantity' => 1]);
    }

    #[Test]
    public function enabled_is_required(): void
    {
        $this->actingAs($this->user)
            ->put('/cabinet/profile/preorders', [])
            ->assertSessionHasErrors('enabled');
    }

    #[Test]
    public function guest_is_redirected(): void
    {
        $this->put('/cabinet/profile/preorders', ['enabled' => false])->assertRedirect(route('login'));
    }

    #[Test]
    public function shared_props_carry_flag_and_lead_time(): void
    {
        config(['preorder.lead_days' => ['min' => 7, 'max' => 9]]);
        $this->user->forceFill(['preorders_enabled' => false])->save();

        $this->actingAs($this->user)
            ->get('/cabinet/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.preorders_enabled', false)
                ->where('preorder.lead_label', '7–9 дней')
                ->where('preorder.lead_min', 7)
                ->where('preorder.lead_max', 9));
    }

    #[Test]
    public function lead_label_declines_days_correctly(): void
    {
        config(['preorder.lead_days' => ['min' => 7, 'max' => 9]]);
        $this->assertSame('7–9 дней', PreorderTerms::leadLabel());

        config(['preorder.lead_days' => ['min' => 3, 'max' => 3]]);
        $this->assertSame('3 дня', PreorderTerms::leadLabel());

        config(['preorder.lead_days' => ['min' => 1, 'max' => 1]]);
        $this->assertSame('1 день', PreorderTerms::leadLabel());

        config(['preorder.lead_days' => ['min' => 10, 'max' => 14]]);
        $this->assertSame('10–14 дней', PreorderTerms::leadLabel());

        config(['preorder.lead_days' => ['min' => 20, 'max' => 21]]);
        $this->assertSame('20–21 день', PreorderTerms::leadLabel());
    }
}
