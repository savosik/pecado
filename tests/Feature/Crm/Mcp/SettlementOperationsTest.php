<?php

namespace Tests\Feature\Crm\Mcp;

use App\Mcp\Servers\AnalyticsServer;
use App\Models\Company;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\Api\OperationRegistry;
use App\Services\Crm\Api\Operations\PaymentOperations;
use App\Services\Crm\Api\Operations\SettlementOperations;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Взаиморасчёты для ИИ-агента (v16.0.0, карточка fin-09).
 *
 * До регистра агенту приходилось не верить собственному API: в ответах висели
 * предупреждения «это НЕ долг клиента». Тест закрепляет две вещи — что операции
 * появляются только вместе с флагом и что скоуп задаёт актор, а не аргумент.
 */
class SettlementOperationsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    private User $foreign;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        config(['settlements.ledger_enabled' => true]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
        $this->company = Company::factory()->create(['user_id' => $this->client->id]);
        $this->foreign = User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes, ?User $owner = null): SettlementEntry
    {
        $owner ??= $this->client;

        return SettlementEntry::factory()->create($attributes + [
            'user_id' => $owner->id,
            'company_id' => $owner->is($this->client)
                ? $this->company->id
                : Company::factory()->create(['user_id' => $owner->id])->id,
            'organization_id' => Organization::factory()->create()->id,
            'currency_code' => 'RUB',
        ]);
    }

    private function ops(): SettlementOperations
    {
        return app(SettlementOperations::class);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function input(array $params = []): OperationInput
    {
        return new OperationInput($params);
    }

    /**
     * Пока регистр пуст, операции отвечали бы «никто ничего не должен», и агент
     * сообщил бы это менеджеру с полной уверенностью. Поэтому они появляются
     * только вместе с переключением чтения.
     */
    #[Test]
    public function операции_регистра_появляются_только_с_флагом(): void
    {
        config(['settlements.ledger_enabled' => false]);
        $ids = collect((new OperationRegistry)->all())->pluck('id');
        $this->assertFalse($ids->contains('settlement.balance'));

        config(['settlements.ledger_enabled' => true]);
        $ids = collect((new OperationRegistry)->all())->pluck('id');

        foreach (['settlement.balance', 'settlement.schedule', 'settlement.reconciliation', 'settlement.debtors'] as $id) {
            $this->assertTrue($ids->contains($id), $id.' не зарегистрирована');
        }
    }

    /**
     * Три числа сразу, потому что по отдельности их путают: сальдо включает
     * ещё не наступившие обязательства, долг — только наступившие.
     */
    #[Test]
    public function баланс_отдаёт_сальдо_долг_и_просрочку(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -120000,
            'amount_rub' => -120000,
            'date' => CarbonImmutable::today()->subDays(30)->toDateString(),
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 65000,
            'amount_rub' => 65000,
            'date' => CarbonImmutable::today()->subDays(10)->toDateString(),
        ]);
        // Просроченная плановая строка.
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 55000,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->subDays(3)->toDateString(),
        ]);
        // Ещё не наступившая — в долг сейчас не входит.
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 40000,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->addDays(10)->toDateString(),
        ]);

        $row = $this->ops()->balance($this->manager, $this->input())['data'][0];

        $this->assertEqualsWithDelta(-55000.0, $row['balance'], 0.01);
        $this->assertEqualsWithDelta(55000.0, $row['due_now'], 0.01);
        $this->assertEqualsWithDelta(55000.0, $row['overdue'], 0.01);
        $this->assertSame(0.0, $row['advance']);
    }

    /**
     * Скоуп задаёт актор, а не аргумент вызова.
     */
    #[Test]
    public function чужие_расчёты_не_видны(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -99000,
            'amount_rub' => -99000,
            'date' => CarbonImmutable::today()->toDateString(),
        ], $this->foreign);

        $result = $this->ops()->balance($this->manager, $this->input());

        $this->assertSame([], $result['data']);
    }

    #[Test]
    public function чужой_client_id_не_расширяет_видимость(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->ops()->balance($this->manager, $this->input(['client_id' => $this->foreign->id]));
    }

    #[Test]
    public function график_отдаёт_только_непогашенные_строки(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 50000,
            'settled_amount' => 20000,
            'date' => CarbonImmutable::today()->addDays(5)->toDateString(),
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 30000,
            'settled_amount' => 30000,
            'date' => CarbonImmutable::today()->addDays(6)->toDateString(),
        ]);

        $result = $this->ops()->schedule($this->manager, $this->input());

        $this->assertCount(1, $result['data']);
        $this->assertEqualsWithDelta(30000.0, $result['data'][0]['unsettled_amount'], 0.01);
    }

    #[Test]
    public function должники_отсортированы_по_сумме(): void
    {
        $second = User::factory()->create(['personal_manager_id' => $this->client->personal_manager_id]);

        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 10000,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->subDays(5)->toDateString(),
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 90000,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->subDays(20)->toDateString(),
        ], $second);

        $rows = $this->ops()->debtors($this->manager, $this->input())['data'];

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(90000.0, $rows[0]['overdue'], 0.01);
        $this->assertSame($second->id, $rows[0]['client_id']);
    }

    #[Test]
    public function акт_сверки_отдаётся_с_расшифровкой_формулы(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -10000,
            'amount_rub' => -10000,
            'date' => '2026-02-10',
        ]);

        $result = $this->ops()->reconciliation($this->manager, $this->input([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]));

        $this->assertEqualsWithDelta(-10000.0, $result['data']['closing_balance'], 0.01);
        $this->assertNotEmpty(array_filter(
            $result['meta']['notes'],
            static fn (string $note): bool => str_contains($note, 'Формула'),
        ));
    }

    /**
     * Совсем удалить операцию нельзя: у существующих агентов вызов зашит
     * в промптах, и молчаливое исчезновение они истолкуют как «долгов нет».
     */
    #[Test]
    public function снятая_операция_объясняет_чем_её_заменить(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/settlement\.balance/');

        app(PaymentOperations::class)->unpaidShipments($this->manager, $this->input());
    }

    #[Test]
    public function при_выключенном_флаге_старая_операция_работает(): void
    {
        config(['settlements.ledger_enabled' => false]);

        $result = app(PaymentOperations::class)->unpaidShipments($this->manager, $this->input());

        $this->assertArrayHasKey('data', $result);
    }

    /**
     * Инструкции аналитического агента переключаются тем же флагом: снять
     * предупреждения «это не долг» раньше данных значило бы дать агенту
     * уверенно врать.
     */
    #[Test]
    public function инструкции_mcp_переключаются_флагом(): void
    {
        $transport = \Mockery::mock(\Laravel\Mcp\Server\Contracts\Transport::class);

        config(['settlements.ledger_enabled' => false]);
        $legacy = (new AnalyticsServer($transport))->createContext()->instructions;

        config(['settlements.ledger_enabled' => true]);
        $ledger = (new AnalyticsServer($transport))->createContext()->instructions;

        $this->assertStringContainsString('Не считайте долг суммой', $legacy);
        $this->assertStringNotContainsString('Не считайте долг суммой', $ledger);

        $this->assertStringContainsString('settlement_entries', $ledger);
        // Предупреждение про знак остаётся навсегда: перепутав его, агент выдаст
        // долг наизнанку — арифметика сойдётся, а смысл перевернётся.
        $this->assertStringContainsString('Знак содержательный', $ledger);
    }
}
