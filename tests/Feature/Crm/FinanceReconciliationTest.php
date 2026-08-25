<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\ContractorOrganizationBalance;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Акт сверки взаиморасчётов (v16.0.0, карточка fin-08).
 *
 * Экран отдаёт документ, который менеджер отправляет клиенту, поэтому проверок
 * две группы: арифметика сальдо и то, что неверный акт нельзя отправить молча.
 */
class FinanceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $client;

    private Company $company;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-finance.view', 'crm-department.view', 'crm-clients-all.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->actor = User::factory()->create();
        $this->actor->givePermissionTo('crm-finance.view');
        $card = PersonalManager::create(['name' => 'Менеджер', 'user_id' => $this->actor->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
        $this->company = Company::factory()->create(['user_id' => $this->client->id]);
        $this->organization = Organization::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes): SettlementEntry
    {
        return SettlementEntry::factory()->create($attributes + [
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'nature' => SettlementEntry::NATURE_FACT,
        ]);
    }

    /**
     * Сверенная контрольная точка 1С на 01.03.2026 — эталон, с которым
     * сравнивается лента. Дата после движений тестов, чтобы они в неё попадали.
     */
    private function checkpoint(float $amount): void
    {
        \App\Models\SettlementCheckpoint::factory()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'as_of_date' => '2026-03-01',
            'amount' => $amount,
            'is_verified' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function open(array $query = []): array
    {
        $response = $this->actingAs($this->actor)
            ->get('/crm/finance/reconciliation?'.http_build_query($query));

        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * Сотруднику CRM без права на финансы — 403.
     *
     * Актору выдаётся другое право раздела намеренно: без единого CRM-права
     * его выбрасывает из кабинета редиректом, и проверка гейта не состоялась бы.
     */
    #[Test]
    public function без_права_раздел_закрыт(): void
    {
        $stranger = User::factory()->create();
        $stranger->givePermissionTo(Permission::firstOrCreate(['name' => 'crm-clients.view', 'guard_name' => 'web']));

        $this->actingAs($stranger->fresh())->get('/crm/finance/reconciliation')->assertForbidden();
    }

    #[Test]
    public function без_выбранного_клиента_показывается_только_форма(): void
    {
        $props = $this->open();

        $this->assertNull($props['client']);
        $this->assertNull($props['act']);
        $this->assertNotEmpty($props['options']['clients']);
    }

    /**
     * Чужой клиент обязан давать 404, а не акт по деньгам другого менеджера.
     */
    #[Test]
    public function клиент_вне_скоупа_недоступен(): void
    {
        $foreign = User::factory()->create([
            'personal_manager_id' => PersonalManager::create([
                'name' => 'Другой',
                'user_id' => User::factory()->create()->id,
            ])->id,
        ]);

        $this->actingAs($this->actor)
            ->get('/crm/finance/reconciliation?client_id='.$foreign->id)
            ->assertNotFound();
    }

    /**
     * Формула заказчика целиком: сальдо на начало + оплаты + возвраты товара
     * − реализации − возврат денег.
     */
    #[Test]
    public function сальдо_считается_нарастающим_итогом(): void
    {
        // До периода — формирует сальдо на начало.
        $this->entry(['type' => SettlementEntry::TYPE_OPENING_BALANCE, 'amount' => -50000, 'date' => '2026-01-01']);

        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000, 'date' => '2026-02-10']);
        $this->entry(['type' => SettlementEntry::TYPE_PAYMENT_IN, 'amount' => 100000, 'date' => '2026-02-15']);
        $this->entry(['type' => SettlementEntry::TYPE_GOODS_RETURN, 'amount' => 20000, 'date' => '2026-02-20']);
        $this->entry(['type' => SettlementEntry::TYPE_PAYMENT_OUT, 'amount' => -5000, 'date' => '2026-02-25']);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertEqualsWithDelta(-50000.0, $act['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(-55000.0, $act['closing_balance'], 0.01);
        $this->assertEqualsWithDelta(125000.0, $act['turnover_debit'], 0.01);
        $this->assertEqualsWithDelta(120000.0, $act['turnover_credit'], 0.01);
        $this->assertCount(4, $act['rows']);

        // Сальдо нарастающим: последняя строка совпадает с итогом.
        $this->assertEqualsWithDelta(-55000.0, end($act['rows'])['balance'], 0.01);
    }

    /**
     * Движения вне периода в обороты не попадают, но в сальдо на начало — да.
     */
    #[Test]
    public function движения_после_периода_в_акт_не_входят(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -99000, 'date' => '2026-03-10']);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertCount(1, $act['rows']);
        $this->assertEqualsWithDelta(-10000.0, $act['closing_balance'], 0.01);
    }

    /**
     * Граница периода включительно: движение ровно в последний день обязано
     * попасть в акт. Каст даты хранит время, и наивное сравнение его теряло бы.
     */
    #[Test]
    public function движение_в_последний_день_периода_попадает_в_акт(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-28']);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertCount(1, $act['rows']);
    }

    /**
     * Акт уходит клиенту. Молча отправить цифру, не сходящуюся с учётной
     * системой, нельзя — поэтому расхождение отдаётся отдельным полем.
     */
    #[Test]
    public function расхождение_со_сверенной_точкой_попадает_в_ответ(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000, 'date' => '2026-02-10']);

        $this->checkpoint(-100000);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertNotNull($act['discrepancy']);
        $this->assertEqualsWithDelta(-20000.0, $act['discrepancy']['delta'], 0.01);
        $this->assertSame('2026-03-01', $act['discrepancy']['as_of']);
    }

    /**
     * Балансы из `balance.updated` баннер больше не поднимают: 1С признала канал
     * недостоверным (v16.3.0). Расхождение с ним — не повод блокировать акт.
     */
    #[Test]
    public function расхождение_с_балансом_1с_баннера_не_поднимает(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000, 'date' => '2026-02-10']);

        ContractorOrganizationBalance::query()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'current_balance' => -100000,
            'overdue_debt' => 0,
        ]);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertNull($act['discrepancy']);
    }

    #[Test]
    public function сошедшаяся_точка_баннера_не_показывает(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000, 'date' => '2026-02-10']);

        $this->checkpoint(-120000);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertNull($act['discrepancy']);
    }

    #[Test]
    public function без_контрольной_точки_баннера_нет(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000, 'date' => '2026-02-10']);

        ContractorOrganizationBalance::query()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'current_balance' => -120000,
            'overdue_debt' => 0,
        ]);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertNull($act['discrepancy']);
    }

    /**
     * Период раньше начала ленты — это отсутствие истории, а не пустой акт.
     * Разница важная: пустой акт менеджер отправит клиенту.
     */
    #[Test]
    public function период_до_начала_ленты_помечается(): void
    {
        // 30.12.2025, а не 31-е: последним днём года приезжает долг прошлых
        // периодов («Ввод остатков взаиморасчётов»), и этот день уже в ленте.
        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-30',
        ])['act'];

        $this->assertTrue($act['before_ledger']);
        $this->assertSame([], $act['rows']);
    }

    #[Test]
    public function фильтр_по_организации_сужает_акт(): void
    {
        $other = Organization::factory()->create();

        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -70000,
            'date' => '2026-02-11',
            'organization_id' => $other->id,
        ]);

        $act = $this->open([
            'client_id' => $this->client->id,
            'organization_id' => $this->organization->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertCount(1, $act['rows']);
        $this->assertEqualsWithDelta(-10000.0, $act['closing_balance'], 0.01);
    }

    /**
     * У партнёра может быть несколько юрлиц-контрагентов, и подписывают акт
     * по каждому отдельно: без фильтра движения слипались бы в один документ.
     */
    #[Test]
    public function фильтр_по_контрагенту_сужает_акт(): void
    {
        $second = Company::factory()->create(['user_id' => $this->client->id]);

        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -70000,
            'date' => '2026-02-11',
            'company_id' => $second->id,
        ]);

        $props = $this->open([
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]);

        $this->assertCount(1, $props['act']['rows']);
        $this->assertEqualsWithDelta(-10000.0, $props['act']['closing_balance'], 0.01);
        // Справочник формы — оба контрагента с движениями.
        $this->assertCount(2, $props['options']['companies']);
    }

    /**
     * Контрагента выбирают без партнёра: партнёр выводится из его движений.
     */
    #[Test]
    public function контрагент_выбирается_без_партнёра(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);

        $props = $this->open([
            'company_id' => $this->company->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]);

        $this->assertSame($this->client->id, $props['client']['id']);
        $this->assertSame($this->client->id, $props['form']['client_id']);
        $this->assertCount(1, $props['act']['rows']);
    }

    /**
     * Справочники доступны до выбора партнёра — иначе контрагента не выбрать
     * первым: раньше список был пуст, пока не сформируешь акт.
     */
    #[Test]
    public function справочники_наполнены_без_выбранного_партнёра(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);

        $props = $this->open();

        $this->assertNull($props['client']);
        $this->assertCount(1, $props['options']['companies']);
        $this->assertCount(1, $props['options']['organizations']);
    }

    /**
     * Чужой контрагент не доводит до чужого акта: партнёр не выводится,
     * и экран остаётся формой.
     */
    #[Test]
    public function контрагент_вне_скоупа_акта_не_даёт(): void
    {
        $foreign = User::factory()->create([
            'personal_manager_id' => PersonalManager::create(['name' => 'Чужой'])->id,
        ]);
        $foreignCompany = Company::factory()->create(['user_id' => $foreign->id]);

        SettlementEntry::factory()->create([
            'user_id' => $foreign->id,
            'company_id' => $foreignCompany->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -50000,
            'date' => '2026-02-10',
        ]);

        $props = $this->open(['company_id' => $foreignCompany->id]);

        $this->assertNull($props['client']);
        $this->assertNull($props['act']);
        $this->assertNull($props['form']['company_id']);
    }

    /**
     * Контрагент чужого партнёра при явно выбранном своём гасится, а не смешивается.
     */
    #[Test]
    public function несочетаемый_контрагент_гасится_а_партнёр_остаётся(): void
    {
        $second = User::factory()->create([
            'personal_manager_id' => $this->client->personal_manager_id,
        ]);
        $secondCompany = Company::factory()->create(['user_id' => $second->id]);

        SettlementEntry::factory()->create([
            'user_id' => $second->id,
            'company_id' => $secondCompany->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -30000,
            'date' => '2026-02-10',
        ]);

        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);

        $props = $this->open([
            'client_id' => $this->client->id,
            'company_id' => $secondCompany->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]);

        $this->assertSame($this->client->id, $props['client']['id']);
        $this->assertNull($props['form']['company_id']);
        $this->assertCount(1, $props['act']['rows']);
        $this->assertEqualsWithDelta(-10000.0, $props['act']['closing_balance'], 0.01);
    }

    /**
     * Стороны расчётов видны построчно, пока не заданы фильтром.
     */
    #[Test]
    public function строка_акта_несёт_контрагента_и_нашу_организацию(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);

        $row = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act']['rows'][0];

        $this->assertSame($this->company->name, $row['company_name']);
        $this->assertSame($this->organization->name, $row['organization_name']);
    }

    /**
     * Соглашения из отбора убраны: акт строится по всем соглашениям сразу,
     * и параметр из адреса на состав движений больше не влияет.
     */
    #[Test]
    public function соглашения_в_отборе_не_участвуют(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);

        $props = $this->open([
            'client_id' => $this->client->id,
            'without_agreement' => 1,
            'agreement_id' => 999,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]);

        $this->assertCount(1, $props['act']['rows']);
        $this->assertArrayNotHasKey('agreements', $props['options']);
        $this->assertArrayNotHasKey('agreement_id', $props['form']);
        $this->assertArrayNotHasKey('without_agreement', $props['form']);
    }

    /**
     * Сверка с 1С обязана идти в том же разрезе, что и акт: баланс другого
     * контрагента — не расхождение.
     */
    #[Test]
    public function расхождение_считается_в_разрезе_контрагента(): void
    {
        $second = Company::factory()->create(['user_id' => $this->client->id]);

        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -70000,
            'date' => '2026-02-11',
            'company_id' => $second->id,
        ]);

        foreach ([[$this->company->id, -10000], [$second->id, -70000]] as [$companyId, $balance]) {
            ContractorOrganizationBalance::query()->create([
                'user_id' => $this->client->id,
                'company_id' => $companyId,
                'organization_id' => $this->organization->id,
                'current_balance' => $balance,
                'overdue_debt' => 0,
            ]);
        }

        $act = $this->open([
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertNull($act['discrepancy']);
    }

    /**
     * Плановые строки в акт не входят: акт отражает свершившиеся операции,
     * а не обещания заплатить.
     */
    #[Test]
    public function плановые_строки_в_акт_не_входят(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 10000,
            'date' => '2026-02-20',
        ]);

        $act = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act'];

        $this->assertCount(1, $act['rows']);
    }

    /**
     * Акт печатают и отправляют клиенту, поэтому выгрузка обязана нести и итоги,
     * и предупреждение о расхождении — на экране оно останется, а в файле нет.
     */
    #[Test]
    public function акт_выгружается_в_xlsx(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -10000, 'date' => '2026-02-10']);

        $response = $this->actingAs($this->actor)->get('/crm/finance/reconciliation/export?'.http_build_query([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheet',
            (string) $response->headers->get('content-type'),
        );
    }

    #[Test]
    public function выгрузка_чужого_клиента_недоступна(): void
    {
        $foreign = User::factory()->create([
            'personal_manager_id' => PersonalManager::create([
                'name' => 'Другой',
                'user_id' => User::factory()->create()->id,
            ])->id,
        ]);

        $this->actingAs($this->actor)
            ->get('/crm/finance/reconciliation/export?client_id='.$foreign->id)
            ->assertNotFound();
    }

    /**
     * Маршрут выгрузки не должен перехватываться страницей акта.
     */
    #[Test]
    public function маршрут_выгрузки_не_съеден_страницей(): void
    {
        $this->assertSame(
            'crm.finance.reconciliation.export',
            app('router')->getRoutes()->match(
                \Illuminate\Http\Request::create('/crm/finance/reconciliation/export', 'GET')
            )->getName(),
        );
    }

    #[Test]
    public function строка_акта_читается_без_документа_на_сайте(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_COMMISSION_SALE,
            'amount' => -15000,
            'date' => '2026-02-10',
            'document_type' => null,
            'document_id' => null,
            'document_kind' => 'commission_report',
            'document_number' => 'КМ-000123',
        ]);

        $row = $this->open([
            'client_id' => $this->client->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
        ])['act']['rows'][0];

        $this->assertSame('Отчёт комиссионера КМ-000123', $row['document']);
        $this->assertNull($row['document_url']);
        $this->assertSame('Продажа через комиссионера', $row['type_label']);
    }
}
