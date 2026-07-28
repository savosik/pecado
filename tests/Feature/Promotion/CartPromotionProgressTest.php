<?php

namespace Tests\Feature\Promotion;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\User;
use App\Services\Promotion\ActivePromotionRuleCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Прогресс акций в корзине — GET /api/cart/promotions (карточка promo-05).
 */
class CartPromotionProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ActivePromotionRuleCache::class)->flush();
    }

    private function cartWith(User $user, Product $product, int $quantity): Cart
    {
        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'item_type' => 'instock',
        ]);

        return $cart;
    }

    // ────────────────────────────────────────────
    // Доступ
    // ────────────────────────────────────────────

    #[Test]
    public function guest_cannot_read_cart_promotions(): void
    {
        $this->getJson('/api/cart/promotions')->assertUnauthorized();
    }

    #[Test]
    public function user_cannot_read_someone_elses_cart(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertForbidden();
    }

    #[Test]
    public function empty_cart_returns_empty_progress(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/cart/promotions')
            ->assertOk()
            ->assertJsonPath('near_miss', [])
            ->assertJsonPath('achieved', []);
    }

    // ────────────────────────────────────────────
    // Прогресс
    // ────────────────────────────────────────────

    #[Test]
    public function near_miss_reports_remaining_amount(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 1000]);
        $rewardProduct = Product::factory()->create(['name' => 'Lush 4']);

        $promotion = Promotion::factory()->create(['name' => 'Lovense: Lush 4 в подарок']);

        PromotionRule::factory()
            ->active()
            ->amountThreshold(10000, [$product->id])
            ->freeGift($rewardProduct->id)
            ->create(['promotion_id' => $promotion->id]);

        $cart = $this->cartWith($user, $product, 8);

        $response = $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk();

        $response->assertJsonCount(1, 'near_miss');
        $response->assertJsonPath('near_miss.0.current', 8000);
        $response->assertJsonPath('near_miss.0.target', 10000);
        $response->assertJsonPath('near_miss.0.remaining', 2000);
        // Заголовок — название акции-лендинга, а не служебное имя правила
        $response->assertJsonPath('near_miss.0.title', 'Lovense: Lush 4 в подарок');
        $this->assertStringContainsString('2 000 ₽', $response->json('near_miss.0.message'));
        $this->assertStringContainsString('Lush 4', $response->json('near_miss.0.message'));
    }

    #[Test]
    public function quantity_threshold_speaks_in_pieces(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 100]);

        PromotionRule::factory()
            ->active()
            ->quantityThreshold(10, [$product->id])
            ->create();

        $cart = $this->cartWith($user, $product, 6);

        $response = $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk();

        $response->assertJsonPath('near_miss.0.remaining', 4);
        $response->assertJsonPath('near_miss.0.remaining_label', '4 шт.');
    }

    #[Test]
    public function achieved_rule_says_manager_will_add_the_item(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 1000]);
        $rewardProduct = Product::factory()->create(['name' => 'Lush 4']);

        PromotionRule::factory()
            ->active()
            ->amountThreshold(1000, [$product->id])
            ->freeGift($rewardProduct->id)
            ->create();

        $cart = $this->cartWith($user, $product, 5);

        $response = $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk();

        $response->assertJsonCount(0, 'near_miss');
        $response->assertJsonCount(1, 'achieved');
        // Волна 1 ничего не выдаёт — обещать автоматическую выдачу нельзя
        $response->assertJsonPath('achieved.0.issued', false);
        $this->assertStringContainsString('добавит менеджер', $response->json('achieved.0.message'));
    }

    #[Test]
    public function untouched_promotion_is_not_shown(): void
    {
        $user = User::factory()->create();
        $inCart = Product::factory()->create(['base_price' => 100]);
        $promoProduct = Product::factory()->create(['base_price' => 1000]);

        // Правило на товар, которого в корзине нет
        PromotionRule::factory()
            ->active()
            ->amountThreshold(150000, [$promoProduct->id])
            ->create();

        $cart = $this->cartWith($user, $inCart, 3);

        $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk()
            ->assertJsonPath('near_miss', []);
    }

    #[Test]
    public function disabled_rule_is_not_shown(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 1000]);

        PromotionRule::factory()->amountThreshold(10000, [$product->id])->create(); // выключено

        $cart = $this->cartWith($user, $product, 8);

        $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk()
            ->assertJsonPath('near_miss', []);
    }

    // ────────────────────────────────────────────
    // Что наружу не уходит
    // ────────────────────────────────────────────

    #[Test]
    public function blocked_reasons_never_leak_to_the_client(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 1000]);
        $rewardProduct = Product::factory()->create();

        // Правило сработает, но упрётся в лимит на клиента — причина
        // предназначена только для админского предпросмотра
        PromotionRule::factory()
            ->active()
            ->amountThreshold(1000, [$product->id])
            ->freeGift($rewardProduct->id)
            ->create(['limits' => ['per_client_total' => 1, 'total' => null]]);

        $cart = $this->cartWith($user, $product, 5);

        $response = $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk();

        $payload = $response->json();

        $this->assertArrayNotHasKey('blocked', $payload);
        $this->assertStringNotContainsString('blocked', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('остат', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('лимит', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Правило сработало, но кратность не набрана. Админ видит причину в
     * предпросмотре, клиент — ничего: для него акция просто не выполнена.
     */
    #[Test]
    public function unreached_multiplier_is_not_shown_to_the_client(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 1000]);
        $rewardProduct = Product::factory()->create();

        PromotionRule::factory()
            ->active()
            ->quantityThreshold(5, [$product->id])
            ->freeGift($rewardProduct->id)
            ->perThreshold(100, 3)
            ->create();

        $cart = $this->cartWith($user, $product, 6);

        $payload = $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk()
            ->json();

        $this->assertSame([], $payload['achieved']);
        $this->assertArrayNotHasKey('blocked', $payload);
        $this->assertStringNotContainsString('кратност', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Кратности разных артикулов считаются отдельно и складываются —
     * акция Lovense одним правилом вместо пятнадцати.
     */
    #[Test]
    public function per_item_steps_are_summed_in_the_cart(): void
    {
        $user = User::factory()->create();
        $everyFour = Product::factory()->create(['base_price' => 1000]);
        $everySix = Product::factory()->create(['base_price' => 1000]);
        $rewardProduct = Product::factory()->create(['name' => 'Lush 4']);

        PromotionRule::factory()
            ->active()
            ->perItemSteps([$everyFour->id => 4, $everySix->id => 6])
            ->freeGift($rewardProduct->id)
            ->perThreshold(null, 20)
            ->create();

        $cart = Cart::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        foreach ([[$everyFour, 8], [$everySix, 6]] as [$product, $quantity]) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'item_type' => 'instock',
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk()
            ->assertJsonCount(1, 'achieved');
    }

    #[Test]
    public function promo_lines_do_not_count_towards_the_threshold(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 1000]);

        PromotionRule::factory()
            ->active()
            ->amountThreshold(10000, [$product->id])
            ->create();

        $cart = $this->cartWith($user, $product, 8);

        // Корзина в волне 1 промо-строк не содержит вовсе — проверяем, что
        // агрегат считается ровно по обычным позициям
        $response = $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk();

        $response->assertJsonPath('near_miss.0.current', 8000);
    }

    #[Test]
    public function progress_is_calculated_per_cart_not_per_user(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 1000]);

        PromotionRule::factory()
            ->active()
            ->amountThreshold(10000, [$product->id])
            ->create();

        $first = $this->cartWith($user, $product, 8);
        $second = $this->cartWith($user, $product, 2);

        $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$first->id)
            ->assertOk()
            ->assertJsonPath('near_miss.0.current', 8000);

        $this->actingAs($user)
            ->getJson('/api/cart/promotions?cart_id='.$second->id)
            ->assertOk()
            // 2 000 из 10 000 — ниже порога показа nearMiss (25 %)
            ->assertJsonPath('near_miss', []);
    }
}
