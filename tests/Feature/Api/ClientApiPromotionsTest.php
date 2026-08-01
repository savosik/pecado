<?php

namespace Tests\Feature\Api;

use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Enums\OrderType;
use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Promotion\ActivePromotionRuleCache;
use App\Services\Promotion\AlwaysAvailablePromoStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Расчёт акций в клиентском API (карточка promo-12).
 *
 * На этом эндпоинте сидят боевые интеграции клиентов, поэтому главное
 * требование — обратная совместимость: без `apply_promotions` не должно
 * измениться ничего, даже ключей в JSON.
 */
class ClientApiPromotionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ApiToken $token;

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

        // erp_id обязателен: без него PriceService не идёт в базу индивидуальных цен
        $this->user = User::factory()->create([
            'region_id' => $region->id,
            'erp_id' => 'partner-api-uuid',
        ]);

        $this->token = ApiToken::create([
            'user_id' => $this->user->id,
            'name' => 'test',
            'is_active' => true,
        ]);

        Company::factory()->create([
            'user_id' => $this->user->id,
            'tax_id' => '7707083893',
        ]);
    }

    // ────────────────────────────────────────────
    // Обратная совместимость
    // ────────────────────────────────────────────

    #[Test]
    public function без_параметра_ответ_совпадает_с_прежним(): void
    {
        $trigger = $this->product('ART-A', 10);
        $gift = $this->product('GIFT', 10);
        $this->issuingRule($trigger, $gift);

        $response = $this->order([['identifier' => 'ART-A', 'quantity' => 5]]);

        $response->assertStatus(201);

        $json = $response->json();

        $this->assertArrayNotHasKey('promotions', $json, 'Ключа не должно быть даже пустого');
        $this->assertSame(['orders', 'total_orders', 'fully_fulfilled'], array_keys($json));
        $this->assertSame(1, $json['total_orders'], 'Промо-заказ создаваться не должен');
        $this->assertSame(0, $this->promoOrders()->count());
    }

    #[Test]
    public function явный_false_ничего_не_начисляет(): void
    {
        $trigger = $this->product('ART-A', 10);
        $this->issuingRule($trigger, $this->product('GIFT', 10));

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => false],
        )->assertStatus(201)->json();

        $this->assertArrayNotHasKey('promotions', $json);
        $this->assertSame(0, $this->promoOrders()->count());
    }

    // ────────────────────────────────────────────
    // Начисление
    // ────────────────────────────────────────────

    #[Test]
    public function с_флагом_создаётся_промо_заказ_и_возвращается_блок(): void
    {
        $trigger = $this->product('ART-A', 10);
        $gift = $this->product('GIFT', 10, name: 'Подарочный набор');
        $rule = $this->issuingRule($trigger, $gift);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertSame(2, $json['total_orders'], 'Обычный заказ + промо-заказ');

        $promoOrder = $this->promoOrders()->firstOrFail();

        $this->assertCount(1, $json['promotions']['applied']);

        $applied = $json['promotions']['applied'][0];
        $this->assertSame($rule->id, $applied['rule_id']);
        $this->assertSame('Акция клиентского API', $applied['promotion']);
        $this->assertSame($gift->id, $applied['product_id']);
        $this->assertSame('Подарочный набор', $applied['name']);
        $this->assertSame(1, $applied['quantity']);
        $this->assertEquals(0, $applied['price']);
        $this->assertSame('accountable', $applied['promo_kind']);
        $this->assertSame($promoOrder->id, $applied['order_id'], 'Партнёру нужен id документа');
    }

    #[Test]
    public function промо_заказ_из_api_несёт_лист_отбора_для_склада(): void
    {
        $trigger = $this->product('ART-A', 10);
        $gift = $this->product('GIFT', 10, name: 'Массажное масло');
        $this->issuingRule($trigger, $gift);

        $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201);

        $promoOrder = $this->promoOrders()->firstOrFail();

        $this->assertStringContainsString(
            'Промо-позиции — отобрать с обычного склада вместе с заказом:',
            (string) $promoOrder->warehouse_comment,
        );
        $this->assertStringContainsString('Массажное масло — 1 шт.', (string) $promoOrder->warehouse_comment);
    }

    #[Test]
    public function образцы_уезжают_отдельным_заказом(): void
    {
        $trigger = $this->product('ART-A', 10);
        $sample = $this->product('SAMPLE', 10);

        $this->issuingRule($trigger, $sample, ['promo_kind' => PromoKind::SAMPLE->value]);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $sampleOrder = $this->promoOrders(OrderType::PROMO_SAMPLE)->firstOrFail();

        $this->assertSame('sample', $json['promotions']['applied'][0]['promo_kind']);
        $this->assertSame($sampleOrder->id, $json['promotions']['applied'][0]['order_id']);
    }

    // ────────────────────────────────────────────
    // Правила расчёта
    // ────────────────────────────────────────────

    #[Test]
    public function промо_считается_по_принятым_позициям_а_не_по_запрошенным(): void
    {
        // На складе три штуки, клиент просит пять — порог акции ровно пять
        $trigger = $this->product('ART-A', 3);
        $this->issuingRule($trigger, $this->product('GIFT', 10), thresholdOverride: 5);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertFalse($json['fully_fulfilled'], 'Позиция урезана по остатку');
        $this->assertSame([], $json['promotions']['applied'], 'Подарок за неотгруженный товар не выдаём');
        $this->assertSame(0, $this->promoOrders()->count());
    }

    #[Test]
    public function правило_только_для_сайта_в_api_не_срабатывает(): void
    {
        $trigger = $this->product('ART-A', 10);
        $this->issuingRule($trigger, $this->product('GIFT', 10), ruleOverrides: [
            'audience' => ['channels' => [PromotionRule::CHANNEL_SITE]],
        ]);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertSame([], $json['promotions']['applied']);
        $this->assertSame(0, $this->promoOrders()->count());
    }

    #[Test]
    public function причины_блокировки_наружу_не_утекают(): void
    {
        $trigger = $this->product('ART-A', 10);
        $this->issuingRule($trigger, $this->product('GIFT', 10), ruleOverrides: [
            'audience' => ['channels' => [PromotionRule::CHANNEL_SITE]],
        ]);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertSame(['applied', 'near_miss'], array_keys($json['promotions']));
        $this->assertStringNotContainsString('wrong_channel', json_encode($json));
    }

    #[Test]
    public function награда_с_выбором_не_выдаётся_и_попадает_в_near_miss(): void
    {
        $trigger = $this->product('ART-A', 10);
        $first = $this->product('CH-1', 10);
        $second = $this->product('CH-2', 10);

        $rule = $this->issuingRule($trigger, $first, [
            'type' => PromotionRule::REWARD_TYPE_CHOICE,
            'product_id' => null,
            'choices' => [$first->id, $second->id],
        ]);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertSame([], $json['promotions']['applied'], 'Выбирать за клиента нельзя');
        $this->assertSame(0, $this->promoOrders()->count());

        $miss = collect($json['promotions']['near_miss'])->firstWhere('rule_id', $rule->id);

        $this->assertNotNull($miss);
        $this->assertSame('choice', $miss['missing']['type']);
        $this->assertSame('Выбор промо-позиции доступен только на сайте', $miss['message']);
    }

    #[Test]
    public function near_miss_отдаётся_даже_когда_ничего_не_начислено(): void
    {
        $trigger = $this->product('ART-A', 10, price: 1000);
        $this->issuingRule($trigger, $this->product('GIFT', 10), ruleOverrides: [
            'conditions' => ['mode' => 'all', 'items' => [[
                'selector' => ['products' => [$trigger->id]],
                'aggregate' => PromotionRule::AGGREGATE_AMOUNT,
                'operator' => '>=',
                'value' => 20000,
            ]]],
        ]);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 5]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertSame([], $json['promotions']['applied']);
        $this->assertCount(1, $json['promotions']['near_miss']);

        $miss = $json['promotions']['near_miss'][0];

        $this->assertSame('amount', $miss['missing']['type']);
        $this->assertSame('RUB', $miss['missing']['currency'], 'Пороги правил заданы в рублях');
        $this->assertEquals(15000, $miss['missing']['value'], '20 000 − 5 × 1000');
        $this->assertNotEmpty($miss['reward']);
    }

    #[Test]
    public function порог_считается_по_индивидуальной_цене_клиента(): void
    {
        $trigger = $this->product('ART-A', 20, price: 1000);
        $this->issuingRule($trigger, $this->product('GIFT', 10), ruleOverrides: [
            'conditions' => ['mode' => 'all', 'items' => [[
                'selector' => ['products' => [$trigger->id]],
                'aggregate' => PromotionRule::AGGREGATE_AMOUNT,
                'operator' => '>=',
                'value' => 6000,
            ]]],
        ]);

        // Индивидуальная цена вдвое ниже базовой: 8 шт. по 500 ₽ — это 4000 ₽,
        // порог не взят, хотя по базовой цене был бы пройден
        IndividualPrice::create([
            'partner_id' => $this->user->id,
            'product_id' => $trigger->id,
            'warehouse_id' => $this->warehouse->id,
            'price' => 500,
        ]);

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 8]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertSame([], $json['promotions']['applied'], 'Порог считается по цене клиента');

        $json = $this->order(
            [['identifier' => 'ART-A', 'quantity' => 12]],
            ['apply_promotions' => true],
        )->assertStatus(201)->json();

        $this->assertCount(1, $json['promotions']['applied'], '12 × 500 = 6000 ₽ — порог взят');
    }

    // ────────────────────────────────────────────
    // Хелперы
    // ────────────────────────────────────────────

    private function product(string $code, int $available, float $price = 100, ?string $name = null): Product
    {
        $product = Product::factory()->create([
            'code' => $code,
            'name' => $name ?? "Товар {$code}",
            'base_price' => $price,
        ]);

        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $available,
        ]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $rewardOverrides
     * @param  array<string, mixed>  $ruleOverrides
     */
    private function issuingRule(
        Product $trigger,
        Product $gift,
        array $rewardOverrides = [],
        array $ruleOverrides = [],
        int $thresholdOverride = 3,
    ): PromotionRule {
        return PromotionRule::factory()->active()->create(array_merge([
            'name' => 'Акция клиентского API',
            'mode' => PromotionRuleMode::ISSUE,
            'conditions' => ['mode' => 'all', 'items' => [[
                'selector' => ['products' => [$trigger->id]],
                'aggregate' => PromotionRule::AGGREGATE_QUANTITY,
                'operator' => '>=',
                'value' => $thresholdOverride,
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
        ], $ruleOverrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $extra
     */
    private function order(array $products, array $extra = []): TestResponse
    {
        return $this->postJson("/api/client-api/{$this->token->token}/orders", array_merge([
            'inn' => '7707083893',
            'products' => $products,
        ], $extra));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Order>
     */
    private function promoOrders(OrderType $type = OrderType::PROMO)
    {
        return \App\Models\Order::query()->where('type', $type->value);
    }
}
