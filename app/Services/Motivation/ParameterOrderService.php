<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationParameterOrder;
use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Приказы с параметрами Приложения № 1 (карточка mot-32).
 *
 * Параметры — не настройка приложения, а приказ, действующий с периода. Издание
 * приказа создаёт запись и новую версию схемы расчёта с той же даты: прежние
 * версии не правятся, утверждённые месяцы читаются по своим значениям.
 *
 * Предпросмотр считает тем же калькулятором на входах снимка выбранного месяца —
 * второй формулы не заводим. Он превращает форму ввода чисел с ценой ошибки
 * в десятки тысяч рублей в инструмент решения.
 */
class ParameterOrderService
{
    /** Компоненты схемы, чьи умолчания задаются приказом; у каждого есть и персональный слой. */
    public const ORDER_COMPONENTS = [
        'salary', 'motivation_channels_allowance', 'motivation_substitution', 'motivation_variable', 'motivation_guarantee',
    ];

    public function __construct(
        private readonly ParameterCatalog $catalog,
        private readonly MotivationSchemeInstaller $installer,
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollCalculator $calculator,
        private readonly PayrollParamsResolver $params,
    ) {}

    /**
     * Действующие значения на месяц: приказ либо умолчания Приложения № 1.
     *
     * @return array{order: MotivationParameterOrder|null, values: array<string, mixed>}
     */
    public function effective(CarbonInterface $month): array
    {
        $order = MotivationParameterOrder::effectiveFor(CarbonImmutable::instance($month));

        return [
            'order' => $order,
            'values' => $this->catalog->complete((array) ($order->values ?? [])),
        ];
    }

    /**
     * Экран «Параметры мотивации».
     *
     * @return array<string, mixed>
     */
    public function overview(CarbonInterface $month): array
    {
        ['order' => $order, 'values' => $values] = $this->effective($month);

        $groups = [];
        foreach ($this->catalog->groups() as $group) {
            $groups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'params' => array_map(fn (array $p): array => $p + ['value' => $values[$p['key']] ?? null], $group['params']),
            ];
        }

