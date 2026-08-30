<?php

namespace App\Services\Payroll;

use App\Models\PayrollParamOverride;
use App\Models\PayrollScheme;
use App\Models\User;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Exceptions\InvalidPayrollParams;
use Carbon\CarbonInterface;

/**
 * Что в силе у менеджера в этом месяце: схема → постоянные отклонения → месяц.
 *
 * Единственное место, которое решает «какие параметры действуют». Экран
 * настроек, калькулятор и снимок спрашивают его — иначе интерфейс показывал бы
 * одни цифры, а считалось бы по другим.
 *
 * Слои сливаются по верхним ключам параметров компонента: вложенный объект
 * (лестница, ступени) заменяется целиком. Отклонение хранит только ключи,
 * отличные от нижнего слоя; совпадение с ним — удаление строки.
 */
class PayrollParamsResolver
{
    public function __construct(
        private readonly PayrollCatalog $catalog,
        private readonly PayrollSchemeRepository $schemes,
        private readonly PayrollParamsValidator $validator,
    ) {}

    public function effective(int $managerId, CarbonInterface $month): EffectiveParams
    {
        $scheme = $this->schemes->forMonth($month);

        $permanent = $this->layer($managerId, null);
        $monthly = $this->layer($managerId, $month);

        return $this->merge($scheme, $permanent, $monthly);
    }

    /**
     * Параметры только по схеме, без отклонений, — для тестов и предпросмотра.
     */
    public function fromScheme(PayrollScheme $scheme): EffectiveParams
    {
        return $this->merge($scheme, [], []);
    }

    /**
     * Сырой слой отклонений менеджера: ключ компонента → сохранённые отличия.
     *
     * @return array<string, array<string, mixed>>
     */
    public function layer(int $managerId, ?CarbonInterface $month): array
    {
        $rows = PayrollParamOverride::query()
            ->forManager($managerId)
            ->forPeriod($month)
            ->get(['component_key', 'params']);

        $layer = [];
        foreach ($rows as $row) {
            $layer[(string) $row->component_key] = (array) $row->params;
        }

        return $layer;
    }

    /**
     * Сохранить полный набор параметров компонента в слой (постоянный при $month = null).
     *
     * Хранится разница с нижним слоем; если разницы нет — строка удаляется.
     *
     * @param  array<string, mixed>  $fullParams
     *
     * @throws InvalidPayrollParams
     */
    public function save(
        int $managerId,
        ?CarbonInterface $month,
        string $componentKey,
        array $fullParams,
        ?User $actor,
        ?string $comment = null,
    ): void {
        if (! $this->catalog->exists($componentKey)) {
            throw new InvalidPayrollParams($componentKey, ['Неизвестный компонент.']);
        }

        $errors = $this->validator->validate($componentKey, $fullParams);
        if ($errors !== []) {
            throw new InvalidPayrollParams($componentKey, $errors);
        }

        $lower = $this->lowerLayer($managerId, $month)->for($componentKey);
        $diff = $this->diff($fullParams, $lower);

        $query = PayrollParamOverride::query()
            ->forManager($managerId)
            ->forPeriod($month)
            ->where('component_key', $componentKey);

        if ($diff === []) {
            $query->delete();

            return;
        }

        PayrollParamOverride::query()->updateOrCreate(
            [
                'personal_manager_id' => $managerId,
                'period_month' => PayrollParamOverride::periodKey($month),
                'component_key' => $componentKey,
            ],
            [
                'params' => $diff,
                'updated_by_user_id' => $actor?->getKey(),
                'comment' => $comment,
            ],
        );
    }

    /**
     * Снять отклонение слоя целиком — вернуться к нижнему.
     */
    public function reset(int $managerId, ?CarbonInterface $month, string $componentKey): void
    {
        PayrollParamOverride::query()
            ->forManager($managerId)
            ->forPeriod($month)
            ->where('component_key', $componentKey)
            ->delete();
    }

    /**
     * Скопировать месячные отклонения всех менеджеров из одного месяца в другой.
     *
     * @return array{copied: int, skipped: int}
     */
    public function copyMonth(CarbonInterface $from, CarbonInterface $to, ?User $actor, bool $overwrite = false): array
    {
        $copied = 0;
        $skipped = 0;

        $source = PayrollParamOverride::query()->forPeriod($from)->get();
        $targetKey = PayrollParamOverride::periodKey($to);

        foreach ($source as $row) {
            $exists = PayrollParamOverride::query()
                ->forManager((int) $row->personal_manager_id)
                ->forPeriod($to)
                ->where('component_key', $row->component_key)
                ->exists();

            if ($exists && ! $overwrite) {
                $skipped++;

                continue;
            }

            PayrollParamOverride::query()->updateOrCreate(
                [
                    'personal_manager_id' => $row->personal_manager_id,
                    'period_month' => $targetKey,
                    'component_key' => $row->component_key,
                ],
                [
                    'params' => $row->params,
                    'updated_by_user_id' => $actor?->getKey(),
                    'comment' => $row->comment,
                ],
            );
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Действующие параметры без верхнего слоя: для месяца — схема + постоянные,
     * для постоянного слоя — только схема.
     */
    private function lowerLayer(int $managerId, ?CarbonInterface $month): EffectiveParams
    {
        $scheme = $this->schemes->forMonth($month ?? now());

        return $month === null
            ? $this->merge($scheme, [], [])
            : $this->merge($scheme, $this->layer($managerId, null), []);
    }

    /**
     * @param  array<string, array<string, mixed>>  $permanent
     * @param  array<string, array<string, mixed>>  $monthly
     */
    private function merge(PayrollScheme $scheme, array $permanent, array $monthly): EffectiveParams
    {
        $order = [];
        $byComponent = [];
        $sources = [];

        foreach ($this->schemes->orderedComponents($scheme) as $entry) {
            $key = $entry['key'];
            $order[] = ['key' => $key, 'enabled' => $entry['enabled']];

            $base = array_replace($this->catalog->component($key)->defaults(), $entry['defaults']);
            $params = array_replace($base, $permanent[$key] ?? [], $monthly[$key] ?? []);

            $componentSources = [];
            foreach (array_keys($params) as $paramKey) {
                $componentSources[$paramKey] = match (true) {
                    array_key_exists($paramKey, $monthly[$key] ?? []) => EffectiveParams::SOURCE_MONTH,
                    array_key_exists($paramKey, $permanent[$key] ?? []) => EffectiveParams::SOURCE_PERMANENT,
                    default => EffectiveParams::SOURCE_SCHEME,
                };
            }

            $byComponent[$key] = $params;
            $sources[$key] = $componentSources;
        }

        return new EffectiveParams((int) $scheme->getKey(), $order, $byComponent, $sources);
    }

    /**
     * Ключи, значения которых отличаются от нижнего слоя.
     *
     * @param  array<string, mixed>  $full
     * @param  array<string, mixed>  $lower
     * @return array<string, mixed>
     */
    private function diff(array $full, array $lower): array
    {
        $diff = [];

        foreach ($full as $key => $value) {
            if (! array_key_exists($key, $lower) || ! $this->same($value, $lower[$key])) {
                $diff[$key] = $value;
            }
        }

        return $diff;
    }

    /**
     * Сравнение без ложных отличий: 85000 и 85000.0 — одно и то же число,
     * порядок ключей в объекте не важен, порядок элементов в списке — важен.
     */
    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }

            if (array_is_list($a) !== array_is_list($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! $this->same($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return $a === $b;
    }
}
