<?php

namespace Tests\Feature\User;

use App\Enums\Crm\TaskStatus;
use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\TaxRegimeFreshness;
use App\Enums\Crm\VatPreference;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Crm\TaxRegime\TaxRegimeTaskPlanner;
use App\Support\Impersonation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Опрос клиента о налогах и НДС на сайте.
 *
 * Инварианты: спрашиваем только покупающих клиентов о юрлицах без актуального
 * ответа; менеджер в режиме просмотра видит опрос и отвечает от своего имени;
 * ответ клиента ложится в CRM с источником и закрывает задачу менеджера;
 * «уточню у бухгалтера» не стирает известное; «Не сейчас» уважается.
 */
class TaxSurveyTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Середина года: сроки в две недели и 90 дней не пересекают 1 января.
        $this->travelTo(now()->setDate(now()->year, 3, 2)->setTime(12, 0));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function contractor(?User $partner = null, bool $buying = true, string $name = 'ИП Иванов'): Company
    {
        $company = Company::withoutEvents(fn () => Company::factory()->create([
            'user_id' => ($partner ?? $this->client)->id,
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

    private function survey(?User $user = null, array $session = [])
    {
        return $this->withSession($session)
            ->actingAs($user ?? $this->client)
            ->get(route('cabinet.dashboard'))
            ->assertOk();
    }

    private function answer(Company $company, array $answer)
    {
        return $this->actingAs($this->client)
            ->post(route('cabinet.tax-survey.store'), ['answers' => [$answer + ['company_id' => $company->id]]]);
    }

    #[Test]
    #[TestDox('Покупающему клиенту без ответа приходит опрос с приглашением')]
    public function buying_client_gets_survey(): void
    {
        $company = $this->contractor();
        $this->contractor(buying: false, name: 'ООО Спящее');

        $this->survey()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taxSurvey.invite', true)
            ->where('taxSurvey.target_year', now()->year + 1)
            ->has('taxSurvey.contractors', 1)
            ->where('taxSurvey.contractors.0.id', $company->id)
            ->has('taxSurvey.options.vat_preference', 4));
    }

    #[Test]
    #[TestDox('Опроса нет, если спрашивать не о чем, и у сотрудников')]
    public function no_survey_when_nothing_to_ask(): void
    {
        $this->contractor(buying: false);
        $this->survey()->assertInertia(fn (AssertableInertia $page) => $page->where('taxSurvey', null));

        $this->contractor($this->manager);
        $this->survey($this->manager)->assertInertia(fn (AssertableInertia $page) => $page->where('taxSurvey', null));
    }

    #[Test]
    #[TestDox('В режиме просмотра менеджер видит опрос, отвечает от своего имени и не откладывает его за клиента')]
    public function impersonating_manager_answers_on_own_behalf(): void
    {
        $company = $this->contractor();
        $session = [Impersonation::SESSION_KEY => $this->manager->id];

        $this->survey(session: $session)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('taxSurvey.contractors.0.id', $company->id));

        $this->withSession($session)
            ->actingAs($this->client)
            ->post(route('cabinet.tax-survey.snooze'))
            ->assertRedirect();
        $this->assertDatabaseCount('crm_tax_survey_prompts', 0);

        $this->withSession($session)
            ->actingAs($this->client)
            ->post(route('cabinet.tax-survey.store'), ['answers' => [[
                'company_id' => $company->id,
                'current_regime' => 'osno',
                'planned_regime' => 'osno',
                'vat_preference' => 'required',
            ]]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $regime = $company->taxRegime()->firstOrFail();
        $this->assertSame(CrmContractorTaxRegime::SOURCE_MANAGER, $regime->source);
        $this->assertSame($this->manager->id, $regime->confirmed_by);
        $this->assertDatabaseHas('crm_contractor_tax_regime_history', [
            'company_id' => $company->id,
            'source' => 'manager',
            'user_id' => $this->manager->id,
        ]);
    }

    #[Test]
    #[TestDox('Ответ клиента ложится в CRM с источником и закрывает задачу менеджера')]
    public function client_answer_reaches_crm_and_closes_task(): void
    {
        $company = $this->contractor();
        $this->artisan('crm:tax-regime-tasks')->assertSuccessful();
        $task = CrmTask::query()->withAnyTags([TaxRegimeTaskPlanner::tag()], CrmTask::TAG_TYPE)->firstOrFail();

        $this->answer($company, [
            'current_regime' => 'usn_exempt',
            'planned_regime' => 'usn_vat_5',
            'vat_preference' => 'required',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $regime = $company->taxRegime()->firstOrFail();
        $this->assertSame(CrmContractorTaxRegime::SOURCE_CLIENT, $regime->source);
        $this->assertSame($this->client->id, $regime->confirmed_by);
        $this->assertSame(VatPreference::REQUIRED, $regime->vat_preference);
        $this->assertSame(TaxRegimeFreshness::FRESH, CrmContractorTaxRegime::freshnessOf($regime));
        $this->assertDatabaseHas('crm_contractor_tax_regime_history', [
            'company_id' => $company->id,
            'source' => 'client',
            'user_id' => $this->client->id,
        ]);

        $this->assertSame(TaskStatus::DONE, $task->refresh()->status);

        $this->survey()->assertInertia(fn (AssertableInertia $page) => $page->where('taxSurvey', null));

        $this->actingAs($this->manager)
            ->get(route('crm.contractors.show', $company))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('taxRegime.source.value', 'client')
                ->where('taxRegime.vat_preference.value', 'required'));
    }

    #[Test]
    #[TestDox('«Уточню у бухгалтера» не стирает известное и не продлевает старый ответ')]
    public function unknown_answers_keep_what_is_known(): void
    {
        $company = $this->contractor();
        CrmContractorTaxRegime::create([
            'company_id' => $company->id,
            'current_regime' => TaxRegime::OSNO,
            'planned_regime' => TaxRegime::OSNO,
            'planned_year' => CrmContractorTaxRegime::targetYear(),
            'vat_preference' => VatPreference::PREFERRED,
            'confirmed_at' => now()->subDays(100),
            'confirmed_by' => $this->manager->id,
        ]);

        $this->answer($company, [
            'current_regime' => 'usn_exempt',
            'planned_regime' => null,
            'vat_preference' => null,
        ])->assertSessionHasNoErrors();

        $regime = $company->taxRegime()->firstOrFail();
        $this->assertSame(TaxRegime::USN_EXEMPT, $regime->current_regime);
        $this->assertSame(TaxRegime::OSNO, $regime->planned_regime);
        $this->assertSame(VatPreference::PREFERRED, $regime->vat_preference);
        $this->assertSame(TaxRegimeFreshness::OUTDATED, CrmContractorTaxRegime::freshnessOf($regime));
    }

    #[Test]
    #[TestDox('Чужое юрлицо и «ещё не решили» про текущий режим не принимаются')]
    public function foreign_company_and_bad_values_are_rejected(): void
    {
        $foreign = $this->contractor(User::factory()->create(), name: 'Чужое ООО');

        $this->answer($foreign, ['current_regime' => 'osno'])->assertSessionHasErrors('answers.0.company_id');
        $this->answer($this->contractor(), ['current_regime' => 'undecided'])->assertSessionHasErrors('answers.0.current_regime');

        $this->assertDatabaseCount('crm_contractor_tax_regimes', 0);
    }

    #[Test]
    #[TestDox('«Не сейчас» прячет приглашение на две недели, после двух отказов — насовсем, ярлычок остаётся')]
    public function snooze_is_respected(): void
    {
        $this->contractor();

        $this->actingAs($this->client)->post(route('cabinet.tax-survey.snooze'))->assertRedirect();
        $this->survey()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taxSurvey.invite', false)
            ->has('taxSurvey.contractors', 1));

        $this->travel(15)->days();
        $this->survey()->assertInertia(fn (AssertableInertia $page) => $page->where('taxSurvey.invite', true));

        $this->actingAs($this->client)->post(route('cabinet.tax-survey.snooze'))->assertRedirect();
        $this->travel(15)->days();
        $this->survey()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taxSurvey.invite', false)
            ->has('taxSurvey.contractors', 1));
    }
}
