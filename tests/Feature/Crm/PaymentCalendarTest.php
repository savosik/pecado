<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Календарь поступлений: график из 1С и фактические платежи по дням.
 *
 * Раздел намеренно ничего не предсказывает, поэтому тест следит за обратным
 * тому, что проверяется в прогнозе: суммы должны совпадать с учётной системой
 * буква в букву, без поправок на дисциплину, а просрочка не должна попадать
 * в дни месяца.
 */
class PaymentCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-clients.view', 'crm-department.view', 'crm-clients-all.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->actor = User::factory()->create();
        $this->actor->givePermissionTo(['crm-clients.view', 'crm-department.view', 'crm-clients-all.view']);
        $this->card = PersonalManager::create(['name' => 'Сухов', 'user_id' => $this->actor->id]);
        $this->actor = $this->actor->fresh();
    }

    private function client(?PersonalManager $card = null): User
    {
        return User::factory()->create(['personal_manager_id' => ($card ?? $this->card)->id]);
    }

    private function plan(User $client, float $amount, string $date, array $attrs = []): SettlementEntry
    {
        return SettlementEntry::factory()->create($attrs + [
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $client->id,
            'document_uuid' => (string) Str::uuid(),
            'document_kind' => 'shipment',
            'date' => $date,
            'amount' => $amount,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
        ]);
    }

    private function payment(User $client, float $amount, string $date, array $attrs = []): SettlementEntry
    {
        return SettlementEntry::factory()->create($attrs + [
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'user_id' => $client->id,
            'amount' => $amount,
            'amount_rub' => $amount,
            'currency_code' => 'RUB',
            'date' => $date,
        ]);
    }

    private function props(string $query = ''): array
    {
        $query = $query === '' ? '?scope=department' : $query.'&scope=department';

        return $this->actingAs($this->actor)
            ->get('/crm/payments/calendar'.$query)
            ->viewData('page')['props'];
    }

    #[Test]
    public function день_показывает_обещанное_и_пришедшее_как_есть(): void
    {
        $client = $this->client();
        $day = Carbon::today()->startOfMonth()->addDays(9)->toDateString();

        $this->plan($client, 100000, $day);
        $this->payment($client, 40000, $day);
        $this->payment($client, 25000, $day);

        $props = $this->props();

        // Суммы совпадают с учётной системой: никаких поправок на дисциплину,
        // в отличие от «Плана поступлений».
        $this->assertEqualsWithDelta(100000.0, $props['days'][$day]['plan'], 0.01);
        $this->assertSame(1, $props['days'][$day]['plan_count']);
        $this->assertEqualsWithDelta(65000.0, $props['days'][$day]['fact'], 0.01);
        $this->assertSame(2, $props['days'][$day]['fact_count']);

        $this->assertEqualsWithDelta(100000.0, $props['summary']['plan'], 0.01);
        $this->assertEqualsWithDelta(65000.0, $props['summary']['fact'], 0.01);
    }

    /**
     * Календарь показывает график целиком, а не остаток долга.
     *
     * Пока в план шли только непогашенные строки, «обещано» оказывалось
     * остатком: оплаченный вовремя месяц выглядел пустым, а исполнение
     * графика — тысячами процентов.
     */
    #[Test]
    public function график_показан_целиком_а_закрытая_часть_отдельно(): void
    {
        $client = $this->client();
        $day = Carbon::today()->startOfMonth()->addDays(7)->toDateString();

        $this->plan($client, 100000, $day);
        $paid = $this->plan($client, 200000, $day);
        $paid->update(['settled_amount' => 200000]);

        $props = $this->props();

        $this->assertEqualsWithDelta(300000.0, $props['days'][$day]['plan'], 0.01);
        $this->assertEqualsWithDelta(200000.0, $props['days'][$day]['settled'], 0.01);
        $this->assertSame(2, $props['days'][$day]['plan_count']);

        $this->assertEqualsWithDelta(300000.0, $props['summary']['plan'], 0.01);
        $this->assertEqualsWithDelta(200000.0, $props['summary']['settled'], 0.01);

        // Закрытая строка не считается просрочкой, даже если её срок прошёл.
        $closed = $this->plan($client, 500000, Carbon::today()->startOfMonth()->subDays(20)->toDateString());
        $closed->update(['settled_amount' => 500000]);

        $this->assertEqualsWithDelta(0.0, $this->props()['overdueThread']['total'], 0.01);
    }

    /**
     * Просрочка в клетки не попадает: её срок в прошлом, и рисовать её
     * сегодняшним днём значило бы выдумать дату.
     */
    #[Test]
    public function просрочка_идёт_навесом_а_не_днями_месяца(): void
    {
        $client = $this->client();
        $monthStart = Carbon::today()->startOfMonth();

        $this->plan($client, 30000, $monthStart->copy()->addDays(15)->toDateString());
        $this->plan($client, 500000, $monthStart->copy()->subDays(10)->toDateString());
        $this->plan($client, 200000, $monthStart->copy()->subDays(120)->toDateString());

        $props = $this->props();

        // В месяце — только то, чей срок в этом месяце.
        $this->assertEqualsWithDelta(30000.0, $props['summary']['plan'], 0.01);

        // Просроченное — отдельным навесом, с разбивкой по возрасту.
        $this->assertEqualsWithDelta(700000.0, $props['overdueThread']['total'], 0.01);
        $this->assertSame(2, $props['overdueThread']['lines']);
        $this->assertGreaterThanOrEqual(120, $props['overdueThread']['oldest_days']);

        $buckets = collect($props['overdueThread']['buckets'])->keyBy('key');
        $this->assertEqualsWithDelta(200000.0, $buckets['old']['amount'], 0.01);
    }

    #[Test]
    public function разрез_меняет_группировку_но_не_итоги(): void
    {
        $organization = Organization::factory()->create(['name' => 'ООО Пекадо']);
        $first = $this->client();
        $second = $this->client();
        $company = Company::factory()->create(['user_id' => $first->id, 'name' => 'ООО Ромашка']);

        $day = Carbon::today()->startOfMonth()->addDays(4)->toDateString();

        $this->plan($first, 60000, $day, ['organization_id' => $organization->id, 'company_id' => $company->id]);
        $this->plan($second, 40000, $day, ['organization_id' => $organization->id]);
        $this->payment($first, 15000, $day, ['organization_id' => $organization->id, 'company_id' => $company->id]);

        foreach (['partner', 'company', 'organization', 'manager'] as $axis) {
            $props = $this->props('?axis='.$axis);

            $this->assertSame($axis, $props['axis'], $axis);
            $this->assertEqualsWithDelta(
                100000.0,
                array_sum(array_column($props['breakdown'], 'plan')),
                0.01,
                $axis,
            );
            $this->assertEqualsWithDelta(
                15000.0,
                array_sum(array_column($props['breakdown'], 'fact')),
                0.01,
                $axis,
            );
        }

        // По партнёрам строк две, по нашей организации — одна.
        $this->assertCount(2, $this->props('?axis=partner')['breakdown']);
        $this->assertCount(1, $this->props('?axis=organization')['breakdown']);

        // Разрез по нашей организации не должен дробиться по менеджерам:
        // за одним юрлицом стоит весь отдел, и группировка по человеку
        // разваливала бы строку на части, а сумму — на слагаемые.
        $second = PersonalManager::create(['name' => 'Курочкина']);
        $third = User::factory()->create(['personal_manager_id' => $second->id]);
        $this->plan($third, 25000, $day, ['organization_id' => $organization->id]);

        $byOrganization = $this->props('?axis=organization')['breakdown'];
        $this->assertCount(1, $byOrganization);
        $this->assertEqualsWithDelta(125000.0, $byOrganization[0]['plan'], 0.01);
    }

    #[Test]
    public function отбор_по_организации_сужает_календарь(): void
    {
        $ours = Organization::factory()->create(['name' => 'ООО Пекадо']);
        $theirs = Organization::factory()->create(['name' => 'ИП Елисеев']);
        $client = $this->client();
        $day = Carbon::today()->startOfMonth()->addDays(6)->toDateString();

        $this->plan($client, 70000, $day, ['organization_id' => $ours->id]);
        $this->plan($client, 30000, $day, ['organization_id' => $theirs->id]);
        $this->payment($client, 50000, $day, ['organization_id' => $ours->id]);

        $props = $this->props('?organization_ids[]='.$theirs->id);

        $this->assertEqualsWithDelta(30000.0, $props['summary']['plan'], 0.01);
        $this->assertEqualsWithDelta(0.0, $props['summary']['fact'], 0.01);
    }

    /** План по заказу — намерение, а не обязательство: в календарь не идёт. */
    #[Test]
    public function план_по_заказу_в_календарь_не_попадает(): void
    {
        $client = $this->client();
        $day = Carbon::today()->startOfMonth()->addDays(3)->toDateString();

        $this->plan($client, 10000, $day);
        $this->plan($client, 90000, $day, ['document_kind' => 'order']);

        $this->assertEqualsWithDelta(10000.0, $this->props()['summary']['plan'], 0.01);
    }

    #[Test]
    public function листание_месяцев_и_мусор_в_адресе(): void
    {
        $client = $this->client();
        $previous = Carbon::today()->startOfMonth()->subMonth();

        $this->plan($client, 55000, $previous->copy()->addDays(5)->toDateString());

        $props = $this->props('?month='.$previous->format('Y-m'));
        $this->assertSame($previous->format('Y-m'), $props['month']);
        $this->assertEqualsWithDelta(55000.0, $props['summary']['plan'], 0.01);

        // Мусорный разрез и месяц не роняют экран.
        $garbage = $this->props('?axis=выдумка&month=неделя');
        $this->assertSame('partner', $garbage['axis']);
        $this->assertSame(Carbon::today()->format('Y-m'), $garbage['month']);
    }
}
