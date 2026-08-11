<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * График оплаты как самостоятельное событие (v16.0.0, карточка fin-04).
 *
 * Главное, что здесь проверяется, — сайт больше **не раскладывает платежи**.
 * По реализациям остаток приходит построчно из 1С; по заказам построчного остатка
 * в учёте нет вовсе, и приходит одна авторитетная сумма на документ, которая
 * делится по этапам только ради календаря и помечается производной.
 *
 * Прежняя самодельная FIFO-раскладка показала одному клиенту 5,53 млн ₽ просрочки
 * против 478 тыс ₽ по данным учёта — ради этого различия событие и выделено.
 */
class PaymentScheduleV16IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const SHIPMENT_UUID = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

    private const ORDER_UUID = 'a2c4e6f8-1b3d-4507-9e2a-6c8f4d1b7e35';

    private const CONTRACTOR_UUID = 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508';

    private const PARTNER_UUID = '7c9e6b21-4a3d-4e8f-b512-9d7c3e1a6f04';

    private const ORGANIZATION_UUID = 'e1a7c3d9-2b8f-4056-9c14-7d3e8b5a2f61';

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
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $payload): void
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
            'erp_in.settlements',
        ))->fire();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scheduleMessage(array $lines, array $overrides = []): array
    {
        return $overrides + [
            'event' => 'payment_schedule.updated',
            'message_id' => 'msg-schedule-'.uniqid(),
            'spec_version' => '16.0',
            'document_uuid' => self::SHIPMENT_UUID,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-006915',
            'document_date' => '2026-07-15',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'organization_uuid' => self::ORGANIZATION_UUID,
            'currency_code' => 'RUB',
            'lines' => $lines,
        ];
    }

    #[Test]
    public function остаток_по_реализации_берётся_из_1с(): void
    {
        $this->dispatch($this->scheduleMessage([
            [
                'uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208',
                'due_date' => '2026-08-14',
                'amount' => 120000.00,
                'settled_amount' => 100000.00,
                'line_number' => 1,
                'percent' => 100,
                'stage_name' => 'Оплата после отгрузки',
            ],
        ]));

        $line = SettlementEntry::query()->sole();

        $this->assertSame(SettlementEntry::NATURE_PLAN, $line->nature);
        $this->assertSame(SettlementEntry::TYPE_PAYMENT_DUE, $line->type);
        $this->assertEqualsWithDelta(120000.0, (float) $line->amount, 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $line->settled_amount, 0.01);
        $this->assertEqualsWithDelta(20000.0, $line->unsettled_amount, 0.01);
        $this->assertFalse($line->is_settled_derived);
        $this->assertSame($this->company->id, $line->company_id);
        $this->assertSame(['percent' => 100, 'stage_name' => 'Оплата после отгрузки'], $line->meta);
    }

    /**
     * График присылается целиком, а не дельтой: массив полностью вытесняет
     * сохранённое. Иначе отменённую строку было бы нечем убрать.
     */
    #[Test]
    public function повторная_доставка_заменяет_график_целиком(): void
    {
        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
            ['uuid' => 'a5b9d3f8-0e4c-4276-9d8b-2f6a0c4e7319', 'due_date' => '2026-09-14', 'amount' => 80000.00],
        ]));

        $this->assertSame(2, SettlementEntry::query()->count());

        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 200000.00],
        ]));

        $this->assertSame(1, SettlementEntry::query()->count());
        $this->assertEqualsWithDelta(200000.0, (float) SettlementEntry::query()->sum('amount'), 0.01);
    }

    #[Test]
    public function пустой_график_очищает_строки_документа(): void
    {
        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
        ]));

        $this->dispatch($this->scheduleMessage([]));

        $this->assertSame(0, SettlementEntry::query()->count());
    }

    /**
     * Строка без плановой даты отбраковывает сообщение целиком.
     *
     * График — единое целое: сохранив его частично, мы показали бы клиенту
     * календарь, который не сходится с суммой документа, и просрочку, посчитанную
     * по неполному плану. Молчаливая частичная правда здесь хуже видимой ошибки.
     *
     * Дата к тому же входит в ключ строки («расчётный документ + дата планового
     * погашения»), так что строки без неё в 1С не существует.
     */
    #[Test]
    public function строка_без_даты_отбраковывает_сообщение_целиком(): void
    {
        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
            ['uuid' => 'a5b9d3f8-0e4c-4276-9d8b-2f6a0c4e7319', 'due_date' => null, 'amount' => 80000.00],
        ]));

        $this->assertSame(0, SettlementEntry::query()->count());
    }

    /**
     * Переплата по строке легитимна — так 1С отражает досрочный платёж.
     * Отрицательный остаток вычитался бы из общего долга и занижал его.
     */
    #[Test]
    public function переплата_по_строке_не_даёт_отрицательного_остатка(): void
    {
        $this->dispatch($this->scheduleMessage([
            [
                'uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208',
                'due_date' => '2026-08-14',
                'amount' => 120000.00,
                'settled_amount' => 150000.00,
            ],
        ]));

        $this->assertSame(0.0, SettlementEntry::query()->sole()->unsettled_amount);
        $this->assertSame(0, SettlementEntry::query()->outstanding()->count());
    }

    /**
     * По заказам построчного остатка в 1С нет: в регистре по срокам ноль строк
     * с объектом расчётов «заказ клиента». Приходит одна сумма на документ.
     */
    #[Test]
    public function оплата_заказа_разносится_по_этапам_и_помечается_производной(): void
    {
        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 30000.00],
            ['uuid' => 'a5b9d3f8-0e4c-4276-9d8b-2f6a0c4e7319', 'due_date' => '2026-09-14', 'amount' => 70000.00],
        ], [
            'document_uuid' => self::ORDER_UUID,
            'document_kind' => 'order',
            'document_settled_amount' => 40000.00,
        ]));

        $lines = SettlementEntry::query()->orderBy('date')->get();

        $this->assertCount(2, $lines);
        // Первый этап закрыт целиком, остаток лёг на второй: раньше срок —
        // раньше закрывается.
        $this->assertEqualsWithDelta(30000.0, (float) $lines[0]->settled_amount, 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $lines[1]->settled_amount, 0.01);
        $this->assertTrue($lines[0]->is_settled_derived);
        $this->assertTrue($lines[1]->is_settled_derived);

        // Авторитетная сумма сохраняется как есть: разнесение её не заменяет.
        $this->assertEqualsWithDelta(40000.0, (float) $lines[0]->document_settled_amount, 0.01);
        // Итог по документу верен по построению — это и есть смысл правила.
        $this->assertEqualsWithDelta(40000.0, (float) $lines->sum(fn ($line) => (float) $line->settled_amount), 0.01);
    }

    /**
     * Переплата по заказу оседает на последнем этапе. Потеряв её, календарь
     * показал бы долг там, где его нет.
     */
    #[Test]
    public function переплата_по_заказу_не_теряется_при_разнесении(): void
    {
        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 30000.00],
        ], [
            'document_uuid' => self::ORDER_UUID,
            'document_kind' => 'order',
            'document_settled_amount' => 45000.00,
        ]));

        $line = SettlementEntry::query()->sole();

        $this->assertEqualsWithDelta(45000.0, (float) $line->settled_amount, 0.01);
        $this->assertSame(0.0, $line->unsettled_amount);
    }

    /**
     * План и факт живут в одной таблице, но принадлежат разным сообщениям.
     * Перепутав скоупы, мы бы удаляли чужие строки: график стирал бы движения,
     * а проведение — календарь.
     */
    #[Test]
    public function график_и_движения_не_вытесняют_друг_друга(): void
    {
        $this->dispatch([
            'event' => 'settlement.posted',
            'message_id' => 'msg-posted-mixed',
            'document_uuid' => self::SHIPMENT_UUID,
            'document_kind' => 'shipment',
            'document_date' => '2026-07-15',
            'entries' => [[
                'uuid' => 'c7f2e9a4-3b1d-4857-9e6c-2a8f4d7b1035',
                'type' => 'shipment',
                'amount' => -120000.00,
                'contractor_uuid' => self::CONTRACTOR_UUID,
                'partner_uuid' => self::PARTNER_UUID,
            ]],
        ]);

        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
        ]));

        $this->assertSame(1, SettlementEntry::query()->facts()->count());
        $this->assertSame(1, SettlementEntry::query()->plans()->count());

        // Плановая строка в баланс не входит: деньги ещё не пришли.
        $this->assertEqualsWithDelta(
            -120000.0,
            (float) SettlementEntry::query()->facts()->sum('amount'),
            0.01,
        );
    }

    /**
     * График и движения — два независимых потока сообщений об одном документе,
     * но `revision` у них общая, документа. С единым счётчиком ревизий график
     * с той же ревизией, что уже применённое проведение, отбрасывался бы
     * как устаревший — и клиент молча остался бы без календаря платежей.
     */
    #[Test]
    public function график_не_отбрасывается_из_за_ревизии_проведения(): void
    {
        $this->dispatch([
            'event' => 'settlement.posted',
            'message_id' => 'msg-posted-rev5',
            'revision' => 5,
            'document_uuid' => self::SHIPMENT_UUID,
            'document_kind' => 'shipment',
            'document_date' => '2026-07-15',
            'entries' => [[
                'uuid' => 'c7f2e9a4-3b1d-4857-9e6c-2a8f4d7b1035',
                'type' => 'shipment',
                'amount' => -120000.00,
                'contractor_uuid' => self::CONTRACTOR_UUID,
            ]],
        ]);

        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
        ], ['revision' => 5]));

        $this->assertSame(1, SettlementEntry::query()->plans()->count());
    }

    /**
     * Внутри своего потока порядок соблюдается: устаревший график не затирает свежий.
     */
    #[Test]
    public function устаревший_график_отбрасывается(): void
    {
        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
        ], ['revision' => 4]));

        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'a5b9d3f8-0e4c-4276-9d8b-2f6a0c4e7319', 'due_date' => '2026-09-14', 'amount' => 80000.00],
        ], ['revision' => 2]));

        $this->assertSame(1, SettlementEntry::query()->plans()->count());
        $this->assertEqualsWithDelta(120000.0, (float) SettlementEntry::query()->plans()->sum('amount'), 0.01);
    }

    #[Test]
    public function неизвестный_вид_документа_отбраковывается_схемой(): void
    {
        $this->dispatch($this->scheduleMessage([
            ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
        ], ['document_kind' => 'invoice']));

        $this->assertSame(0, SettlementEntry::query()->count());
    }
}
