<?php

namespace Tests\Feature\User;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Быстрые фильтры по статусу в разделе «Мои заказы»: счётчики над списком
 * и множественный выбор статусов.
 */
class OrderStatusQuickFiltersTest extends TestCase
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

    /** @return array<string, mixed> Inertia-props страницы списка заказов */
    private function fetchProps(string $query = ''): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/orders'.($query !== '' ? '?'.$query : ''));
        $response->assertOk();

        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true)['props'];
    }

    /** @return array<string, int> Счётчики статусов: значение статуса => количество */
    private function statusCounts(array $props): array
    {
        return collect($props['statuses'])->pluck('count', 'value')->all();
    }

    /** @return array<int, int> ID заказов в выдаче */
    private function orderIds(array $props): array
    {
        return array_map(static fn (array $row) => (int) $row['id'], $props['orders']['data']);
    }

    private function makeOrder(OrderStatus $status, array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'status' => $status,
        ], $attributes));
    }

    #[Test]
    public function counts_are_grouped_by_status_and_summed_in_total(): void
    {
        $this->makeOrder(OrderStatus::CLOSED);
        $this->makeOrder(OrderStatus::CLOSED);
        $this->makeOrder(OrderStatus::SHIPPING);

        $props = $this->fetchProps();
        $counts = $this->statusCounts($props);

        $this->assertSame(2, $counts[OrderStatus::CLOSED->value]);
        $this->assertSame(1, $counts[OrderStatus::SHIPPING->value]);
        $this->assertSame(0, $counts[OrderStatus::PENDING_APPROVAL->value]);
        $this->assertSame(3, $props['statusTotal']);
    }

    #[Test]
    public function counts_ignore_the_status_filter_itself(): void
    {
        $this->makeOrder(OrderStatus::CLOSED);
        $this->makeOrder(OrderStatus::SHIPPING);

        // Выбран один статус — счётчики остальных должны остаться ненулевыми,
        // иначе переключиться на другой статус чипом было бы невозможно.
        $counts = $this->statusCounts($this->fetchProps('status[]='.OrderStatus::CLOSED->value));

        $this->assertSame(1, $counts[OrderStatus::CLOSED->value]);
        $this->assertSame(1, $counts[OrderStatus::SHIPPING->value]);
    }

    #[Test]
    public function counts_respect_other_active_filters(): void
    {
        $this->makeOrder(OrderStatus::CLOSED, ['type' => OrderType::ORDER]);
        $this->makeOrder(OrderStatus::CLOSED, ['type' => OrderType::PREORDER]);
        $this->makeOrder(OrderStatus::SHIPPING, ['type' => OrderType::PREORDER]);

        $props = $this->fetchProps('type='.OrderType::PREORDER->value);
        $counts = $this->statusCounts($props);

        $this->assertSame(1, $counts[OrderStatus::CLOSED->value]);
        $this->assertSame(1, $counts[OrderStatus::SHIPPING->value]);
        $this->assertSame(2, $props['statusTotal']);
    }

    #[Test]
    public function counts_cover_only_orders_of_the_current_user(): void
    {
        $this->makeOrder(OrderStatus::CLOSED);

        $stranger = User::factory()->create();
        Order::factory()->create([
            'user_id' => $stranger->id,
            'company_id' => Company::factory()->create(['user_id' => $stranger->id])->id,
            'status' => OrderStatus::CLOSED,
        ]);

        $props = $this->fetchProps();

        $this->assertSame(1, $this->statusCounts($props)[OrderStatus::CLOSED->value]);
        $this->assertSame(1, $props['statusTotal']);
    }

    #[Test]
    public function several_statuses_are_selected_at_once(): void
    {
        $closed = $this->makeOrder(OrderStatus::CLOSED);
        $shipping = $this->makeOrder(OrderStatus::SHIPPING);
        $pending = $this->makeOrder(OrderStatus::PENDING_APPROVAL);

        $props = $this->fetchProps(
            'status[]='.OrderStatus::CLOSED->value.'&status[]='.OrderStatus::SHIPPING->value
        );

        $ids = $this->orderIds($props);
        $this->assertContains($closed->id, $ids);
        $this->assertContains($shipping->id, $ids);
        $this->assertNotContains($pending->id, $ids);

        // Выбранные статусы возвращаются во фронт, чтобы подсветить чипы.
        $this->assertSame(
            [OrderStatus::CLOSED->value, OrderStatus::SHIPPING->value],
            $props['filters']['status'],
        );
    }
}
