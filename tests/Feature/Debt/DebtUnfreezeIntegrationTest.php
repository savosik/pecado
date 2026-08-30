<?php

namespace Tests\Feature\Debt;

use App\Enums\DebtLevel;
use App\Events\PartnerSettlementsChanged;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\DebtState;
use App\Models\Organization;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Debt\DebtStateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Разморозка по сообщениям 1С (карточка debt-04): `balance.updated` и
 * `settlement.posted` из RabbitMQ → валидация схемой → хендлер → событие →
 * пересчёт ступени только вверх. Через `ErpIncomingJob`, а не вызовом
 * хендлера: контракт определяет схема, и обойти её тест не должен.
 */
class DebtUnfreezeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER_UUID = '7c9e6b21-4a3d-4e8f-b512-9d7c3e1a6f04';

    private const CONTRACTOR_UUID = 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508';

    private const ORGANIZATION_UUID = 'e1a7c3d9-2b8f-4056-9c14-7d3e8b5a2f61';

    private User $user;

    private Company $company;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = CarbonImmutable::parse('2026-08-27');
        CarbonImmutable::setTestNow($this->today);

        config([
            'erp.bus_logging_enabled' => true,
            'debt.enabled' => true,
            'debt.mode' => 'live',
            'debt.live_actions' => 'gate',
        ]);

        $this->user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'erp_id' => self::CONTRACTOR_UUID,
            'tax_id' => '7710140679',
        ]);
        Organization::factory()->create(['external_id' => self::ORGANIZATION_UUID]);

        ContractorBalance::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'tax_id' => '7710140679',
            'current_balance' => -50000,
            'overdue_debt' => 50000,
            'balance_erp_updated_at' => $this->today->toDateTimeString(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function balance_update_from_bus_lifts_the_level_once_debt_is_settled(): void
    {
        $line = $this->overduePlan(50000, 40);
        // Свежая отгрузка: просрочка — не весь долг, значит стоп-отгрузка
        // не положена и ступень остаётся «заказы закрыты».
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'date' => $this->today->subDays(2)->toDateString(),
            'amount' => -300000,
            'amount_rub' => -300000,
        ]);
        app(DebtStateService::class)->recalculate($this->today);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel());

        // 1С разнесла оплату по графику, следом прислала баланс.
        $line->update(['settled_amount' => 50000]);
        $this->dispatch($this->balanceMessage(0, 0));

        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel());
        $this->assertSame(DebtLevel::CLEAN, DebtState::query()->where('company_id', $this->company->id)->firstOrFail()->level);
    }

    #[Test]
    public function repeated_delivery_is_idempotent(): void
    {
        $line = $this->overduePlan(50000, 40);
        app(DebtStateService::class)->recalculate($this->today);
        $line->update(['settled_amount' => 50000]);

        $message = $this->balanceMessage(0, 0);
        $this->dispatch($message);
        $this->dispatch($message);
        $this->dispatch(['message_id' => 'msg-balance-again'] + $message);

        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel());
        $this->assertSame(2, DebtState::query()->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function event_driven_recalculation_never_escalates(): void
    {
        $this->overduePlan(50000, 40);

        // Просрочка давняя, но ночного пересчёта ещё не было: баланс из 1С
        // не имеет права ужесточить ступень сам.
        $this->dispatch($this->balanceMessage(-50000, 50000));

        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel());
    }

    #[Test]
    public function settlement_posted_from_bus_raises_partner_event(): void
    {
        Event::fake([PartnerSettlementsChanged::class]);

        $this->dispatch([
            'event' => 'settlement.posted',
            'message_id' => 'msg-posted-'.uniqid(),
            'spec_version' => '16.0',
            'document_uuid' => '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34',
            'document_kind' => 'payment',
            'document_number' => 'ПП-1',
            'document_date' => '2026-08-27',
            'entries' => [[
                'uuid' => 'c7f2e9a4-3b1d-4857-9e6c-2a8f4d7b1035',
                'type' => 'payment_in',
                'date' => '2026-08-27',
                'amount' => 50000.00,
                'amount_rub' => 50000.00,
                'currency_code' => 'RUB',
                'contractor_uuid' => self::CONTRACTOR_UUID,
                'partner_uuid' => self::PARTNER_UUID,
                'organization_uuid' => self::ORGANIZATION_UUID,
            ]],
        ], 'erp_in.settlements');

        Event::assertDispatched(
            PartnerSettlementsChanged::class,
            fn (PartnerSettlementsChanged $event): bool => $event->userIds === [$this->user->id] && $event->source === 'settlement.posted',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $payload, string $queue = 'erp_in.partners'): void
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
     * @return array<string, mixed>
     */
    private function balanceMessage(float $balance, float $overdue): array
    {
        return [
            'event' => 'balance.updated',
            'message_id' => 'msg-balance-'.uniqid(),
            'partner_uuid' => self::PARTNER_UUID,
            'updated_at' => $this->today->toIso8601String(),
            'contractors' => [[
                'uuid' => self::CONTRACTOR_UUID,
                'tax_id' => '7710140679',
                'current_balance' => $balance,
                'overdue_debt' => $overdue,
            ]],
        ];
    }

    private function overduePlan(float $amount, int $daysAgo): SettlementEntry
    {
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'date' => $this->today->subDays($daysAgo + 14)->toDateString(),
            'amount' => -$amount,
            'amount_rub' => -$amount,
        ]);

        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'document_kind' => 'shipment',
            'date' => $this->today->subDays($daysAgo)->toDateString(),
            'amount' => $amount,
            'amount_rub' => $amount,
            'settled_amount' => 0,
        ]);
    }

    private function partnerLevel(): DebtLevel
    {
        return DebtState::query()->partners()->live()->where('user_id', $this->user->id)->first()?->level ?? DebtLevel::CLEAN;
    }
}
