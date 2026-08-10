<?php

namespace Tests\Unit\Services\Erp;

use App\Services\Erp\ErpMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Схемы регистра взаиморасчётов (v16.0.0, карточка fin-02).
 *
 * Три группы гарантий:
 *
 * 1. Новые события проходят валидацию в минимальном и в полном виде.
 * 2. Снятые из контракта поля (`allocations`, `payment_schedule`, `overdue_details`)
 *    больше не описаны схемой, но присланные по инерции НЕ роняют сообщение в DLQ —
 *    ради этого во всех схемах оставлен `additionalProperties: true`. Переключение
 *    сторон растянуто во времени, и терять документы на нём нельзя.
 * 3. Перечень `type` закрытый, а `settlement_object_kind` открытый. Различие
 *    намеренное: неизвестный тип движения молча пропустить нельзя — баланс разъедется
 *    незаметно; неизвестный вид объекта расчётов, наоборот, штатная ситуация,
 *    состав `ОбъектРасчетов` на стороне 1С заранее не известен.
 */
class ErpSettlementSchemaTest extends TestCase
{
    private ErpMessageValidator $validator;

    private const DOC_UUID = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

    private const ENTRY_UUID = 'c7f2e9a4-3b1d-4857-9e6c-2a8f4d7b1035';

    private const CONTRACTOR_UUID = 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508';

