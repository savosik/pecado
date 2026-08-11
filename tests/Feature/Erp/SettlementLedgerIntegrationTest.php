<?php

namespace Tests\Feature\Erp;

use App\Models\Agreement;
use App\Models\Company;
use App\Models\ErpBusMessage;
use App\Models\Organization;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementDocument;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сквозной путь регистра взаиморасчётов: сообщение из RabbitMQ → runtime-валидация
 * схемой → обработчик → строки регистра (v16.0.0, карточка fin-04).
 *
 * Через `ErpIncomingJob`, а не вызовом обработчика напрямую: только так проверяется,
 * что payload проходит валидацию `settlement.*.json` и не уходит в DLQ. Прямой вызов
 * обработчика оставил бы схему непроверенной — а именно она контракт и определяет.
 */
class SettlementLedgerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const DOCUMENT_UUID = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

    private const CONTRACTOR_UUID = 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508';

    private const PARTNER_UUID = '7c9e6b21-4a3d-4e8f-b512-9d7c3e1a6f04';

    private const ORGANIZATION_UUID = 'e1a7c3d9-2b8f-4056-9c14-7d3e8b5a2f61';

    private const AGREEMENT_UUID = '5c8a2f4d-7e1b-4903-a6c5-8f2d4b7e1a39';

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'erp_id' => self::CONTRACTOR_UUID,
        ]);
        Organization::factory()->create(['external_id' => self::ORGANIZATION_UUID]);
    }

    /**
     * Прогон сообщения тем же путём, каким его получает воркер очереди.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $payload, string $queue = 'erp_in.settlements'): void
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            $queue,
        ))->fire();
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function postedMessage(array $entries, ?int $revision = null): array
    {
        return array_filter([
            'event' => 'settlement.posted',
            'message_id' => 'msg-posted-'.uniqid(),
            'spec_version' => '16.0',
            'revision' => $revision,
            'document_uuid' => self::DOCUMENT_UUID,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-006915',
            'document_date' => '2026-07-15',
            'entries' => $entries,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => 'c7f2e9a4-3b1d-4857-9e6c-2a8f4d7b1035',
            'type' => 'shipment',
            'date' => '2026-07-15',
            'amount' => -120000.00,
            'amount_rub' => -120000.00,
            'currency_code' => 'RUB',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'organization_uuid' => self::ORGANIZATION_UUID,
            'settlement_object_kind' => 'order',
            'settlement_object_name' => 'Заказ клиента A2УТ-000417 от 01.07.2026',
        ];
    }

    #[Test]
    public function движение_сохраняется_со_всеми_измерениями(): void
    {
        $this->dispatch($this->postedMessage([$this->entry()]));

        $entry = SettlementEntry::query()->sole();

        $this->assertSame(SettlementEntry::NATURE_FACT, $entry->nature);
        $this->assertSame('shipment', $entry->type);
        $this->assertEqualsWithDelta(-120000.0, (float) $entry->amount, 0.01);
        $this->assertSame($this->company->id, $entry->company_id);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertNotNull($entry->organization_id);
        $this->assertSame('29УТ-006915', $entry->document_number);
        $this->assertSame('Заказ клиента A2УТ-000417 от 01.07.2026', $entry->settlement_object_name);
    }

    /**
     * Повторная доставка — норма для брокера. Задвоенное движение удвоило бы
     * долг клиента, и заметили бы это только на сверке.
     */
    #[Test]
    public function повторная_доставка_не_задваивает_движения(): void
    {
        $this->dispatch($this->postedMessage([$this->entry()]));
        $this->dispatch($this->postedMessage([$this->entry()]));

        $this->assertSame(1, SettlementEntry::query()->count());
        $this->assertEqualsWithDelta(-120000.0, (float) SettlementEntry::query()->sum('amount'), 0.01);
    }

    /**
     * Перепроведение заменяет набор целиком. Отличить «строка не изменилась»
     * от «строки больше нет» по дельте невозможно, поэтому только полная замена.
     */
    #[Test]
    public function перепроведение_заменяет_набор_движений_целиком(): void
    {
        $this->dispatch($this->postedMessage([
            $this->entry(),
            $this->entry(['uuid' => 'd8a3f1c6-2e7b-4930-8f45-1c6a9e3b7024', 'amount' => -30000.00]),
        ]));

        $this->assertSame(2, SettlementEntry::query()->count());

        $this->dispatch($this->postedMessage([$this->entry(['amount' => -90000.00])]));

        $this->assertSame(1, SettlementEntry::query()->count());
        $this->assertEqualsWithDelta(-90000.0, (float) SettlementEntry::query()->sum('amount'), 0.01);
    }

    #[Test]
    public function пустой_массив_движений_очищает_документ(): void
    {
        $this->dispatch($this->postedMessage([$this->entry()]));
        $this->dispatch($this->postedMessage([]));

        $this->assertSame(0, SettlementEntry::query()->count());
    }

    /**
     * Неизвестный тип — единственное, из-за чего документ отбрасывается целиком.
     * Пропустить строку молча нельзя: баланс разъедется незаметно.
     */
    #[Test]
    public function неизвестный_тип_движения_не_сохраняется(): void
    {
        $this->dispatch($this->postedMessage([$this->entry(['type' => 'что_то_новое'])]));

        $this->assertSame(0, SettlementEntry::query()->count());
        $this->assertSame('failed', ErpBusMessage::query()->latest('id')->value('status'));
    }

    #[Test]
    public function отмена_проведения_гасит_вклад_документа(): void
    {
        $this->dispatch($this->postedMessage([$this->entry()], 1));

        $this->dispatch([
            'event' => 'settlement.reverted',
            'message_id' => 'msg-reverted-1',
            'revision' => 2,
            'document_uuid' => self::DOCUMENT_UUID,
        ]);

        $this->assertSame(0, SettlementEntry::query()->count());
        $this->assertTrue(SettlementDocument::query()->where('uuid', self::DOCUMENT_UUID)->value('is_reverted'));
    }

    /**
     * Ревизии по одному документу могут доехать в обратном порядке: у очереди
     * свой connection, а у брокера нет обязательств по порядку между сообщениями.
     */
    #[Test]
    public function устаревшая_ревизия_отбрасывается(): void
    {
        $this->dispatch($this->postedMessage([$this->entry(['amount' => -90000.00])], 5));
        $this->dispatch($this->postedMessage([$this->entry(['amount' => -120000.00])], 3));

        $this->assertEqualsWithDelta(-90000.0, (float) SettlementEntry::query()->sum('amount'), 0.01);
        $this->assertSame('stale', ErpBusMessage::query()->latest('id')->value('status'));
    }

    /**
     * Отметка отмены переживает удаление движений — именно ради этого заведена
     * служебная запись документа. Иначе устаревшее `posted` воскресило бы
     * отменённый документ: сравнивать ревизию было бы не с чем.
     */
    #[Test]
    public function устаревшее_проведение_не_воскрешает_отменённый_документ(): void
    {
        $this->dispatch($this->postedMessage([$this->entry()], 1));
        $this->dispatch([
            'event' => 'settlement.reverted',
            'message_id' => 'msg-reverted-2',
            'revision' => 4,
            'document_uuid' => self::DOCUMENT_UUID,
        ]);

        $this->dispatch($this->postedMessage([$this->entry()], 2));

        $this->assertSame(0, SettlementEntry::query()->count());
    }

    /**
     * Рублёвый эквивалент приходит из 1С и никогда не считается сайтом: свой курс
     * обновляется отдельно, и акт сверки, посчитанный по нему, разъехался бы
     * с отчётами 1С задним числом.
     */
    #[Test]
    public function валютное_движение_сохраняет_рублёвый_эквивалент_из_1с(): void
    {
        $this->dispatch($this->postedMessage([$this->entry([
            'currency_code' => 'EUR',
            'amount' => -1000.00,
            'amount_rub' => -104500.00,
        ])]));

        $entry = SettlementEntry::query()->sole();

        $this->assertSame('EUR', $entry->currency_code);
        $this->assertEqualsWithDelta(-104500.0, (float) $entry->amount_rub, 0.01);
    }

    #[Test]
    public function валютное_движение_без_эквивалента_не_получает_наш_курс(): void
    {
        $entry = $this->entry(['currency_code' => 'EUR', 'amount' => -1000.00]);
        unset($entry['amount_rub']);

        $this->dispatch($this->postedMessage([$entry]));

        $this->assertNull(SettlementEntry::query()->sole()->amount_rub);
    }

    /**
     * Движение и документ идут разными очередями, порядок не гарантирован
     * ни в какую сторону. Проверяем худший случай: движение приехало первым.
     */
    #[Test]
    public function движение_доклеивается_к_документу_приехавшему_позже(): void
    {
        $this->dispatch($this->postedMessage([$this->entry()]));

        $this->assertNull(SettlementEntry::query()->sole()->document_id);

        $shipment = Shipment::factory()->create([
            'uuid' => self::DOCUMENT_UUID,
            'user_id' => $this->user->id,
        ]);

        $entry = SettlementEntry::query()->sole();

        $this->assertSame($shipment->id, $entry->document_id);
        $this->assertTrue($entry->document->is($shipment));
    }

    #[Test]
    public function движение_доклеивается_к_документу_приехавшему_раньше(): void
    {
        $shipment = Shipment::factory()->create([
            'uuid' => self::DOCUMENT_UUID,
            'user_id' => $this->user->id,
        ]);

        $this->dispatch($this->postedMessage([$this->entry()]));

        $this->assertSame($shipment->id, SettlementEntry::query()->sole()->document_id);
    }

    #[Test]
    public function соглашение_приезжает_и_подхватывается_движением(): void
    {
        $this->dispatch([
            'event' => 'agreement.created',
            'message_id' => 'msg-agreement-1',
            'uuid' => self::AGREEMENT_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'organization_uuid' => self::ORGANIZATION_UUID,
            'number' => 'СГ-0042',
            'name' => 'Соглашение об условиях продаж №СГ-0042',
            'settlement_procedure' => 'orders',
            'deferral_days' => 30,
        ], 'erp_in.contractors');

        $agreement = Agreement::query()->sole();
        $this->assertSame($this->company->id, $agreement->company_id);
        $this->assertSame('По заказам', $agreement->settlement_procedure_label);

        $this->dispatch($this->postedMessage([$this->entry(['agreement_uuid' => self::AGREEMENT_UUID])]));

        $this->assertSame($agreement->id, SettlementEntry::query()->sole()->agreement_id);
    }

    /**
     * Ненайденное соглашение движение не отбрасывает: деньги важнее связей,
     * а `agreement_uuid` рядом позволит доклеить позже.
     */
    #[Test]
    public function движение_с_неизвестным_соглашением_сохраняется(): void
    {
        $this->dispatch($this->postedMessage([$this->entry(['agreement_uuid' => self::AGREEMENT_UUID])]));

        $entry = SettlementEntry::query()->sole();

        $this->assertNull($entry->agreement_id);
        $this->assertSame(self::AGREEMENT_UUID, $entry->agreement_uuid);
    }

    #[Test]
    public function начальное_сальдо_ложится_в_ленту_одной_строкой(): void
    {
        $message = [
            'event' => 'settlement.opening_balance',
            'message_id' => 'msg-opening-1',
            'uuid' => '9d4c7e2a-1f8b-4356-a09c-3e7d5b2f8a14',
            'as_of_date' => '2026-01-01',
            'amount' => -50000.00,
            'is_verified' => false,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'organization_uuid' => self::ORGANIZATION_UUID,
        ];

        $this->dispatch($message);
        $this->dispatch($message + ['message_id' => 'msg-opening-2']);

        $entry = SettlementEntry::query()->sole();

        $this->assertSame(SettlementEntry::TYPE_OPENING_BALANCE, $entry->type);
        $this->assertEqualsWithDelta(-50000.0, (float) $entry->amount, 0.01);
        $this->assertSame('2026-01-01', $entry->date->toDateString());
    }

    /**
     * Формула акта сверки заказчика целиком, одним `SUM`:
     * сальдо + оплаты + возвраты товара − реализации − возврат денег.
     */
    #[Test]
    public function лента_движений_даёт_баланс_из_контракта(): void
    {
        $this->dispatch([
            'event' => 'settlement.opening_balance',
            'message_id' => 'msg-opening-3',
            'uuid' => '9d4c7e2a-1f8b-4356-a09c-3e7d5b2f8a14',
            'as_of_date' => '2026-01-01',
            'amount' => -50000.00,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'organization_uuid' => self::ORGANIZATION_UUID,
        ]);

        $this->dispatch($this->postedMessage([
            $this->entry(['amount' => -120000.00, 'amount_rub' => -120000.00]),
            $this->entry([
                'uuid' => 'a1b2c3d4-5e6f-4708-9a1b-2c3d4e5f6071',
                'type' => 'payment_in',
                'amount' => 100000.00,
                'amount_rub' => 100000.00,
            ]),
            $this->entry([
                'uuid' => 'b2c3d4e5-6f70-4819-a2b3-3d4e5f607182',
                'type' => 'goods_return',
                'amount' => 20000.00,
                'amount_rub' => 20000.00,
            ]),
            $this->entry([
                'uuid' => 'c3d4e5f6-7081-492a-b3c4-4e5f60718293',
                'type' => 'payment_out',
                'amount' => -5000.00,
                'amount_rub' => -5000.00,
            ]),
        ]));

        $balance = SettlementEntry::query()
            ->facts()
            ->forReconciliation($this->company->id)
            ->sum('amount');

        $this->assertEqualsWithDelta(-55000.0, (float) $balance, 0.01);
    }

    #[Test]
    public function контрольная_точка_сохраняется_и_не_задваивается(): void
    {
        $message = [
            'event' => 'settlement.checkpoint',
            'message_id' => 'msg-checkpoint-1',
            'as_of_date' => '2026-07-01',
            'is_verified' => true,
            'amount' => -55000.00,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'organization_uuid' => self::ORGANIZATION_UUID,
            'currency_code' => 'RUB',
        ];

        $this->dispatch($message);
        $this->dispatch(['message_id' => 'msg-checkpoint-2', 'amount' => -57000.00] + $message);

        $checkpoint = SettlementCheckpoint::query()->sole();

        $this->assertEqualsWithDelta(-57000.0, (float) $checkpoint->amount, 0.01);
        $this->assertTrue($checkpoint->is_verified);
        // Контрольная точка — не движение: в ленту она попасть не должна.
        $this->assertSame(0, SettlementEntry::query()->count());
    }
}
