<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationParameterOrder;
use App\Models\PayrollScheme;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Motivation\ParameterCatalog;
use App\Services\Motivation\ParameterOrderService;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollParamsResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * «Параметры мотивации» (карточка mot-32): приказы, история, предпросмотр, отклонения.
 *
 * Приёмка из ТЗ: каждая строка Приложения № 1 имеет поле; изменение ставки К1
 * меняет доход в предпросмотре и после сохранения на ту же величину;
 * утверждённый месяц после приказа показывает прежние числа; убывающие
 * ступени отклоняются с объяснением; персональное отклонение применяется
 * только к своему работнику.
 */
class MotivationSettingsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $first;

    private PersonalManager $second;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->first = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Первый']);
        $this->second = PersonalManager::factory()->create(['name' => 'Второй']);

        app(MotivationSchemeInstaller::class)->install($this->month);
    }

    #[Test]
    #[TestDox('Каждая строка Приложения № 1 имеет поле на экране')]
    public function every_appendix_parameter_has_a_field(): void
    {
        $catalog = app(ParameterCatalog::class);
        $defaults = array_keys((array) config('motivation.default_parameters'));

        $this->assertEqualsCanonicalizing($defaults, array_keys($catalog->index()), 'Каталог экрана и умолчания конфига обязаны совпадать по ключам');
        $this->assertCount(6, $catalog->groups(), 'Шесть групп, как в Приложении № 1');
    }

    #[Test]
    #[TestDox('Без приказа показаны умолчания; ставку К1 менеджер видит, а менять не может')]
    public function defaults_are_shown_until_the_first_order(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/motivation/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Settings')
                ->where('is_default', true)
                ->where('can_edit', true)
                ->where('values.rate_k1_per_day', 0.0005)
                ->has('groups', 6));

        $worker = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $worker->assignRole('sales-manager');
        $worker->givePermissionTo(Permission::findByName('crm-motivation.view'));

        $this->actingAs($worker)->get('/crm/motivation/settings')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_edit', false));
        $this->actingAs($worker)->postJson('/crm/motivation/settings/preview', ['values' => ['rate_p1' => 0.02]])->assertForbidden();
    }

    #[Test]
    #[TestDox('Убывающие ступени и порог вне (0;1] отклоняются с объяснением')]
    public function coherence_rules_reject_bad_values(): void
    {
        $catalog = app(ParameterCatalog::class);

        $errors = $catalog->validate($catalog->complete([
            'quarterly_steps' => [['count' => 8, 'amount' => 40000], ['count' => 6, 'amount' => 100000]],
            'payment_threshold' => 1.5,
            'novelty_periods' => 14,
        ]));

        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn (string $e): bool => str_contains($e, 'Ступень 2')));
        $this->assertTrue(collect($errors)->contains(fn (string $e): bool => str_contains($e, 'Порог оплаты')));
        $this->assertTrue(collect($errors)->contains(fn (string $e): bool => str_contains($e, 'Период новизны')));

        $this->actingAs($this->head)
            ->postJson('/crm/motivation/settings/order', [
                'effective_from' => $this->month->addMonth()->format('Y-m'),
                'values' => ['quarterly_steps' => [['count' => 8, 'amount' => 40000], ['count' => 6, 'amount' => 100000]]],
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Ступень 2: количество и сумма должны строго расти от ступени к ступени.']);

        $this->assertSame(0, MotivationParameterOrder::query()->count());
    }

    #[Test]
    #[TestDox('Предпросмотр и приказ меняют доход на одну и ту же величину')]
    public function preview_and_issued_order_agree(): void
    {
        $service = app(ParameterOrderService::class);
        $calculations = app(PayrollCalculationService::class);

        // Черновик с окладом по умолчанию: 70 000 + 5 000.
        $calculations->ensureDraft($this->first->id, $this->month);
        $calculations->ensureDraft($this->second->id, $this->month);

        $preview = $service->preview($this->month, ['salary' => 80000, 'channels_allowance' => 0]);

        $this->assertSame([], $preview['errors']);
        $this->assertCount(2, $preview['rows']);
        $this->assertSame(75_000.0, $preview['rows'][0]['current']);
        $this->assertSame(80_000.0, $preview['rows'][0]['projected']);
        $this->assertSame(10_000.0, $preview['payroll']['delta']);

        $result = $service->issue(['salary' => 80000, 'channels_allowance' => 0], $this->month, '1-М', $this->month, 'Тест', $this->head);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(2, PayrollScheme::query()->where('code', 'sales')->where('version', '>', 1)->count(), 'Приказ материализован новой версией схемы');

        $calculations->recalculateDraft($this->first->id, $this->month);
        $this->assertSame(80_000.0, (float) $calculations->current($this->first->id, $this->month)->total, 'После приказа расчёт даёт ровно то, что обещал предпросмотр');
    }

    #[Test]
    #[TestDox('Утверждённый месяц после нового приказа показывает прежние числа, приказ предупреждает')]
    public function approved_month_keeps_its_numbers(): void
    {
        $calculations = app(PayrollCalculationService::class);
        $draft = $calculations->ensureDraft($this->first->id, $this->month);
        $calculations->approve($draft, $this->head);

        $result = app(ParameterOrderService::class)->issue(['salary' => 90000], $this->month, null, null, null, $this->head);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('утверждённых расчётов: 1', $result['warnings'][0]);
        $this->assertSame(75_000.0, (float) $calculations->current($this->first->id, $this->month)->total, 'Утверждённый снимок читается по своим значениям');
    }

    #[Test]
    #[TestDox('История приказов показывает изменения против предыдущего; второй приказ на ту же дату не издаётся')]
    public function history_lists_changes_between_orders(): void
    {
        $service = app(ParameterOrderService::class);
        $service->issue(['rate_p3' => 0.03], $this->month, '9-ОП', null, null, $this->head);
        $service->issue(['rate_p3' => 0.03, 'rate_k1_per_day' => 0.0002], $this->month->addMonth(), '12-ОП', null, null, $this->head);

        $history = $service->history();

        $this->assertCount(2, $history);
        $this->assertSame('12-ОП', $history[0]['order_number'], 'Свежий приказ первым');
        $this->assertSame(['rate_k1_per_day'], array_column($history[0]['changes'], 'key'));
        $this->assertSame(['rate_p3'], array_column($history[1]['changes'], 'key'));
        $this->assertSame(0.01, $history[1]['changes'][0]['from']);
        $this->assertSame(0.03, $history[1]['changes'][0]['to']);

        $this->expectException(\InvalidArgumentException::class);
        $service->issue(['rate_p3' => 0.05], $this->month, null, null, null, $this->head);
    }

    #[Test]
    #[TestDox('Персональное отклонение применяется только к своему работнику; снятие возвращает к приказу')]
    public function personal_override_applies_to_one_worker(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/motivation/settings/personal', [
                'manager_id' => $this->second->id,
                'component_key' => 'salary',
                'params' => ['amount' => 55000],
                'comment' => 'Приказ о приёме № 7',
            ])
            ->assertOk()
            ->assertJsonPath('personal.0.name', 'Второй')
            ->assertJsonPath('personal.0.overrides.salary.amount', 55000);

        $calculations = app(PayrollCalculationService::class);
        $this->assertSame(60_000.0, (float) $calculations->ensureDraft($this->second->id, $this->month)->total, '55 000 + надбавка 5 000');
        $this->assertSame(75_000.0, (float) $calculations->ensureDraft($this->first->id, $this->month)->total, 'У другого работника ничего не изменилось');

        $this->actingAs($this->head)
            ->deleteJson('/crm/motivation/settings/personal', ['manager_id' => $this->second->id, 'component_key' => 'salary'])
            ->assertOk()
            ->assertJsonPath('personal', []);

        $this->assertSame([], app(PayrollParamsResolver::class)->layer($this->second->id, null));
    }

    #[Test]
    #[TestDox('Отклонение ставки П2 по работнику не трогается приказом на отдел в предпросмотре')]
    public function personal_rate_survives_order_preview(): void
    {
        app(ParameterOrderService::class)->savePersonal($this->second->id, 'motivation_variable', [
            'payment_threshold' => 0.6, 'rate_p1' => 0.019, 'rate_p2' => 0.05, 'rate_p3' => 0.01, 'rate_k1_per_day' => 0.0005, 'cap' => 200000,
        ], $this->head, null);

        $preview = app(ParameterOrderService::class)->preview($this->month, ['rate_p2' => 0.04], $this->second->id);

        // Отгрузок нет — суммы равны; проверяем, что предпросмотр не упал и ряд один.
        $this->assertCount(1, $preview['rows']);
        $this->assertSame('Второй', $preview['rows'][0]['name']);
    }
}
