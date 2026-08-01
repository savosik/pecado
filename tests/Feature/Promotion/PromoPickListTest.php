<?php

namespace Tests\Feature\Promotion;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Promotion\ActivePromotionRuleCache;
use App\Services\Promotion\AlwaysAvailablePromoStock;
use App\Services\Promotion\DTO\AppliedReward;
use App\Services\Promotion\PromoPickListFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Лист отбора промо-позиций в комментарии складу (карточка promo-11).
 *
 * Кладовщик собирает заказ по печатному документу из 1С, поэтому конкретика
 * должна оказаться в `warehouse_comment`, а не только в интерфейсе сайта.
 */
class PromoPickListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        app(ActivePromotionRuleCache::class)->flush();
        $this->app->bind(PromoStockCheckerInterface::class, AlwaysAvailablePromoStock::class);

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
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    #[Test]
    public function форматтер_собирает_строку_по_образцу(): void
    {
        $product = Product::factory()->create(['name' => 'Lovense Lush 4', 'sku' => 'LV-LUSH4']);

        $text = app(PromoPickListFormatter::class)->format(
            [$this->reward($product->id, 2, 'Lovense: Lush 4 в подарок')],
            collect([$product->id => $product]),
            PromoKind::SAMPLE,
        );

        $this->assertSame(
            "Рекламные образцы — отобрать со склада «Москва подарки»:\n"
            .'арт. LV-LUSH4 — Lovense Lush 4 — 2 шт. — по акции «Lovense: Lush 4 в подарок»',
            $text,
        );
    }

    #[Test]
    public function у_подотчётных_позиций_свой_заголовок(): void
    {
        $product = Product::factory()->create(['name' => 'Пробник геля', 'sku' => 'GEL-1']);

        $text = app(PromoPickListFormatter::class)->format(
            [$this->reward($product->id, 1, 'Собери серию')],
            collect([$product->id => $product]),
            PromoKind::ACCOUNTABLE,
        );

        $this->assertStringStartsWith(PromoPickListFormatter::HEADING_ACCOUNTABLE, $text);
        $this->assertStringNotContainsString('Москва подарки', $text);
    }

    #[Test]
    public function без_наград_лист_пустой(): void
    {
        $this->assertSame(
            '',
            app(PromoPickListFormatter::class)->format([], collect(), PromoKind::SAMPLE),
        );
    }

    #[Test]
    public function лист_дописывается_к_комментарию_склада_не_затирая_его(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500, 'Подарочный набор', 'GIFT-1');
        $this->issuingRule($trigger, $gift, ['promo_kind' => PromoKind::SAMPLE->value]);

        $orders = $this->checkout($trigger, warehouseComment: 'Упаковать в плёнку');

        $sampleOrder = $orders->firstWhere('type', OrderType::PROMO_SAMPLE);

        $this->assertNotNull($sampleOrder);
        $this->assertStringContainsString('Упаковать в плёнку', $sampleOrder->warehouse_comment);
        $this->assertStringContainsString(PromoPickListFormatter::HEADING_SAMPLE, $sampleOrder->warehouse_comment);
        $this->assertStringContainsString('арт. GIFT-1 — Подарочный набор — 1 шт.', $sampleOrder->warehouse_comment);

        // Обычный заказ листа отбора не получает — он там ни при чём
        $plain = $orders->firstWhere('type', OrderType::ORDER);
        $this->assertSame('Упаковать в плёнку', $plain->warehouse_comment);
    }

    #[Test]
    public function подотчётная_позиция_тоже_попадает_в_лист(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500, 'Массажное масло', 'OIL-7');
        $this->issuingRule($trigger, $gift);

        $promoOrder = $this->checkout($trigger)->firstWhere('type', OrderType::PROMO);

        $this->assertStringContainsString(PromoPickListFormatter::HEADING_ACCOUNTABLE, $promoOrder->warehouse_comment);
        $this->assertStringContainsString('арт. OIL-7 — Массажное масло — 1 шт.', $promoOrder->warehouse_comment);
    }

    private function reward(int $productId, int $quantity, string $ruleName): AppliedReward
    {
        return new AppliedReward(
            ruleId: 1,
            ruleName: $ruleName,
            ruleMode: PromotionRuleMode::ISSUE,
            rewardIndex: 0,
            productId: $productId,
            quantity: $quantity,
            price: 0.0,
            promoKind: PromoKind::SAMPLE,
            warehouseId: null,
            optional: false,
            declined: false,
        );
    }

    private function product(int $quantity, float $price = 1000, ?string $name = null, ?string $sku = null): Product
    {
        $product = Product::factory()->create(array_filter([
            'base_price' => $price,
            'name' => $name,
            'sku' => $sku,
        ]));

        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
        ]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $rewardOverrides
     */
    private function issuingRule(Product $trigger, Product $gift, array $rewardOverrides = []): PromotionRule
    {
        return PromotionRule::factory()->active()->create([
            'name' => 'Акция на пробники',
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

    private function checkout(Product $trigger, int $quantity = 3, ?string $warehouseComment = null)
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $trigger->id,
            'quantity' => $quantity,
            'price' => $trigger->base_price,
            'item_type' => 'instock',
        ]);

        return app(CheckoutServiceInterface::class)->checkout(
            $cart->fresh(),
            $this->company,
            'г. Москва, ул. Тестовая, д. 1',
            null,
            null,
            $warehouseComment,
            DeliveryMethod::DELIVERY,
        );
    }
}
