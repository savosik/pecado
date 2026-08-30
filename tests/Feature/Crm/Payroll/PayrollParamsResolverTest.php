<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollParamOverride;
use App\Models\PayrollScheme;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Exceptions\InvalidPayrollParams;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\PayrollSchemeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PayrollParamsResolverTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private User $head;

    private Carbon $month;

    private PayrollParamsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = PersonalManager::factory()->create();
        $this->head = User::factory()->create();
        $this->month = Carbon::parse('2026-08-01');
        $this->resolver = app(PayrollParamsResolver::class);
    }

    #[Test]
    #[TestDox('Первая версия схемы материализуется из конфига при первом обращении')]
    public function scheme_v1_is_created_from_config(): void
    {
        $this->assertSame(0, PayrollScheme::query()->count());

        $params = $this->resolver->effective($this->manager->id, $this->month);

        $this->assertSame(1, PayrollScheme::query()->count());
        $this->assertSame(70000.0, (float) $params->for('salary')['amount']);
        $this->assertSame(85000.0, (float) $params->for('kpi_bonus')['base']);
        $this->assertSame(EffectiveParams::SOURCE_SCHEME, $params->sources['salary']['amount']);
        $this->assertSame(['salary', 'kpi_bonus', 'extra_income', 'manual_correction'], $params->enabledKeys());
    }

    #[Test]
    #[TestDox('Три слоя: схема → постоянные менеджера → месяц')]
    public function three_layers_override_in_order(): void
    {
        $this->resolver->save($this->manager->id, null, 'salary', ['amount' => 80000], $this->head);

        $params = $this->resolver->effective($this->manager->id, $this->month);
        $this->assertSame(80000.0, (float) $params->for('salary')['amount']);
        $this->assertSame(EffectiveParams::SOURCE_PERMANENT, $params->sources['salary']['amount']);

        $this->resolver->save($this->manager->id, $this->month, 'salary', ['amount' => 90000], $this->head, 'сезонная надбавка');

        $params = $this->resolver->effective($this->manager->id, $this->month);
        $this->assertSame(90000.0, (float) $params->for('salary')['amount']);
        $this->assertSame(EffectiveParams::SOURCE_MONTH, $params->sources['salary']['amount']);

        // Соседний месяц месячного слоя не видит — только постоянный.
        $next = $this->resolver->effective($this->manager->id, $this->month->copy()->addMonth());
        $this->assertSame(80000.0, (float) $next->for('salary')['amount']);
    }

    #[Test]
    #[TestDox('Совпадение с нижним слоем удаляет строку отклонения')]
    public function saving_value_equal_to_lower_layer_deletes_the_row(): void
    {
        $this->resolver->save($this->manager->id, null, 'salary', ['amount' => 80000], $this->head);
        $this->assertSame(1, PayrollParamOverride::query()->count());

        // Месяц = постоянному → строки месяца нет.
        $this->resolver->save($this->manager->id, $this->month, 'salary', ['amount' => 80000], $this->head);
        $this->assertSame(1, PayrollParamOverride::query()->count());

        // Постоянное = схеме → и постоянная строка исчезает.
        $this->resolver->save($this->manager->id, null, 'salary', ['amount' => 70000.0], $this->head);
        $this->assertSame(0, PayrollParamOverride::query()->count());
    }

    #[Test]
    #[TestDox('В строке хранятся только отличающиеся ключи, лестница — целиком')]
    public function override_row_keeps_only_the_diff(): void
    {
        $full = $this->resolver->effective($this->manager->id, $this->month)->for('kpi_bonus');
        $full['base'] = 90000;

        $this->resolver->save($this->manager->id, $this->month, 'kpi_bonus', $full, $this->head);

        $row = PayrollParamOverride::query()->firstOrFail();
        $this->assertSame(['base' => 90000], $row->params);

        $full['active_clients'] = ['ladder' => [
            ['from_share' => 0, 'multiplier' => 0.7],
            ['from_share' => 0.9, 'multiplier' => 1.0],
        ]];
        $this->resolver->save($this->manager->id, $this->month, 'kpi_bonus', $full, $this->head);

        $row->refresh();
        $this->assertSame(['base', 'active_clients'], array_keys($row->params));
        $this->assertCount(2, $row->params['active_clients']['ladder']);

        $effective = $this->resolver->effective($this->manager->id, $this->month)->for('kpi_bonus');
        $this->assertCount(2, $effective['active_clients']['ladder']);
        $this->assertCount(2, $effective['discipline_penalty']['tiers']);   // нетронутый фактор — из схемы
    }

    #[Test]
    #[TestDox('Невалидные параметры отвергаются: схема и монотонность лестницы')]
    public function invalid_params_are_rejected(): void
    {
        try {
            $this->resolver->save($this->manager->id, null, 'salary', ['amount' => -5], $this->head);
            $this->fail('Отрицательный оклад должен быть отвергнут');
        } catch (InvalidPayrollParams $e) {
            $this->assertSame('salary', $e->componentKey);
            $this->assertNotEmpty($e->errors);
        }

        $full = $this->resolver->effective($this->manager->id, $this->month)->for('kpi_bonus');
        // Ступени сортируются по порогу, поэтому «не по порядку» — не ошибка;
        // ошибка — лестница не с нуля и два одинаковых порога.
        $full['active_clients'] = ['ladder' => [
            ['from_share' => 0.5, 'multiplier' => 0.8],
            ['from_share' => 0.5, 'multiplier' => 0.9],
        ]];

        try {
            $this->resolver->save($this->manager->id, null, 'kpi_bonus', $full, $this->head);
            $this->fail('Лестница с дублем порога должна быть отвергнута');
        } catch (InvalidPayrollParams $e) {
            $this->assertStringContainsString('Первая ступень', implode(' ', $e->errors));
            $this->assertStringContainsString('не выше предыдущего', implode(' ', $e->errors));
        }

        $full['active_clients'] = ['ladder' => [['from_share' => 0, 'multiplier' => 1.0]]];
        $full['discipline_penalty'] = ['tiers' => [
            ['from_days' => 3, 'to_days' => null, 'coefficient' => 1.5],
            ['from_days' => 8, 'to_days' => null, 'coefficient' => 3],
        ]];

        try {
            $this->resolver->save($this->manager->id, null, 'kpi_bonus', $full, $this->head);
            $this->fail('Открытая не последняя ступень должна быть отвергнута');
        } catch (InvalidPayrollParams $e) {
            $this->assertStringContainsString('открыта', implode(' ', $e->errors));
        }

        $this->assertSame(0, PayrollParamOverride::query()->count());
    }

    #[Test]
    #[TestDox('Сброс слоя возвращает нижний')]
    public function reset_drops_the_layer(): void
    {
        $this->resolver->save($this->manager->id, null, 'salary', ['amount' => 80000], $this->head);
        $this->resolver->save($this->manager->id, $this->month, 'salary', ['amount' => 90000], $this->head);

        $this->resolver->reset($this->manager->id, $this->month, 'salary');
        $this->assertSame(80000.0, (float) $this->resolver->effective($this->manager->id, $this->month)->for('salary')['amount']);

        $this->resolver->reset($this->manager->id, null, 'salary');
        $this->assertSame(70000.0, (float) $this->resolver->effective($this->manager->id, $this->month)->for('salary')['amount']);
    }

    #[Test]
    #[TestDox('Копирование месяца переносит отклонения, без overwrite не затирает')]
    public function copy_month(): void
    {
        $other = PersonalManager::factory()->create();
        $next = $this->month->copy()->addMonth();

        $this->resolver->save($this->manager->id, $this->month, 'salary', ['amount' => 90000], $this->head);
        $this->resolver->save($other->id, $this->month, 'salary', ['amount' => 95000], $this->head);
        $this->resolver->save($other->id, $next, 'salary', ['amount' => 60000], $this->head);

        $result = $this->resolver->copyMonth($this->month, $next, $this->head);
        $this->assertSame(['copied' => 1, 'skipped' => 1], $result);
        $this->assertSame(90000.0, (float) $this->resolver->effective($this->manager->id, $next)->for('salary')['amount']);
        $this->assertSame(60000.0, (float) $this->resolver->effective($other->id, $next)->for('salary')['amount']);

        $result = $this->resolver->copyMonth($this->month, $next, $this->head, overwrite: true);
        $this->assertSame(['copied' => 2, 'skipped' => 0], $result);
        $this->assertSame(95000.0, (float) $this->resolver->effective($other->id, $next)->for('salary')['amount']);
    }

    #[Test]
    #[TestDox('Новая версия схемы действует с месяца, старые месяцы считаются по старой')]
    public function scheme_versions_apply_by_effective_month(): void
    {
        $repo = app(PayrollSchemeRepository::class);
        $repo->ensureDefault();

        $components = config('payroll.default_scheme.components');
        $components[0]['defaults']['amount'] = 75000;
        $repo->createVersion($components, Carbon::parse('2026-09-01'), $this->head, 'оклад 75');

        $this->assertSame(70000.0, (float) $this->resolver->effective($this->manager->id, Carbon::parse('2026-08-01'))->for('salary')['amount']);
        $this->assertSame(75000.0, (float) $this->resolver->effective($this->manager->id, Carbon::parse('2026-09-01'))->for('salary')['amount']);
        $this->assertSame(75000.0, (float) $this->resolver->effective($this->manager->id, Carbon::parse('2026-12-01'))->for('salary')['amount']);
    }
}
