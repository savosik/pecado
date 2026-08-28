<?php

namespace Tests\Feature\Erp;

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
use App\Models\Order;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\ErpMessageValidator;
use App\Services\Promotion\ActivePromotionRuleCache;
use App\Services\Promotion\AlwaysAvailablePromoStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Заказы промо-позиций в обмене с 1С (карточка promo-09).
 *
 * Правило проекта: всё, что идёт через RabbitMQ и отражено в AsyncAPI,
 * покрывается интеграционными тестами.
 */
class PromoOrderPublishTest extends TestCase
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

        $this->warehouse = Warehouse::factory()->create([
            'name' => 'Основной',
            'external_id' => 'wh-primary-uuid',
        ]);

        $region = Region::factory()->create(['name' => 'Тестовый регион']);
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create([
            'region_id' => $region->id,
            'erp_id' => 'partner-uuid',
        ]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function product(int $quantity, float $price = 1000): Product
    {
        $product = Product::factory()->create([
            'base_price' => $price,
            'external_id' => 'product-'.uniqid(),
        ]);

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

    private function checkout(Product $trigger, int $quantity = 3)
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
            null,
            DeliveryMethod::DELIVERY,
        );
    }

    #[Test]
    public function чекаут_с_промо_создаёт_два_заказа_и_шлёт_два_сообщения(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10);
        $this->issuingRule($trigger, $gift);

        $orders = $this->checkout($trigger);

        $this->assertCount(2, $orders);
        $this->assertSame(
            [OrderType::ORDER, OrderType::PROMO],
            $orders->map(fn (Order $o) => $o->type)->all(),
        );

        Queue::assertPushed(PublishOrderToErpJob::class, 2);

        $types = [];
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$types) {
            $payload = $this->payloadOf($job);
            $types[$payload['type']] = $payload;

            return true;
        });

        $this->assertArrayHasKey('promo', $types, 'В шину должно уйти сообщение с type = promo');
        $this->assertSame(['wh-primary-uuid'], $types['promo']['warehouse_uuids']);
        $this->assertTrue($types['promo']['items'][0]['is_promo']);
        $this->assertSame('accountable', $types['promo']['items'][0]['promo_kind']);

        // У обычного заказа признака промо быть не должно
        $this->assertArrayNotHasKey('is_promo', $types['order']['items'][0]);
    }

    #[Test]
    public function позиция_подарка_несёт_базовую_цену_и_стопроцентную_скидку(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500);
        $this->issuingRule($trigger, $gift);

        $promoOrder = $this->checkout($trigger)->firstWhere('type', OrderType::PROMO);
        $item = $promoOrder->items()->first();

        $this->assertEquals(0, $item->final_price, 'Подарок бесплатный');
        $this->assertEquals(500, $item->base_price, 'base_price — обычная цена клиента');
        $this->assertEquals(100, $item->discount_percent, 'Скидка — производная от цен');
        $this->assertEquals(0, $promoOrder->total_amount, 'Заказ с нулевой суммой — норма');
        $this->assertSame('accountable', $item->promo_kind);
    }

    #[Test]
    public function платная_промо_позиция_даёт_производную_скидку(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500);
        $this->issuingRule($trigger, $gift, ['price' => 40]);

        $item = $this->checkout($trigger)->firstWhere('type', OrderType::PROMO)->items()->first();

        $this->assertEquals(40, $item->final_price);
        $this->assertEquals(500, $item->base_price);
        $this->assertEquals(92, $item->discount_percent, '(1 − 40/500) × 100');
    }

    #[Test]
    public function заказ_с_нулевой_суммой_проходит_валидацию_исходящих(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500);
        $this->issuingRule($trigger, $gift);

        $this->checkout($trigger);

        $promoPayload = null;
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$promoPayload) {
            $payload = $this->payloadOf($job);

            if ($payload['type'] === 'promo') {
                $promoPayload = $payload;
            }

            return true;
        });

        $this->assertNotNull($promoPayload);

        $result = app(ErpMessageValidator::class)->validateOutbound('order.created', $promoPayload);

        $this->assertTrue($result['valid'], 'Нулевая сумма не должна резаться валидатором');
        $this->assertSame([], $result['errors']);
        $this->assertEquals(0.0, $promoPayload['items'][0]['final_price']);
    }

    /**
     * Гейт публикации: пока склад не получил external_id от 1С, заказ образцов
     * остаётся на сайте. Инвариант проверяем и после того, как UUID прописан
     * миграцией, — он должен пережить откат склада в 1С.
     */
    #[Test]
    public function рекламные_образцы_не_публикуются_без_склада(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);
        Log::spy();

        Warehouse::query()->promoSample()->update(['external_id' => null]);

        $trigger = $this->product(10);
        $sample = $this->product(10);
        $this->issuingRule($trigger, $sample, ['promo_kind' => PromoKind::SAMPLE->value]);

        $orders = $this->checkout($trigger);

        // Заказ на сайте создан — менеджер его увидит
        $this->assertNotNull($orders->firstWhere('type', OrderType::PROMO_SAMPLE));

        // Но в шину не ушёл: пустой warehouse_uuids хуже отсутствия сообщения
        Queue::assertPushed(PublishOrderToErpJob::class, 1);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'Москва подарки'))
            ->once();
    }

    /**
     * Обратная сторона гейта: склад «Москва подарки» заведён миграцией с боевым
     * UUID из 1С, и заказ образцов уходит в шину с ним — регион клиента здесь
     * не участвует.
     */
    #[Test]
    public function рекламные_образцы_публикуются_со_своим_складом(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $sample = $this->product(10, 300);
        $this->issuingRule($trigger, $sample, ['promo_kind' => PromoKind::SAMPLE->value]);

        $this->checkout($trigger);

        Queue::assertPushed(PublishOrderToErpJob::class, 2);

        $payloads = [];
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$payloads) {
            $payload = $this->payloadOf($job);
            $payloads[$payload['type']] = $payload;

            return true;
        });

        $this->assertArrayHasKey('promo_sample', $payloads, 'Гейт снят — сообщение уходит');
        $this->assertSame(
            ['9da1768a-40d4-11e1-a692-001e6711ed1d'],
            $payloads['promo_sample']['warehouse_uuids'],
            'UUID склада «Москва подарки» из 1С',
        );
        $this->assertTrue($payloads['promo_sample']['items'][0]['is_promo']);
        $this->assertSame('sample', $payloads['promo_sample']['items'][0]['promo_kind']);

        // Обычный заказ по-прежнему уезжает со склада региона
        $this->assertSame(['wh-primary-uuid'], $payloads['order']['warehouse_uuids']);

        $result = app(ErpMessageValidator::class)->validateOutbound('order.created', $payloads['promo_sample']);
        $this->assertTrue($result['valid'], 'Сообщение обязано проходить схему исходящих');
    }

    /**
     * Лист отбора уходит в 1С — кладовщик собирает пробники по печатному документу,
     * а не по интерфейсу сайта.
     */
    #[Test]
    public function лист_отбора_образцов_уходит_в_шину(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $sample = $this->product(10, 300);
        $this->issuingRule($trigger, $sample, ['promo_kind' => PromoKind::SAMPLE->value]);

        $this->checkout($trigger);

        $samplePayload = null;
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$samplePayload) {
            $payload = $this->payloadOf($job);

            if ($payload['type'] === 'promo_sample') {
                $samplePayload = $payload;
            }

            return true;
        });

        $this->assertNotNull($samplePayload);
        $this->assertStringContainsString(
            'Рекламные образцы — отобрать со склада «Москва подарки»',
            $samplePayload['warehouse_comment'],
        );
    }

    #[Test]
    public function обратный_order_updated_не_затирает_привязку_к_акции(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500);
        $rule = $this->issuingRule($trigger, $gift);

        $promoOrder = $this->checkout($trigger)->firstWhere('type', OrderType::PROMO);
        $item = $promoOrder->items()->first();

        $this->assertSame($rule->id, $item->promotion_rule_id);

        // 1С возвращает заказ обратно — про promotion_rule_id она не знает
        app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $promoOrder->uuid,
            'items' => [[
                'product_uuid' => $gift->external_id,
                'quantity' => 1,
                'base_price' => 500,
                'discount_percent' => 100,
                'final_price' => 0,
            ]],
        ]);

        $reloaded = $promoOrder->fresh()->items()->first();

        $this->assertSame($rule->id, $reloaded->promotion_rule_id, 'Привязка к акции обязана пережить roundtrip');
        $this->assertSame('accountable', $reloaded->promo_kind);
    }

    #[Test]
    public function позиция_добавленная_вручную_в_1с_сохраняется(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500);
        $manual = $this->product(10, 300);
        $this->issuingRule($trigger, $gift);

        $promoOrder = $this->checkout($trigger)->firstWhere('type', OrderType::PROMO);

        // Менеджер дописал позицию прямо в 1С — правила акции за ней нет
        app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $promoOrder->uuid,
            'items' => [
                [
                    'product_uuid' => $gift->external_id,
                    'quantity' => 1,
                    'base_price' => 500,
                    'discount_percent' => 100,
                    'final_price' => 0,
                ],
                [
                    'product_uuid' => $manual->external_id,
                    'quantity' => 2,
                    'base_price' => 300,
                    'discount_percent' => 100,
                    'final_price' => 0,
                    'is_promo' => true,
                ],
            ],
        ]);

        $items = $promoOrder->fresh()->items()->get()->keyBy('product_id');

        $this->assertCount(2, $items);
        $this->assertNull($items[$manual->id]->promotion_rule_id, 'Ручную позицию не сопоставляем с правилом');
        $this->assertSame('accountable', $items[$manual->id]->promo_kind, 'Но признак промо сохраняем');

        // И она переживает следующее обновление
        app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle([
            'event' => 'order.updated',
            'uuid' => $promoOrder->uuid,
            'items' => [
                ['product_uuid' => $gift->external_id, 'quantity' => 1, 'base_price' => 500, 'discount_percent' => 100, 'final_price' => 0],
                ['product_uuid' => $manual->external_id, 'quantity' => 2, 'base_price' => 300, 'discount_percent' => 100, 'final_price' => 0],
            ],
        ]);

        $after = $promoOrder->fresh()->items()->get()->keyBy('product_id');

        $this->assertCount(2, $after);
        $this->assertSame('accountable', $after[$manual->id]->promo_kind);
    }

    /**
     * Инвариант, на котором держится «одно оформление — одно письмо»: событие
     * покупки выпускается один раз, сколько бы документов она ни породила.
     * Письмо менеджеру теперь слушает именно его.
     */
    #[Test]
    public function событие_покупки_выпускается_один_раз_на_оформление(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);
        \Illuminate\Support\Facades\Event::fake([\App\Events\OrdersPlaced::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500);
        $this->issuingRule($trigger, $gift);

        $this->checkout($trigger);

        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\OrdersPlaced::class, 1);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\OrdersPlaced::class,
            fn (\App\Events\OrdersPlaced $event) => $event->orders->count() === 2,
        );
    }

    /**
     * Повторная доставка того же сообщения не должна задваивать позиции —
     * RabbitMQ гарантирует at-least-once, дубли неизбежны.
     */
    #[Test]
    public function повторная_доставка_не_задваивает_позиции_промо_заказа(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $trigger = $this->product(10);
        $gift = $this->product(10, 500);
        $rule = $this->issuingRule($trigger, $gift);

        $promoOrder = $this->checkout($trigger)->firstWhere('type', OrderType::PROMO);

        $payload = [
            'event' => 'order.updated',
            'uuid' => $promoOrder->uuid,
            'items' => [[
                'product_uuid' => $gift->external_id,
                'quantity' => 1,
                'base_price' => 500,
                'discount_percent' => 100,
                'final_price' => 0,
            ]],
        ];

        app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle($payload);
        app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle($payload);

        $items = $promoOrder->fresh()->items()->get();

        $this->assertCount(1, $items, 'Позиции не должны задваиваться');
        $this->assertSame($rule->id, $items->first()->promotion_rule_id);
    }

    /**
     * Payload из job-а: свойство приватное, читаем рефлексией — это дешевле,
     * чем менять видимость ради теста.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(PublishOrderToErpJob $job): array
    {
        $property = new \ReflectionProperty($job, 'payload');
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
