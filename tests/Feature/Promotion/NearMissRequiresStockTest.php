<?php

namespace Tests\Feature\Promotion;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PromotionRuleMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Promotion\ActivePromotionRuleCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Подсказка «доберите на …» показывается только при наличии фонда.
 *
 * Дефект с боевого dev (правило 20): клиент видел шкалу прогресса, добирал
 * корзину — и блок акции исчезал. Причина в том, что подарка не было на складе:
 * при выполненном условии правило уходит в `blocked`, а `blocked` клиенту
 * не отдаётся by design. Снаружи это выглядело как поломка сайта.
 *
 * Инвариант: если выдать нечего — крючка нет вовсе. Обещание, которое движок
 * заведомо не выполнит, клиенту не показываем.
 */
class NearMissRequiresStockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app(ActivePromotionRuleCache::class)->flush();

        // Реальный фонд, а не заглушка: тест именно про остаток
        $this->warehouse = Warehouse::factory()->create(['name' => 'Основной']);
        $region = Region::factory()->create(['name' => 'Тестовый регион']);

        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create(['region_id' => $region->id]);
    }

    private function product(int $stock): Product
    {
        $product = Product::factory()->create();

        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $stock,
        ]);

        return $product;
    }

    /** Правило «5 шт. триггера → подарок», порог заведомо не взят. */
    private function rule(Product $trigger, Product $gift, array $rewardOverrides = []): PromotionRule
    {
        $rule = PromotionRule::factory()
            ->active()
            ->quantityThreshold(5, [$trigger->id])
            ->freeGift($gift->id)
            ->create(['mode' => PromotionRuleMode::ISSUE]);

        if ($rewardOverrides !== []) {
            $rewards = $rule->rewards;
            $rewards[0] = array_merge($rewards[0], $rewardOverrides);
            $rule->update(['rewards' => $rewards]);
        }

        return $rule->refresh();
    }

    private function cartWith(Product $product, int $quantity): Cart
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'item_type' => 'instock',
        ]);

        return $cart;
    }

    private function progressFor(Cart $cart): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/cart/promotions?cart_id='.$cart->id)
            ->assertOk()
            ->json();
    }

    #[Test]
    public function подсказка_показывается_когда_подарок_есть_на_складе(): void
    {
        $trigger = $this->product(50);
        $gift = $this->product(3);
        $rule = $this->rule($trigger, $gift);

        $progress = $this->progressFor($this->cartWith($trigger, 4));

        $this->assertCount(1, $progress['near_miss']);
        $this->assertSame($rule->id, $progress['near_miss'][0]['rule_id']);
        $this->assertSame(1.0, (float) $progress['near_miss'][0]['remaining']);
    }

    #[Test]
    public function без_остатка_подарка_подсказки_нет(): void
    {
        $trigger = $this->product(50);
        $gift = $this->product(0);
        $this->rule($trigger, $gift);

        $progress = $this->progressFor($this->cartWith($trigger, 4));

        $this->assertSame([], $progress['near_miss'], 'Обещать нечего — крючка быть не должно');
    }

    /**
     * Ровно случай с dev: остаток на складе есть, но весь он забронирован
     * ранее оформленными промо-заказами. Именно так «работающее вчера» правило
     * исчезает сегодня.
     */
    #[Test]
    public function остаток_съеденный_резервом_промо_заказов_убирает_подсказку(): void
    {
        $trigger = $this->product(50);
        $gift = $this->product(2);
        $this->rule($trigger, $gift);

        $company = Company::factory()->create(['user_id' => $this->user->id]);
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'status' => OrderStatus::PENDING_APPROVAL,
            'type' => OrderType::PROMO,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $gift->id,
            'quantity' => 2,
        ]);

        $progress = $this->progressFor($this->cartWith($trigger, 4));

        $this->assertSame([], $progress['near_miss']);

        // Заказ закрыт — товар вернулся в фонд, подсказка снова уместна
        $order->update(['status' => OrderStatus::CLOSED]);

        $this->assertCount(1, $this->progressFor($this->cartWith($trigger, 4))['near_miss']);
    }

    #[Test]
    public function награда_со_своим_складом_проверяется_по_нему(): void
    {
        $trigger = $this->product(50);
        $gift = $this->product(10);
        $sampleWarehouse = Warehouse::factory()->create([
            'name' => 'Москва подарки',
            'is_promo_sample' => true,
        ]);

        // Остаток есть в регионе, но награда прибита к складу образцов, где пусто
        $this->rule($trigger, $gift, [
            'warehouse_id' => $sampleWarehouse->id,
            'promo_kind' => 'sample',
        ]);

        $progress = $this->progressFor($this->cartWith($trigger, 4));

        $this->assertSame([], $progress['near_miss'], 'Регион не спасает: фонд считается по складу награды');
    }

    #[Test]
    public function из_вариантов_выбора_в_подсказку_попадают_только_доступные(): void
    {
        $trigger = $this->product(50);
        $available = $this->product(5);
        $soldOut = $this->product(0);

        $this->rule($trigger, $available, [
            'type' => PromotionRule::REWARD_TYPE_CHOICE,
            'product_id' => null,
            'choices' => [$available->id, $soldOut->id],
        ]);

        $progress = $this->progressFor($this->cartWith($trigger, 4));

        $this->assertCount(1, $progress['near_miss']);

        $shown = array_column($progress['near_miss'][0]['rewards'], 'product_id');
        $this->assertSame([$available->id], $shown, 'Отсутствующий вариант в подсказке не показываем');
    }
}
