<?php

namespace Tests\Feature\User;

use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Документы одного оформления в списке заказов кабинета.
 *
 * Чекаут расщепляет корзину по типам и создаёт до пяти заказов. Для клиента это
 * одна покупка, и в списке они обязаны идти подряд, в понятном порядке и с явным
 * признаком связи — иначе пять строк выглядят как пять разных покупок.
 */
class OrderPlacementGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function order(OrderType $type, ?int $cartId, string $createdAt): Order
    {
        return Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'type' => $type,
            'cart_id' => $cartId,
            'created_at' => $createdAt,
            'erp_created_at' => null,
        ]);
    }

    #[Test]
    public function список_отдаёт_корзину_и_время_оформления(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);
        $this->order(OrderType::ORDER, $cart->id, '2026-08-01 10:00:00');

        $this->actingAs($this->user)
            ->get('/cabinet/orders')
            ->assertInertia(function (AssertableInertia $page) use ($cart) {
                $row = $page->toArray()['props']['orders']['data'][0];

                $this->assertSame($cart->id, $row['cart_id']);
                $this->assertSame('01.08.2026 10:00', $row['placed_at']);
            });
    }

    #[Test]
    public function документы_одного_оформления_идут_подряд_и_в_порядке_сборки(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);
        $other = Cart::factory()->create(['user_id' => $this->user->id]);

        // Одна секунда на всё оформление — как их и создаёт OrderAssembler
        $at = '2026-08-01 10:00:00';
        $order = $this->order(OrderType::ORDER, $cart->id, $at);
        $preorder = $this->order(OrderType::PREORDER, $cart->id, $at);
        $promo = $this->order(OrderType::PROMO, $cart->id, $at);

        // Чужое оформление на секунду позже — оно должно идти выше целиком,
        // а не вклиниваться в середину
        $foreign = $this->order(OrderType::ORDER, $other->id, '2026-08-01 10:00:01');

        $this->actingAs($this->user)
            ->get('/cabinet/orders')
            ->assertInertia(function (AssertableInertia $page) use ($foreign, $order, $preorder, $promo) {
                $ids = array_column($page->toArray()['props']['orders']['data'], 'id');

                $this->assertSame(
                    [$foreign->id, $order->id, $preorder->id, $promo->id],
                    $ids,
                    'Документы одной корзины обязаны идти подряд, в порядке сборки',
                );
            });
    }

    #[Test]
    public function заказ_без_корзины_не_ломает_группировку(): void
    {
        // Заказ, созданный менеджером или приехавший из 1С, корзины не имеет
        $this->order(OrderType::ORDER, null, '2026-08-01 10:00:00');

        $this->actingAs($this->user)
            ->get('/cabinet/orders')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertNull($page->toArray()['props']['orders']['data'][0]['cart_id']);
            });
    }
}
