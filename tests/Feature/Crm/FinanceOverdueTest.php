<?php

namespace Tests\Feature\Crm;

use App\Models\Currency;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Раздел «Просрочка»: отборы (корзина задержки, порог остатка), разрезы и итоги.
 *
 * Главное свойство раздела — одна и та же цифра на всех его поверхностях:
 * итог в шапке, сумма корзин и любой разрез обязаны сходиться, иначе экран
 * читается как ошибка расчёта.
 */
class FinanceOverdueTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-finance.view', 'crm-department.view', 'crm-clients-all.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->actor = User::factory()->create();
        $this->actor->givePermissionTo(['crm-finance.view', 'crm-department.view', 'crm-clients-all.view']);
        $this->card = PersonalManager::create(['name' => 'Сухов', 'user_id' => $this->actor->id]);
        $this->actor = $this->actor->fresh();
    }

    private function makeClient(?PersonalManager $card = null): User
    {
        return User::factory()->create(['personal_manager_id' => ($card ?? $this->card)->id]);
    }

    /** Просроченная строка графика: сумма, срок и организация. */
    private function overdueLine(User $client, float $amount, int $daysAgo, array $attrs = []): SettlementEntry
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today()->subDays($daysAgo + 5),
            'erp_created_at' => Carbon::today()->subDays($daysAgo + 5),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
            'paid_amount' => 0,
        ]);

        return SettlementEntry::factory()->create($attrs + [
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'amount' => $amount,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
        ]);
    }

    /**
     * Запрос идёт в отдельческом скоупе: раздел смотрит РОП, а личный скоуп
     * оставил бы за бортом партнёров других менеджеров.
     */
    private function props(string $query = ''): array
    {
        $query = $query === '' ? '?scope=department' : $query.'&scope=department';

        return $this->actingAs($this->actor)
            ->get('/crm/finance/overdue'.$query)
            ->viewData('page')['props'];
    }

    #[Test]
    public function корзина_задержки_отбирает_строки_и_корзины_в_сумме_дают_итог(): void
    {
        $client = $this->makeClient();

        $this->overdueLine($client, 1000, 3);    // 1–7 дней
        $this->overdueLine($client, 2000, 20);   // 15–30 дней
        $this->overdueLine($client, 4000, 200);  // более 60 дней

        $all = $this->props();
        $this->assertEqualsWithDelta(7000.0, $all['totals']['amount'], 0.01);

        // Сумма корзин равна итогу: границы диапазонов не перекрываются и не оставляют дыр.
        $this->assertEqualsWithDelta(
            7000.0,
            array_sum(array_column($all['aging']['buckets'], 'amount')),
            0.01,
        );

        $narrow = $this->props('?overdue_buckets[]=15_30');
        $this->assertEqualsWithDelta(2000.0, $narrow['totals']['amount'], 0.01);
        $this->assertSame(1, $narrow['totals']['lines']);

        // Плитки при выбранной корзине показывают всю картину — иначе переключиться
        // на соседнюю корзину было бы нечем.
        $this->assertEqualsWithDelta(
            7000.0,
            array_sum(array_column($narrow['aging']['buckets'], 'amount')),
            0.01,
        );
    }

    #[Test]
    public function порог_остатка_отсекает_копеечные_хвосты(): void
    {
        $client = $this->makeClient();

        $this->overdueLine($client, 5000, 10);
        $this->overdueLine($client, 0.04, 10);

        $this->assertSame(2, $this->props()['totals']['lines']);

        $filtered = $this->props('?min_amount=1');
        $this->assertSame(1, $filtered['totals']['lines']);
        $this->assertEqualsWithDelta(5000.0, $filtered['totals']['amount'], 0.01);

        // Порог действует и на плитки: разные цифры на одном экране читались бы
        // как ошибка расчёта.
        $this->assertEqualsWithDelta(
            5000.0,
            array_sum(array_column($filtered['aging']['buckets'], 'amount')),
            0.01,
        );
    }

    #[Test]
    public function разрез_не_меняет_итог_и_подписывает_менеджера(): void
    {
        $second = PersonalManager::create(['name' => 'Курочкина']);
        $organization = Organization::factory()->create(['name' => 'ООО Пекадо']);

        $mine = $this->makeClient();
        $theirs = $this->makeClient($second);

        $this->overdueLine($mine, 3000, 10, ['organization_id' => $organization->id]);
        $this->overdueLine($theirs, 1000, 40, ['organization_id' => $organization->id]);

        foreach (['partner', 'manager', 'organization', 'company'] as $axis) {
            $props = $this->props('?group='.$axis);

            $this->assertSame($axis, $props['group'], $axis);
            $this->assertEqualsWithDelta(
                4000.0,
                array_sum(array_column($props['groupRows'], 'unpaid')),
                0.01,
                $axis,
            );
        }

        // У партнёра менеджер один — он и подписан.
        $byPartner = collect($this->props('?group=partner')['groupRows'])->firstWhere('unpaid', 3000.0);
        $this->assertSame('Сухов', $byPartner['manager_name']);
        $this->assertSame(1, $byPartner['clients_count']);

        // За нашей организацией стоят двое: имя одного из них было бы враньём.
        $byOrganization = $this->props('?group=organization')['groupRows'][0];
        $this->assertSame('2 менеджера', $byOrganization['manager_name']);
        $this->assertSame(2, $byOrganization['clients_count']);
        $this->assertSame(2, $byOrganization['lines_count']);
        // Самая давняя строка — 40 дней назад, а не средняя по группе.
        $this->assertSame(40, $byOrganization['days_overdue']);
    }

    #[Test]
    public function отбор_и_разрез_живут_независимо(): void
    {
        $client = $this->makeClient();
        $this->overdueLine($client, 1000, 3);
        $this->overdueLine($client, 9000, 100);

        $props = $this->props('?group=partner&overdue_buckets[]=60_plus');

        $this->assertSame('partner', $props['group']);
        $this->assertSame(['60_plus'], $props['filters']['overdue_buckets']);
        $this->assertEqualsWithDelta(9000.0, $props['groupRows'][0]['unpaid'], 0.01);
        $this->assertEqualsWithDelta(9000.0, $props['totals']['amount'], 0.01);
    }

    #[Test]
    public function сортировка_по_сумме_ставит_крупный_долг_наверх(): void
    {
        $client = $this->makeClient();
        $this->overdueLine($client, 500, 100);   // давняя, но мелкая
        $this->overdueLine($client, 9000, 2);    // свежая и крупная

        $bySum = $this->props('?sort=unpaid&direction=desc');
        $this->assertSame('unpaid', $bySum['sort']['column']);
        $this->assertEqualsWithDelta(9000.0, $bySum['rows']['data'][0]['unpaid_rub'], 0.01);

        // По умолчанию — по сроку: сверху самая давняя.
        $byDate = $this->props();
        $this->assertEqualsWithDelta(500.0, $byDate['rows']['data'][0]['unpaid_rub'], 0.01);
    }

    #[Test]
    public function план_по_заказу_в_просрочку_не_попадает(): void
    {
        $client = $this->makeClient();
        $this->overdueLine($client, 1000, 10);
        $this->overdueLine($client, 5000, 10, ['document_kind' => 'order']);

        $props = $this->props();

        $this->assertSame(1, $props['totals']['lines']);
        $this->assertEqualsWithDelta(1000.0, $props['totals']['amount'], 0.01);
    }

    #[Test]
    public function мусор_в_адресе_не_роняет_отчёт(): void
    {
        $client = $this->makeClient();
        $this->overdueLine($client, 1000, 10);

        $props = $this->props('?group=выдумка&overdue_buckets[]=вчера&min_amount=-5&sort=xxx');

        $this->assertSame('', $props['group']);
        $this->assertSame([], $props['filters']['overdue_buckets']);
        $this->assertSame('due_date', $props['sort']['column']);
        $this->assertEqualsWithDelta(1000.0, $props['totals']['amount'], 0.01);
    }
}
