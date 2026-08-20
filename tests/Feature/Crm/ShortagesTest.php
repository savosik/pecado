<?php

namespace Tests\Feature\Crm;

use App\Enums\Order\CancelSource;
use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\User;
use App\Services\Shortage\CancellationHintResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * CRM-раздел «Недоборы»: журнал отменённых строк заказов.
 *
 * Предмет проверок — то, ради чего раздел переделан из подборок замен: строки
 * видно по скоупу менеджера, фильтры возвращаются в снимке (иначе экран
 * «забывает» отбор при переходе по страницам), сводки сходятся с журналом,
 * а метку «кто отменил» ставит человек — автоматика только подсказывает.
 */
class ShortagesTest extends TestCase
{
    use RefreshDatabase, RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $this->managerProfile->id,
            'erp_name' => 'ООО «Ромашка»',
        ]);
    }

    /**
     * Отменённая строка заказа — запись журнала.
     *
     * @return array{order: Order, line: OrderItem, product: Product}
     */
    private function makeCancelledLine(
        ?User $client = null,
        int $quantity = 5,
        float $subtotal = 500.0,
        string $cancelledAt = '-3 days',
        ?Product $product = null,
    ): array {
        $client ??= $this->client;
        $product ??= Product::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $client->id,
            'erp_number' => '29УТ-011777',
        ]);

        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'cancelled' => true,
            'cancelled_at' => now()->modify($cancelledAt),
            'quantity' => $quantity,
            'final_price' => $subtotal / max($quantity, 1),
            'subtotal' => $subtotal,
        ]);

        return ['order' => $order, 'line' => $line, 'product' => $product];
    }

    /**
     * @return array<string, mixed>
     */
    private function props($response): array
    {
        return $response->viewData('page')['props'];
    }

    #[Test]
    public function journal_is_gated_by_permission(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get('/crm/shortages')->assertRedirect('/');
    }

    #[Test]
    public function manager_sees_only_cancellations_of_own_partners(): void
    {
        ['line' => $mine] = $this->makeCancelledLine();

        // Чужой партнёр другого менеджера.
        $otherProfile = PersonalManager::factory()->create();
        $otherClient = User::factory()->create(['personal_manager_id' => $otherProfile->id]);
        $this->makeCancelledLine($otherClient);

        $response = $this->actingAs($this->manager)->get('/crm/shortages');

        $response->assertOk();
        $rows = $this->props($response)['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]['id']);
        $this->assertSame('ООО «Ромашка»', $rows[0]['client']);
    }

    #[Test]
    public function head_of_sales_sees_the_whole_department_and_can_filter_by_manager(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->makeCancelledLine();

        $otherProfile = PersonalManager::factory()->create();
        $otherClient = User::factory()->create(['personal_manager_id' => $otherProfile->id]);
        ['line' => $foreign] = $this->makeCancelledLine($otherClient);

        $all = $this->actingAs($head)->get('/crm/shortages?scope=department');
        $this->assertCount(2, $this->props($all)['rows']['data']);

        $filtered = $this->actingAs($head)->get("/crm/shortages?scope=department&manager_id={$otherProfile->id}");
        $rows = $this->props($filtered)['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($foreign->id, $rows[0]['id']);
        // Фильтр обязан вернуться в снимке: иначе пагинация и вкладки его теряют.
        $this->assertSame($otherProfile->id, $this->props($filtered)['filters']['manager_id']);
    }

    #[Test]
    public function filters_come_back_in_the_snapshot(): void
    {
        $this->makeCancelledLine();

        $response = $this->actingAs($this->manager)->get(
            '/crm/shortages?from=2026-01-01&to=2026-12-31&source=none&search=Ромашка&tab=products'
        );

        $filters = $this->props($response)['filters'];

        $this->assertSame('2026-01-01', $filters['from']);
        $this->assertSame('2026-12-31', $filters['to']);
        $this->assertSame('none', $filters['source']);
        $this->assertSame('Ромашка', $filters['search']);
        $this->assertSame('products', $filters['tab']);
    }

    #[Test]
    public function period_filter_cuts_off_old_cancellations(): void
    {
        $this->makeCancelledLine(cancelledAt: '-2 days');
        $this->makeCancelledLine(cancelledAt: '-200 days');

        // Период по умолчанию — 90 дней.
        $default = $this->actingAs($this->manager)->get('/crm/shortages');
        $this->assertCount(1, $this->props($default)['rows']['data']);

        $wide = $this->actingAs($this->manager)->get('/crm/shortages?from='.now()->subYear()->format('Y-m-d'));
        $this->assertCount(2, $this->props($wide)['rows']['data']);
    }

    #[Test]
    public function totals_and_summaries_add_up(): void
    {
        $product = Product::factory()->create(['name' => 'Смазка «Аква»']);

        $this->makeCancelledLine(quantity: 5, subtotal: 500.0, product: $product);
        ['line' => $second] = $this->makeCancelledLine(quantity: 2, subtotal: 300.0, product: $product);

        $second->forceFill([
            'cancel_source' => CancelSource::WAREHOUSE,
            'cancel_source_user_id' => $this->manager->id,
            'cancel_source_at' => now(),
        ])->save();

        $response = $this->actingAs($this->manager)->get('/crm/shortages?tab=products');
        $props = $this->props($response);

        $this->assertSame(2, $props['totals']['lines_count']);
        $this->assertSame(7, $props['totals']['quantity']);
        $this->assertSame(800.0, $props['totals']['amount']);
        $this->assertSame(1, $props['totals']['warehouse_count']);
        $this->assertSame(1, $props['totals']['unmarked_count']);

        $this->assertCount(1, $props['products']);
        $this->assertSame('Смазка «Аква»', $props['products'][0]['name']);
        $this->assertSame(2, $props['products'][0]['lines_count']);
        $this->assertSame(800.0, $props['products'][0]['amount']);

        $partners = $this->props($this->actingAs($this->manager)->get('/crm/shortages?tab=partners'))['partners'];

        $this->assertCount(1, $partners);
        $this->assertSame('ООО «Ромашка»', $partners[0]['name']);
        $this->assertSame(2, $partners[0]['lines_count']);
        $this->assertSame(2, $partners[0]['orders_count']);
    }

    #[Test]
    public function manager_marks_who_cancelled_the_line(): void
    {
        ['line' => $line] = $this->makeCancelledLine();

        $this->actingAs($this->manager)
            ->post("/crm/shortages/{$line->id}/source", [
                'source' => 'warehouse',
                'note' => 'мятая упаковка',
            ])
            ->assertRedirect();

        $line->refresh();

        $this->assertSame(CancelSource::WAREHOUSE, $line->cancel_source);
        $this->assertSame('мятая упаковка', $line->cancel_note);
        $this->assertSame($this->manager->id, $line->cancel_source_user_id);
        $this->assertNotNull($line->cancel_source_at);
    }

    #[Test]
    public function mark_can_be_removed(): void
    {
        ['line' => $line] = $this->makeCancelledLine();

        $line->forceFill([
            'cancel_source' => CancelSource::CLIENT,
            'cancel_source_user_id' => $this->manager->id,
            'cancel_source_at' => now(),
        ])->save();

        $this->actingAs($this->manager)
            ->post("/crm/shortages/{$line->id}/source", ['source' => null, 'note' => null])
            ->assertRedirect();

        $line->refresh();

        $this->assertNull($line->cancel_source);
        $this->assertNull($line->cancel_source_user_id);
        $this->assertNull($line->cancel_source_at);
    }

    #[Test]
    public function manager_cannot_mark_a_line_of_another_managers_partner(): void
    {
        $otherProfile = PersonalManager::factory()->create();
        $otherClient = User::factory()->create(['personal_manager_id' => $otherProfile->id]);
        ['line' => $foreign] = $this->makeCancelledLine($otherClient);

        $this->actingAs($this->manager)
            ->post("/crm/shortages/{$foreign->id}/source", ['source' => 'client'])
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->cancel_source);
    }

    #[Test]
    public function goods_issue_by_the_order_hints_at_the_warehouse(): void
    {
        ['line' => $line, 'order' => $order, 'product' => $product] = $this->makeCancelledLine();

        $issue = GoodsIssue::factory()->create([
            'number' => 'УТ-00009419',
            'status' => GoodsIssue::STATUS_SHIPPED,
        ]);
        GoodsIssueItem::factory()->create([
            'goods_issue_id' => $issue->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($this->manager)->get('/crm/shortages');
        $row = $this->props($response)['rows']['data'][0];

        // Тот же товар в расходном ордере — строку дробили при сборке.
        $this->assertSame(CancellationHintResolver::HINT_WAREHOUSE_STRONG, $row['hint']['kind']);
        $this->assertSame('УТ-00009419', $row['hint']['issues'][0]['number']);
        $this->assertSame($line->id, $row['id']);
    }

    #[Test]
    public function without_goods_issue_the_hint_says_there_is_no_warehouse_trace(): void
    {
        $this->makeCancelledLine();

        $response = $this->actingAs($this->manager)->get('/crm/shortages');
        $row = $this->props($response)['rows']['data'][0];

        $this->assertSame(CancellationHintResolver::HINT_NONE, $row['hint']['kind']);
        $this->assertSame([], $row['hint']['issues']);
    }

    #[Test]
    public function archived_lines_leave_the_working_list_but_stay_under_the_filter(): void
    {
        ['line' => $active] = $this->makeCancelledLine();
        ['line' => $archived] = $this->makeCancelledLine();
        $archived->forceFill(['cancel_archived_at' => now()])->save();

        $working = $this->actingAs($this->manager)->get('/crm/shortages');
        $rows = $this->props($working)['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($active->id, $rows[0]['id']);

        $archive = $this->actingAs($this->manager)->get('/crm/shortages?state=archived');
        $archivedRows = $this->props($archive)['rows']['data'];

        $this->assertCount(1, $archivedRows);
        $this->assertSame($archived->id, $archivedRows[0]['id']);
        $this->assertNotNull($archivedRows[0]['archived_at']);

        $all = $this->actingAs($this->manager)->get('/crm/shortages?state=all');
        $this->assertCount(2, $this->props($all)['rows']['data']);
    }
}
