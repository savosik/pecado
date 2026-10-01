<?php

namespace Tests\Feature\Erp;

use App\Enums\DeliveryMethod;
use App\Models\ErpBusMessage;
use App\Models\ErpValidationError;
use App\Models\GoodsIssue;
use App\Models\GoodsIssuePackage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Delivery\MeasuredPlacesGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Обмер грузовых мест расходного ордера (v16.14.0, топик Agent Hub №13) — тест-план 1.22 F–S.
 *
 * Сообщения идут через {@see ErpIncomingJob} целиком: JSON Schema → защита по ревизии →
 * handler → маппер (сверка мест по uuid, состояние обмера) → журнал шины. Случаи R и S
 * (откат сохранения и сбой записи коробки в 1С) — на стороне 1С: сайт сообщения не получает;
 * их сайтовая часть — «done без обмеренного места невозможен» — покрыта случаем Q.
 */
class GoodsIssueMeasurementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const GI_UUID = '00000000-0000-4000-a000-000000001301';

    private const PRODUCT_UUID = '00000000-0000-4000-a000-0000000013b1';

    private const BOX = '00000000-0000-4000-a000-0000000013c1';

    private const PALLET = '00000000-0000-4000-a000-0000000013c2';

    private const NEW_BOX = '00000000-0000-4000-a000-0000000013c3';

    /** Образец 1С из seq 6: 39 цифр, без ведущих нулей. */
    private const BARCODE = '200683171295922156459098038718576216010';

    private Order $deliveryOrder;

    private Order $pickupOrder;

    protected function setUp(): void
    {
        parent::setUp();

        config(['erp.bus_logging_enabled' => true]);

        Product::factory()->create(['external_id' => self::PRODUCT_UUID]);

        $client = User::factory()->create();
        $this->deliveryOrder = Order::factory()->create(['user_id' => $client->id, 'delivery_method' => DeliveryMethod::DELIVERY]);
        $this->pickupOrder = Order::factory()->create(['user_id' => $client->id, 'delivery_method' => DeliveryMethod::PICKUP]);
    }

    private function fire(array $payload): void
    {
        $amqp = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqp->method('getBody')->willReturn(json_encode($payload));
        $amqp->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqp,
            'rabbitmq-erp-incoming',
            'erp_in.warehouse',
        ))->fire();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<Order>|null  $orders  заказы-распоряжения строк; null — один заказ на доставку
     * @return array<string, mixed>
     */
    private function message(array $extra = [], ?array $orders = null, string $event = 'goods_issue.updated'): array
    {
        $orders ??= [$this->deliveryOrder];

        return array_merge([
            'event' => $event,
            'message_id' => 'msg-gi-13-'.uniqid('', true),
            'uuid' => self::GI_UUID,
            'number' => 'УТ-00013001',
            'date' => '2026-09-29T10:00:00+03:00',
            'status' => GoodsIssue::STATUS_CHECKING,
            'recipient_name' => 'ООО «Тест»',
            'items' => array_map(fn (Order $order, int $i): array => [
                'line_number' => $i + 1,
                'product_uuid' => self::PRODUCT_UUID,
                'product_name' => 'Товар',
                'order_uuid' => $order->uuid,
                'order_number' => (string) $order->erp_number,
                'quantity' => 2,
            ], $orders, array_keys($orders)),
        ], $extra);
    }

    /**
     * Сообщение нового формата: revision + shipping_mode + measurement + места с uuid.
     *
     * @param  list<array<string, mixed>>  $packages
     * @param  array<string, mixed>  $extra
     * @param  list<Order>|null  $orders
     * @return array<string, mixed>
     */
    private function snapshot(int $revision, string $state, array $packages, ?string $mode = 'delivery', array $extra = [], ?array $orders = null): array
    {
        return $this->message(array_merge([
            'revision' => $revision,
            'shipping_mode' => $mode,
            'measurement' => $this->measurement($state),
            'packages' => $packages,
        ], $extra), $orders);
    }

    /**
     * @return array<string, mixed>
     */
    private function measurement(string $state, array $override = []): array
    {
        return array_merge([
            'required' => $state !== 'not_required',
            'state' => $state,
            'measured_at' => $state === 'done' ? '2026-09-29T14:12:00+03:00' : null,
            'measured_by' => $state === 'done' ? 'Иванов И.И.' : null,
        ], $override);
    }

    /**
     * @return array<string, mixed>
     */
    private function box(array $override = []): array
    {
        return array_merge([
            'uuid' => self::BOX,
            'number' => 1,
            'barcode' => self::BARCODE,
            'package_type' => 'box',
            'positions_count' => 2,
            'weight' => 12.4,
            'dimensions' => ['length' => 60, 'width' => 40, 'height' => 35],
        ], $override);
    }

    /**
     * @return array<string, mixed>
     */
    private function pallet(array $override = []): array
    {
        return array_merge([
            'uuid' => self::PALLET,
            'number' => 2,
            'package_type' => 'pallet',
            'positions_count' => 1,
            'weight' => 218,
            'dimensions' => ['length' => 120, 'width' => 80, 'height' => 145],
        ], $override);
    }

    private function issue(): GoodsIssue
    {
        return GoodsIssue::withTrashed()->where('uuid', self::GI_UUID)->firstOrFail();
    }

    /**
     * @return array{verdict: string, blocks: bool, message: string|null, site_mode: string|null, places: list<array<string, mixed>>}
     */
    private function gate(): array
    {
        return app(MeasuredPlacesGate::class)->evaluate($this->issue());
    }

    // ───────────────────────── F ─────────────────────────

    #[Test]
    public function f_delivery_with_two_places_of_different_types_is_ready_for_calculation(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box(), $this->pallet()]));

        $this->assertSame(0, ErpValidationError::query()->count());

        $issue = $this->issue();
        $this->assertSame(10, $issue->applied_revision);
        $this->assertSame('delivery', $issue->shipping_mode);
        $this->assertTrue($issue->measurement_required);
        $this->assertSame(GoodsIssue::MEASUREMENT_DONE, $issue->measurement_state);
        $this->assertSame('Иванов И.И.', $issue->measured_by);
        $this->assertSame('2026-09-29 14:12:00', $issue->measured_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, (int) $issue->packages_count);

        $box = $issue->packages->firstWhere('uuid', self::BOX);
        $this->assertSame('box', $box->package_type);
        $this->assertEquals(12.4, (float) $box->weight);
        $this->assertSame([60, 40, 35], [$box->length, $box->width, $box->height]);
        $this->assertSame(0.084, $box->volumeM3());

        $pallet = $issue->packages->firstWhere('uuid', self::PALLET);
        $this->assertSame('pallet', $pallet->package_type);
        $this->assertEquals(218.0, (float) $pallet->weight);
        $this->assertSame(1.392, $pallet->volumeM3());

        $gate = $this->gate();
        $this->assertSame(MeasuredPlacesGate::READY, $gate['verdict']);
        $this->assertFalse($gate['blocks']);
        // Единицы перевозчика: граммы и сантиметры.
        $this->assertSame([12400, 218000], array_column($gate['places'], 'weight'));
        $this->assertSame([60, 120], array_column($gate['places'], 'length'));
    }

    #[Test]
    public function barcode_is_stored_as_string_of_variable_length_without_padding(): void
    {
        // Старшие hex-цифры GUID нулевые — строка короче 39 знаков; ведущих нулей нет.
        $short = '9007199254740993123';

        $this->fire($this->snapshot(1, 'pending', [
            $this->box(),
            $this->pallet(['barcode' => $short]),
        ]));

        $packages = $this->issue()->packages;

        $this->assertSame(self::BARCODE, $packages->firstWhere('uuid', self::BOX)->barcode);
        $this->assertSame($short, $packages->firstWhere('uuid', self::PALLET)->barcode);
        $this->assertSame(1, GoodsIssuePackage::query()->where('barcode', $short)->count());
    }

    // ───────────────────────── G ─────────────────────────

    #[Test]
    public function g_measurement_saved_without_status_change_adds_no_history_row(): void
    {
        $this->fire($this->snapshot(10, 'pending', [$this->box(['weight' => null, 'dimensions' => null])]));
        $this->fire($this->snapshot(11, 'done', [$this->box()]));

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::STATUS_CHECKING, $issue->status);
        $this->assertSame(GoodsIssue::MEASUREMENT_DONE, $issue->measurement_state);
        $this->assertSame(11, $issue->applied_revision);
        $this->assertEquals(12.4, (float) $issue->packages->first()->weight);
        $this->assertSame(1, $issue->statusHistories()->count(), 'сохранение обмера — не смена статуса');
    }

    // ───────────────────────── H ─────────────────────────

    #[Test]
    public function h_partial_measurement_is_stored_but_not_used_for_calculation(): void
    {
        $this->fire($this->snapshot(10, 'pending', [
            $this->box(),
            $this->pallet(['weight' => null, 'dimensions' => null]),
        ]));

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::MEASUREMENT_PENDING, $issue->measurement_state);
        $this->assertNull($issue->measured_at);
        $this->assertNull($issue->measured_by);

        $this->assertEquals(12.4, (float) $issue->packages->firstWhere('uuid', self::BOX)->weight);
        $this->assertNull($issue->packages->firstWhere('uuid', self::PALLET)->weight);
        $this->assertNull($issue->packages->firstWhere('uuid', self::PALLET)->length);

        $gate = $this->gate();
        $this->assertSame(MeasuredPlacesGate::PENDING, $gate['verdict']);
        $this->assertTrue($gate['blocks']);
        $this->assertSame([], $gate['places']);
    }

    // ───────────────────────── I ─────────────────────────

    #[Test]
    public function i_reposting_without_changes_keeps_done_and_package_rows(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box(), $this->pallet()]));
        $ids = $this->issue()->packages->pluck('id', 'uuid')->all();

        $this->fire($this->snapshot(11, 'done', [$this->box(), $this->pallet()], 'delivery', ['status' => GoodsIssue::STATUS_CHECKED]));

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::MEASUREMENT_DONE, $issue->measurement_state);
        $this->assertSame('2026-09-29 14:12:00', $issue->measured_at->format('Y-m-d H:i:s'));
        $this->assertSame($ids, $issue->packages->pluck('id', 'uuid')->all(), 'строки мест не пересоздаются');
    }

    #[Test]
    public function renumbering_places_keeps_identity_by_uuid(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box(), $this->pallet()]));
        $ids = $this->issue()->packages->pluck('id', 'uuid')->all();

        // 1С поменяла номера листов местами: идентичность держится на uuid.
        $this->fire($this->snapshot(11, 'done', [$this->box(['number' => 2]), $this->pallet(['number' => 1])]));

        $packages = $this->issue()->packages;
        $this->assertEquals($ids, $packages->pluck('id', 'uuid')->all());
        $this->assertSame(2, (int) $packages->firstWhere('uuid', self::BOX)->number);
        $this->assertSame(1, (int) $packages->firstWhere('uuid', self::PALLET)->number);
    }

    // ───────────────────────── J ─────────────────────────

    #[Test]
    public function j_moving_goods_between_places_resets_measurement(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box(), $this->pallet()]));
        $this->assertSame(MeasuredPlacesGate::READY, $this->gate()['verdict']);

        $this->fire($this->snapshot(11, 'pending', [
            $this->box(['positions_count' => 1]),
            $this->pallet(['positions_count' => 2]),
        ]));

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::MEASUREMENT_PENDING, $issue->measurement_state);
        $this->assertNull($issue->measured_at);
        $this->assertNull($issue->measured_by);
        $this->assertSame(MeasuredPlacesGate::PENDING, $this->gate()['verdict']);
    }

    // ───────────────────────── K ─────────────────────────

    #[Test]
    public function k_deleting_and_recreating_a_place_replaces_only_that_place(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box(), $this->pallet()]));
        $ids = $this->issue()->packages->pluck('id', 'uuid')->all();

        $this->fire($this->snapshot(11, 'pending', [
            $this->box(['uuid' => self::NEW_BOX, 'barcode' => '77', 'weight' => null, 'dimensions' => null]),
            $this->pallet(),
        ]));

        $packages = $this->issue()->packages;
        $this->assertCount(2, $packages);
        $this->assertNull($packages->firstWhere('uuid', self::BOX), 'место со старым uuid удалено');
        $this->assertNotNull($packages->firstWhere('uuid', self::NEW_BOX));
        $this->assertSame($ids[self::PALLET], $packages->firstWhere('uuid', self::PALLET)->id, 'нетронутое место сохранило строку');
        $this->assertSame(2, (int) $this->issue()->packages_count);
    }

    #[Test]
    public function legacy_places_without_uuid_are_replaced_by_the_first_new_snapshot(): void
    {
        $this->fire($this->message(['packages' => [['number' => 1, 'positions_count' => 3], ['number' => 2]]]));
        $this->assertSame(2, $this->issue()->packages()->whereNull('uuid')->count());

        $this->fire($this->snapshot(1, 'pending', [$this->box(['weight' => null, 'dimensions' => null])]));

        $packages = $this->issue()->packages;
        $this->assertCount(1, $packages);
        $this->assertSame(self::BOX, $packages->first()->uuid);
    }

    // ───────────────────────── L ─────────────────────────

    #[Test]
    public function l_changing_delivery_method_republishes_mode_and_resets_measurement(): void
    {
        $this->fire($this->snapshot(10, 'not_required', [$this->box(['weight' => null, 'dimensions' => null])], 'pickup', [], [$this->pickupOrder]));

        $issue = $this->issue();
        $this->assertSame('pickup', $issue->shipping_mode);
        $this->assertFalse($issue->measurement_required);
        $this->assertSame(MeasuredPlacesGate::NOT_REQUIRED, $this->gate()['verdict']);

        // Менеджер сменил самовывоз на доставку: заказ пришёл по order.updated, ордер — новым снимком.
        $this->pickupOrder->forceFill(['delivery_method' => DeliveryMethod::DELIVERY])->saveQuietly();
        $this->fire($this->snapshot(11, 'pending', [$this->box(['weight' => null, 'dimensions' => null])], 'delivery', [], [$this->pickupOrder]));

        $issue = $this->issue();
        $this->assertSame('delivery', $issue->shipping_mode);
        $this->assertTrue($issue->measurement_required);
        $this->assertSame(GoodsIssue::MEASUREMENT_PENDING, $issue->measurement_state);
        $this->assertSame(MeasuredPlacesGate::PENDING, $this->gate()['verdict']);
    }

    // ───────────────────────── M ─────────────────────────

    #[Test]
    public function m_mixed_issue_is_calculated_by_all_places_once(): void
    {
        $orders = [$this->deliveryOrder, $this->pickupOrder];
        $this->fire($this->snapshot(10, 'done', [$this->box(), $this->pallet()], 'mixed', [], $orders));

        $gate = $this->gate();
        $this->assertSame(MeasuredPlacesGate::READY, $gate['verdict']);
        $this->assertFalse($gate['blocks']);
        $this->assertSame('mixed', $gate['site_mode']);
        $this->assertCount(2, $gate['places'], 'в расчёт идут все места ордера');
        $this->assertStringContainsString('весь ордер', (string) $gate['message']);

        // Оба заказа ордера ведут к одному ордеру — места в расчёт входят один раз.
        $measurement = app(MeasuredPlacesGate::class);
        $issues = $measurement->issuesForOrders([$this->deliveryOrder->uuid, $this->pickupOrder->uuid]);
        $this->assertCount(1, $issues);
        $this->assertNull($measurement->blockingMessage([$this->deliveryOrder->uuid, $this->pickupOrder->uuid]));
    }

    #[Test]
    public function m_unknown_shipping_mode_blocks_calculation_and_is_journaled(): void
    {
        $this->fire($this->snapshot(10, 'pending', [$this->box(['weight' => null, 'dimensions' => null])], null));

        $issue = $this->issue();
        $this->assertNull($issue->shipping_mode);
        $this->assertTrue($issue->measurement_required);
        $this->assertSame(GoodsIssue::MEASUREMENT_PENDING, $issue->measurement_state);

        $gate = $this->gate();
        $this->assertSame(MeasuredPlacesGate::MODE_UNKNOWN, $gate['verdict']);
        $this->assertTrue($gate['blocks']);
        $this->assertStringContainsString('обратитесь к менеджеру', (string) $gate['message']);

        $log = ErpBusMessage::query()->where('event', 'goods_issue.updated')->latest('id')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('shipping_mode = null', (string) $log->error_message);
    }

    #[Test]
    public function m_unknown_mode_blocks_even_when_measurement_is_done(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box()], null));

        $this->assertSame(MeasuredPlacesGate::MODE_UNKNOWN, $this->gate()['verdict']);
    }

    #[Test]
    public function m_pickup_from_erp_against_delivery_orders_blocks_and_is_journaled(): void
    {
        $this->fire($this->snapshot(10, 'not_required', [$this->box(['weight' => null, 'dimensions' => null])], 'pickup'));

        $gate = $this->gate();
        $this->assertSame(MeasuredPlacesGate::MODE_MISMATCH, $gate['verdict']);
        $this->assertTrue($gate['blocks']);

        $log = ErpBusMessage::query()->where('event', 'goods_issue.updated')->latest('id')->firstOrFail();
        $this->assertStringContainsString('Способ доставки расходится', (string) $log->error_message);
    }

    #[Test]
    public function m_delivery_from_erp_against_pickup_orders_blocks(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box()], 'delivery', [], [$this->pickupOrder]));

        $this->assertSame(MeasuredPlacesGate::MODE_MISMATCH, $this->gate()['verdict']);
    }

    // ───────────────────────── N ─────────────────────────

    #[Test]
    public function n_pickup_issue_needs_no_measurement(): void
    {
        $this->fire($this->snapshot(10, 'not_required', [$this->box(['weight' => null, 'dimensions' => null])], 'pickup', [], [$this->pickupOrder]));

        $issue = $this->issue();
        $this->assertSame('pickup', $issue->shipping_mode);
        $this->assertFalse($issue->measurement_required);
        $this->assertSame(GoodsIssue::MEASUREMENT_NOT_REQUIRED, $issue->measurement_state);

        $gate = $this->gate();
        $this->assertSame(MeasuredPlacesGate::NOT_REQUIRED, $gate['verdict']);
        $this->assertFalse($gate['blocks']);

        $log = ErpBusMessage::query()->where('event', 'goods_issue.updated')->latest('id')->firstOrFail();
        $this->assertNull($log->error_message);
    }

    // ───────────────────────── O ─────────────────────────

    #[Test]
    public function o_late_done_after_pending_is_dropped_as_stale(): void
    {
        $this->fire($this->snapshot(19, 'pending', [$this->box(['weight' => null, 'dimensions' => null])]));

        $pending = $this->snapshot(21, 'pending', [$this->box(['weight' => null, 'dimensions' => null])]);
        $done = $this->snapshot(20, 'done', [$this->box()]);

        // Брокер доставил в обратном порядке: сначала 21, потом 20.
        $this->fire($pending);
        $this->fire($done);

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::MEASUREMENT_PENDING, $issue->measurement_state);
        $this->assertSame(21, $issue->applied_revision);
        $this->assertNull($issue->packages->first()->weight);

        $stale = ErpBusMessage::query()->where('status', 'stale')->firstOrFail();
        $this->assertStringContainsString('ревизия 20', (string) $stale->error_message);

        // Повтор того же message_id — транспортная идемпотентность, второй записи в журнале нет.
        $before = ErpBusMessage::query()->count();
        $this->fire($pending);
        $this->assertSame($before, ErpBusMessage::query()->count());
    }

    #[Test]
    public function equal_revision_is_dropped(): void
    {
        $this->fire($this->snapshot(5, 'done', [$this->box()]));
        $this->fire($this->snapshot(5, 'pending', [$this->box(['weight' => null, 'dimensions' => null])]));

        $this->assertSame(GoodsIssue::MEASUREMENT_DONE, $this->issue()->measurement_state);
    }

    // ───────────────────────── P ─────────────────────────

    #[Test]
    public function p_legacy_message_after_a_revision_is_dropped(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box(), $this->pallet()]));

        $this->fire($this->message(['status' => GoodsIssue::STATUS_SHIPPED, 'packages' => [['number' => 1]]]));

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::STATUS_CHECKING, $issue->status, 'опоздавший снимок старого формата не применён');
        $this->assertSame(GoodsIssue::MEASUREMENT_DONE, $issue->measurement_state);
        $this->assertCount(2, $issue->packages);

        $stale = ErpBusMessage::query()->where('status', 'stale')->firstOrFail();
        $this->assertStringContainsString('без revision', (string) $stale->error_message);
    }

    #[Test]
    public function p_legacy_messages_before_the_first_revision_are_applied_as_before(): void
    {
        $this->fire($this->message(['status' => GoodsIssue::STATUS_TO_PICK], null, 'goods_issue.created'));
        $this->fire($this->message(['status' => GoodsIssue::STATUS_TO_CHECK]));

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::STATUS_TO_CHECK, $issue->status);
        $this->assertNull($issue->applied_revision);
        $this->assertNull($issue->measurement_state);
        $this->assertSame(MeasuredPlacesGate::LEGACY, $this->gate()['verdict']);
        $this->assertFalse($this->gate()['blocks']);
        $this->assertSame(0, ErpBusMessage::query()->where('status', 'stale')->count());
    }

    #[Test]
    public function p_done_without_revision_is_stored_as_pending_and_journaled(): void
    {
        $this->fire($this->message([
            'shipping_mode' => 'delivery',
            'measurement' => $this->measurement('done'),
            'packages' => [$this->box()],
        ]));

        $issue = $this->issue();
        $this->assertSame(GoodsIssue::MEASUREMENT_PENDING, $issue->measurement_state);
        $this->assertTrue($issue->measurement_required);
        $this->assertNull($issue->measured_at);
        $this->assertNull($issue->measured_by);
        $this->assertNull($issue->applied_revision);
        $this->assertSame(MeasuredPlacesGate::PENDING, $this->gate()['verdict']);

        $log = ErpBusMessage::query()->where('event', 'goods_issue.updated')->latest('id')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('только из сообщения с revision', (string) $log->error_message);
    }

    // ───────────────────────── Q ─────────────────────────

    #[Test]
    public function q_done_violations_are_rejected_by_schema_and_do_not_touch_the_issue(): void
    {
        $this->fire($this->snapshot(10, 'pending', [$this->box(['weight' => null, 'dimensions' => null])]));

        $bad = [
            'пустые места' => $this->snapshot(11, 'done', []),
            'нулевой вес' => $this->snapshot(12, 'done', [$this->box(['weight' => 0])]),
            'нулевая высота' => $this->snapshot(13, 'done', [$this->box(['dimensions' => ['length' => 60, 'width' => 40, 'height' => 0]])]),
            'нет упаковщика' => $this->snapshot(14, 'done', [$this->box()], 'delivery', ['measurement' => $this->measurement('done', ['measured_by' => null])]),
            'место без uuid' => $this->snapshot(15, 'done', [$this->box(['uuid' => null])]),
            'pending с датой' => $this->snapshot(16, 'pending', [$this->box()], 'delivery', ['measurement' => $this->measurement('pending', ['measured_at' => '2026-09-29T14:12:00+03:00'])]),
            'not_required c required' => $this->snapshot(17, 'not_required', [$this->box()], 'pickup', ['measurement' => $this->measurement('not_required', ['required' => true])]),
            'чужой способ' => $this->snapshot(18, 'pending', [$this->box()], 'courier'),
            'чужой тип места' => $this->snapshot(19, 'pending', [$this->box(['package_type' => 'crate'])]),
            'ревизия без measurement' => $this->message(['revision' => 20, 'packages' => [$this->box()]]),
        ];

        foreach ($bad as $case => $payload) {
            $this->fire($payload);

            $issue = $this->issue();
            $this->assertSame(10, $issue->applied_revision, $case);
            $this->assertSame(GoodsIssue::MEASUREMENT_PENDING, $issue->measurement_state, $case);
        }

        $this->assertSame(count($bad), ErpValidationError::query()->count());
        $this->assertSame(count($bad), ErpBusMessage::query()->where('status', 'failed')->count());
    }

    // ───────────────────────── Ревизии: deleted и повторное проведение ─────────────────────────

    #[Test]
    public function deleted_continues_the_counter_and_reposting_restores_the_issue(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box()]));

        $this->fire(['event' => 'goods_issue.deleted', 'message_id' => 'msg-del-11', 'uuid' => self::GI_UUID, 'revision' => 11]);

        $issue = $this->issue();
        $this->assertTrue($issue->trashed());
        $this->assertSame(11, $issue->applied_revision);

        // Опоздавший снимок до удаления не воскрешает ордер.
        $this->fire($this->snapshot(9, 'pending', [$this->box(['weight' => null, 'dimensions' => null])]));
        $this->assertTrue($this->issue()->trashed());

        // Опоздавший снимок старого формата — тоже.
        $this->fire($this->message());
        $this->assertTrue($this->issue()->trashed());

        // Повторное проведение продолжает счётчик.
        $this->fire($this->snapshot(12, 'done', [$this->box()], 'delivery', [], null));

        $issue = $this->issue();
        $this->assertFalse($issue->trashed());
        $this->assertSame(12, $issue->applied_revision);
        $this->assertSame(GoodsIssue::MEASUREMENT_DONE, $issue->measurement_state);
    }

    #[Test]
    public function stale_deleted_does_not_remove_a_newer_issue(): void
    {
        $this->fire($this->snapshot(10, 'done', [$this->box()]));

        $this->fire(['event' => 'goods_issue.deleted', 'message_id' => 'msg-del-9', 'uuid' => self::GI_UUID, 'revision' => 9]);
        $this->fire(['event' => 'goods_issue.deleted', 'message_id' => 'msg-del-legacy', 'uuid' => self::GI_UUID]);

        $this->assertFalse($this->issue()->trashed());
        $this->assertSame(2, ErpBusMessage::query()->where('status', 'stale')->count());
    }

    // ───────────────────────── Совместимость с 16.15.0 ─────────────────────────

    #[Test]
    public function empty_shipped_with_revision_clears_places_and_needs_no_measurement(): void
    {
        $this->fire($this->snapshot(10, 'pending', [$this->box()]));

        $this->fire($this->message([
            'revision' => 11,
            'status' => GoodsIssue::STATUS_SHIPPED,
            'items' => [],
            'packages' => [],
            'shipping_mode' => 'delivery',
            'measurement' => $this->measurement('not_required'),
        ]));

        $this->assertSame(0, ErpValidationError::query()->count());

        $issue = $this->issue();
        $this->assertTrue($issue->isShippedEmpty());
        $this->assertSame(GoodsIssue::MEASUREMENT_NOT_REQUIRED, $issue->measurement_state);
        $this->assertCount(0, $issue->packages);
        $this->assertSame(1, $issue->items()->count(), 'строки последней сборки сохранены');

        $gate = $this->gate();
        $this->assertSame(MeasuredPlacesGate::NOT_REQUIRED, $gate['verdict']);
        $this->assertFalse($gate['blocks']);
    }
}
