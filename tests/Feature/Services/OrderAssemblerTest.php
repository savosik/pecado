<?php

namespace Tests\Feature\Services;

use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Events\OrderUpdated;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\OrderAssembler;
use App\Services\Order\OrderDraft;
use App\Services\Order\OrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Единая сборка заказов (карточка promo-06).
 *
 * Сборщик обязан быть тупым и предсказуемым: заказ на каждую непустую группу,
 * сумма без лишнего `OrderUpdated`, `OrderCreated` после коммита.
 */
class OrderAssemblerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $priceMock = $this->createMock(PriceServiceInterface::class);
        $priceMock->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $this->app->instance(PriceServiceInterface::class, $priceMock);

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function assembler(): OrderAssembler
    {
        return $this->app->make(OrderAssembler::class);
    }

    /**
     * @param  array<string, list<OrderLine>>  $groups
     * @param  array<string, mixed>  $extra
     */
    private function draft(array $groups, array $extra = []): OrderDraft
    {
        return new OrderDraft(
            user: $this->user,
            company: $this->company,
            deliveryMethod: $extra['deliveryMethod'] ?? DeliveryMethod::DELIVERY,
            groups: $groups,
            deliveryAddress: $extra['deliveryAddress'] ?? 'г. Москва, ул. Тестовая, д. 1',
            comment: $extra['comment'] ?? null,
            managerComment: $extra['managerComment'] ?? null,
            warehouseComment: $extra['warehouseComment'] ?? null,
            cartId: $extra['cartId'] ?? null,
            currency: null,
            warehouseComments: $extra['warehouseComments'] ?? [],
        );
    }

    #[Test]
    public function одна_группа_даёт_один_заказ_с_верной_суммой(): void
    {
        $product = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft([
            OrderType::ORDER->value => [new OrderLine($product, 3)],
        ]));

        $this->assertCount(1, $orders);

        $order = $orders->first();
        $this->assertSame(OrderType::ORDER, $order->type);
        $this->assertSame(OrderStatus::PENDING_APPROVAL, $order->status);
        $this->assertEquals(300.0, $order->total_amount, '3 шт. по индивидуальной цене 100 ₽');
        $this->assertSame(1, $order->items()->count());

        // В позицию едут обе цены: базовая для 1С и финальная для клиента
        $item = $order->items()->first();
        $this->assertEquals(120.0, $item->base_price);
        $this->assertEquals(100.0, $item->final_price);
        $this->assertEquals(16.67, $item->discount_percent);
    }

    #[Test]
    public function пустая_группа_не_создаёт_заказ(): void
    {
        $product = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft([
            OrderType::ORDER->value => [new OrderLine($product, 1)],
            OrderType::PREORDER->value => [],
            OrderType::DEFECT->value => [],
        ]));

        $this->assertCount(1, $orders);
        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function несколько_групп_дают_по_заказу_на_каждую(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft([
            OrderType::ORDER->value => [new OrderLine($first, 2)],
            OrderType::PREORDER->value => [new OrderLine($second, 5)],
        ]));

        $this->assertCount(2, $orders);
        $this->assertSame(
            [OrderType::ORDER, OrderType::PREORDER],
            $orders->map(fn (Order $order) => $order->type)->all(),
        );
    }

    /**
     * Главный инвариант: сумма фиксируется без выпуска OrderUpdated, иначе
     * в 1С уходит order.updated по документу, о котором она ещё не знает.
     */
    #[Test]
    public function фиксация_суммы_не_выпускает_order_updated(): void
    {
        Event::fake([OrderCreated::class, OrderUpdated::class]);

        $product = Product::factory()->create();

        $this->assembler()->assemble($this->draft([
            OrderType::ORDER->value => [new OrderLine($product, 1)],
        ]));

        Event::assertDispatched(OrderCreated::class, 1);
        Event::assertNotDispatched(OrderUpdated::class);
    }

    #[Test]
    public function order_created_уходит_только_после_коммита(): void
    {
        Event::fake([OrderCreated::class]);

        $product = Product::factory()->create();

        DB::transaction(function () use ($product) {
            $this->assembler()->assemble($this->draft([
                OrderType::ORDER->value => [new OrderLine($product, 1)],
            ]));

            // Транзакция ещё открыта — событие не должно было выйти
            Event::assertNotDispatched(OrderCreated::class);
        });

        Event::assertDispatched(OrderCreated::class, 1);
    }

    /**
     * Уценка: цена зафиксирована в строке, скидки к ней не применяются,
     * описание дефекта сохраняется снапшотом. Базовая цена при этом —
     * прайсовая цена товара-родителя (v15.9.3), иначе 1С вернёт свою
     * первым же order.updated и в истории заказа появится фантомная правка.
     */
    #[Test]
    public function фиксированная_цена_строки_не_пересчитывается_по_прайсу(): void
    {
        // Прайсовая цена товара из мока PriceService — 120 ₽.
        $product = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft([
            OrderType::DEFECT->value => [
                OrderLine::defect($product, 2, 45.5, null, 'скол на корпусе'),
            ],
        ]));

        $item = $orders->first()->items()->first();

        $this->assertEquals(45.5, $item->price, 'Цена партии не пересчитывается по прайсу');
        $this->assertEquals(120.0, $item->base_price, 'Базовая цена — прайсовая цена товара-родителя');
        $this->assertEquals(62.08, $item->discount_percent, 'Скидка — производная глубина уценки');
        $this->assertEquals(91.0, $item->subtotal, 'Сумма считается по цене партии');
        $this->assertSame('скол на корпусе', $item->defect_description);
    }

    /**
     * Прайсовая цена не выше цены уценки — прежнее поведение:
     * base_price = цена партии, скидка 0. Отрицательная «скидка» выглядела бы
     * в документе 1С наценкой на некондицию.
     */
    #[Test]
    public function уценка_дороже_прайса_оставляет_базовую_равной_цене_партии(): void
    {
        $product = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft([
            OrderType::DEFECT->value => [
                OrderLine::defect($product, 1, 200.0, null, null),
            ],
        ]));

        $item = $orders->first()->items()->first();

        $this->assertEquals(200.0, $item->base_price);
        $this->assertEquals(0, $item->discount_percent);
    }

    /**
     * Прайсовой цены у товара нет вовсе — тот же фолбэк, без деления на ноль.
     */
    #[Test]
    public function уценка_без_прайсовой_цены_оставляет_базовую_равной_цене_партии(): void
    {
        $priceMock = $this->createMock(PriceServiceInterface::class);
        $priceMock->method('getPriceResult')->willReturn(PriceResult::withoutDiscount(0.0));
        $this->app->instance(PriceServiceInterface::class, $priceMock);

        $product = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft([
            OrderType::DEFECT->value => [
                OrderLine::defect($product, 1, 45.5, null, null),
            ],
        ]));

        $item = $orders->first()->items()->first();

        $this->assertEquals(45.5, $item->base_price);
        $this->assertEquals(0, $item->discount_percent);
    }

    #[Test]
    public function комментарий_склада_переопределяется_для_отдельного_типа(): void
    {
        $product = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft(
            [
                OrderType::ORDER->value => [new OrderLine($product, 1)],
                OrderType::DEFECT->value => [OrderLine::defect($product, 1, 10.0, null, null)],
            ],
            [
                'warehouseComment' => 'общий комментарий',
                'warehouseComments' => [OrderType::DEFECT->value => 'лист отбора по партиям'],
            ],
        ));

        $this->assertSame('общий комментарий', $orders[0]->warehouse_comment);
        $this->assertSame('лист отбора по партиям', $orders[1]->warehouse_comment);
    }

    #[Test]
    public function при_самовывозе_адрес_не_сохраняется(): void
    {
        $product = Product::factory()->create();

        $orders = $this->assembler()->assemble($this->draft(
            [OrderType::ORDER->value => [new OrderLine($product, 1)]],
            ['deliveryMethod' => DeliveryMethod::PICKUP, 'deliveryAddress' => 'этот адрес игнорируется'],
        ));

        $this->assertNull($orders->first()->delivery_address);
        $this->assertSame(DeliveryMethod::PICKUP, $orders->first()->delivery_method);
    }
}
