<?php

namespace Tests\Unit\Services\Erp;

use App\Services\Erp\ErpMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Схема обмера грузовых мест в `goods_issue.*` (v16.14.0, проект, топик Agent Hub №13).
 *
 * Три группы гарантий:
 *
 * 1. Прежний формат (без `revision` и `measurement`, места без `uuid`) остаётся валидным:
 *    новые поля включаются 1С одним выпуском, и до него поток не должен падать в DLQ.
 * 2. Новый формат во всех состояниях обмера проходит валидацию.
 * 3. Условия `done` и «нового отправителя» проверяет сама схема: `done` с пустым массивом
 *    мест, без веса или со стороной < 1 отклоняется целиком — иначе сайт мог бы посчитать
 *    доставку по неполным данным.
 */
class GoodsIssueMeasurementSchemaTest extends TestCase
{
    private ErpMessageValidator $validator;

    private const GI_UUID = '6f1c2a3b-4c5d-4e6f-8a7b-9c0d1e2f3a4b';

    private const PACKAGE_UUID = 'b21e4c7a-0d3f-4a1b-8c2e-5f6a7b8c9d01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ErpMessageValidator;
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return [
            'event' => 'goods_issue.updated',
            'message_id' => 'msg-gi-measure',
            'uuid' => self::GI_UUID,
            'number' => 'УТ-00009419',
            'status' => 'checked',
            'items' => [[
                'product_uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'quantity' => 1,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function package(array $override = []): array
    {
        return array_merge([
            'uuid' => self::PACKAGE_UUID,
            'number' => 1,
            'package_type' => 'box',
            'weight' => 12.4,
            'dimensions' => ['length' => 60, 'width' => 40, 'height' => 35],
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function measurement(string $state, array $override = []): array
    {
        return array_merge([
            'required' => $state !== 'not_required',
            'state' => $state,
            'measured_at' => $state === 'done' ? '2026-09-26T14:12:00+03:00' : null,
            'measured_by' => $state === 'done' ? 'Иванов И.И.' : null,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function modern(array $fields): array
    {
        return array_merge(self::base(), ['revision' => 5], $fields);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function validPayloads(): array
    {
        return [
            'прежний формат без новых полей' => [self::base()],
            'прежний формат, места без uuid' => [self::base() + ['packages' => [['number' => 1, 'weight' => null]]]],
            'доставка, обмер завершён, два места' => [self::modern([
                'shipping_mode' => 'delivery',
                'measurement' => self::measurement('done'),
                'packages' => [
                    self::package(),
                    self::package([
                        'uuid' => '9d04e1f2-3a4b-4c5d-8e6f-7a8b9c0d1e02',
                        'number' => 2,
                        'package_type' => 'pallet',
                        'weight' => 218.0,
                        'dimensions' => ['length' => 120, 'width' => 80, 'height' => 145],
                    ]),
                ],
            ])],
            'частичный обмер в pending' => [self::modern([
                'shipping_mode' => 'delivery',
                'measurement' => self::measurement('pending'),
                'packages' => [self::package(['weight' => null, 'dimensions' => null])],
            ])],
            'смешанный ордер' => [self::modern([
                'shipping_mode' => 'mixed',
                'measurement' => self::measurement('done'),
                'packages' => [self::package()],
            ])],
            'самовывоз' => [self::modern([
                'shipping_mode' => 'pickup',
                'measurement' => self::measurement('not_required'),
                'packages' => [['uuid' => self::PACKAGE_UUID, 'number' => 1]],
            ])],
            'способ не определён, мест ещё нет' => [self::modern([
                'shipping_mode' => null,
                'measurement' => self::measurement('pending'),
                'packages' => [],
            ])],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        $done = fn (array $package) => self::modern([
            'shipping_mode' => 'delivery',
            'measurement' => self::measurement('done'),
            'packages' => [$package],
        ]);

        return [
            'done с пустым массивом мест' => [self::modern([
                'shipping_mode' => 'delivery',
                'measurement' => self::measurement('done'),
                'packages' => [],
            ]), '/packages'],
            'done, место без веса' => [$done(self::package(['weight' => null])), '/packages/0/weight'],
            'done, вес ноль' => [$done(self::package(['weight' => 0])), '/packages/0/weight'],
            'done, место без габаритов' => [$done(self::package(['dimensions' => null])), '/packages/0/dimensions'],
            'сторона меньше сантиметра' => [$done(self::package(['dimensions' => ['length' => 60, 'width' => 40, 'height' => 0]])), '/packages/0/dimensions/height'],
            'габарит дробный' => [$done(self::package(['dimensions' => ['length' => 60.5, 'width' => 40, 'height' => 35]])), '/packages/0/dimensions/length'],
            'done без момента обмера' => [self::modern([
                'shipping_mode' => 'delivery',
                'measurement' => self::measurement('done', ['measured_at' => null]),
                'packages' => [self::package()],
            ]), '/measurement/measured_at'],
            'pending с упаковщиком' => [self::modern([
                'shipping_mode' => 'delivery',
                'measurement' => self::measurement('pending', ['measured_by' => 'Иванов И.И.']),
                'packages' => [self::package()],
            ]), '/measurement/measured_by'],
            'not_required при required=true' => [self::modern([
                'shipping_mode' => 'pickup',
                'measurement' => self::measurement('not_required', ['required' => true]),
                'packages' => [],
            ]), '/measurement/required'],
            'revision без measurement' => [self::modern(['packages' => [self::package()]]), 'measurement'],
            'revision, место без uuid' => [self::modern([
                'shipping_mode' => 'delivery',
                'measurement' => self::measurement('pending'),
                'packages' => [['number' => 1]],
            ]), 'uuid'],
            'чужой способ доставки' => [self::base() + ['shipping_mode' => 'courier'], '/shipping_mode'],
            'чужой тип места' => [self::base() + ['packages' => [['number' => 1, 'package_type' => 'crate']]], '/packages/0/package_type'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('validPayloads')]
    public function valid_payload_passes_both_created_and_updated(array $payload): void
    {
        foreach (['goods_issue.created', 'goods_issue.updated'] as $event) {
            $result = $this->validator->validate($event, ['event' => $event] + $payload);

            $this->assertTrue($result['valid'], $event.': '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_payload_is_rejected_with_pointer(array $payload, string $expectedFragment): void
    {
        foreach (['goods_issue.created', 'goods_issue.updated'] as $event) {
            $result = $this->validator->validate($event, ['event' => $event] + $payload);

            $this->assertFalse($result['valid'], $event.' должен быть отклонён');
            $this->assertStringContainsString($expectedFragment, implode("\n", $result['errors']));
        }
    }

    #[Test]
    public function deleted_accepts_revision(): void
    {
        $result = $this->validator->validate('goods_issue.deleted', [
            'event' => 'goods_issue.deleted',
            'message_id' => 'msg-gi-del',
            'uuid' => self::GI_UUID,
            'revision' => 7,
        ]);

        $this->assertTrue($result['valid'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    }
}
