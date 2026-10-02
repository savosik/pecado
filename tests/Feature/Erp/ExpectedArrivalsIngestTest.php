<?php

namespace Tests\Feature\Erp;

use App\Models\ErpBusMessage;
use App\Models\ErpProcessedMessage;
use App\Models\ErpValidationError;
use App\Models\Product;
use App\Models\ProductExpectedArrival;
use App\Models\ProductExpectedArrivalSnapshot;
use App\Models\Warehouse;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Stock\ExpectedArrivals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Приём `product.expected_arrivals.updated` (v16.16.0, топик №16 Agent Hub).
 *
 * Сообщения идут через `ErpIncomingJob`, то есть сквозь валидацию по JSON Schema,
 * идемпотентность и журнал шины — так же, как их получит боевой воркер очереди
 * `erp_in.expected_arrivals`. Тесты повторяют сценарии тест-плана 1.27 A–G
 * (`docs-erp/content/tests/phase-1-inbound.md`).
 *
 * Даты задаются от «сегодня»: зашитая календарная дата со временем стала бы
 * прошедшей и молча сменила бы смысл проверки.
 */
class ExpectedArrivalsIngestTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_A = '00000000-0000-4000-a000-0000000160a1';

    private const PRODUCT_B = '00000000-0000-4000-a000-0000000160b2';

    private const WH_MAIN = '40301d16-3847-11e1-8034-001e6711ed1d';

    private const WH_TYUMEN = '00000000-0000-4000-a000-000000016002';

    private const WH_FOREIGN = '00000000-0000-4000-a000-0000000160ff';

    private Product $productA;

    private Product $productB;

    private Warehouse $main;

    private Warehouse $tyumen;

    private int $sequence = 0;

    /** @var list<array{released: bool, deleted: bool}> */
    private array $deliveries = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('erp.bus_logging_enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Europe/Moscow'));

        $this->productA = Product::factory()->create(['external_id' => self::PRODUCT_A]);
        $this->productB = Product::factory()->create(['external_id' => self::PRODUCT_B]);
        $this->main = Warehouse::factory()->create(['name' => 'Москва Основной', 'external_id' => self::WH_MAIN]);
        $this->tyumen = Warehouse::factory()->create(['name' => 'Тюмень Основной', 'external_id' => self::WH_TYUMEN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Доставить сообщение так, как это делает воркер очереди.
     *
     * @param  array<string, mixed>  $payload
     * @return array{released: bool, deleted: bool}
     */
    private function fire(array $payload): array
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        $job = new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.expected_arrivals',
        );
        $job->fire();

        return ['released' => $job->isReleased(), 'deleted' => $job->isDeleted()];
    }

    /**
     * Снимок по товару.
     *
     * @param  array<string, list<array{0: string|null, 1: int|float, 2?: string}>>  $warehouses  склад → строки [дата, количество, источник]
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function snapshot(string $productUuid, string $calculatedAt, array $warehouses, array $extra = []): array
    {
        $list = [];

        foreach ($warehouses as $warehouseUuid => $arrivals) {
            $list[] = [
                'warehouse_uuid' => $warehouseUuid,
                'arrivals' => array_map(static fn (array $row) => [
                    'date' => $row[0],
                    'quantity' => $row[1],
                    'source' => $row[2] ?? 'purchase',
                ], $arrivals),
            ];
        }

        return array_merge([
            'event' => 'product.expected_arrivals.updated',
            'message_id' => 'msg-arrivals-'.(++$this->sequence),
            'product_uuid' => $productUuid,
            'calculated_at' => $calculatedAt,
            'warehouses' => $list,
        ], $extra);
    }

    private function day(int $offset): string
    {
        return Carbon::today()->addDays($offset)->toDateString();
    }

    /**
     * Сохранённые строки товара в сравнимом виде: «склад|дата|источник» → количество.
     *
     * @return array<string, float>
     */
    private function stored(Product $product): array
    {
        return ProductExpectedArrival::query()
            ->where('product_id', $product->id)
            ->get()
            ->mapWithKeys(fn (ProductExpectedArrival $row) => [
                $row->warehouse_id.'|'.($row->expected_date?->toDateString() ?? 'null').'|'.$row->source => (float) $row->quantity,
            ])
            ->sortKeys()
            ->all();
    }

    #[Test]
    #[TestDox('1.27 A: снимок по двум складам — две даты на первом, «дата уточняется» на втором')]
    public function snapshot_over_two_warehouses_is_stored(): void
    {
        $delivery = $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:15:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20], [$this->day(28), 12]],
            self::WH_TYUMEN => [[null, 5]],
        ], ['message_id' => 'msg-a']));

        $this->assertSame(['released' => false, 'deleted' => true], $delivery);

        $this->assertEquals([
            $this->main->id.'|'.$this->day(9).'|purchase' => 20.0,
            $this->main->id.'|'.$this->day(28).'|purchase' => 12.0,
            $this->tyumen->id.'|null|purchase' => 5.0,
        ], $this->stored($this->productA));

        $header = ProductExpectedArrivalSnapshot::where('product_id', $this->productA->id)->sole();
        $this->assertSame('2026-10-05 09:15:00', $header->calculated_at->format('Y-m-d H:i:s'));
        $this->assertSame('msg-a', $header->message_id);
        $this->assertSame(3, $header->rows_count);

        $this->assertTrue(ErpProcessedMessage::where('message_id', 'msg-a')->exists());
        $this->assertSame('success', ErpBusMessage::where('message_id', 'msg-a')->sole()->status);

        // Что увидит менеджер: по складам, дата раньше — выше, «дата уточняется» — последней.
        $view = app(ExpectedArrivals::class)->forProducts([$this->productA->id])[$this->productA->id];
        $this->assertSame(['Москва Основной', 'Тюмень Основной'], array_column($view, 'warehouse'));
        $this->assertSame(
            [Carbon::today()->addDays(9)->format('d.m.Y'), Carbon::today()->addDays(28)->format('d.m.Y')],
            array_column($view[0]['rows'], 'label'),
        );
        $this->assertSame(32.0, $view[0]['total']);
        $this->assertSame('дата уточняется', $view[1]['rows'][0]['label']);
        $this->assertSame(5.0, $view[1]['rows'][0]['quantity']);
    }

    #[Test]
    #[TestDox('1.27 B: изменение даты — полная замена, старые строки не задваиваются')]
    public function changed_date_replaces_the_whole_snapshot(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:15:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20], [$this->day(28), 12]],
            self::WH_TYUMEN => [[null, 5]],
        ]));

        // Закупки сменили D1 на D3 и внесли дату вместо «без даты» на втором складе.
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T11:40:00+03:00', [
            self::WH_MAIN => [[$this->day(14), 20], [$this->day(28), 12]],
            self::WH_TYUMEN => [[$this->day(20), 5]],
        ]));

        $this->assertEquals([
            $this->main->id.'|'.$this->day(14).'|purchase' => 20.0,
            $this->main->id.'|'.$this->day(28).'|purchase' => 12.0,
            $this->tyumen->id.'|'.$this->day(20).'|purchase' => 5.0,
        ], $this->stored($this->productA));
        $this->assertSame(1, ProductExpectedArrivalSnapshot::where('product_id', $this->productA->id)->count());
    }

    #[Test]
    #[TestDox('Склад, которого нет в новом снимке, у товара очищается; чужой товар не задет')]
    public function warehouse_missing_from_snapshot_is_cleared(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
            self::WH_TYUMEN => [[$this->day(9), 7]],
        ]));
        $this->fire($this->snapshot(self::PRODUCT_B, '2026-10-05T09:00:00+03:00', [
            self::WH_TYUMEN => [[$this->day(3), 4]],
        ]));

        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:30:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ]));

        $this->assertEquals([$this->main->id.'|'.$this->day(9).'|purchase' => 20.0], $this->stored($this->productA));
        $this->assertEquals([$this->tyumen->id.'|'.$this->day(3).'|purchase' => 4.0], $this->stored($this->productB));
    }

    #[Test]
    #[TestDox('1.27 C: пустой warehouses очищает товар; свободный остаток не меняется')]
    public function empty_warehouses_clears_the_product(): void
    {
        $this->productB->warehouses()->attach($this->main->id, ['quantity' => 3]);

        $this->fire($this->snapshot(self::PRODUCT_B, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(2), 10]],
        ]));
        $delivery = $this->fire($this->snapshot(self::PRODUCT_B, '2026-10-05T12:00:00+03:00', [], ['message_id' => 'msg-clear']));

        $this->assertSame(['released' => false, 'deleted' => true], $delivery);
        $this->assertSame([], $this->stored($this->productB));
        $this->assertSame([], app(ExpectedArrivals::class)->forProducts([$this->productB->id]));
        $this->assertSame('success', ErpBusMessage::where('message_id', 'msg-clear')->sole()->status);

        // Шапка остаётся — по ней отбрасывается опоздавший непустой снимок.
        $header = ProductExpectedArrivalSnapshot::where('product_id', $this->productB->id)->sole();
        $this->assertSame(0, $header->rows_count);
        $this->assertSame('2026-10-05 12:00:00', $header->calculated_at->format('Y-m-d H:i:s'));

        // Ожидания — не остаток: stock.updated живёт своей жизнью.
        $this->assertSame(3, (int) $this->productB->warehouses()->first()->pivot->quantity);
    }

    #[Test]
    #[TestDox('1.27 D: склад, не заведённый на сайте, пропущен молча; остальные склады применены')]
    public function unknown_warehouse_is_skipped_silently(): void
    {
        $delivery = $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:00:00+03:00', [
            self::WH_FOREIGN => [[$this->day(5), 100]],
            self::WH_MAIN => [[$this->day(5), 8]],
        ], ['message_id' => 'msg-foreign']));

        $this->assertSame(['released' => false, 'deleted' => true], $delivery);
        $this->assertEquals([$this->main->id.'|'.$this->day(5).'|purchase' => 8.0], $this->stored($this->productA));

        // Не ошибка: статус success, сообщение не возвращено в очередь, в журнале ошибок валидации пусто.
        $logged = ErpBusMessage::where('message_id', 'msg-foreign')->sole();
        $this->assertSame('success', $logged->status);
        $this->assertStringContainsString(self::WH_FOREIGN, (string) $logged->error_message);
        $this->assertSame(0, ErpValidationError::count());
    }

    #[Test]
    #[TestDox('Снимок только с чужим складом очищает товар — ожиданий на складах сайта нет')]
    public function snapshot_with_only_unknown_warehouse_clears_the_product(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(5), 8]],
        ]));
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:10:00+03:00', [
            self::WH_FOREIGN => [[$this->day(5), 100]],
        ]));

        $this->assertSame([], $this->stored($this->productA));
    }

    #[Test]
    #[TestDox('1.27 E: снимок с более ранним calculated_at отброшен как устаревший, данные не изменились')]
    public function older_snapshot_is_discarded_as_stale(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T12:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ], ['message_id' => 'msg-t2']));

        $delivery = $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T11:59:59+03:00', [
            self::WH_MAIN => [[$this->day(1), 999]],
            self::WH_TYUMEN => [[null, 1]],
        ], ['message_id' => 'msg-t1']));

        // Устаревшее — не сбой: сообщение снято с очереди, повторять его незачем.
        $this->assertSame(['released' => false, 'deleted' => true], $delivery);
        $this->assertEquals([$this->main->id.'|'.$this->day(9).'|purchase' => 20.0], $this->stored($this->productA));

        $header = ProductExpectedArrivalSnapshot::where('product_id', $this->productA->id)->sole();
        $this->assertSame('msg-t2', $header->message_id);
        $this->assertSame('2026-10-05 12:00:00', $header->calculated_at->format('Y-m-d H:i:s'));

        // 1С должна видеть, что её сообщение не применено.
        $logged = ErpBusMessage::where('message_id', 'msg-t1')->sole();
        $this->assertSame('stale', $logged->status);
        $this->assertStringContainsString('устарел', (string) $logged->error_message);
        $this->assertTrue(ErpProcessedMessage::where('message_id', 'msg-t1')->exists());
    }

    #[Test]
    #[TestDox('1.27 E: повтор снимка с тем же calculated_at и новым message_id применяется без изменений')]
    public function snapshot_with_equal_calculated_at_is_applied(): void
    {
        $rows = [self::WH_MAIN => [[$this->day(9), 20]]];

        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T12:00:00+03:00', $rows, ['message_id' => 'msg-first']));
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T12:00:00+03:00', $rows, ['message_id' => 'msg-repeat']));

        $this->assertEquals([$this->main->id.'|'.$this->day(9).'|purchase' => 20.0], $this->stored($this->productA));
        $this->assertSame('msg-repeat', ProductExpectedArrivalSnapshot::where('product_id', $this->productA->id)->sole()->message_id);
        $this->assertSame('success', ErpBusMessage::where('message_id', 'msg-repeat')->sole()->status);
    }

    #[Test]
    #[TestDox('Моменты сравниваются с учётом смещения, а не как строки: 09:30Z позже, чем 12:00+03:00')]
    public function calculated_at_is_compared_as_an_instant(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T12:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ]));
        // 09:30 UTC = 12:30 по Москве — снимок новее, хотя строка «меньше».
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:30:00Z', [
            self::WH_MAIN => [[$this->day(9), 15]],
        ]));

        $this->assertEquals([$this->main->id.'|'.$this->day(9).'|purchase' => 15.0], $this->stored($this->productA));
        $this->assertSame(
            '2026-10-05 12:30:00',
            ProductExpectedArrivalSnapshot::where('product_id', $this->productA->id)->sole()->calculated_at->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    #[TestDox('Опоздавший непустой снимок после очистки не возвращает ожидания')]
    public function late_snapshot_does_not_resurrect_cleared_product(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T10:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ]));
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T12:00:00+03:00', []));
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T11:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ]));

        $this->assertSame([], $this->stored($this->productA));
    }

    #[Test]
    #[TestDox('Повторная доставка того же message_id не применяется второй раз (идемпотентность)')]
    public function redelivery_of_the_same_message_is_ignored(): void
    {
        $payload = $this->snapshot(self::PRODUCT_A, '2026-10-05T12:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ]);

        $this->fire($payload);
        ProductExpectedArrival::query()->update(['quantity' => 1]);
        $delivery = $this->fire($payload);

        $this->assertSame(['released' => false, 'deleted' => true], $delivery);
        $this->assertEquals([$this->main->id.'|'.$this->day(9).'|purchase' => 1.0], $this->stored($this->productA));
    }

    #[Test]
    #[TestDox('Две строки с одинаковыми складом, датой и источником складываются; разные источники хранятся раздельно')]
    public function duplicate_rows_are_summed(): void
    {
        $payload = $this->snapshot(self::PRODUCT_A, '2026-10-05T12:00:00+03:00', [
            self::WH_MAIN => [
                [$this->day(9), 20],
                [$this->day(9), 5.5],
                [null, 2],
                [null, 3],
                [$this->day(9), 4, 'import'],
            ],
        ]);
        // Склад, перечисленный дважды, — тоже не повод затирать: количества складываются.
        $payload['warehouses'][] = [
            'warehouse_uuid' => self::WH_MAIN,
            'arrivals' => [['date' => $this->day(9), 'quantity' => 1, 'source' => 'purchase']],
        ];

        $this->fire($payload);

        $this->assertEquals([
            $this->main->id.'|'.$this->day(9).'|import' => 4.0,
            $this->main->id.'|'.$this->day(9).'|purchase' => 26.5,
            $this->main->id.'|null|purchase' => 5.0,
        ], $this->stored($this->productA));

        // Менеджеру источник не показывается: одна дата — одна строка.
        $view = app(ExpectedArrivals::class)->forProducts([$this->productA->id])[$this->productA->id];
        $this->assertSame([30.5, 5.0], array_column($view[0]['rows'], 'quantity'));
    }

    #[Test]
    #[TestDox('1.27 F: суточная выгрузка переводит вчерашнюю дату в null — менеджер видит «дата уточняется»')]
    public function daily_export_turns_yesterdays_date_into_pending(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(0), 20], [$this->day(6), 4]],
        ]));
        $this->fire($this->snapshot(self::PRODUCT_B, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(0), 10]],
        ]));

        // Сегодняшняя дата — ещё не прошедшая.
        $view = app(ExpectedArrivals::class)->forProducts([$this->productA->id])[$this->productA->id];
        $this->assertSame(Carbon::today()->format('d.m.Y'), $view[0]['rows'][0]['label']);

        // Ночь: наступил следующий день, выгрузка 1С ещё не пришла. Дата стала вчерашней —
        // сайт показывает её так же, как пришлёт выгрузка, а не прячет.
        Carbon::setTestNow(Carbon::parse('2026-10-06 00:30:00', 'Europe/Moscow'));
        $view = app(ExpectedArrivals::class)->forProducts([$this->productA->id])[$this->productA->id];
        $this->assertSame(
            [[Carbon::parse('2026-10-11')->format('d.m.Y'), 4.0], ['дата уточняется', 20.0]],
            array_map(static fn (array $row) => [$row['label'], $row['quantity']], $view[0]['rows']),
        );

        // Суточная выгрузка: товар А — вчерашняя дата пришла как null; товар Б принят — пустой снимок.
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-06T03:00:00+03:00', [
            self::WH_MAIN => [[null, 20], ['2026-10-11', 4]],
        ]));
        $this->fire($this->snapshot(self::PRODUCT_B, '2026-10-06T03:00:00+03:00', []));

        $this->assertEquals([
            $this->main->id.'|2026-10-11|purchase' => 4.0,
            $this->main->id.'|null|purchase' => 20.0,
        ], $this->stored($this->productA));
        $this->assertSame([], $this->stored($this->productB));

        // Повторный пустой снимок по уже пустому товару — норма, а не ошибка.
        $delivery = $this->fire($this->snapshot(self::PRODUCT_B, '2026-10-07T03:00:00+03:00', []));
        $this->assertSame(['released' => false, 'deleted' => true], $delivery);
        $this->assertSame([], $this->stored($this->productB));
    }

    #[Test]
    #[TestDox('Прошедшая дата и строка без даты на одном складе показываются одной строкой «дата уточняется»')]
    public function past_date_merges_with_pending_row_on_display(): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(-2), 7], [null, 3], [$this->day(4), 1]],
        ]));

        // Хранится как пришло.
        $this->assertArrayHasKey($this->main->id.'|'.$this->day(-2).'|purchase', $this->stored($this->productA));

        $service = app(ExpectedArrivals::class);
        $view = $service->forProducts([$this->productA->id])[$this->productA->id];

        $this->assertSame(
            [[Carbon::today()->addDays(4)->format('d.m.Y'), 1.0], ['дата уточняется', 10.0]],
            array_map(static fn (array $row) => [$row['label'], $row['quantity']], $view[0]['rows']),
        );
        $this->assertSame(
            ['label' => Carbon::today()->addDays(4)->format('d.m.Y'), 'quantity' => 11.0],
            $service->summary($view),
        );
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function schemaViolations(): array
    {
        return [
            'calculated_at без смещения' => [static fn (array $p) => array_merge($p, ['calculated_at' => '2026-10-05T13:00:00'])],
            'quantity = 0' => [static function (array $p) {
                $p['warehouses'][0]['arrivals'][0]['quantity'] = 0;

                return $p;
            }],
            'неизвестный source' => [static function (array $p) {
                $p['warehouses'][0]['arrivals'][0]['source'] = 'transfer';

                return $p;
            }],
            'склад с пустым arrivals' => [static function (array $p) {
                $p['warehouses'][0]['arrivals'] = [];

                return $p;
            }],
            'нет warehouses' => [static function (array $p) {
                unset($p['warehouses']);

                return $p;
            }],
            'дата со временем' => [static function (array $p) {
                $p['warehouses'][0]['arrivals'][0]['date'] = '2026-10-14T00:00:00';

                return $p;
            }],
        ];
    }

    #[Test]
    #[TestDox('1.27 G: нарушение схемы отклонено валидацией и записано в журнал; ожидания товара не изменились')]
    #[\PHPUnit\Framework\Attributes\DataProvider('schemaViolations')]
    public function schema_violation_is_rejected_and_logged(callable $break): void
    {
        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ]));
        $before = $this->stored($this->productA);

        $broken = $break($this->snapshot(self::PRODUCT_A, '2026-10-05T13:00:00+03:00', [
            self::WH_MAIN => [[$this->day(1), 500]],
        ], ['message_id' => 'msg-broken']));

        $delivery = $this->fire($broken);

        // Повтор невалидному сообщению не поможет — оно снято с очереди, а не возвращено.
        $this->assertSame(['released' => false, 'deleted' => true], $delivery);
        $this->assertSame($before, $this->stored($this->productA));

        $error = ErpValidationError::where('message_id', 'msg-broken')->sole();
        $this->assertSame('product.expected_arrivals.updated', $error->event);
        $this->assertSame('failed', ErpBusMessage::where('message_id', 'msg-broken')->sole()->status);
        $this->assertFalse(ErpProcessedMessage::where('message_id', 'msg-broken')->exists());
    }

    #[Test]
    #[TestDox('1.27 G: товар, которого нет на сайте, пропущен без ошибки, без повтора и без DLQ')]
    public function unknown_product_is_skipped_without_error(): void
    {
        $delivery = $this->fire($this->snapshot('00000000-0000-4000-a000-00000016dead', '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ], ['message_id' => 'msg-no-product']));

        // release() вернул бы сообщение в очередь, а после исчерпания попыток — в DLQ.
        $this->assertSame(['released' => false, 'deleted' => true], $delivery);
        $this->assertSame(0, ProductExpectedArrival::count());
        $this->assertSame(0, ProductExpectedArrivalSnapshot::count());
        $this->assertSame(0, ErpValidationError::count());

        $logged = ErpBusMessage::where('message_id', 'msg-no-product')->sole();
        $this->assertSame('success', $logged->status);
        $this->assertStringContainsString('не найден', (string) $logged->error_message);
    }

    #[Test]
    #[TestDox('Скрытый товар принимает ожидания: 1С — мастер каталога, HiddenScope не должен делать его мёртвой зоной')]
    public function hidden_product_accepts_arrivals(): void
    {
        Product::withoutGlobalScopes()->whereKey($this->productA->id)->update(['hidden' => true]);

        $this->fire($this->snapshot(self::PRODUCT_A, '2026-10-05T09:00:00+03:00', [
            self::WH_MAIN => [[$this->day(9), 20]],
        ]));

        $this->assertEquals([$this->main->id.'|'.$this->day(9).'|purchase' => 20.0], $this->stored($this->productA));
    }
}
