<?php

namespace Tests\Feature\Cabinet;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Бейджи меню кабинета (см. HandleInertiaRequests::cabinetCounts).
 *
 * Число в меню обещает работу клиента, поэтому закрытые предзаказы и пустые
 * корзины в него не входят. Считается это на каждой странице кабинета, но
 * нигде больше — на витрине меню нет, и платить за счётчики там незачем.
 */
class CabinetMenuCountsTest extends TestCase
{
    use RefreshDatabase;

    private function counts(User $user, string $url = '/cabinet/dashboard'): array
    {
        $response = $this->actingAs($user)->get($url);
        $response->assertOk();

        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true)['props']['config'];
    }

    #[Test]
    public function предзаказы_считаются_без_закрытых(): void
    {
        $user = User::factory()->create();

        Order::factory()->count(2)->create([
            'user_id' => $user->id,
            'type' => OrderType::PREORDER,
            'status' => OrderStatus::AWAITING_PROVISION,
        ]);
        Order::factory()->create([
            'user_id' => $user->id,
            'type' => OrderType::PREORDER,
            'status' => OrderStatus::CLOSED,
        ]);
        // Обычный заказ и чужой предзаказ в бейдж не входят
        Order::factory()->create(['user_id' => $user->id, 'type' => OrderType::ORDER]);
        Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'type' => OrderType::PREORDER,
            'status' => OrderStatus::AWAITING_PROVISION,
        ]);

        $this->assertSame(2, $this->counts($user)['preorder_count']);
    }

    #[Test]
    public function корзины_считаются_только_непустые(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $filled = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $filled->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
        ]);
        Cart::factory()->create(['user_id' => $user->id]); // пустая

        $this->assertSame(1, $this->counts($user)['cart_count']);
    }

    #[Test]
    public function вне_кабинета_счётчики_не_считаются(): void
    {
        $user = User::factory()->create();

        Order::factory()->create([
            'user_id' => $user->id,
            'type' => OrderType::PREORDER,
            'status' => OrderStatus::AWAITING_PROVISION,
        ]);

        $counts = $this->counts($user, '/');

        $this->assertSame(0, $counts['preorder_count']);
        $this->assertSame(0, $counts['cart_count']);
    }
}