    private const AGREEMENT_UUID = '5c8a2f4d-7e1b-4903-a6c5-8f2d4b7e1a39';

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ErpMessageValidator;
    }

    /**
     * Минимальные валидные payload-ы каждого нового события.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function payloads(): array
    {
        return [
            'settlement.posted' => [
                'event' => 'settlement.posted',
                'message_id' => 'msg-settlement-posted',
                'document_uuid' => self::DOC_UUID,
                'entries' => [[
                    'uuid' => self::ENTRY_UUID,
                    'type' => 'shipment',
                    'amount' => -120000.00,
                ]],
            ],
            'settlement.reverted' => [
                'event' => 'settlement.reverted',
                'message_id' => 'msg-settlement-reverted',
                'document_uuid' => self::DOC_UUID,
            ],
            'settlement.opening_balance' => [
                'event' => 'settlement.opening_balance',
                'message_id' => 'msg-opening-balance',
                'uuid' => '9d4c7e2a-1f8b-4356-a09c-3e7d5b2f8a14',
                'as_of_date' => '2026-01-01',
                'amount' => -50000.00,
            ],
            'settlement.checkpoint' => [
                'event' => 'settlement.checkpoint',
                'message_id' => 'msg-checkpoint',
                'as_of_date' => '2026-07-01',
                'amount' => -55000.00,
            ],
            'payment_schedule.updated' => [
                'event' => 'payment_schedule.updated',
                'message_id' => 'msg-schedule',
                'document_uuid' => self::DOC_UUID,
                'document_kind' => 'shipment',
                'lines' => [[
                    'uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208',
                    'due_date' => '2026-08-14',
                    'amount' => 120000.00,
                ]],
            ],
            'agreement.created' => [
                'event' => 'agreement.created',
                'message_id' => 'msg-agreement-created',
                'uuid' => self::AGREEMENT_UUID,
            ],
            'agreement.updated' => [
                'event' => 'agreement.updated',
                'message_id' => 'msg-agreement-updated',
                'uuid' => self::AGREEMENT_UUID,
            ],
            'agreement.deleted' => [
                'event' => 'agreement.deleted',
                'message_id' => 'msg-agreement-deleted',
                'uuid' => self::AGREEMENT_UUID,
            ],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function eventProvider(): array
    {
        return array_map(static fn (string $event): array => [$event], array_combine(
            array_keys(self::payloads()),
            array_keys(self::payloads()),
        ));
    }

    #[Test]
    #[DataProvider('eventProvider')]
    public function минимальный_payload_проходит_валидацию(string $event): void
    {
        $result = $this->validator->validate($event, self::payloads()[$event]);

        $this->assertTrue($result['valid'], $event.': '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    #[DataProvider('eventProvider')]
    public function неизвестные_дополнительные_поля_не_отбраковываются(string $event): void
    {
        $payload = self::payloads()[$event] + ['какое_то_новое_поле_из_1с' => 'значение'];

        $this->assertTrue($this->validator->validate($event, $payload)['valid']);
    }

    #[Test]
    public function движение_со_всеми_измерениями_проходит(): void
    {
        $payload = self::payloads()['settlement.posted'];
        $payload['spec_version'] = '16.0';
        $payload['revision'] = 3;
        $payload['document_kind'] = 'shipment';
        $payload['document_number'] = '29УТ-006915';
        $payload['document_date'] = '2026-07-15';
        $payload['entries'][0] += [
            'date' => '2026-07-15',
            'amount_rub' => -120000.00,
            'currency_code' => 'RUB',
            'movement_kind' => 'income',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'organization_uuid' => 'e1a7c3d9-2b8f-4056-9c14-7d3e8b5a2f61',
            'agreement_uuid' => self::AGREEMENT_UUID,
            'agreement_name' => 'Соглашение об условиях продаж №СГ-0042',
            'settlement_object_kind' => 'order',
            'settlement_object_uuid' => 'a2c4e6f8-1b3d-4507-9e2a-6c8f4d1b7e35',
            'settlement_object_name' => 'Заказ клиента A2УТ-000417 от 01.07.2026',
        ];

        $this->assertTrue($this->validator->validate('settlement.posted', $payload)['valid']);
    }

    #[Test]
    public function пустой_массив_движений_валиден(): void
    {
        $payload = self::payloads()['settlement.posted'];
        $payload['entries'] = [];

        // Пустой entries — валидный способ сказать «движений у документа больше нет».
        $this->assertTrue($this->validator->validate('settlement.posted', $payload)['valid']);
    }

    #[Test]
    public function неизвестный_тип_движения_отбраковывается(): void
    {
        $payload = self::payloads()['settlement.posted'];
        $payload['entries'][0]['type'] = 'что_то_новое';

        // Перечень type закрытый намеренно: пропустив строку молча, мы получили бы
        // незаметно разъехавшийся баланс. Лучше DLQ и разбор.
        $this->assertFalse($this->validator->validate('settlement.posted', $payload)['valid']);
    }

    #[Test]
    public function неизвестный_вид_объекта_расчётов_проходит(): void
    {
        $payload = self::payloads()['settlement.posted'];
        $payload['entries'][0]['settlement_object_kind'] = 'договор_комиссии_нового_вида';

        // В отличие от type, перечень открытый: состав ОбъектРасчетов на стороне 1С
        // заранее не известен, и отбрасывать документ из-за него нельзя.
        $this->assertTrue($this->validator->validate('settlement.posted', $payload)['valid']);
    }

    #[Test]
    public function положительный_знак_движения_допустим(): void
    {
        $payload = self::payloads()['settlement.posted'];
        $payload['entries'][0]['type'] = 'payment_in';
        $payload['entries'][0]['amount'] = 100000.00;

        // Знак содержательный, а не проверяемый схемой: инверсию ловит только сверка.
        $this->assertTrue($this->validator->validate('settlement.posted', $payload)['valid']);
    }

    #[Test]
    public function движение_без_обязательных_полей_отбраковывается(): void
    {
        $payload = self::payloads()['settlement.posted'];
        $payload['entries'][0] = ['amount' => 100];

        $this->assertFalse($this->validator->validate('settlement.posted', $payload)['valid']);
    }

    #[Test]
    public function график_с_переплатой_по_строке_валиден(): void
    {
        $payload = self::payloads()['payment_schedule.updated'];
        $payload['lines'][0]['settled_amount'] = 150000.00;

        // settled_amount больше amount — легитимная переплата, схема её не запрещает.
        $this->assertTrue($this->validator->validate('payment_schedule.updated', $payload)['valid']);
    }

    #[Test]
    public function график_заказа_с_суммой_по_документу_валиден(): void
    {
        $payload = self::payloads()['payment_schedule.updated'];
        $payload['document_kind'] = 'order';
        $payload['document_settled_amount'] = 40000.00;
        unset($payload['lines'][0]['settled_amount']);

        // По заказам построчного остатка нет — регистр по срокам заказы не ведёт.
        $this->assertTrue($this->validator->validate('payment_schedule.updated', $payload)['valid']);
    }

    #[Test]
    public function график_с_неизвестным_видом_документа_отбраковывается(): void
    {
        $payload = self::payloads()['payment_schedule.updated'];
        $payload['document_kind'] = 'invoice';

        $this->assertFalse($this->validator->validate('payment_schedule.updated', $payload)['valid']);
    }

    #[Test]
    public function соглашение_без_порядка_расчётов_валидно(): void
    {
        $payload = self::payloads()['agreement.created'];
        $payload['settlement_procedure'] = null;

        // «Не заполнено» — допустимое состояние: таких соглашений в базе 167.
        $this->assertTrue($this->validator->validate('agreement.created', $payload)['valid']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function removedFieldProvider(): array
    {
        return [
            'allocations в платеже' => ['payment.created', 'allocations'],
            'payment_schedule в реализации' => ['shipment.created', 'payment_schedule'],
            'overdue_details в балансе' => ['balance.updated', 'overdue_details'],
        ];
    }

    #[Test]
    #[DataProvider('removedFieldProvider')]
    public function снятое_в_v16_поле_не_описано_схемой(string $event, string $field): void
    {
        $schema = json_decode(
            file_get_contents(app_path('Services/Erp/Schemas/'.$event.'.json')),
            true,
        );

        $this->assertArrayNotHasKey($field, $schema['properties'], $event);
        $this->assertTrue($schema['additionalProperties'], $event.': additionalProperties обязан остаться true');
    }

    #[Test]
    public function платёж_с_присланным_по_инерции_allocations_проходит(): void
    {
        $payload = [
            'event' => 'payment.created',
            'message_id' => 'msg-payment-legacy',
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'number' => '29УТ-002488',
            'date' => '2026-07-20T10:00:00+03:00',
            'amount' => 100000.00,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'allocations' => [[
                'target_type' => 'shipment',
                'shipment_uuid' => self::DOC_UUID,
                'amount' => 100000.00,
            ]],
        ];

        // Переходный период: 1С уберёт поле не мгновенно, и терять на этом
        // платёжные документы нельзя.
        $this->assertTrue($this->validator->validate('payment.created', $payload)['valid']);
    }

    #[Test]
    public function реализация_с_присланным_по_инерции_графиком_проходит(): void
    {
        $payload = [
            'event' => 'shipment.created',
            'message_id' => 'msg-shipment-legacy',
            'uuid' => self::DOC_UUID,
            'number' => '29УТ-006915',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'payment_schedule' => [[
                'due_date' => '2026-08-27',
                'amount' => 2325.20,
            ]],
        ];

        $this->assertTrue($this->validator->validate('shipment.created', $payload)['valid']);
    }
}