        return [
            'month' => CarbonImmutable::instance($month)->startOfMonth()->toDateString(),
            'current_order' => $order === null ? null : $this->orderRow($order),
            'is_default' => $order === null,
            'values' => $values,
            'groups' => $groups,
            'history' => $this->history(),
            'personal' => $this->personal(),
        ];
    }

    /**
     * История приказов с изменениями против предыдущего.
     *
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        $orders = MotivationParameterOrder::query()->with('author:id,name')->orderBy('effective_from')->get();
        $previous = $this->catalog->complete([]);
        $rows = [];

        foreach ($orders as $order) {
            $values = $this->catalog->complete((array) $order->values);
            $rows[] = $this->orderRow($order) + ['changes' => $this->catalog->diff($previous, $values)];
            $previous = $values;
        }

        return array_reverse($rows);
    }

    /**
     * Предпросмотр: доход каждого работника за месяц при предложенных значениях.
     *
     * @param  array<string, mixed>  $values  изменённые значения поверх действующих
     * @return array<string, mixed>
     */
    public function preview(CarbonInterface $month, array $values, ?int $managerId = null): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $proposed = $this->catalog->complete(array_replace($this->effective($period)['values'], $values));

        $managers = PersonalManager::query()
            ->active()->where('payroll_enabled', true)
            ->when($managerId !== null, fn ($q) => $q->whereKey($managerId))
            ->orderBy('name')
            ->get();

        $rows = [];
        $warnings = [];

        foreach ($managers as $manager) {
            $snapshot = $this->calculations->ensureDraft((int) $manager->getKey(), $period);
            $params = EffectiveParams::fromArray((array) $snapshot->params_effective);
            $inputs = PayrollInputs::fromArray((array) $snapshot->inputs);

            if (! $params->enabled('motivation_variable')) {
                $warnings[] = sprintf('%s: месяц считается по прежней схеме, предпросмотр по нему невозможен.', $manager->name);

                continue;
            }

            $current = $this->calculator->calculate($params, $inputs)->total;
            $projected = $this->calculator->calculate($this->withOrder($params, $proposed), $inputs)->total;

            $rows[] = [
                'manager_id' => (int) $manager->getKey(),
                'name' => (string) $manager->name,
                'current' => Money::round($current),
                'projected' => Money::round($projected),
                'delta' => Money::round($projected - $current),
            ];
        }

        $sumCurrent = array_sum(array_column($rows, 'current'));
        $sumProjected = array_sum(array_column($rows, 'projected'));

        return [
            'month' => $period->toDateString(),
            'rows' => $rows,
            'payroll' => [
                'current' => Money::round($sumCurrent),
                'projected' => Money::round($sumProjected),
                'delta' => Money::round($sumProjected - $sumCurrent),
            ],
            'warnings' => $warnings,
            'errors' => $this->catalog->validate($proposed),
        ];
    }

    /**
     * Издать приказ.
     *
     * @param  array<string, mixed>  $values
     * @return array{order: MotivationParameterOrder, warnings: list<string>}
     *
     * @throws \InvalidArgumentException с перечнем нарушенных правил
     */
    public function issue(array $values, CarbonInterface $effectiveFrom, ?string $number, ?CarbonInterface $date, ?string $comment, User $actor): array
    {
        $from = CarbonImmutable::instance($effectiveFrom)->startOfMonth();
        $complete = $this->catalog->complete(array_replace($this->effective($from)['values'], $values));

        $errors = $this->catalog->validate($complete);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        if (MotivationParameterOrder::query()->whereDate('effective_from', $from)->exists()) {
            throw new \InvalidArgumentException(sprintf('Приказ с началом действия %s уже издан. Ранее изданные приказы не правятся — выберите другой период.', $from->format('m.Y')));
        }

        $approved = PayrollCalculation::query()
            ->whereDate('period_month', '>=', $from)
            ->where('status', '<>', PayrollCalculation::STATUS_DRAFT)
            ->count();

        $order = DB::transaction(function () use ($complete, $from, $number, $date, $comment, $actor): MotivationParameterOrder {
            $order = MotivationParameterOrder::query()->create([
                'effective_from' => $from->toDateString(),
                'order_number' => $number,
                'order_date' => $date?->toDateString(),
                'values' => $complete,
                'comment' => $comment,
                'author_id' => $actor->getKey(),
            ]);

            // Приказ материализуется новой версией схемы: расчёт читает параметры оттуда.
            $this->installer->install($from, $actor, $complete);

            return $order;
        });

        $warnings = [];
        if ($approved > 0) {
            $warnings[] = sprintf('С этого периода уже есть утверждённых расчётов: %d. Они пересчитаны не будут — утверждённые месяцы читаются по своим значениям.', $approved);
        }

        return ['order' => $order, 'warnings' => $warnings];
    }

    /**
     * Отклонения по работнику: у кого условия не общие.
     *
     * Хранится только отклонение от приказа; работник без отклонений в списке
     * не появляется — иначе вкладка стала бы копией штатного расписания.
     *
     * @return list<array<string, mixed>>
     */
    public function personal(): array
    {
        $rows = [];

        foreach (PersonalManager::query()->active()->where('payroll_enabled', true)->orderBy('name')->get() as $manager) {
            $layer = $this->params->layer((int) $manager->getKey(), null);
            $overrides = array_intersect_key($layer, array_flip(self::ORDER_COMPONENTS));

            if ($overrides === []) {
                continue;
            }

            $rows[] = [
                'manager_id' => (int) $manager->getKey(),
                'name' => (string) $manager->name,
                'overrides' => $overrides,
            ];
        }

        return $rows;
    }

    /**
     * Сохранить персональное отклонение компонента (постоянный слой).
     *
     * @param  array<string, mixed>  $params  полный набор параметров компонента
     */
    public function savePersonal(int $managerId, string $componentKey, array $params, User $actor, ?string $comment): void
    {
        if (! in_array($componentKey, self::ORDER_COMPONENTS, true)) {
            throw new \InvalidArgumentException('У этого компонента нет персонального слоя.');
        }

        $this->params->save($managerId, null, $componentKey, $params, $actor, $comment);
    }

    public function resetPersonal(int $managerId, string $componentKey): void
    {
        $this->params->reset($managerId, null, $componentKey);
    }

    /**
     * Параметры с подменёнными умолчаниями приказа — персональные отклонения
     * в снимке остаются как есть: приказ меняет общее, не личное.
     *
     * @param  array<string, mixed>  $values
     */
    private function withOrder(EffectiveParams $params, array $values): EffectiveParams
    {
        $result = $params;
        $sources = $params->sources;

        foreach (self::ORDER_COMPONENTS as $key) {
            if (! $params->enabled($key)) {
                continue;
            }

            $merged = $params->for($key);
            $defaults = MotivationSchemeInstaller::componentDefaults($key, $values);

            foreach ($defaults as $param => $value) {
                // Личное отклонение важнее приказа — не трогаем; общее подменяем.
                if (($sources[$key][$param] ?? EffectiveParams::SOURCE_SCHEME) === EffectiveParams::SOURCE_SCHEME) {
                    $merged[$param] = $value;
                }
            }

            $result = $result->withComponent($key, $merged);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(MotivationParameterOrder $order): array
    {
        return [
            'id' => (int) $order->getKey(),
            'effective_from' => $order->effective_from->toDateString(),
            'order_number' => $order->order_number,
            'order_date' => $order->order_date?->toDateString(),
            'author' => $order->author?->name,
            'comment' => $order->comment,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
