<?php

namespace Tests\Feature\Promotion;

use App\Enums\PromotionRuleMode;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\Warehouse;
use App\Services\Promotion\PromotionRuleSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Команда `promo:seed-demo` — демо-акции для ручной приёмки конструктора.
 *
 * Проверяем не «что-то создалось», а что каждая из пяти механик настроена так,
 * как её собираются тестировать: иначе приёмка упрётся в кривой конфиг, а не
 * в поведение движка.
 */
class SeedDemoPromotionRulesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('promotions.issue_enabled', true);

        $this->warehouse = Warehouse::factory()->create(['name' => 'Основной']);

        $region = Region::factory()->create(['name' => 'Тестовый регион']);
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Команде нужно 12 товаров с остатком и ценой выше 100 ₽
        for ($i = 0; $i < 14; $i++) {
            $product = Product::factory()->create([
                'sku' => 'DEMO-'.$i,
                'base_price' => 1000 + $i,
            ]);

            DB::table('product_warehouse')->insert([
                'product_id' => $product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 100,
            ]);
        }
    }

    private function rule(string $needle): PromotionRule
    {
        return PromotionRule::query()->where('name', 'like', "%{$needle}%")->firstOrFail();
    }

    #[Test]
    public function создаёт_пять_акций_по_числу_механик(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $rules = PromotionRule::query()->where('name', 'like', '[Демо]%')->get();

        $this->assertCount(5, $rules);
        $this->assertTrue($rules->every(fn (PromotionRule $rule) => $rule->is_active));
        $this->assertTrue($rules->every(fn (PromotionRule $rule) => $rule->mode === PromotionRuleMode::ISSUE));
    }

    #[Test]
    public function все_правила_проходят_валидатор_схемы(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $validator = app(PromotionRuleSchemaValidator::class);

        foreach (PromotionRule::query()->where('name', 'like', '[Демо]%')->get() as $rule) {
            $result = $validator->validate([
                'name' => $rule->name,
                'mode' => $rule->mode->value,
                'is_active' => $rule->is_active,
                'conditions' => $rule->conditions,
                'rewards' => $rule->rewards,
                'audience' => $rule->audience,
                'limits' => $rule->limits,
            ]);

            $this->assertTrue($result['valid'], "«{$rule->name}»: ".json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        }
    }

    #[Test]
    public function пробник_привязан_к_рекламному_складу_и_имеет_остаток(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $promoWarehouse = Warehouse::query()->promoSample()->firstOrFail();
        $reward = $this->rule('Пробник')->rewards[0];

        $this->assertSame('sample', $reward['promo_kind']);
        $this->assertSame($promoWarehouse->id, $reward['warehouse_id']);

        // Без остатка на складе награды движок пробник не выдаст
        $stock = DB::table('product_warehouse')
            ->where('warehouse_id', $promoWarehouse->id)
            ->where('product_id', $reward['product_id'])
            ->sum('quantity');

        $this->assertSame(50, (int) $stock);
    }

    #[Test]
    public function награда_на_выбор_содержит_два_товара(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $reward = $this->rule('на выбор')->rewards[0];

        $this->assertSame(PromotionRule::REWARD_TYPE_CHOICE, $reward['type']);
        $this->assertCount(2, $reward['choices']);
        $this->assertNull($reward['product_id']);
    }

    #[Test]
    public function отклоняемая_позиция_платная(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $reward = $this->rule('Отклоняемая')->rewards[0];

        $this->assertTrue($reward['optional'], 'Иначе кнопки отказа не будет');
        // От бесплатной позиции отказываться незачем — движок флаг у неё игнорирует
        $this->assertGreaterThan(0, $reward['price']);
    }

    #[Test]
    public function ограниченный_тираж_несёт_оба_лимита(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $limits = $this->rule('Ограниченный тираж')->limits;

        $this->assertSame(3, $limits['total']);
        $this->assertSame(1, $limits['per_client_total']);
    }

    #[Test]
    public function у_каждой_акции_свой_триггерный_товар(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $triggers = PromotionRule::query()
            ->where('name', 'like', '[Демо]%')
            ->get()
            ->map(fn (PromotionRule $rule) => $rule->conditions['items'][0]['selector']['products'][0])
            ->all();

        $this->assertSame(
            count($triggers),
            count(array_unique($triggers)),
            'Общий триггер сработал бы сразу по нескольким акциям — разобрать результат было бы нельзя',
        );
    }

    /**
     * Сквозная проверка: конфиг не просто валиден — движок по нему реально
     * выдаёт подарок. Иначе приёмка упрётся в пустую корзину и будет неясно,
     * сломан движок или демо-данные.
     */
    #[Test]
    public function демо_акция_реально_выдаёт_подарок(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $rule = $this->rule('Подотчётный подарок');
        $triggerId = $rule->conditions['items'][0]['selector']['products'][0];

        $user = \App\Models\User::factory()->create([
            'region_id' => \App\Models\Region::query()->value('id'),
        ]);

        $evaluation = app(\App\Services\Promotion\PromotionEngine::class)->evaluate(
            \App\Services\Promotion\DTO\PromoContext::fromLines(
                lines: [new \App\Services\Promotion\DTO\PromoContextLine(
                    productId: (int) $triggerId,
                    quantity: 3,
                )],
                user: $user,
            ),
        );

        $issuable = $evaluation->issuable();

        $this->assertCount(1, $issuable, 'Порог 3 шт. взят — подарок должен выдаться');
        $this->assertSame($rule->id, $issuable[0]->ruleId);
        $this->assertSame($rule->rewards[0]['product_id'], $issuable[0]->productId);
        $this->assertEquals(0.0, $issuable[0]->price);
    }

    #[Test]
    public function повторный_запуск_не_плодит_дубли(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();
        $this->artisan('promo:seed-demo')->assertSuccessful();

        $this->assertSame(5, PromotionRule::query()->where('name', 'like', '[Демо]%')->count());
    }

    #[Test]
    public function правила_работают_в_обоих_каналах(): void
    {
        $this->artisan('promo:seed-demo')->assertSuccessful();

        foreach (PromotionRule::query()->where('name', 'like', '[Демо]%')->get() as $rule) {
            $this->assertSame(['site', 'api'], $rule->audience['channels'], $rule->name);
        }
    }
}
