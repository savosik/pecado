<?php

namespace Tests\Feature\Promotion;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartPromotionSelection;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\User;
use App\Services\Promotion\ActivePromotionRuleCache;
use App\Services\Promotion\AlwaysAvailablePromoStock;
use App\Services\Promotion\CartPromoLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Промо-позиции как строки корзины (карточка promo-08).
 *
 * Главное свойство: строки виртуальные. Их нет в `cart_items`, их id строковый,
 * и в общий итог корзины они не подмешиваются — промо уедет отдельным заказом.
 */
class CartPromoLinesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(ActivePromotionRuleCache::class)->flush();

        // Тесты про строки, а не про остаток: фонд проверяется в PromoStockServiceTest
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
    public function промо_строка_виртуальная_и_имеет_строковый_id(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create(['name' => 'Подарочный набор']);
        $rule = $this->issuingRule($trigger, $gift);

        $cart = $this->cartWith($trigger, 3);

        $lines = app(CartPromoLines::class)->forCart($cart);

        $this->assertCount(1, $lines);
        $this->assertSame('promo:'.$rule->id.':0', $lines[0]['id']);
        $this->assertIsString($lines[0]['id'], 'Числовой id уехал бы в /api/cart/items/{id}');
        $this->assertSame('promo', $lines[0]['item_type']);
        $this->assertSame('Подарочный набор', $lines[0]['product']['name']);

        // В самой корзине по-прежнему одна настоящая строка
        $this->assertSame(1, $cart->items()->count());
    }

    #[Test]
    public function правило_в_режиме_показа_строкой_не_становится(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();

        $this->issuingRule($trigger, $gift)->update(['mode' => PromotionRuleMode::INFO]);

        $this->assertSame([], app(CartPromoLines::class)->forCart($this->cartWith($trigger, 3)));
    }

    #[Test]
    public function итог_промо_считается_отдельно_от_корзины(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();

        $this->issuingRule($trigger, $gift, ['price' => 40, 'quantity' => 2]);

        $cart = $this->cartWith($trigger, 3);

        $details = app(CartServiceInterface::class)->getCartDetails($cart, $this->user);

        $this->assertCount(1, $details['promo_items']);
        $this->assertSame(2, $details['promo_quantity']);
        $this->assertEquals(80.0, $details['promo_amount']);

        // Главное: 80 ₽ промо не попали в сумму корзины
        $this->assertEquals(300.0, $details['total_amount_discounted']);
        $this->assertSame(3, $details['total_quantity']);
    }

    #[Test]
    public function виртуальный_id_не_проходит_в_эндпоинт_позиции_корзины(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();
        $rule = $this->issuingRule($trigger, $gift);

        $this->cartWith($trigger, 3);

        $id = CartPromoLines::id($rule->id, 0);

        $this->actingAs($this->user)
            ->patchJson("/api/cart/items/{$id}", ['quantity' => 5])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->deleteJson("/api/cart/items/{$id}")
            ->assertNotFound();
    }

    #[Test]
    public function разбор_виртуального_id(): void
    {
        $this->assertSame([12, 3], CartPromoLines::parseId('promo:12:3'));
        $this->assertNull(CartPromoLines::parseId(42), 'Числовой id — настоящая строка корзины');
        $this->assertNull(CartPromoLines::parseId('promo:12'));
        $this->assertNull(CartPromoLines::parseId('defect:12:3'));
        $this->assertNull(CartPromoLines::parseId('promo:abc:3'));
        $this->assertTrue(CartPromoLines::isPromoId('promo:1:0'));
        $this->assertFalse(CartPromoLines::isPromoId('1'));
    }

    #[Test]
    public function выбор_товара_из_вариантов_сохраняется_и_переживает_перезагрузку(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $first = Product::factory()->create(['name' => 'Первый вариант']);
        $second = Product::factory()->create(['name' => 'Второй вариант']);

        $rule = $this->issuingRule($trigger, $first, [
            'type' => PromotionRule::REWARD_TYPE_CHOICE,
            'product_id' => null,
            'choices' => [$first->id, $second->id],
        ]);

        $cart = $this->cartWith($trigger, 3);

        // По умолчанию — первый вариант
        $lines = app(CartPromoLines::class)->forCart($cart);
        $this->assertSame($first->id, $lines[0]['product']['id']);
        $this->assertCount(2, $lines[0]['choices']);

        $this->actingAs($this->user)
            ->postJson('/api/cart/promo/select', [
                'cart_id' => $cart->id,
                'rule_id' => $rule->id,
                'reward_index' => 0,
                'product_id' => $second->id,
            ])
            ->assertOk()
            ->assertJsonPath('promo_items.0.product.id', $second->id);

        $this->assertDatabaseHas('cart_promotion_selections', [
            'cart_id' => $cart->id,
            'promotion_rule_id' => $rule->id,
            'reward_index' => 0,
            'product_id' => $second->id,
        ]);

        // Перезагрузка страницы — выбор на месте
        $this->assertSame($second->id, app(CartPromoLines::class)->forCart($cart->fresh())[0]['product']['id']);
    }

    #[Test]
    public function отказ_от_платной_позиции_работает_и_возвращается(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();
        $rule = $this->issuingRule($trigger, $gift, ['price' => 40, 'optional' => true]);

        $cart = $this->cartWith($trigger, 3);

        $this->actingAs($this->user)
            ->postJson('/api/cart/promo/decline', [
                'cart_id' => $cart->id,
                'rule_id' => $rule->id,
                'reward_index' => 0,
                'declined' => true,
            ])
            ->assertOk()
            ->assertJsonPath('promo_items.0.is_declined', true);

        // Возврат
        $this->actingAs($this->user)
            ->postJson('/api/cart/promo/decline', [
                'cart_id' => $cart->id,
                'rule_id' => $rule->id,
                'reward_index' => 0,
                'declined' => false,
            ])
            ->assertOk()
            ->assertJsonPath('promo_items.0.is_declined', false);
    }

    #[Test]
    public function от_бесплатной_позиции_отказаться_нельзя(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();
        $rule = $this->issuingRule($trigger, $gift); // price = 0

        $cart = $this->cartWith($trigger, 3);

        $this->actingAs($this->user)
            ->postJson('/api/cart/promo/decline', [
                'cart_id' => $cart->id,
                'rule_id' => $rule->id,
                'reward_index' => 0,
                'declined' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount(CartPromotionSelection::class, 0);
    }

    #[Test]
    public function платная_промо_позиция_не_поднимает_корзину_до_следующего_порога(): void
    {
        $trigger = Product::factory()->create(['base_price' => 100]);
        $gift = Product::factory()->create();

        // Первое правило выдаёт платную позицию за 40 ₽
        $this->issuingRule($trigger, $gift, ['price' => 40]);

        // Второе срабатывает от суммы 340 ₽: 300 ₽ корзины + 40 ₽ промо дали бы порог,
        // но промо в условиях участвовать не должно
        PromotionRule::factory()->active()->create([
            'mode' => PromotionRuleMode::ISSUE,
            'conditions' => ['mode' => 'all', 'items' => [[
                'selector' => ['whole_cart' => true],
                'aggregate' => PromotionRule::AGGREGATE_AMOUNT,
                'operator' => '>=',
                'value' => 340,
            ]]],
            'rewards' => [[
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
            ]],
        ]);

        $lines = app(CartPromoLines::class)->forCart($this->cartWith($trigger, 3));

        $this->assertCount(1, $lines, 'Второе правило не должно сработать: 300 ₽ < 340 ₽');
        $this->assertEquals(40.0, $lines[0]['price']);
    }
}
