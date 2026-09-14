<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\TaskStatus;
use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\TaxRegimeFreshness;
use App\Enums\Crm\TaxRegimeShift;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Crm\TaxRegime\ContractorTaxRegimeService;
use App\Services\Crm\TaxRegime\TaxRegimeQuery;
use App\Services\Crm\TaxRegime\TaxRegimeTaskPlanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Налоговый режим юрлиц партнёров — сбор ответов для оценки рисков перехода на НДС.
 *
 * Инварианты: ответ пишется с автором и журналом; устаревает по давности и
 * безусловно 1 января года плана; SQL-отборы совпадают с PHP-правилом; задача
 * менеджеру ставится по покупающим юрлицам без ответа, не дублируется и
 * закрывается сама, когда ответы собраны.
 */
class ContractorTaxRegimeTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $card;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        // Середина года: сроки «90 дней» не должны пересекать 1 января,
        // иначе тесты давности сломаются в зависимости от даты прогона.
        $this->travelTo(now()->setDate(now()->year, 3, 2)->setTime(12, 0));

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->partner = User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    private function contractor(?User $partner = null, bool $buying = true, string $name = 'ООО Ромашка'): Company
    {
        $company = Company::withoutEvents(fn () => Company::factory()->create([
            'user_id' => ($partner ?? $this->partner)->id,
            'name' => $name,
        ]));

        if ($buying) {
            Shipment::factory()->create([
                'user_id' => $company->user_id,
                'company_id' => $company->id,
                'erp_created_at' => now()->subDays(20),
            ]);
        }

        return $company;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function answer(Company $company, array $attributes = []): CrmContractorTaxRegime
    {
        return CrmContractorTaxRegime::create($attributes + [
            'company_id' => $company->id,
            'current_regime' => TaxRegime::USN_EXEMPT,
            'planned_regime' => TaxRegime::USN_EXEMPT,
            'planned_year' => CrmContractorTaxRegime::targetYear(),
            'confirmed_at' => now(),
            'confirmed_by' => $this->manager->id,
        ]);
    }

    private function save(Company $company, array $data, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->manager)
            ->put(route('crm.contractors.tax-regime.update', $company), $data);
    }

    #[Test]
    #[TestDox('Менеджер сохраняет режим: план на следующий год, автор и запись в журнале')]
    public function manager_saves_regime_with_author_and_history(): void
    {
        $company = $this->contractor();

        $this->save($company, [
            'current_regime' => 'usn_exempt',
            'planned_regime' => 'usn_vat_5',
            'note' => '  со слов бухгалтера  ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $regime = $company->taxRegime()->firstOrFail();

        $this->assertSame(TaxRegime::USN_EXEMPT, $regime->current_regime);
        $this->assertSame(TaxRegime::USN_VAT_5, $regime->planned_regime);
        $this->assertSame(now()->year + 1, $regime->planned_year);
        $this->assertSame('со слов бухгалтера', $regime->note);
        $this->assertSame($this->manager->id, $regime->confirmed_by);
        $this->assertSame(TaxRegimeFreshness::FRESH, CrmContractorTaxRegime::freshnessOf($regime));
        $this->assertDatabaseHas('crm_contractor_tax_regime_history', [
            'company_id' => $company->id,
            'action' => 'saved',
            'current_regime' => 'usn_exempt',
            'planned_regime' => 'usn_vat_5',
            'user_id' => $this->manager->id,
        ]);
    }

    #[Test]
    #[TestDox('«Ещё не решил» допустимо только для плана, текущий режим обязателен')]
    public function undecided_is_allowed_only_for_plan(): void
    {
        $company = $this->contractor();

        $this->save($company, ['current_regime' => 'undecided', 'planned_regime' => 'osno'])
            ->assertSessionHasErrors('current_regime');
        $this->save($company, ['planned_regime' => 'osno'])
            ->assertSessionHasErrors('current_regime');

        $this->assertDatabaseCount('crm_contractor_tax_regimes', 0);

        $this->save($company, ['current_regime' => 'osno', 'planned_regime' => 'undecided'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    #[TestDox('Юрлицо чужого партнёра — 404, сотрудник без права на анкету — 403')]
    public function access_is_limited(): void
    {
        $foreign = $this->contractor(User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]));

        $this->save($foreign, ['current_regime' => 'osno', 'planned_regime' => 'osno'])->assertNotFound();

        $role = Role::create(['name' => 'crm-viewer']);
        $role->givePermissionTo(Permission::findByName('crm-dashboard.view'));
        $viewer = User::factory()->create();
        $viewer->assignRole($role);

        $this->save($this->contractor(), ['current_regime' => 'osno', 'planned_regime' => 'osno'], $viewer)
            ->assertForbidden();

        $this->assertDatabaseCount('crm_contractor_tax_regimes', 0);
    }

    #[Test]
    #[TestDox('Ответ устаревает через 90 дней, «ещё не решил» — через 30; «Всё так же» продлевает')]
    public function answer_expires_by_age_and_confirm_extends_it(): void
    {
        $decided = $this->answer($this->contractor());
        $undecided = $this->answer($this->contractor(name: 'ИП Иванов'), ['planned_regime' => TaxRegime::UNDECIDED]);

        $this->travel(29)->days();
        $this->assertSame(TaxRegimeFreshness::FRESH, CrmContractorTaxRegime::freshnessOf($undecided->refresh()));

        $this->travel(2)->days();
        $this->assertSame(TaxRegimeFreshness::OUTDATED, CrmContractorTaxRegime::freshnessOf($undecided->refresh()));
        $this->assertSame(TaxRegimeFreshness::FRESH, CrmContractorTaxRegime::freshnessOf($decided->refresh()));

        $this->travel(60)->days();
        $this->assertSame(TaxRegimeFreshness::OUTDATED, CrmContractorTaxRegime::freshnessOf($decided->refresh()));

        $this->actingAs($this->manager)
            ->post(route('crm.contractors.tax-regime.confirm', $decided->company_id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(TaxRegimeFreshness::FRESH, CrmContractorTaxRegime::freshnessOf($decided->refresh()));
        $this->assertDatabaseHas('crm_contractor_tax_regime_history', [
            'company_id' => $decided->company_id,
            'action' => 'confirmed',
        ]);
    }

    #[Test]
    #[TestDox('Подтвердить пустой ответ нельзя')]
    public function confirm_without_answer_is_rejected(): void
    {
        $company = $this->contractor();

        $this->actingAs($this->manager)
            ->post(route('crm.contractors.tax-regime.confirm', $company))
            ->assertSessionHasErrors('tax_regime');

        $this->assertDatabaseCount('crm_contractor_tax_regime_history', 0);
    }

    #[Test]
    #[TestDox('1 января план становится текущим режимом: ответ устарел, форма подставляет план, подтвердить нельзя')]
    public function new_year_turns_plan_into_current_regime(): void
    {
        $company = $this->contractor();
        $this->save($company, ['current_regime' => 'usn_exempt', 'planned_regime' => 'osno'])->assertSessionHasNoErrors();

        $this->travelTo(now()->addYear()->startOfYear()->setTime(9, 0));

        $regime = $company->taxRegime()->firstOrFail();
        $payload = app(ContractorTaxRegimeService::class)->payload($regime);

        $this->assertSame('outdated', $payload['freshness']['value']);
        $this->assertFalse($payload['can_confirm']);
        $this->assertSame('osno', $payload['form']['current_regime']);
        $this->assertNull($payload['form']['planned_regime']);
        $this->assertNull($payload['shift'], 'Прошлогодний план — уже не риск, а текущий режим');

        $this->actingAs($this->manager)
            ->post(route('crm.contractors.tax-regime.confirm', $company))
            ->assertSessionHasErrors('tax_regime');
    }

    #[Test]
    #[TestDox('SQL-отбор по состоянию ответа совпадает с правилом карточки')]
    public function sql_freshness_matches_php_rule(): void
    {
        $cases = [
            'missing' => null,
            'partial' => ['planned_regime' => null],
            'fresh' => [],
            'old' => ['confirmed_at' => now()->subDays(91)],
            'undecided_fresh' => ['planned_regime' => TaxRegime::UNDECIDED, 'confirmed_at' => now()->subDays(10)],
            'undecided_old' => ['planned_regime' => TaxRegime::UNDECIDED, 'confirmed_at' => now()->subDays(31)],
            'last_year_plan' => ['planned_year' => now()->year],
            'never_confirmed' => ['confirmed_at' => null],
        ];

        $expected = [];

        foreach ($cases as $name => $attributes) {
            $company = $this->contractor(buying: false, name: $name);
            $regime = $attributes === null ? null : $this->answer($company, $attributes);
            $expected[CrmContractorTaxRegime::freshnessOf($regime)->value][] = $company->id;
        }

        $query = app(TaxRegimeQuery::class);

        foreach (TaxRegimeFreshness::cases() as $state) {
            $ids = $query->whereFreshness(Company::query(), $state)->pluck('id')->sort()->values()->all();

            $this->assertSame($expected[$state->value] ?? [], $ids, "Состояние {$state->value}");
        }

        $this->assertSame(
            array_merge($expected['missing'], $expected['outdated']),
            $query->whereNeedsAnswer(Company::query())->pluck('id')->sort()->values()->all(),
        );
    }

    #[Test]
    #[TestDox('SQL-выражение смены режима совпадает с PHP на всех сочетаниях режимов')]
    public function sql_shift_matches_php_for_every_pair(): void
    {
        $expected = [];

        foreach (TaxRegime::cases() as $current) {
            foreach (TaxRegime::cases() as $planned) {
                $company = $this->contractor(buying: false, name: "{$current->value} → {$planned->value}");
                $this->answer($company, ['current_regime' => $current, 'planned_regime' => $planned]);
                $expected[TaxRegimeShift::between($current, $planned)->value][] = $company->id;
            }
        }

        foreach (TaxRegimeShift::cases() as $shift) {
            $ids = app(TaxRegimeQuery::class)
                ->whereShift(Company::query(), [$shift])
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($expected[$shift->value] ?? [], $ids, "Смена {$shift->value}");
        }

        $this->assertSame(TaxRegimeShift::TO_DEDUCTIBLE, TaxRegimeShift::between(TaxRegime::USN_EXEMPT, TaxRegime::USN_VAT_22));
        $this->assertSame(TaxRegimeShift::TO_REDUCED, TaxRegimeShift::between(TaxRegime::PATENT, TaxRegime::USN_VAT_5));
        $this->assertSame(TaxRegimeShift::OTHER, TaxRegimeShift::between(TaxRegime::OSNO, TaxRegime::USN_EXEMPT));
        $this->assertSame(TaxRegimeShift::SAME, TaxRegimeShift::between(TaxRegime::USN_VAT_5, TaxRegime::USN_VAT_7));
    }

    #[Test]
    #[TestDox('Список партнёров отбирает по налоговому режиму юрлиц')]
    public function partner_list_filters_by_tax_regime(): void
    {
        $needsAnswer = $this->partner;
        $this->contractor($needsAnswer);

        $toVat = User::factory()->create(['personal_manager_id' => $this->card->id]);
        $this->answer($this->contractor($toVat), [
            'current_regime' => TaxRegime::USN_EXEMPT,
            'planned_regime' => TaxRegime::OSNO,
        ]);

        $undecided = User::factory()->create(['personal_manager_id' => $this->card->id]);
        $this->answer($this->contractor($undecided), ['planned_regime' => TaxRegime::UNDECIDED]);

        $expectations = [
            'attention' => [$needsAnswer->id],
            'to_vat' => [$toVat->id],
            'undecided' => [$undecided->id],
        ];

        foreach ($expectations as $state => $ids) {
            $this->actingAs($this->manager)
                ->get(route('crm.clients.index', ['tax_regime' => $state]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('filters.tax_regime', $state)
                    ->where('clients.data', fn ($rows) => collect($rows)->pluck('id')->all() === $ids));
        }
    }

    #[Test]
    #[TestDox('Задача ставится одна на партнёра, только по покупающим юрлицам, и не дублируется')]
    public function weekly_command_creates_one_task_per_partner(): void
    {
        $first = $this->contractor(name: 'ООО Ромашка');
        $this->contractor(name: 'ИП Ромашкин');
        $this->contractor(buying: false, name: 'ООО Спящее');

        $settled = User::factory()->create(['personal_manager_id' => $this->card->id]);
        $this->answer($this->contractor($settled));

        $this->artisan('crm:tax-regime-tasks')->assertSuccessful();
        $this->artisan('crm:tax-regime-tasks')->assertSuccessful();

        $tasks = CrmTask::query()->withAnyTags([TaxRegimeTaskPlanner::tag()], CrmTask::TAG_TYPE)->get();

        $this->assertCount(1, $tasks);
        $task = $tasks->first();
        $this->assertSame($this->manager->id, $task->assignee_id);
        $this->assertSame($this->partner->id, $task->client_user_id);
        $this->assertStringContainsString('ООО Ромашка', $task->description);
        $this->assertStringContainsString('ИП Ромашкин', $task->description);
        $this->assertStringNotContainsString('ООО Спящее', $task->description);
        $this->assertStringContainsString((string) (now()->year + 1), $task->title);
        $this->assertNotNull($first->id);
    }

    #[Test]
    #[TestDox('Задача закрывается сама, когда ответы по всем покупающим юрлицам собраны')]
    public function task_closes_itself_when_answers_are_collected(): void
    {
        $first = $this->contractor(name: 'ООО Ромашка');
        $second = $this->contractor(name: 'ИП Ромашкин');

        $this->artisan('crm:tax-regime-tasks')->assertSuccessful();
        $task = CrmTask::query()->withAnyTags([TaxRegimeTaskPlanner::tag()], CrmTask::TAG_TYPE)->firstOrFail();

        $this->save($first, ['current_regime' => 'osno', 'planned_regime' => 'osno'])->assertSessionHasNoErrors();
        $this->assertNotSame(TaskStatus::DONE, $task->refresh()->status, 'Второе юрлицо ещё без ответа');

        $this->save($second, ['current_regime' => 'usn_exempt', 'planned_regime' => 'undecided'])->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::DONE, $task->refresh()->status);

        // Ответ «не решил» устарел через 30 дней — в понедельник задача появится снова.
        $this->travel(31)->days();
        $this->artisan('crm:tax-regime-tasks')->assertSuccessful();

        $this->assertSame(2, CrmTask::query()->withAnyTags([TaxRegimeTaskPlanner::tag()], CrmTask::TAG_TYPE)->count());
    }

    #[Test]
    #[TestDox('Карточки партнёра и контрагента несут налоговый режим и варианты формы')]
    public function cards_carry_tax_regime(): void
    {
        $company = $this->contractor();

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->partner))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contractors.0.tax_regime.freshness.value', 'missing')
                ->where('taxRegimeOptions.target_year', now()->year + 1)
                ->where('canEditTaxRegime', true));

        $this->actingAs($this->manager)
            ->get(route('crm.contractors.show', $company))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('taxRegime.freshness.value', 'missing')
                ->has('taxRegimeOptions.current'));
    }

    #[Test]
    #[TestDox('Реестр: сводка по состояниям и менеджерам, отбор, выгрузка в Excel')]
    public function registry_shows_summary_and_exports(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->contractor(name: 'ООО Без ответа');
        $this->answer($this->contractor(name: 'ООО Ответило'), [
            'current_regime' => TaxRegime::USN_EXEMPT,
            'planned_regime' => TaxRegime::USN_VAT_22,
        ]);
        $this->contractor(buying: false, name: 'ООО Не покупает');

        $this->actingAs($head)
            ->get(route('crm.tax-regimes.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/TaxRegimes/Index')
                ->where('summary.totals.total', 2)
                ->where('summary.totals.fresh', 1)
                ->where('summary.totals.missing', 1)
                ->where('summary.shifts.0.value', 'to_deductible')
                ->where('summary.shifts.0.count', 1)
                ->where('summary.managers.0.total', 2)
                ->has('rows.data', 2));

        $this->actingAs($head)
            ->get(route('crm.tax-regimes.index', ['freshness' => 'missing', 'active' => 0]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.totals.total', 3)
                ->has('rows.data', 2));

        $response = $this->actingAs($head)->get(route('crm.tax-regimes.export'));
        $response->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
    }
}
