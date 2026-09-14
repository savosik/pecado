<?php

namespace App\Services\Crm\TaxRegime;

use App\Enums\Crm\TaxRegimeFreshness;
use App\Enums\Crm\TaxRegimeShift;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Отборы юрлиц по налоговому режиму — общие для реестра, списка партнёров и задач.
 *
 * Список партнёров, задачи менеджеру и бейдж карточки обязаны считать «кому
 * нужно уточнить» одинаково: иначе задача висит, а в карточке всё зелёное.
 */
class TaxRegimeQuery
{
    /**
     * Юрлица, по которым была реализация за `active_days` дней.
     *
     * Задачи ставятся только по ним: объём под риском есть лишь у тех, кто
     * покупает, а звонок про налоги юрлицу, с которым не работаем, — впустую.
     *
     * @param  Builder<Company>  $companies
     * @return Builder<Company>
     */
    public function whereActive(Builder $companies): Builder
    {
        $since = now()->subDays((int) config('crm_tax_regime.active_days'));

        return $companies->whereExists(fn (QueryBuilder $shipments) => $shipments
            ->selectRaw('1')
            ->from('shipments')
            ->whereColumn('shipments.company_id', 'companies.id')
            // Бизнес-дата 1С: created_at у импортированной истории ничего не значит.
            ->where('shipments.erp_created_at', '>=', $since));
    }

    /**
     * Покупающие юрлица партнёров без актуального ответа — то, что превращается в задачу.
     *
     * @return Builder<Company>
     */
    public function pending(?int $partnerId = null): Builder
    {
        $query = Company::query()
            ->whereNotNull('companies.user_id')
            ->when($partnerId !== null, fn (Builder $q) => $q->where('companies.user_id', $partnerId));

        return $this->whereNeedsAnswer($this->whereActive($query));
    }

    /**
     * @param  Builder<Company>  $companies
     * @return Builder<Company>
     */
    public function whereNeedsAnswer(Builder $companies): Builder
    {
        return $companies->whereDoesntHave(
            'taxRegime',
            fn (Builder $regime) => CrmContractorTaxRegime::constrainUpToDate($regime),
        );
    }

    /**
     * @param  Builder<Company>  $companies
     * @return Builder<Company>
     */
    public function whereFreshness(Builder $companies, TaxRegimeFreshness $state): Builder
    {
        return match ($state) {
            TaxRegimeFreshness::FRESH => $companies->whereHas(
                'taxRegime',
                fn (Builder $regime) => CrmContractorTaxRegime::constrainUpToDate($regime),
            ),
            TaxRegimeFreshness::MISSING => $companies->whereDoesntHave(
                'taxRegime',
                fn (Builder $regime) => CrmContractorTaxRegime::constrainFilled($regime),
            ),
            TaxRegimeFreshness::OUTDATED => $companies->whereHas(
                'taxRegime',
                fn (Builder $regime) => CrmContractorTaxRegime::constrainFilled($regime)->whereNot(
                    fn (Builder $fresh) => CrmContractorTaxRegime::constrainUpToDate($fresh),
                ),
            ),
        };
    }

    /**
     * Юрлица, у которых план на следующий год означает заданную смену режима.
     *
     * Прошлогодний план не в счёт: «переходил на НДС в прошлом году» — это
     * уже текущий режим, а не риск.
     *
     * @param  Builder<Company>  $companies
     * @param  list<TaxRegimeShift>  $shifts
     * @return Builder<Company>
     */
    public function whereShift(Builder $companies, array $shifts): Builder
    {
        $values = array_map(static fn (TaxRegimeShift $shift): string => $shift->value, $shifts);
        $expression = TaxRegimeShift::sqlExpression('current_regime', 'planned_regime');

        return $companies->whereHas('taxRegime', fn (Builder $regime) => $regime
            ->where('planned_year', '>=', CrmContractorTaxRegime::targetYear())
            ->whereRaw(
                '('.$expression.') IN ('.implode(', ', array_fill(0, count($values), '?')).')',
                $values,
            ));
    }
}
