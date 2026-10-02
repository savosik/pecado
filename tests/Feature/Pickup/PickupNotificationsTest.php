<?php

namespace Tests\Feature\Pickup;

use App\Enums\DeliveryMethod;
use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\GoodsIssue;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Pickup\HandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\EnablesClientNotifications;
use Tests\TestCase;

/** pick-12: письма клиенту «собран, ждёт выдачи» и «выдан курьеру». */
class PickupNotificationsTest extends TestCase
{
    use EnablesClientNotifications, PickupTestHelpers, RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.handover_since' => null, 'mail_stream.enabled' => true]);

        // У письма потока обязателен автор — персональный менеджер клиента.
        $manager = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $manager->id]);
        Company::factory()->create(['user_id' => $this->client->id]);
    }

    private function emails(string $event): int
    {
        return CrmEmail::query()->where('origin_event', $event)->count();
    }

    #[Test]
    public function ready_letter_goes_by_default_when_whole_order_is_assembled(): void
    {
        $order = $this->pickupOrder($this->client);
        $first = $this->goodsIssueFor($order, GoodsIssue::STATUS_TO_SHIP);
        $second = $this->goodsIssueFor($order, GoodsIssue::STATUS_TO_PICK);

        $this->moveIssue($first, GoodsIssue::STATUS_SHIPPED);
        $this->assertSame(0, $this->emails('orders.ready_for_pickup'), 'собрана только часть заказа — курьера слать рано');

        $this->moveIssue($second, GoodsIssue::STATUS_SHIPPED);
        $this->assertSame(1, $this->emails('orders.ready_for_pickup'));
    }

    #[Test]
    public function delivery_orders_and_switched_off_feature_stay_silent(): void
    {
        $delivery = $this->goodsIssueFor($this->pickupOrder($this->client, ['delivery_method' => DeliveryMethod::DELIVERY]), GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($delivery, GoodsIssue::STATUS_SHIPPED);

        config(['pickup.enabled' => false]);
        $pickup = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($pickup, GoodsIssue::STATUS_SHIPPED);

        $this->assertSame(0, $this->emails('orders.ready_for_pickup'));
    }

    #[Test]
    public function rollback_and_reassembly_is_a_new_occasion_not_a_duplicate(): void
    {
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_SHIP);

        $this->moveIssue($issue, GoodsIssue::STATUS_SHIPPED);
        $this->moveIssue($issue, GoodsIssue::STATUS_TO_PICK);
        $this->travel(30)->minutes();
        $this->moveIssue($issue->fresh(), GoodsIssue::STATUS_SHIPPED);

        $this->assertSame(2, $this->emails('orders.ready_for_pickup'));
    }

    #[Test]
    public function several_orders_in_one_set_give_one_letter_not_one_per_order(): void
    {
        // Совместимость с будущим объединением отгрузок: один ордер на несколько заказов — одно письмо.
        $a = $this->pickupOrder($this->client, ['erp_number' => '29УТ-030001']);
        $b = $this->pickupOrder($this->client, ['erp_number' => '29УТ-030002']);
        $issue = $this->goodsIssueFor([$a, $b], GoodsIssue::STATUS_TO_SHIP);

        $this->moveIssue($issue, GoodsIssue::STATUS_SHIPPED);

        $this->assertSame(1, $this->emails('orders.ready_for_pickup'));
        $this->assertStringContainsString('29УТ-030001, 29УТ-030002', (string) CrmEmail::query()->where('origin_event', 'orders.ready_for_pickup')->value('subject'));
    }

    #[Test]
    public function shortfall_during_picking_tells_client_to_order_something_else(): void
    {
        $order = $this->pickupOrder($this->client);
        event(new \App\Events\Order\OrderItemsCancelled($order, [['name' => 'Массажное масло', 'quantity' => 2.0]]));

        $email = CrmEmail::query()->where('origin_event', 'orders.items_unavailable')->first();
        $this->assertNotNull($email);
        $this->assertStringContainsString('Массажное масло — 2 шт.', (string) $email->body_html.$email->body_text);

        // В окне резерва строки уменьшает сам клиент — это не недобор склада.
        $reserved = $this->pickupOrder($this->client, ['reserve' => true, 'reserved_until' => now()->addDay()]);
        event(new \App\Events\Order\OrderItemsCancelled($reserved, [['name' => 'Свеча', 'quantity' => 1.0]]));
        $this->assertSame(1, $this->emails('orders.items_unavailable'));
    }

    #[Test]
    public function erp_reason_decides_whether_cancellation_is_a_shortfall(): void
    {
        // v16.17.0: причина отмены из 1С. Клиент снял строку сам или истёк резерв — это не нехватка.
        $order = $this->pickupOrder($this->client);

        foreach (['client', 'reserve_expired'] as $reason) {
            event(new \App\Events\Order\OrderItemsCancelled($order, [['name' => 'Свеча', 'quantity' => 1.0, 'reason' => $reason]]));
        }
        $this->assertSame(0, $this->emails('orders.items_unavailable'));

        // В одном сообщении и нехватка, и отказ клиента: в письме только то, чего не хватило.
        event(new \App\Events\Order\OrderItemsCancelled($order, [
            ['name' => 'Массажное масло', 'quantity' => 2.0, 'reason' => 'out_of_stock'],
            ['name' => 'Свеча', 'quantity' => 1.0, 'reason' => 'client'],
        ]));

        $email = CrmEmail::query()->where('origin_event', 'orders.items_unavailable')->sole();
        $body = (string) $email->body_html.$email->body_text;
        $this->assertStringContainsString('Массажное масло — 2 шт.', $body);
        $this->assertStringNotContainsString('Свеча', $body);
        // Отмена при приёме заказа случается до сборки — «при сборке не хватило» было бы неправдой.
        $this->assertStringContainsString('не оказалось в наличии', $body);
        $this->assertStringNotContainsString('При сборке', $body);
    }

    #[Test]
    public function substandard_defect_and_supplier_reasons_are_shortfalls_too(): void
    {
        // Топики №17 и №18: некондиция и брак при сборке, «поставщик не привёз» по предзаказу —
        // клиенту это та же нехватка, письмо уходит как по `shortage`, в том числе по заказу в резерве.
        foreach (['substandard', 'defect', 'supplier_unavailable'] as $i => $reason) {
            $order = $this->pickupOrder($this->client, ['erp_number' => '29УТ-03100'.$i]);
            event(new \App\Events\Order\OrderItemsCancelled($order, [['name' => 'Свеча', 'quantity' => 1.0, 'reason' => $reason]]));
            $this->assertSame($i + 1, $this->emails('orders.items_unavailable'), $reason);
            $this->travel(10)->minutes();
        }

        $reserved = $this->pickupOrder($this->client, ['reserve' => true, 'reserved_until' => now()->addDay(), 'erp_number' => '29УТ-031009']);
        event(new \App\Events\Order\OrderItemsCancelled($reserved, [['name' => 'Гель', 'quantity' => 1.0, 'reason' => 'defect']]));
        $this->assertSame(4, $this->emails('orders.items_unavailable'));
    }

    #[Test]
    public function supplier_unavailable_letter_does_not_blame_picking(): void
    {
        $order = $this->pickupOrder($this->client);
        event(new \App\Events\Order\OrderItemsCancelled($order, [['name' => 'Массажное масло', 'quantity' => 2.0, 'reason' => 'supplier_unavailable']]));

        $email = CrmEmail::query()->where('origin_event', 'orders.items_unavailable')->sole();
        $body = (string) $email->body_html.$email->body_text;
        $this->assertStringContainsString('Поставщик не привёз', $body);
        $this->assertStringContainsString('Массажное масло — 2 шт.', $body);
        $this->assertStringNotContainsString('При сборке', $body);
    }

    #[Test]
    public function confirmed_stock_shortfall_is_reported_even_for_an_order_in_reserve(): void
    {
        // Инцидент 23.09.2026: 1С отменяет строку без остатка сразу при приёме заказа, а заказ интернет-магазина
        // в этот момент в резерве. Без причины сайт в окне резерва молчит (строки уменьшает сам клиент),
        // с причиной «нет остатка» / «недобор при сборке» — обязан сказать.
        $reserved = $this->pickupOrder($this->client, ['reserve' => true, 'reserved_until' => now()->addDay()]);

        foreach ([null, 'client', 'reserve_expired', 'other'] as $reason) {
            event(new \App\Events\Order\OrderItemsCancelled($reserved, [['name' => 'Свеча', 'quantity' => 1.0, 'reason' => $reason]]));
        }
        $this->assertSame(0, $this->emails('orders.items_unavailable'));

        event(new \App\Events\Order\OrderItemsCancelled($reserved, [['name' => 'Гель', 'quantity' => 1.0, 'reason' => 'out_of_stock']]));
        $this->assertSame(1, $this->emails('orders.items_unavailable'));
    }

    #[Test]
    public function split_on_acceptance_through_the_bus_sends_one_letter_and_redelivery_none(): void
    {
        $product = \App\Models\Product::factory()->create(['external_id' => '00000000-0000-4000-a000-0000000017a9', 'name' => 'Гель-смазка']);
        $order = $this->pickupOrder($this->client, ['reserve' => true, 'reserved_until' => now()->addDay()]);
        $order->items()->create([
            'product_id' => $product->id, 'line_number' => 1, 'name' => 'Гель-смазка',
            'quantity' => 3, 'price' => 1000, 'final_price' => 1000, 'base_price' => 1000, 'subtotal' => 3000,
        ]);

        $payload = [
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'items' => [
                ['line_number' => 1, 'product_uuid' => $product->external_id, 'quantity' => 2, 'base_price' => 1000, 'discount_percent' => 0, 'final_price' => 1000, 'cancelled' => false],
                ['line_number' => 2, 'product_uuid' => $product->external_id, 'quantity' => 1, 'base_price' => 1000, 'discount_percent' => 0, 'final_price' => 1000, 'cancelled' => true, 'cancel_reason' => 'out_of_stock'],
            ],
        ];

        app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle($payload);
        // Тот же состав приходит снова с каждым следующим order.updated — повод уже отработан.
        app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle($payload);

        $email = CrmEmail::query()->where('origin_event', 'orders.items_unavailable')->sole();
        $this->assertStringContainsString('Гель-смазка — 1 шт.', (string) $email->body_html.$email->body_text);
    }

    #[Test]
    public function closed_or_old_order_is_not_a_picking_shortfall(): void
    {
        // Инцидент 25.09.2026: 1С закрыла заказы с начала года, отменив непоставленные строки, —
        // клиенты получили письма о «недоборе» по январским заказам.
        $closed = $this->pickupOrder($this->client, ['status' => \App\Enums\OrderStatus::CLOSED]);
        event(new \App\Events\Order\OrderItemsCancelled($closed, [['name' => 'Свеча', 'quantity' => 1.0]]));

        $old = $this->pickupOrder($this->client, ['erp_created_at' => now()->subDays(31)]);
        event(new \App\Events\Order\OrderItemsCancelled($old, [['name' => 'Свеча', 'quantity' => 1.0]]));

        $this->assertSame(0, $this->emails('orders.items_unavailable'));

        $fresh = $this->pickupOrder($this->client, ['erp_created_at' => now()->subDays(2)]);
        event(new \App\Events\Order\OrderItemsCancelled($fresh, [['name' => 'Свеча', 'quantity' => 1.0]]));

        $this->assertSame(1, $this->emails('orders.items_unavailable'));
    }

    #[Test]
    public function order_closed_by_erp_with_cancelled_lines_sends_no_shortfall_letter(): void
    {
        // Сквозь обработчик шины: тот же payload, что 1С слала 25.09.2026 при массовом закрытии.
        $product = \App\Models\Product::factory()->create(['external_id' => '00000000-0000-4000-a000-0000000025a9']);

        $closing = $this->pickupOrder($this->client, ['erp_created_at' => now()->subMonths(8)]);
        $picking = $this->pickupOrder($this->client);

        foreach ([[$closing, 'закрыт'], [$picking, 'в процессе отгрузки']] as [$order, $status]) {
            $order->items()->create([
                'product_id' => $product->id, 'line_number' => 1, 'name' => 'Массажное масло',
                'quantity' => 2, 'price' => 100, 'final_price' => 100, 'base_price' => 100, 'subtotal' => 200,
            ]);

            app(\App\Services\Erp\Handlers\HandleOrderUpdated::class)->handle([
                'event' => 'order.updated',
                'uuid' => $order->uuid,
                'status' => $status,
                'items' => [[
                    'line_number' => 1, 'product_uuid' => $product->external_id, 'quantity' => 2,
                    'base_price' => 100, 'discount_percent' => 0, 'final_price' => 100, 'cancelled' => true,
                ]],
            ]);
        }

        $letters = CrmEmail::query()->where('origin_event', 'orders.items_unavailable')->get();
        $this->assertCount(1, $letters, 'письмо только по заказу, который собирается сейчас');
        $this->assertSame($picking->id, (int) $letters->first()->related_id);
    }

    #[Test]
    public function reminders_refuse_to_run_without_history_cutoff(): void
    {
        // Старые самовывозы не имеют отметки «выдан» — без даты отсечения письма ушли бы по всей истории.
        config(['pickup.handover_since' => null]);
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_SHIPPED, ['status_changed_at' => now()->subDays(40)]);

        $this->artisan('pickup:remind-waiting')->assertFailed();
        $this->assertSame(0, $this->emails('orders.pickup_waiting'));
    }

    #[Test]
    public function long_waiting_set_is_reminded_once_per_step(): void
    {
        config(['pickup.handover_since' => now()->subDays(10)->format('Y-m-d H:i')]);
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_SHIPPED, ['status_changed_at' => now()->subDays(4)]);
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_SHIPPED, ['status_changed_at' => now()->subDay()]);

        $this->artisan('pickup:remind-waiting')->assertSuccessful();
        $this->artisan('pickup:remind-waiting')->assertSuccessful();
        $this->assertSame(1, $this->emails('orders.pickup_waiting'), 'ступень 3 дня — одно письмо, повторный запуск дубля не даёт');

        $this->travel(4)->days();
        $this->artisan('pickup:remind-waiting')->assertSuccessful();
        // Первый заказ дошёл до ступени 7, второй — до ступени 3. Поток писем склеивает поводы одного клиента
        // в окне склейки, поэтому писем не три, а два: клиент получает одно напоминание про оба заказа.
        $this->assertSame(2, $this->emails('orders.pickup_waiting'));
    }

    #[Test]
    public function handed_over_letter_is_opt_in(): void
    {
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client));
        $keeper = User::factory()->create();

        app(HandoverService::class)->issue($issue, $keeper, 'manual');
        $this->assertSame(0, $this->emails('orders.handed_over'), 'выключено умолчанием');

        $second = $this->goodsIssueFor($this->pickupOrder($this->client));
        $this->enableNotificationsFor($this->client, ['orders.handed_over']);
        app(HandoverService::class)->issue($second, $keeper, 'manual', null, ['recipient_name' => 'Олег']);

        $this->assertSame(1, $this->emails('orders.handed_over'));
    }
}
