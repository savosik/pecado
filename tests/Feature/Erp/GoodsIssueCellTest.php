<?php

namespace Tests\Feature\Erp;

use App\Models\GoodsIssue;
use App\Services\Erp\ErpMessageValidator;
use App\Services\Erp\Handlers\HandleGoodsIssueCreated;
use App\Services\Erp\Handlers\HandleGoodsIssueUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ячейка хранения в строке расходного ордера — `items[].cell` (v15.17.0).
 *
 * Поле необязательное: адресное хранение ведётся не на всех складах. Сайт значение
 * не разбирает и со справочниками не сверяет — только хранит и показывает кладовщику.
 */
class GoodsIssueCellTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '00000000-0000-4000-a000-0000000057f1';

    private const PRODUCT_UUID = '00000000-0000-4000-a000-0000000057a1';

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(string $event, array $items): array
    {
        return [
            'event' => $event,
            'message_id' => 'msg-gi-cell-'.uniqid(),
            'uuid' => self::UUID,
            'number' => 'УТ-00009419',
            'date' => '2026-08-08T13:25:55+03:00',
            'status' => GoodsIssue::STATUS_TO_PICK,
            'items' => $items,
        ];
    }

    #[Test]
    public function schema_accepts_cell_in_both_goods_issue_events(): void
    {
        $validator = app(ErpMessageValidator::class);

        foreach (['goods_issue.created', 'goods_issue.updated'] as $event) {
            $result = $validator->validate($event, $this->payload($event, [
                ['product_uuid' => self::PRODUCT_UUID, 'quantity' => 1, 'cell' => 'А-01-02-03'],
            ]));

            $this->assertTrue($result['valid'], $event.': '.implode('; ', $result['errors']));
        }
    }

    /**
     * Отсутствие ключа и пустая строка одинаково валидны: 1С не обязана заполнять
     * ячейку на складах без адресного хранения.
     */
    #[Test]
    public function schema_accepts_missing_and_empty_cell(): void
    {
        $validator = app(ErpMessageValidator::class);

        foreach ([[], ['cell' => ''], ['cell' => null]] as $variant) {
            $result = $validator->validate(
                'goods_issue.created',
                $this->payload('goods_issue.created', [
                    array_merge(['product_uuid' => self::PRODUCT_UUID, 'quantity' => 1], $variant),
                ]),
            );

            $this->assertTrue($result['valid'], implode('; ', $result['errors']));
        }

        // Длина ограничена размером колонки: слишком длинное значение лучше отбить
        // на валидации с понятной ошибкой, чем уронить запись в БД.
        $tooLong = $validator->validate('goods_issue.created', $this->payload('goods_issue.created', [
            ['product_uuid' => self::PRODUCT_UUID, 'quantity' => 1, 'cell' => str_repeat('я', 256)],
        ]));

        $this->assertFalse($tooLong['valid'], 'Ячейка длиннее 255 символов не должна проходить валидацию');
    }

    #[Test]
    public function it_stores_cell_from_created_event(): void
    {
        app(HandleGoodsIssueCreated::class)->handle($this->payload('goods_issue.created', [
            [
                'line_number' => 1,
                'product_uuid' => self::PRODUCT_UUID,
                'product_name' => 'Товар из 1С',
                'quantity' => 5,
                'unit' => 'шт',
                'cell' => 'А-01-02-03',
            ],
        ]));

        $item = GoodsIssue::firstWhere('uuid', self::UUID)->items->first();

        $this->assertSame('А-01-02-03', $item->cell);
    }

    /**
     * Ордер приезжает целиком, поэтому перенос товара в другую ячейку — это просто
     * новое значение в строке. Пустая строка равносильна отсутствию поля.
     */
    #[Test]
    public function updated_event_replaces_and_clears_cell(): void
    {
        $handler = app(HandleGoodsIssueUpdated::class);

        $handler->handle($this->payload('goods_issue.updated', [
            ['product_uuid' => self::PRODUCT_UUID, 'quantity' => 5, 'cell' => ' А-01-02-03 '],
        ]));

        $this->assertSame(
            'А-01-02-03',
            GoodsIssue::firstWhere('uuid', self::UUID)->items()->first()->cell,
            'Значение сохраняется без окружающих пробелов',
        );

        $handler->handle($this->payload('goods_issue.updated', [
            ['product_uuid' => self::PRODUCT_UUID, 'quantity' => 5, 'cell' => 'Б-02-01-01'],
        ]));

        $this->assertSame('Б-02-01-01', GoodsIssue::firstWhere('uuid', self::UUID)->items()->first()->cell);

        $handler->handle($this->payload('goods_issue.updated', [
            ['product_uuid' => self::PRODUCT_UUID, 'quantity' => 5, 'cell' => ''],
        ]));

        $this->assertNull(
            GoodsIssue::firstWhere('uuid', self::UUID)->items()->first()->cell,
            'Пустая строка из 1С хранится как «не заполнено»',
        );
    }

    #[Test]
    public function item_without_cell_is_stored_with_null(): void
    {
        app(HandleGoodsIssueCreated::class)->handle($this->payload('goods_issue.created', [
            ['product_uuid' => self::PRODUCT_UUID, 'quantity' => 1],
        ]));

        $this->assertNull(GoodsIssue::firstWhere('uuid', self::UUID)->items->first()->cell);
    }
}
