<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Клиентский API отслеживания изменений заказов:
 * GET /api/client-api/{token}/order-changes.
 */
class ClientApiOrderChangesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $apiToken = ApiToken::create([
            'user_id' => $this->user->id,
            'name' => 'test',
            'token' => 'test-token-123',
            'is_active' => true,
        ]);
        $this->token = $apiToken->token;
    }

    private function makeChange(Order $order, Product $product, string $type, int $from, int $to): void
    {
        $item = ['product_id' => $product->id, 'slug' => $product->slug, 'product_name' => $product->name];
        $changes = ['added' => [], 'removed' => [], 'modified' => []];
        if ($type === 'added') {
            $changes['added'][] = $item + ['quantity' => $to, 'price' => 10];
        } elseif ($type === 'removed') {
            $changes['removed'][] = $item + ['quantity' => $from, 'price' => 10];
        } else {
            $changes['modified'][] = $item + ['changes' => ['quantity' => ['old' => $from, 'new' => $to]]];
        }

        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => $changes,
            'source' => 'erp',
        ]);
    }

    #[Test]
    public function it_returns_data_and_meta_envelope(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $p = Product::factory()->create(['name' => 'Товар', 'slug' => 't', 'external_id' => 'uuid-t']);
        $this->makeChange($order, $p, 'changed', 7, 6);

        $response = $this->getJson("/api/client-api/{$this->token}/order-changes");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['order_number', 'order_id', 'changed_at', 'type', 'product_uuid', 'product_name', 'from', 'to']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.type', 'changed')
            ->assertJsonPath('data.0.from', 7)
            ->assertJsonPath('data.0.to', 6)
            ->assertJsonPath('data.0.product_uuid', 'uuid-t')
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function it_filters_by_type(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $this->makeChange($order, Product::factory()->create(['slug' => 'a']), 'added', 0, 3);
        $this->makeChange($order, Product::factory()->create(['slug' => 'b']), 'removed', 4, 0);

        $this->getJson("/api/client-api/{$this->token}/order-changes?type=removed")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'removed');
    }

    #[Test]
    public function it_caps_per_page(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $this->makeChange($order, Product::factory()->create(['slug' => 'a']), 'added', 0, 1);

        $this->getJson("/api/client-api/{$this->token}/order-changes?per_page=99999")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1000);
    }

    #[Test]
    public function invalid_token_returns_404(): void
    {
        $this->getJson('/api/client-api/nonexistent/order-changes')->assertNotFound();
    }

    #[Test]
    public function it_scopes_to_token_owner(): void
    {
        $other = User::factory()->create();
        $otherOrder = Order::factory()->create(['user_id' => $other->id]);
        $this->makeChange($otherOrder, Product::factory()->create(['slug' => 'x']), 'added', 0, 5);

        $this->getJson("/api/client-api/{$this->token}/order-changes")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
