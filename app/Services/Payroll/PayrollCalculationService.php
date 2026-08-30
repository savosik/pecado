<?php

namespace App\Services\Payroll;

use App\Models\PayrollCalculation;
use App\Models\User;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollInputs;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Снимки расчёта: черновик живёт и пересчитывается, утверждённый заморожен.
 *
 * Один черновик на пару менеджер × месяц. Пересчёт с теми же входами и
 * параметрами только обновляет `computed_at` — разбор не переписывается.
 * «Переоткрыть» утверждённый месяц = новая версия черновика рядом со старой.
 */
class PayrollCalculationService
{
    public function __construct(
        private readonly PayrollParamsResolver $params,
        private readonly PayrollInputCollector $collector,
        private readonly PayrollCalculator $calculator,
        private readonly PayrollForecaster $forecaster,
        private readonly PayrollAdvisor $advisor,
    ) {}

    /**
     * Последняя версия снимка — черновик или замороженная.
     */
    public function current(int $managerId, CarbonInterface $month): ?PayrollCalculation
    {
        return PayrollCalculation::query()
            ->forManager($managerId)
            ->forPeriod($month)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Снимок для экрана: есть — отдать, нет — посчитать синхронно.
     */
    public function ensureDraft(int $managerId, CarbonInterface $month): PayrollCalculation
    {
        return $this->current($managerId, $month)
            ?? $this->recalculateDraft($managerId, $month, 'first-open')
            ?? throw new \LogicException('Черновик не создан и замороженного снимка нет');
    }

    /**
     * Пересчитать черновик. Замороженный месяц не трогается — вернёт null.
     */
    public function recalculateDraft(int $managerId, CarbonInterface $month, string $source = 'manual'): ?PayrollCalculation
    {
        $period = PayrollCalculation::normalizeMonth($month);

        return DB::transaction(function () use ($managerId, $period, $source): ?PayrollCalculation {
            $latest = PayrollCalculation::query()
                ->forManager($managerId)
                ->forPeriod($period)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($latest !== null && $latest->isFrozen()) {
                return null;
            }

            return $this->store($managerId, $period, $latest, $latest === null ? 1 : (int) $latest->version, $source);
        });
    }

    /**
     * Расчёт без сохранения — для консольного разбора и предпросмотра.
     *
     * @return array{params: EffectiveParams, inputs: PayrollInputs, breakdown: PayrollBreakdown}
     */
    public function preview(int $managerId, CarbonInterface $month): array
    {
        $params = $this->params->effective($managerId, $month);
        $inputs = $this->collector->collect($managerId, $month);

        return [
            'params' => $params,
            'inputs' => $inputs,
            'breakdown' => $this->calculator->calculate($params, $inputs),
        ];
    }

    public function approve(PayrollCalculation $calculation, User $actor, ?string $comment = null): PayrollCalculation
    {
        if (! $calculation->isDraft()) {
            throw new \InvalidArgumentException('Утвердить можно только черновик.');
        }

        $calculation->forceFill([
            'status' => PayrollCalculation::STATUS_APPROVED,
            'approved_by_user_id' => $actor->getKey(),
            'approved_at' => now(),
            'comment' => $comment,
            // Прогноз и советы — про будущее; у замороженного месяца будущего нет.
            'forecast' => null,
        ])->save();

        return $calculation;
    }

    public function markPaid(PayrollCalculation $calculation, User $actor): PayrollCalculation
    {
        if ($calculation->status !== PayrollCalculation::STATUS_APPROVED) {
            throw new \InvalidArgumentException('Отметить выплаченным можно только утверждённый расчёт.');
        }

        $calculation->forceFill([
            'status' => PayrollCalculation::STATUS_PAID,
            'paid_by_user_id' => $actor->getKey(),
            'paid_at' => now(),
        ])->save();

        return $calculation;
    }

    /**
     * Переоткрыть замороженный месяц: новая версия черновика, старая остаётся как была.
     */
    public function reopen(PayrollCalculation $calculation, User $actor, ?string $comment = null): PayrollCalculation
    {
        if ($calculation->isDraft()) {
            throw new \InvalidArgumentException('Переоткрыть можно только утверждённый расчёт.');
        }

        $managerId = (int) $calculation->personal_manager_id;
        $period = PayrollCalculation::normalizeMonth($calculation->period_month);

        return DB::transaction(function () use ($managerId, $period, $comment, $actor): PayrollCalculation {
            $maxVersion = (int) PayrollCalculation::query()
                ->forManager($managerId)
                ->forPeriod($period)
                ->max('version');

            $draft = $this->store($managerId, $period, null, $maxVersion + 1, 'reopen');
            $draft->forceFill(['comment' => $comment === null ? null : sprintf('%s (переоткрыл %s)', $comment, $actor->name)])->save();

            return $draft;
        });
    }

    /**
     * Черновики, которые давно не пересчитывались.
     *
     * @return Collection<int, PayrollCalculation>
     */
    public function staleDrafts(CarbonInterface $month, int $olderThanMinutes): Collection
    {
        return PayrollCalculation::query()
            ->forPeriod($month)
            ->drafts()
            ->where(function ($query) use ($olderThanMinutes): void {
                $query->whereNull('computed_at')
                    ->orWhere('computed_at', '<', now()->subMinutes($olderThanMinutes));
            })
            ->get();
    }

    /**
     * Месяцы, у которых у менеджера есть открытый черновик (кроме текущего).
     *
     * @return list<string> Y-m-d первого числа
     */
    public function openDraftMonths(int $managerId): array
    {
        return PayrollCalculation::query()
            ->forManager($managerId)
            ->drafts()
            ->pluck('period_month')
            ->map(fn ($date): string => CarbonImmutable::parse((string) $date)->startOfMonth()->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Прогноз и советы — только у живого месяца; для чужого времени они бессмысленны,
     * но считаются тем же кодом и просто пусты.
     *
     * @return array<string, mixed>
     */
    private function forecastPayload(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $breakdown): array
    {
        $forecast = $this->forecaster->forecast($params, $inputs, $breakdown);
        $forecast['advice'] = $this->advisor->advise($params, $inputs, $breakdown);

        return $forecast;
    }

    /**
     * Собрать, посчитать и сохранить черновик (создать или обновить строку).
     */
    private function store(int $managerId, CarbonImmutable|\Illuminate\Support\Carbon $period, ?PayrollCalculation $draft, int $version, string $source): PayrollCalculation
    {
        $params = $this->params->effective($managerId, $period);
        $inputs = $this->collector->collect($managerId, $period);
        $breakdown = $this->calculator->calculate($params, $inputs);

        $hash = $inputs->hash();
        $paramsArray = $params->toArray();

        // Прогноз зависит ещё и от сегодняшней даты (прошло дней, просрочка), поэтому
        // при неизменных входах обновляется только он — разбор остаётся прежним.
        if ($draft !== null && $draft->inputs_hash === $hash && $draft->params_effective == $paramsArray) {
            $draft->forceFill([
                'computed_at' => now(),
                'forecast' => $this->forecastPayload($params, $inputs, $breakdown),
            ])->save();

            return $draft;
        }

        $values = [
            'scheme_id' => $params->schemeId,
            'params_effective' => $paramsArray,
            'inputs' => $inputs->toArray(),
            'breakdown' => $breakdown->toArray(),
            'total' => $breakdown->total,
            'forecast' => $this->forecastPayload($params, $inputs, $breakdown),
            'inputs_hash' => $hash,
            'computed_at' => now(),
        ];

        if ($draft !== null) {
            $draft->forceFill($values)->save();

            return $draft;
        }

        return PayrollCalculation::query()->create(array_merge($values, [
            'personal_manager_id' => $managerId,
            'period_month' => $period,
            'version' => $version,
            'status' => PayrollCalculation::STATUS_DRAFT,
            'comment' => $source === 'reopen' ? 'Переоткрыто' : null,
        ]));
    }
}
