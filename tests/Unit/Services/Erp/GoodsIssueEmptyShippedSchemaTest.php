<?php

namespace Tests\Unit\Services\Erp;

use App\Services\Erp\ErpMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Отгруженный без товара расходный ордер (v16.15.0, топик Agent Hub №19).
 *
 * Полный недобор: упаковщик оформил недобор, а собрано ничего. 1С проводит ордер
 * без строк и шлёт финальный `goods_issue.updated` со `status: shipped` и `items: []`.
 * Пустой `items` допустим только так: в `created` и в любом другом статусе это ошибка.
 * Мест у такого ордера нет, обмер не нужен.
 */
class GoodsIssueEmptyShippedSchemaTest extends TestCase
{
    private ErpMessageValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ErpMessageValidator;
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function payload(string $event, array $override = []): array
    {
        return array_merge([
            'event' => $event,
            'message_id' => 'msg-gi-empty',
            'uuid' => '6f1c2a3b-4c5d-4e6f-8a7b-9c0d1e2f3a4b',
            'number' => 'УТ-00012345',
            'status' => 'shipped',
            'items' => [],
        ], $override);
    }

    private const NOT_REQUIRED = ['required' => false, 'state' => 'not_required', 'measured_at' => null, 'measured_by' => null];

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function validPayloads(): array
    {
        return [
            'shipped, пустые items и packages' => [['packages' => []]],
            'shipped, пустые items без packages' => [[]],
            'shipped, пустые items с ревизией и not_required' => [[
                'revision' => 7,
                'shipping_mode' => 'delivery',
                'packages' => [],
                'measurement' => self::NOT_REQUIRED,
            ]],
            'shipped, пустые items, measurement null' => [['measurement' => null]],
        ];
    }

    #[Test]
    #[DataProvider('validPayloads')]
    public function updated_accepts_empty_shipped(array $override): void
    {
        $result = $this->validator->validate('goods_issue.updated', self::payload('goods_issue.updated', $override));

        $this->assertTrue($result['valid'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, array{0: string}> */
    public static function notShippedStatuses(): array
    {
        return array_combine(
            ['prepared', 'to_pick', 'to_check', 'checking', 'checked', 'to_ship'],
            array_map(fn (string $s) => [$s], ['prepared', 'to_pick', 'to_check', 'checking', 'checked', 'to_ship']),
        );
    }

    #[Test]
    #[DataProvider('notShippedStatuses')]
    public function updated_rejects_empty_items_in_other_statuses(string $status): void
    {
        $result = $this->validator->validate('goods_issue.updated', self::payload('goods_issue.updated', ['status' => $status]));

        $this->assertFalse($result['valid'], "{$status} с пустым items должен быть отклонён");
        $this->assertStringContainsString('items', implode("\n", $result['errors']));
    }

    #[Test]
    public function created_rejects_empty_items_even_when_shipped(): void
    {
        $result = $this->validator->validate('goods_issue.created', self::payload('goods_issue.created'));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('items', implode("\n", $result['errors']));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function inconsistentEmptyShipped(): array
    {
        $package = ['uuid' => 'b21e4c7a-0d3f-4a1b-8c2e-5f6a7b8c9d01', 'number' => 1, 'weight' => 1.2, 'dimensions' => ['length' => 10, 'width' => 10, 'height' => 10]];

        return [
            'места при пустом ордере' => [['packages' => [$package]], 'packages'],
            'обмер pending' => [['measurement' => ['required' => true, 'state' => 'pending', 'measured_at' => null, 'measured_by' => null]], 'measurement'],
            'обмер done' => [[
                'packages' => [],
                'measurement' => ['required' => true, 'state' => 'done', 'measured_at' => '2026-10-01T10:00:00+03:00', 'measured_by' => 'Иванов И.И.'],
            ], 'packages'],
        ];
    }

    #[Test]
    #[DataProvider('inconsistentEmptyShipped')]
    public function updated_rejects_inconsistent_empty_shipped(array $override, string $fragment): void
    {
        $result = $this->validator->validate('goods_issue.updated', self::payload('goods_issue.updated', $override));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString($fragment, implode("\n", $result['errors']));
    }

    #[Test]
    public function shipped_with_items_is_still_valid(): void
    {
        $result = $this->validator->validate('goods_issue.updated', self::payload('goods_issue.updated', [
            'items' => [['product_uuid' => '550e8400-e29b-41d4-a716-446655440001', 'quantity' => 1]],
        ]));

        $this->assertTrue($result['valid'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    }
}
