<?php

namespace Tests\Feature\User;

use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Раздел кабинета «Изменения заказов» (/cabinet/order-changes) — таблица
 * движений товарного состава + экспорт.
 */
class OrderChangesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makeChange(Order $order, Product $product, string $type, int $from, int $to, ?string $at = null): void
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

        $log = OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => $changes,
            'source' => 'erp',
        ]);

        if ($at !== null) {
            $log->created_at = $at; // не входит в fillable
            $log->save();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchRows(string $query = ''): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/order-changes'.($query ? '?'.$query : ''));
        $response->assertOk();

        $content = $response->getContent();
        if (! preg_match('/data-page="([^"]+)"/', $content, $m)) {
            $this->fail('Не удалось извлечь data-page.');
        }
        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['rows']['data'] ?? [];
    }

    #[Test]
    public function it_lists_movement_rows(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $p = Product::factory()->create(['name' => 'Товар', 'slug' => 'tovar']);
        $this->makeChange($order, $p, 'changed', 7, 6);

        $rows = $this->fetchRows();

        $this->assertCount(1, $rows);
        $this->assertSame('Изменено количество', $rows[0]['type_label']);
        $this->assertSame(7, $rows[0]['from']);
        $this->assertSame(6, $rows[0]['to']);
        $this->assertSame('tovar', $rows[0]['slug']);
        $this->assertSame($order->id, $rows[0]['order_id']);
    }

    #[Test]
    public function it_filters_by_type(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $this->makeChange($order, Product::factory()->create(['slug' => 'a']), 'added', 0, 3);
        $this->makeChange($order, Product::factory()->create(['slug' => 'b']), 'removed', 4, 0);

        $rows = $this->fetchRows('type[]=added');

        $this->assertCount(1, $rows);
        $this->assertSame('added', $rows[0]['type']);
    }

    #[Test]
    public function period_hour_filter_excludes_older_changes(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $this->makeChange($order, Product::factory()->create(['name' => 'Свежий', 'slug' => 'new']), 'added', 0, 1, now()->subMinutes(10)->toDateTimeString());
        $this->makeChange($order, Product::factory()->create(['name' => 'Старый', 'slug' => 'old']), 'added', 0, 1, now()->subDays(2)->toDateTimeString());

        $hour = collect($this->fetchRows('period=hour'))->pluck('product_name');
        $this->assertContains('Свежий', $hour->all());
        $this->assertNotContains('Старый', $hour->all());

        // «Все» — обе записи.
        $this->assertCount(2, $this->fetchRows('period=all'));
    }

    #[Test]
    public function it_does_not_leak_other_users_changes(): void
    {
        $other = User::factory()->create();
        $otherOrder = Order::factory()->create(['user_id' => $other->id]);
        $this->makeChange($otherOrder, Product::factory()->create(['slug' => 'x']), 'added', 0, 1);

        $this->assertCount(0, $this->fetchRows());
    }

    #[Test]
    public function export_xlsx_streams_a_file(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $this->makeChange($order, Product::factory()->create(['slug' => 'a']), 'added', 0, 2);

        $response = $this->actingAs($this->user)->get('/cabinet/order-changes/export?format=xlsx');
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    #[Test]
    public function export_respects_type_filter(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $this->makeChange($order, Product::factory()->create(['name' => 'Добавленный', 'slug' => 'a']), 'added', 0, 2);
        $this->makeChange($order, Product::factory()->create(['name' => 'Выбывший', 'slug' => 'b']), 'removed', 4, 0);

        // CSV удобнее проверять на содержимое, чем бинарный XLSX.
        $response = $this->actingAs($this->user)->get('/cabinet/order-changes/export?format=csv&type[]=added');
        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringContainsString('Добавленный', $body);
        $this->assertStringNotContainsString('Выбывший', $body);
    }
}
