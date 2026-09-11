<?php

namespace App\Http\Controllers\Crm;

use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyShare;
use App\Models\PersonalManager;
use App\Services\Motivation\QuarterlyBonusService;
use App\Services\Motivation\QuarterReferenceService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Квартальная премия — экран руководителя (mot-38; формы B6 и B12).
 *
 * Тот же состав, что видит работник, плюс утверждение итога, распределение по
 * работникам с контролем суммы (п. 7.5) и отметка выплаты. Разнесённая доля
 * попадает в расчётный лист за последний месяц квартала отдельной строкой (п. 7.6).
 */
class MotivationQuarterAdminController extends CrmController
{
    public function __construct(
        private readonly QuarterReferenceService $reference,
        private readonly QuarterlyBonusService $bonuses,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/QuarterAdmin', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function recalculate(Request $request): JsonResponse
    {
        $quarter = $this->quarter($request);
        $bonus = $this->bonuses->recalculate($quarter);

        return response()->json(['ok' => true, 'message' => $bonus->isFrozen() ? 'Итог квартала утверждён и не пересчитывается.' : 'Черновик премии пересчитан.'] + $this->payload($request));
    }

    public function approve(Request $request, MotivationQuarterlyBonus $bonus): JsonResponse
    {
        return $this->act($request, fn () => $this->bonuses->approve($bonus, $this->crmActor($request)), 'Итог квартала утверждён. Теперь премию можно распределить между работниками.');
    }

    public function reopen(Request $request, MotivationQuarterlyBonus $bonus): JsonResponse
    {
        return $this->act($request, fn () => $this->bonuses->reopen($bonus, $this->crmActor($request)), 'Итог квартала переоткрыт: премия снова в черновике.');
    }

    public function markPaid(Request $request, MotivationQuarterlyBonus $bonus): JsonResponse
    {
        return $this->act($request, fn () => $this->bonuses->markPaid($bonus, $this->crmActor($request)), 'Премия отмечена выплаченной.');
    }

    public function distribute(Request $request, MotivationQuarterlyBonus $bonus): JsonResponse
    {
        $data = $request->validate([
            'shares' => ['required', 'array', 'min:1'],
            'shares.*.manager_id' => ['required', 'integer', 'exists:personal_managers,id'],
            'shares.*.amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'shares.required' => 'Укажите доли работников.',
            'shares.*.amount.required' => 'Укажите сумму по каждому работнику.',
            'shares.*.amount.min' => 'Сумма не может быть отрицательной.',
        ]);

        $shares = [];
        foreach ((array) $data['shares'] as $row) {
            $shares[(int) $row['manager_id']] = ($shares[(int) $row['manager_id']] ?? 0.0) + (float) $row['amount'];
        }

        return $this->act($request, fn () => $this->bonuses->distribute($bonus, $shares, $this->crmActor($request), $data['reason'] ?? null), 'Премия распределена: сумма долей равна сумме премии.');
    }

    private function act(Request $request, \Closure $action, string $message): JsonResponse
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => $message] + $this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $quarter = $this->quarter($request);
        $data = $this->reference->build($quarter);
        $bonus = MotivationQuarterlyBonus::query()->whereDate('quarter_start', $quarter)->first();

        $managers = PersonalManager::query()->active()->where('payroll_enabled', true)->orderBy('name')->get(['id', 'name']);
        $shares = $bonus === null ? collect() : MotivationQuarterlyShare::query()->where('bonus_id', $bonus->getKey())->with('author:id,name')->get()->keyBy('personal_manager_id');

        // Подсказка к распределению — вклад каждого в квалифицированных партнёрах.
        // Распределение не обязано ей следовать: п. 7.5 оставляет это руководителю.
        $contribution = [];
        foreach ($data['candidates'] as $candidate) {
            if ($candidate['qualified'] && $candidate['manager_id'] !== null) {
                $contribution[(int) $candidate['manager_id']] = ($contribution[(int) $candidate['manager_id']] ?? 0) + 1;
            }
        }
        $qualified = max(1, (int) $data['qualified_count']);

        $rows = $managers->map(function (PersonalManager $m) use ($shares, $contribution, $qualified, $data): array {
            $share = $shares[(int) $m->getKey()] ?? null;
            $count = $contribution[(int) $m->getKey()] ?? 0;

            return [
                'manager' => ['id' => (int) $m->getKey(), 'name' => (string) $m->name],
                'qualified' => $count,
                'suggested' => Money::round((float) $data['amount'] * $count / $qualified),
                'amount' => $share === null ? null : (float) $share->amount,
                'reason' => $share?->reason,
                'author' => $share?->author === null ? null : (string) $share->author->name,
            ];
        })->all();

        return [
            'data' => $data,
            'bonus' => $bonus === null ? null : [
                'id' => (int) $bonus->getKey(),
                'status' => $bonus->status,
                'status_label' => ['draft' => 'черновик', 'approved' => 'утверждена', 'paid' => 'выплачена'][$bonus->status] ?? $bonus->status,
                'approved_at' => $bonus->approved_at === null ? null : CarbonImmutable::parse((string) $bonus->approved_at)->toIso8601String(),
                'paid_at' => $bonus->paid_at === null ? null : CarbonImmutable::parse((string) $bonus->paid_at)->toIso8601String(),
                'computed_at' => data_get($bonus->snapshot, 'computed_at'),
            ],
            'distribution' => [
                'rows' => $rows,
                'total' => Money::round(array_sum(array_map(fn (array $r): float => (float) ($r['amount'] ?? 0), $rows))),
                'complete' => $bonus !== null && (float) $bonus->amount > 0 && abs(array_sum(array_map(fn (array $r): float => (float) ($r['amount'] ?? 0), $rows)) - (float) $bonus->amount) < 0.005,
            ],
            'quarter_options' => $this->quarterOptions(),
            'can_edit' => $this->crmActor($request)->can('crm-motivation.edit'),
        ];
    }

    private function quarter(Request $request): CarbonImmutable
    {
        $raw = (string) $request->query('quarter', $request->query('month', ''));

        return preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $raw) === 1
            ? CarbonImmutable::parse(substr($raw, 0, 7).'-01')->startOfQuarter()
            : CarbonImmutable::now()->startOfQuarter();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function quarterOptions(): array
    {
        $options = [];
        $q = CarbonImmutable::now()->startOfQuarter();
        for ($i = 0; $i < 6; $i++) {
            $options[] = ['value' => $q->format('Y-m'), 'label' => sprintf('%s квартал %d', ['I', 'II', 'III', 'IV'][$q->quarter - 1], $q->year)];
            $q = $q->subQuarter();
        }

        return $options;
    }
}
