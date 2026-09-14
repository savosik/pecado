<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\CrmCall;
use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\Motivation\MotivationPoolPackage;
use App\Models\Motivation\MotivationPoolPackageItem;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Motivation\PartnerAttributionResolver;
use App\Services\Motivation\PoolPackageService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Пул и раздача (карточка mot-35): выдача пакета, сроки, возврат, кран.
 */
class MotivationPoolPackagesTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $profile;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);
    }

    /**
     * @return list<User>
     */
    private function poolPartners(int $count): array
    {
        return User::factory()->count($count)->create(['personal_manager_id' => null])->all();
    }

    #[Test]
    #[TestDox('Выдача пакета: карточка переходит сразу, показатели — со следующего месяца, сроки по приказу')]
    public function issuing_a_package_assigns_partners_with_deferred_attribution(): void
    {
        [$a, $b] = $this->poolPartners(2);

        $package = app(PoolPackageService::class)->issue($this->profile->id, [$a->id, $b->id], $this->head, 'Первый пакет');

        $this->assertSame(2, $package->items()->count());
        $this->assertSame($this->profile->id, (int) $a->fresh()->personal_manager_id, 'Карточка — работнику сразу');

        $assignment = MotivationPartnerAssignment::query()->where('user_id', $a->id)->whereNull('ends_on')->sole();
        $this->assertSame(MotivationPartnerAssignment::REASON_POOL_PACKAGE, $assignment->reason);
        $this->assertSame(CarbonImmutable::today()->addMonthNoOverflow()->startOfMonth()->toDateString(), $assignment->starts_on->toDateString(), 'Атрибуция — с первого числа следующего периода');

        $this->assertTrue($package->contact_due_on->greaterThan(CarbonImmutable::today()));
        $this->assertSame(CarbonImmutable::today()->addDays(90)->toDateString(), $package->shipment_due_on->toDateString());

        $this->assertNotContains($a->id, app(PartnerAttributionResolver::class)->poolPartnerIds(CarbonImmutable::today()->addMonths(2)), 'Со следующего периода партнёр вне Пула');
    }

    #[Test]
    #[TestDox('Предельный размер пакета и партнёр не из Пула отклоняются')]
    public function size_and_membership_are_enforced(): void
    {
        $partners = $this->poolPartners(21);
        $service = app(PoolPackageService::class);

        try {
            $service->issue($this->profile->id, array_map(fn (User $u): int => $u->id, $partners), $this->head);
            $this->fail('Пакет больше предельного размера');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Предельный размер пакета — 20', $e->getMessage());
        }

        $taken = User::factory()->create(['personal_manager_id' => PersonalManager::factory()->create()->id]);

        $this->expectException(\InvalidArgumentException::class);
        $service->issue($this->profile->id, [$taken->id], $this->head);
    }

    #[Test]
    #[TestDox('Обновление пакета видит контакт и отгрузку; просроченные возвращаются в Пул')]
    public function refresh_and_return(): void
    {
        [$contacted, $silent] = $this->poolPartners(2);
        $service = app(PoolPackageService::class);
        $package = $service->issue($this->profile->id, [$contacted->id, $silent->id], $this->head);

        CrmCall::query()->create([
            'user_id' => $this->head->id,
            'client_user_id' => $contacted->id,
            'direction' => 'outgoing',
            'result' => 'talked',
            'started_at' => now(),
            'provider' => CrmCall::PROVIDER_MANUAL,
        ]);

        $service->refresh($package);

        $items = $package->items()->get()->keyBy('user_id');
        $this->assertNotNull($items[$contacted->id]->first_contact_at);
        $this->assertNull($items[$silent->id]->first_contact_at);

        $returned = $service->returnToPool($package, [$items[$silent->id]->id], $this->head);

        $this->assertSame(1, $returned);
        $this->assertSame(MotivationPoolPackageItem::OUTCOME_RETURNED, $items[$silent->id]->fresh()->outcome);
        $this->assertNull($silent->fresh()->personal_manager_id, 'Карточка снова ничья');
        $this->assertSame(
            MotivationPartnerAssignment::REASON_RETURN_TO_POOL,
            MotivationPartnerAssignment::query()->where('user_id', $silent->id)->whereNull('ends_on')->sole()->reason,
        );
    }

    #[Test]
    #[TestDox('Кандидат показывает закупки у конкурента и чем с ним связаться; берущие у конкурента — выше среди холодных')]
    public function candidates_show_competitor_purchases_and_contacts(): void
    {
        [$plain, $buyer] = $this->poolPartners(2);
        $plain->forceFill(['phone' => null, 'email' => 'plain@example.com'])->save();
        $buyer->forceFill(['phone' => '+7 900 000-00-00'])->save();
        \App\Models\CrmCompetitorPurchase::query()->create([
            'user_id' => $buyer->id, 'source' => 'andrey', 'competitor_partner' => 'Покупатель ИП',
            'amount' => 1_250_000, 'documents' => 12, 'first_purchase_on' => '2025-09-01', 'last_purchase_on' => '2026-07-15',
            'period_from' => '2025-08-01', 'period_to' => '2026-07-31', 'imported_at' => now(),
        ]);
        \App\Models\Contact::query()->create(['full_name' => 'Иванова Мария', 'client_user_id' => $buyer->id, 'phone' => '+7 900 111-11-11']);

        $this->actingAs($this->head)
            ->get('/crm/motivation/pool/admin?history=0')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('candidates.rows.data', 2)
                ->where('candidates.rows.data.0.id', $buyer->id)
                ->where('candidates.rows.data.0.competitor.amount', 1_250_000)
                ->where('candidates.rows.data.0.competitor.documents', 12)
                ->where('candidates.rows.data.0.contacts.phone', true)
                ->where('candidates.rows.data.0.contacts.persons', 1)
                ->where('candidates.rows.data.1.competitor', null)
                ->where('candidates.rows.data.1.contacts.phone', false)
                ->where('candidates.rows.data.1.contacts.email', true));
    }

    #[Test]
    #[TestDox('Импорт инсайда сопоставляет партнёров по наименованию и заменяет прежний срез источника')]
    public function competitor_import_matches_by_name(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => null, 'name' => 'Магазин Ромашка', 'erp_name' => 'ИП Петрова Анна Ивановна']);
        $path = tempnam(sys_get_temp_dir(), 'andrey');
        file_put_contents($path, implode("\n", [
            'partner;sum;qty;docs;last_date;first_date;contractors',
            'Петрова Анна Ивановна ИП, г.Тверь;1500000.50;300;7;2026-07-20;2025-10-02;ПЕТРОВА АННА ИВАНОВНА',
            'Неизвестный Партнёр ООО;99;1;1;2026-01-01;2026-01-01;',
        ]));

        $this->artisan('crm:import-competitor-purchases', ['file' => $path, '--unmatched' => 0])
            ->expectsOutputToContain('Сопоставлено партнёров: 1')
            ->assertSuccessful();

        $row = \App\Models\CrmCompetitorPurchase::query()->where('user_id', $partner->id)->sole();
        $this->assertSame(1_500_000.5, (float) $row->amount);
        $this->assertSame(7, $row->documents);
        $this->assertSame('2026-07-20', $row->last_purchase_on?->toDateString());
        $this->assertSame('2025-10-02', $row->period_from->toDateString(), 'Окно без --from берётся из данных: с самой ранней закупки');

        // Повторный импорт того же источника не плодит строк.
        $this->artisan('crm:import-competitor-purchases', ['file' => $path, '--unmatched' => 0])->assertSuccessful();
        $this->assertSame(1, \App\Models\CrmCompetitorPurchase::query()->count());
        unlink($path);
    }

    #[Test]
    #[TestDox('Ушедшие партнёры (банкрот, закрылся) в кандидаты пакета не попадают, но видны по чипу')]
    public function lost_partners_are_kept_out_of_packages(): void
    {
        [$alive, $bankrupt] = $this->poolPartners(2);
        \App\Models\CrmClientProfile::create(['user_id' => $bankrupt->id, 'lifecycle_status' => \App\Enums\Crm\ClientLifecycleStatus::BANKRUPT]);

        $this->actingAs($this->head)
            ->get('/crm/motivation/pool/admin?history=0')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('candidates.summary.total', 1)
                ->where('candidates.summary.lost', 1)
                ->has('candidates.rows.data', 1)
                ->where('candidates.rows.data.0.id', $alive->id)
                ->where('candidates.rows.data.0.stage', 'active'));

        $this->actingAs($this->head)
            ->get('/crm/motivation/pool/admin?lost=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('candidates.rows.data', 1)
                ->where('candidates.rows.data.0.id', $bankrupt->id)
                ->where('candidates.rows.data.0.stage_label', 'Банкрот')
                ->where('candidates.rows.data.0.lost', true));
    }

    #[Test]
    #[TestDox('Страница руководителя показывает состояние, кран и кандидатов; выдача через API')]
    public function page_and_issue_endpoint(): void
    {
        [$a] = $this->poolPartners(3);

        $this->actingAs($this->head)
            ->get('/crm/motivation/pool/admin?history=0')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/PoolAdmin')
                ->where('state.total', 3)
                ->where('state.with_history', 0)
                ->has('taps', 1)
                ->where('taps.0.blocked', false)
                ->has('candidates.rows.data', 3)
                ->where('can_edit', true));

        $this->actingAs($this->head)
            ->postJson('/crm/motivation/pool/packages', ['manager_id' => $this->profile->id, 'partner_ids' => [$a->id]])
            ->assertOk()
            ->assertJsonPath('packages.0.count', 1)
            ->assertJsonPath('state.issued_this_quarter', 1);

        $this->assertSame(1, MotivationPoolPackage::query()->count());

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/pool/admin')->assertForbidden();
    }
}
