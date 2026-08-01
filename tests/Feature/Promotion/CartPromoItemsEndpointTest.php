<?php

namespace Tests\Feature\Promotion;

use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\User;
use App\Services\Promotion\ActivePromotionRuleCache;
use App\Services\Promotion\AlwaysAvailablePromoStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/cart/promo-items — источник свежих промо-строк для корзины.
 *
 * Строки виртуальные и пересчитываются движком, а изменение количеств идёт
 * через store и не обновляет Inertia-пропсы. Без этого эндпоинта блок
 * «Промо-позиции» жил до перезагрузки страницы.
 */
class CartPromoItemsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(ActivePromotionRuleCache::class)->flush();
        $this->app->bind(PromoStockCheckerInterface::class, AlwaysAvailablePromoStock::class);

        $this->user = User::factory()->create();
    }

    private function cartWith(Product $product, int $quantity): Cart
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->base_price,
            'item_type' => 'instock',
        ]);

        return $cart->fresh();
    }

    /**
     * @param  array<string, mixed>  $rewardOverrides
     */
    private function issuingRule(Product $trigger, Product $gift, array $rewardOverrides = []): PromotionRule
    {
        return PromotionRule::factory()->active()->create([
            'mode' => PromotionRuleMode::ISSUE,
            'conditions' => ['mode' => 'all', 'items' => [[
                'selector' => ['products' => [$trigger->id]],
                'aggregate' => PromotionRule::AGGREGATE_QUANTITY,
                'operator' => '>=',
                'value' => 3,
            ]]],
            'rewards' => [array_merge([
                'type' => PromotionRule::REWARD_TYPE_FIXED,
                'product_id' => $gift->id,
                'choices' => null,
                'quantity' => 1,
                'price' => 0,
                'promo_kind' => PromoKind::ACCOUNTABLE->value,
                'warehouse_id' => null,
                'multiply' => PromotionRule::MULTIPLY_ONCE,
                'max_multiplier' => 1,
                'optional' => false,
            ], $rewardOverrides)],
        ]);
    }

    #[Test]
    public function отдаёт_промо_строки_текущей_корзины(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create(['name' => 'Подарочный набор']);
        $this->issuingRule($trigger, $gift);

        $cart = $this->cartWith($trigger, 3);

        $response = $this->actingAs($this->user)
            ->getJson('/api/cart/promo-items?cart_id='.$cart->id)
            ->assertOk();

        $response->assertJsonPath('promo_items.0.product.name', 'Подарочный набор');
        $response->assertJsonStructure(['promo_items', 'promo_quantity', 'promo_amount']);
    }

    /**
     * Ровно тот случай, ради которого эндпоинт появился: количество изменилось,
     * страница не перезагружалась — строки обязаны пересчитаться.
     */
    #[Test]
    public function пересчитывает_строки_после_изменения_количества(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();
        $this->issuingRule($trigger, $gift);

        // Порог 3 шт. ещё не взят
        $cart = $this->cartWith($trigger, 2);

        $this->actingAs($this->user)
            ->getJson('/api/cart/promo-items?cart_id='.$cart->id)
            ->assertOk()
            ->assertJsonCount(0, 'promo_items');

        $cart->items()->first()->update(['quantity' => 3]);

        $this->actingAs($this->user)
            ->getJson('/api/cart/promo-items?cart_id='.$cart->id)
            ->assertOk()
            ->assertJsonCount(1, 'promo_items');
    }

    #[Test]
    public function платная_позиция_помечена_отклоняемой(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();
        $this->issuingRule($trigger, $gift, ['price' => 40, 'optional' => true]);

        $cart = $this->cartWith($trigger, 3);

        $this->actingAs($this->user)
            ->getJson('/api/cart/promo-items?cart_id='.$cart->id)
            ->assertOk()
            ->assertJsonPath('promo_items.0.is_optional', true)
            ->assertJsonPath('promo_items.0.price', 40);
    }

    /**
     * Бесплатную позицию отклонять нечего — иначе рядом с подарком появилась бы
     * кнопка «Отказаться», которая читается как ошибка интерфейса.
     */
    #[Test]
    public function бесплатная_позиция_не_отклоняемая_даже_с_флагом(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();
        $this->issuingRule($trigger, $gift, ['price' => 0, 'optional' => true]);

        $cart = $this->cartWith($trigger, 3);

        $this->actingAs($this->user)
            ->getJson('/api/cart/promo-items?cart_id='.$cart->id)
            ->assertOk()
            ->assertJsonPath('promo_items.0.is_optional', false);
    }

    #[Test]
    public function чужую_корзину_не_отдаёт(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $this->issuingRule($trigger, Product::factory()->create());

        $cart = $this->cartWith($trigger, 3);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/cart/promo-items?cart_id='.$cart->id)
            ->assertForbidden();
    }

    #[Test]
    public function гостя_не_пускает(): void
    {
        $this->getJson('/api/cart/promo-items')->assertUnauthorized();
    }
}
