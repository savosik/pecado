<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorOrganizationBalance;
use App\Models\Organization;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Организации, исключённые из взаиморасчётов (`organizations.is_settlements_excluded`).
 *
 * В 1С есть техническая организация «Реклама»: на неё проводятся внутренние
 * операции, которые не являются расчётами с партнёрами и контрагентами. Её движения,
 * графики, контрольные точки и балансы обязаны отбрасываться на входе — иначе
 * «Реклама» всплывает в акте сверки, календаре оплат и реквизитах долга клиента.
 *
 * Сообщения регистра гоняются через `ErpIncomingJob`, чтобы payload проходил
 * runtime-валидацию схемой — контракт с 1С при исключении не меняется.
 */
class SettlementExcludedOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private const DOCUMENT_UUID = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

    private const CONTRACTOR_UUID = 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508';

    private const PARTNER_UUID = '7c9e6b21-4a3d-4e8f-b512-9d7c3e1a6f04';

    private const ORGANIZATION_UUID = 'e1a7c3d9-2b8f-4056-9c14-7d3e8b5a2f61';

    private const EXCLUDED_ORGANIZATION_UUID = 'f3070b58-327d-11e4-ac24-001e6711ed1d';

    private User $user;

    private Company $company;

    private Organization $organization;

    private Organization $excluded;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'erp_id' => self::CONTRACTOR_UUID,
            'tax_id' => '7710140679',
        ]);
        $this->organization = Organization::factory()->create(['external_id' => self::ORGANIZATION_UUID]);
        $this->excluded = Organization::factory()->create([
            'external_id' => self::EXCLUDED_ORGANIZATION_UUID,
            'name' => 'Реклама',
            'is_settlements_excluded' => true,
        ]);
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
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function postedMessage(array $entries): array
    {
        return [
            'event' => 'settlement.posted',
            'message_id' => 'msg-posted-'.uniqid(),
            'spec_version' => '16.0',
            'document_uuid' => self::DOCUMENT_UUID,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-006915',
            'document_date' => '2026-07-15',
            'entries' => $entries,
        ];
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
        ];
    }

    #[Test]
    public function движение_исключённой_организации_не_попадает_в_регистр(): void
    {
        $this->dispatch($this->postedMessage([
            $this->entry(),
            $this->entry([
                'uuid' => 'a5b9d3f8-0e4c-4276-9d8b-2f6a0c4e7319',
                'organization_uuid' => self::EXCLUDED_ORGANIZATION_UUID,
                'amount' => -7000.00,
                'amount_rub' => -7000.00,
            ]),
        ]));

        $line = SettlementEntry::query()->sole();

        $this->assertSame($this->organization->id, $line->organization_id);
        $this->assertEqualsWithDelta(-120000.0, (float) $line->amount, 0.01);
    }

    /**
     * Delete-and-recreate работает и для исключённой организации: перепроведение
     * документа вычищает её ранее принятые движения, а новые не создаёт.
     */
    #[Test]
    public function перепроведение_вычищает_старые_движения_исключённой_организации(): void
    {
        SettlementEntry::factory()->create([
            'document_uuid' => self::DOCUMENT_UUID,
            'nature' => SettlementEntry::NATURE_FACT,
            'organization_id' => $this->excluded->id,
        ]);

        $this->dispatch($this->postedMessage([
            $this->entry(['organization_uuid' => self::EXCLUDED_ORGANIZATION_UUID]),
        ]));

        $this->assertSame(0, SettlementEntry::query()->count());
    }

    #[Test]
    public function график_оплат_исключённой_организации_пропускается(): void
    {
        $this->dispatch([
            'event' => 'payment_schedule.updated',
            'message_id' => 'msg-schedule-'.uniqid(),
            'spec_version' => '16.0',
            'document_uuid' => self::DOCUMENT_UUID,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-006915',
            'document_date' => '2026-07-15',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'organization_uuid' => self::EXCLUDED_ORGANIZATION_UUID,
            'currency_code' => 'RUB',
            'lines' => [
                ['uuid' => 'f4a8c2e7-9d3b-4165-8c7a-1e5f9b3d6208', 'due_date' => '2026-08-14', 'amount' => 120000.00],
            ],
        ]);

        $this->assertSame(0, SettlementEntry::query()->count());
    }

    #[Test]
    public function контрольная_точка_исключённой_организации_не_сохраняется(): void
    {
        $this->dispatch([
            'event' => 'settlement.checkpoint',
            'message_id' => 'msg-checkpoint-1',
            'as_of_date' => '2026-07-01',
            'is_verified' => true,
            'amount' => -55000.00,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'organization_uuid' => self::EXCLUDED_ORGANIZATION_UUID,
            'currency_code' => 'RUB',
        ]);

        $this->assertSame(0, SettlementCheckpoint::query()->count());
    }

    #[Test]
    public function разрез_балансов_не_пишется_по_исключённой_организации(): void
    {
        app(HandleBalanceUpdated::class)->handle([
            'event' => 'balance.updated',
            'message_id' => 'msg-balance-'.uniqid(),
            'partner_uuid' => self::PARTNER_UUID,
            'updated_at' => '2026-08-15T10:00:00+03:00',
            'contractors' => [[
                'uuid' => self::CONTRACTOR_UUID,
                'tax_id' => '7710140679',
                'current_balance' => -15000.50,
                'overdue_debt' => 5000,
                'organizations' => [
                    ['uuid' => self::ORGANIZATION_UUID, 'current_balance' => -10000.50, 'overdue_debt' => 5000],
                    ['uuid' => self::EXCLUDED_ORGANIZATION_UUID, 'current_balance' => -5000, 'overdue_debt' => 0],
                ],
            ]],
        ]);

        $row = ContractorOrganizationBalance::query()->sole();

        $this->assertSame($this->organization->id, $row->organization_id);
    }

    /**
     * v16.7.0 (круг 12): агрегат контрагента приходит вместе с расчётами внутренних
     * организаций. Строки разреза по ним мы не пишем, а итог писали целиком — и в
     * сверке всплывал долг перед «Рекламой», которого на витрине нет: у клиента
     * 2 785,80 ₽ «просрочки» при полностью погашенном графике.
     */
    #[Test]
    public function итог_контрагента_очищается_от_исключённой_организации(): void
    {
        app(HandleBalanceUpdated::class)->handle([
            'event' => 'balance.updated',
            'message_id' => 'msg-balance-'.uniqid(),
            'partner_uuid' => self::PARTNER_UUID,
            'updated_at' => '2026-08-24T10:00:00+03:00',
            'contractors' => [[
                'uuid' => self::CONTRACTOR_UUID,
                'tax_id' => '7710140679',
                'current_balance' => -12785.80,
                'overdue_debt' => 7785.80,
                'organizations' => [
                    ['uuid' => self::ORGANIZATION_UUID, 'current_balance' => -10000.00, 'overdue_debt' => 5000.00],
                    ['uuid' => self::EXCLUDED_ORGANIZATION_UUID, 'current_balance' => -2785.80, 'overdue_debt' => 2785.80],
                ],
            ]],
        ]);

        $balance = ContractorBalance::query()->sole();

        $this->assertEqualsWithDelta(-10000.00, (float) $balance->current_balance, 0.01);
        $this->assertEqualsWithDelta(5000.00, (float) $balance->overdue_debt, 0.01);
    }

    /**
     * Без разреза вычитать не из чего: агрегат сохраняется как есть. Соврать
     * в другую сторону — занизить долг клиента — хуже, чем оставить шум.
     */
    #[Test]
    public function без_разреза_итог_контрагента_сохраняется_целиком(): void
    {
        app(HandleBalanceUpdated::class)->handle([
            'event' => 'balance.updated',
            'message_id' => 'msg-balance-'.uniqid(),
            'partner_uuid' => self::PARTNER_UUID,
            'updated_at' => '2026-08-24T10:00:00+03:00',
            'contractors' => [[
                'uuid' => self::CONTRACTOR_UUID,
                'tax_id' => '7710140679',
                'current_balance' => -12785.80,
                'overdue_debt' => 7785.80,
            ]],
        ]);

        $balance = ContractorBalance::query()->sole();

        $this->assertEqualsWithDelta(-12785.80, (float) $balance->current_balance, 0.01);
        $this->assertEqualsWithDelta(7785.80, (float) $balance->overdue_debt, 0.01);
    }
}
