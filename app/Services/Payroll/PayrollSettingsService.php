<?php

namespace App\Services\Payroll;

use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Models\PersonalManager;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Экран настроек РОПа: параметры менеджер × месяц, схема, ручные строки.
 *
 * Собирает то, что резолвер знает по одному менеджеру, в сетку по отделу —
 * с пометкой, откуда взялся каждый параметр (схема, постоянное, месяц).
 */
class PayrollSettingsService
{
    public function __construct(
        private readonly PayrollParamsResolver $params,
        private readonly PayrollCatalog $catalog,
        private readonly PayrollSchemeRepository $schemes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $scheme = $this->schemes->forMonth($period);
        $schemeParams = $this->params->fromScheme($scheme);

        // Без json-колонок: сетке нужны итог и статус, а строка снимка весит сотни
        // килобайт и в сортировке роняет MySQL («Out of sort memory»).
        $calculations = [];
        $rows = PayrollCalculation::query()
            ->forPeriod($period)
            ->orderBy('version')
            ->get(['id', 'personal_manager_id', 'period_month', 'version', 'status', 'total', 'computed_at']);

        foreach ($rows as $calculation) {
            $calculations[(int) $calculation->personal_manager_id] = $calculation;   // последняя версия побеждает
        }

        // Исключённые из расчёта тоже в списке: иначе вернуть их обратно было бы нечем.
        $managers = PersonalManager::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'user_id', 'payroll_enabled'])
            ->map(fn (PersonalManager $manager): array => $this->managerRow($manager, $period, $calculations[(int) $manager->getKey()] ?? null))
            ->all();

        return [
            'month' => $period->format('Y-m'),
            'month_label' => MonthLabel::ru($period),
            'scheme' => [
                'id' => (int) $scheme->getKey(),
                'title' => (string) $scheme->title,
                'version' => (int) $scheme->version,
                'effective_from' => $scheme->effective_from->toDateString(),
                'params' => $schemeParams->byComponent,
                'enabled' => $schemeParams->enabledKeys(),
            ],
            'components' => $this->components(),
            'managers' => $managers,
            'scheme_versions' => $this->schemeVersions(),
        ];
    }

    /**
     * Все версии схемы отдела — новые сверху.
     *
     * @return list<array<string, mixed>>
     */
    public function schemeVersions(): array
    {
        return array_map(fn (\App\Models\PayrollScheme $scheme): array => [
            'id' => (int) $scheme->getKey(),
            'version' => (int) $scheme->version,
            'title' => (string) $scheme->title,
            'effective_from' => $scheme->effective_from->toDateString(),
            'effective_label' => MonthLabel::ru($scheme->effective_from),
            'comment' => $scheme->comment,
            'author' => $scheme->author?->name,
            'components' => $scheme->orderedComponents(),
            'created_at' => $scheme->created_at?->toIso8601String(),
        ], $this->schemes->versions());
    }

    /**
     * Одна строка сетки — можно вернуть после сохранения без пересборки всего экрана.
     *
     * @return array<string, mixed>
     */
    public function managerRow(PersonalManager $manager, CarbonInterface $month, ?PayrollCalculation $calculation = null): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $managerId = (int) $manager->getKey();
        $effective = $this->params->effective($managerId, $period);

        $calculation ??= PayrollCalculation::latestFor($managerId, $period);

        return [
            'id' => $managerId,
            'name' => (string) $manager->name,
            'has_account' => $manager->user_id !== null,
            'payroll_enabled' => (bool) $manager->payroll_enabled,
            'params' => $effective->byComponent,
            'sources' => $effective->sources,
            'permanent' => $this->params->layer($managerId, null),
            'monthly' => $this->params->layer($managerId, $period),
            'calculation' => $calculation === null ? null : [
                'id' => (int) $calculation->getKey(),
                'status' => $calculation->status,
                'status_label' => $calculation->statusLabel(),
                'is_frozen' => $calculation->isFrozen(),
                'version' => (int) $calculation->version,
                'total' => (float) $calculation->total,
                'computed_at' => $calculation->computed_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Ручные строки месяца — все или одного менеджера.
     *
     * @return list<array<string, mixed>>
     */
    public function adjustments(CarbonInterface $month, ?int $managerId = null): array
    {
        $query = PayrollManualAdjustment::query()
            ->forPeriod($month)
            ->with(['manager:id,name', 'author:id,name'])
            ->orderBy('personal_manager_id')
            ->orderBy('id');

        if ($managerId !== null) {
            $query->forManager($managerId);
        }

        return $query->get()
            ->map(fn (PayrollManualAdjustment $row): array => [
                'id' => (int) $row->getKey(),
                'manager_id' => (int) $row->personal_manager_id,
                'manager_name' => $row->manager === null ? '' : (string) $row->manager->name,
                'component' => $row->component_key,
                'component_label' => $row->component_key === PayrollManualAdjustment::COMPONENT_EXTRA_INCOME ? 'Доп. доход' : 'Корректировка',
                'label' => (string) $row->label,
                'qty' => (float) $row->qty,
                'price' => (float) $row->price,
                'amount' => (float) $row->amount,
                'comment' => $row->comment,
                'author' => $row->author === null ? null : (string) $row->author->name,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Описание компонентов для форм: тексты и схемы параметров.
     *
     * @return array<string, array<string, mixed>>
     */
    private function components(): array
    {
        $rows = [];

        foreach ($this->catalog->keys() as $key) {
            $component = $this->catalog->component($key);
            $rows[$key] = [
                'label' => $component->label(),
                'description' => $component->description(),
                'how_computed' => $component->howComputed(),
                'schema' => $component->paramsSchema(),
                'has_params' => ($component->paramsSchema()['properties'] ?? []) !== [],
            ];
        }

        foreach ($this->catalog->factorKeys() as $key) {
            $factor = $this->catalog->factor($key);
            $rows[$key] = [
                'label' => $factor->label(),
                'description' => $factor->description(),
                'how_computed' => $factor->howComputed(),
                'schema' => $factor->paramsSchema(),
                'has_params' => ($factor->paramsSchema()['properties'] ?? []) !== [],
            ];
        }

        return $rows;
    }
}
